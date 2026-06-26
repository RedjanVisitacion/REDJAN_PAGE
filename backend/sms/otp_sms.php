<?php
/**
 * OTP SMS Authentication via SMSChef
 * Endpoints:
 *  - POST { action: 'send', phone }
 *  - POST { action: 'verify', phone, code }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

require_once __DIR__ . '/config_emergency.php';

function json_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) {
        // Fallback to form data
        $data = $_POST;
    }
    return is_array($data) ? $data : [];
}

function sanitize_phone($phone) {
    return preg_replace('/[^0-9+]/', '', $phone);
}

function is_valid_phone($phone) {
    return (bool)preg_match('/^\+?[1-9]\d{9,14}$/', $phone);
}

// Normalize to E.164 for Philippine numbers by default
function normalize_phone($phone) {
    if (strpos($phone, '+') === 0) return $phone; // already E.164
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^0\d{10}$/', $digits)) {
        return '+63' . substr($digits, 1);
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '+63' . $digits;
    }
    return $phone; // fallback; validation may fail later
}

function generate_otp($length = 6) {
    $min = 10 ** ($length - 1);
    $max = (10 ** $length) - 1;
    return (string)random_int($min, $max);
}

function store_otp($phone, $code, $ttl_seconds = 300) {
    $_SESSION['otp'] = $_SESSION['otp'] ?? [];
    $_SESSION['otp'][$phone] = [
        'code' => $code,
        'expires_at' => time() + $ttl_seconds,
        'sent_at' => time(),
        'attempts' => 0,
    ];
}

function can_send_again($phone, $cooldown = 60) {
    if (!isset($_SESSION['otp'][$phone])) return true;
    $sent_at = $_SESSION['otp'][$phone]['sent_at'] ?? 0;
    return (time() - $sent_at) >= $cooldown;
}

function send_sms_via_smschef($phone, $message) {
    $url = SMSCHEF_API_URL;

    $postData = [
        'secret' => SMSCHEF_SECRET,
        'mode' => 'devices',
        'device' => SMSCHEF_DEVICE,
        'sim' => SMSCHEF_SIM,
        'priority' => SMSCHEF_PRIORITY,
        'phone' => $phone,
        'message' => $message
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [false, 'cURL error: ' . $curlError, $httpCode, $response];
    }

    if ($httpCode === 200) {
        return [true, 'SMS sent successfully', $httpCode, $response];
    }

    return [false, 'Failed to send SMS. HTTP Code: ' . $httpCode, $httpCode, $response];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
        exit;
    }

    $input = json_input();
    $action = $input['action'] ?? '';
    $rawPhone = sanitize_phone($input['phone'] ?? '');
    $phone = normalize_phone($rawPhone);

    if (!$phone || !is_valid_phone($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing phone number']);
        exit;
    }

    if ($action === 'send') {
        if (!can_send_again($phone)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Please wait before requesting another OTP']);
            exit;
        }

        $code = generate_otp(6);
        $deviceName = defined('SMSCHEF_DEVICE_NAME') ? SMSCHEF_DEVICE_NAME : 'Device';
        $message = "Your Saferide verification code is {$code}. It expires in 5 minutes. Do not share this code. Sent via: {$deviceName}";

        [$ok, $err, $status, $raw] = send_sms_via_smschef($phone, $message);
        if ($ok) {
            store_otp($phone, $code, 300);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $err, 'status' => $status, 'response' => $raw]);
        }
        exit;
    }

    if ($action === 'verify') {
        $code = trim((string)($input['code'] ?? ''));
        if ($code === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'OTP code is required']);
            exit;
        }

        $record = $_SESSION['otp'][$phone] ?? null;
        if (!$record) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No OTP found for this phone. Please request a new code.']);
            exit;
        }

        // Increment attempts and prevent brute force
        $_SESSION['otp'][$phone]['attempts'] = ($record['attempts'] ?? 0) + 1;
        if ($_SESSION['otp'][$phone]['attempts'] > 5) {
            unset($_SESSION['otp'][$phone]);
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many attempts. Please request a new OTP.']);
            exit;
        }

        if (time() > ($record['expires_at'] ?? 0)) {
            unset($_SESSION['otp'][$phone]);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'OTP has expired. Please request a new code.']);
            exit;
        }

        if (hash_equals((string)$record['code'], (string)$code)) {
            unset($_SESSION['otp'][$phone]);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid OTP code']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
