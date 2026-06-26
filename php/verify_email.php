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

require __DIR__ . '/db.php';
// Ensure columns exist (idempotent)
@$conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verify_code VARCHAR(12) DEFAULT NULL AFTER email_verified");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verify_token VARCHAR(64) DEFAULT NULL AFTER email_verify_code");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verify_expires DATETIME DEFAULT NULL AFTER email_verify_token");
@ $conn->query("ALTER TABLE users ADD COLUMN email_verified_at DATETIME DEFAULT NULL AFTER email_verify_expires");

$now = date('Y-m-d H:i:s');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Case 1: GET /verify_email.php?token=...
if ($method === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if ($token === '') { echo json_encode(['success'=>false,'message'=>'Missing token.']); $conn->close(); exit; }
    $sel = $conn->prepare('SELECT id, email_verified, email_verify_expires FROM users WHERE email_verify_token = ? LIMIT 1');
    if (!$sel) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
    $sel->bind_param('s', $token);
    $sel->execute();
    $sel->store_result();
    $uid = 0; $verified = 0; $exp = null;
    $sel->bind_result($uid, $verified, $exp);
    $found = $sel->fetch();
    $sel->close();
    if (!$found) { echo json_encode(['success'=>false,'message'=>'Invalid token.']); $conn->close(); exit; }
    if (intval($verified) === 1) { echo json_encode(['success'=>true,'message'=>'Email already verified.']); $conn->close(); exit; }
    if ($exp !== null && strtotime($exp) < time()) { echo json_encode(['success'=>false,'message'=>'Verification token expired.']); $conn->close(); exit; }
    $upd = $conn->prepare('UPDATE users SET email_verified=1, email_verified_at=?, email_verify_code=NULL, email_verify_token=NULL, email_verify_expires=NULL WHERE id=?');
    if (!$upd) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare update).','hint'=>$conn->error]); $conn->close(); exit; }
    $upd->bind_param('si', $now, $uid);
    $ok = $upd->execute();
    $upd->close();
    echo json_encode(['success'=>$ok ? true : false, 'message'=>$ok ? 'Email verified successfully.' : 'Verification update failed.']);
    $conn->close();
    exit;
}

// Case 2: POST with code + email
if ($method === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    if ($email === '' || $code === '') { echo json_encode(['success'=>false,'message'=>'Email and code are required.']); $conn->close(); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Invalid email.']); $conn->close(); exit; }
    $sel = $conn->prepare('SELECT id, email_verified, email_verify_code, email_verify_expires FROM users WHERE email = ? LIMIT 1');
    if (!$sel) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
    $sel->bind_param('s', $email);
    $sel->execute();
    $sel->store_result();
    $uid = 0; $verified = 0; $dbCode = null; $exp = null;
    $sel->bind_result($uid, $verified, $dbCode, $exp);
    $found = $sel->fetch();
    $sel->close();
    if (!$found) { echo json_encode(['success'=>false,'message'=>'Account not found.']); $conn->close(); exit; }
    if (intval($verified) === 1) { echo json_encode(['success'=>true,'message'=>'Email already verified.']); $conn->close(); exit; }
    if ($dbCode === null || $dbCode === '') { echo json_encode(['success'=>false,'message'=>'No verification code on record.']); $conn->close(); exit; }
    if ($exp !== null && strtotime($exp) < time()) { echo json_encode(['success'=>false,'message'=>'Verification code expired.']); $conn->close(); exit; }
    if (hash_equals($dbCode, $code) === false) { echo json_encode(['success'=>false,'message'=>'Invalid code.']); $conn->close(); exit; }
    $upd = $conn->prepare('UPDATE users SET email_verified=1, email_verified_at=?, email_verify_code=NULL, email_verify_token=NULL, email_verify_expires=NULL WHERE id=?');
    if (!$upd) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare update).','hint'=>$conn->error]); $conn->close(); exit; }
    $upd->bind_param('si', $now, $uid);
    $ok = $upd->execute();
    $upd->close();
    echo json_encode(['success'=>$ok ? true : false, 'message'=>$ok ? 'Email verified successfully.' : 'Verification update failed.']);
    $conn->close();
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid method.']);
$conn->close();
?>
