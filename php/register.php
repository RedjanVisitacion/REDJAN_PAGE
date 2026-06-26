<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && (!headers_sent())) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server fatal error', 'hint' => $e['message']]);
    }
});
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}
require __DIR__ . '/db.php';
$conn->query("CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  password_plain VARCHAR(255) DEFAULT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'user',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verify_code VARCHAR(12) DEFAULT NULL,
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verify_expires DATETIME DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
@$conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER password_hash");
@ $conn->query("ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) DEFAULT NULL AFTER password_hash");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verify_code VARCHAR(12) DEFAULT NULL AFTER email_verified");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verify_token VARCHAR(64) DEFAULT NULL AFTER email_verify_code");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verify_expires DATETIME DEFAULT NULL AFTER email_verify_token");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verified_at DATETIME DEFAULT NULL AFTER email_verify_expires");
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';
if ($username === '' || $email === '' || $password === '' || $confirm === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email.']);
    exit;
}
if (!preg_match('/@(?:gmail\.com|googlemail\.com)$/i', $email)) {
    echo json_encode(['success' => false, 'message' => 'Email must be a valid Gmail address.']);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}
$check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
if (!$check) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (prepare check).', 'hint' => $conn->error]);
    exit;
}
$check->bind_param('ss', $username, $email);
if (!$check->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (execute check).', 'hint' => $check->error]);
    $check->close();
    $conn->close();
    exit;
}
$check->store_result();
if ($check->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
    $check->close();
    $conn->close();
    exit;
}
$check->close();
$hash = password_hash($password, PASSWORD_DEFAULT);
$role = 'user';
$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$token = bin2hex(random_bytes(24));
$expires = date('Y-m-d H:i:s', time() + 1800);
$stmt = $conn->prepare('INSERT INTO users (username, email, password_hash, password_plain, role, email_verified, email_verify_code, email_verify_token, email_verify_expires) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (prepare insert).', 'hint' => $conn->error]);
    exit;
}
$stmt->bind_param('ssssssss', $username, $email, $hash, $password, $role, $code, $token, $expires);
$ok = $stmt->execute();
if ($ok) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/php/register.php';
    $dir = rtrim(str_replace('\\','/', dirname($scriptName)), '/');
    $verifyPath = preg_replace('#/+#','/',$dir . '/../html/verify.html');
    $link = $scheme . '://' . $host . $verifyPath . '?token=' . urlencode($token);
    $emailSent = false; $mailErr = null;
    $mailerCfg = dirname(__DIR__) . '/backend/mailer/mailer_config.php';
    if (is_file($mailerCfg)) {
        require_once $mailerCfg;
        $err = null; $mail = new_configured_mailer($err);
        if ($mail) {
            try {
                $mail->clearAllRecipients();
                $mail->addAddress($email, $username);
                if (method_exists($mail, 'addReplyTo')) { $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME); }
                $mail->CharSet = 'UTF-8';
                $mail->Subject = 'REDJAN Page - Verify your account';
                $pre = '<div style="display:none;max-height:0;overflow:hidden;opacity:0">Your verification code is ' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . ' for REDJAN Page. Expires in 30 minutes.</div>';
                $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only"><title>Verify your account - REDJAN Page</title></head>' .
                        '<body style="margin:0;padding:24px;background:#f5f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111">' . $pre .
                        '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e8ee;border-radius:10px;box-shadow:0 2px 10px rgba(17,24,39,.05)">' .
                        '  <div style="padding:20px 24px;border-bottom:1px solid #f0f2f7">' .
                        '    <h1 style="margin:0;font-size:20px;color:#111">REDJAN Page</h1>' .
                        '    <p style="margin:6px 0 0;color:#6b7280;font-size:13px">Account verification</p>' .
                        '  </div>' .
                        '  <div style="padding:24px">' .
                        '    <p style="margin:0 0 12px">Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>' .
                        '    <p style="margin:0 0 12px">Use the verification code below to complete your registration. This code expires in 30 minutes.</p>' .
                        '    <div style="font-size:28px;letter-spacing:2px;font-weight:700;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;text-align:center">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>' .
                        '    <p style="margin:16px 0 0">Or verify using this link:</p>' .
                        '    <p style="margin:8px 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="color:#dc2626;text-decoration:underline">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>' .
                        '    <p style="margin:16px 0 0;color:#6b7280;font-size:13px">If you didnâ€™t create an account on REDJAN Page, you can safely ignore this email.</p>' .
                        '  </div>' .
                        '  <div style="padding:16px 24px;border-top:1px solid #f0f2f7;color:#6b7280;font-size:12px">Sent by REDJAN Page</div>' .
                        '</div>' .
                        '</body></html>';
                $mail->Body = $html;
                $mail->AltBody = 'Hi ' . $username . "\n\n" . 'Your verification code is: ' . $code . "\n" . 'This code expires in 30 minutes.' . "\n" . 'Or open: ' . $link . "\n\n" . 'If you did not create an account on REDJAN Page, you can ignore this email.';
                $mail->send();
                $emailSent = true;
            } catch (Throwable $e) { $mailErr = $e->getMessage(); }
        } else { $mailErr = $err ?: 'mailer_init_failed'; }
    } else { $mailErr = 'mailer_config_missing'; }
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful. Please verify the code sent to your email.',
        'verification_required' => true,
        'email_sent' => $emailSent,
        'email_error' => $emailSent ? null : $mailErr,
        'verify_url' => $link,
        'verify_token' => $token
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed.', 'hint' => $stmt->error]);
}
$stmt->close();
$conn->close();
?>
