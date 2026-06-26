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
@$conn->query("ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL AFTER password_plain");
@$conn->query("ALTER TABLE users ADD COLUMN password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$now = date('Y-m-d H:i:s');

function build_reset_link($token){
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/php/password_reset.php';
    $dir = rtrim(str_replace('\\','/', dirname($scriptName)), '/');
    $resetPath = preg_replace('#/+#','/',$dir . '/../html/reset.html');
    return $scheme . '://' . $host . $resetPath . '?token=' . urlencode($token);
}

if ($method === 'POST' && $action === 'request') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>true,'message'=>'If that email exists, a reset link will be sent.']); $conn->close(); exit; }
    $sel = $conn->prepare('SELECT id, username FROM users WHERE email = ? LIMIT 1');
    if (!$sel) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
    $sel->bind_param('s', $email);
    $sel->execute();
    $sel->store_result();
    $uid = 0; $username = '';
    $sel->bind_result($uid, $username);
    $found = $sel->fetch();
    $sel->close();
    if ($found) {
        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $upd = $conn->prepare('UPDATE users SET password_reset_token=?, password_reset_expires=? WHERE id=?');
        if ($upd) {
            $upd->bind_param('ssi', $token, $expires, $uid);
            $upd->execute();
            $upd->close();
            $link = build_reset_link($token);
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
                        $mail->Subject = 'REDJAN Page - Reset your password';
                        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only"><title>Password reset - REDJAN Page</title></head>' .
                                '<body style="margin:0;padding:24px;background:#f5f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111">' .
                                '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e8ee;border-radius:10px;box-shadow:0 2px 10px rgba(17,24,39,.05)">' .
                                '  <div style="padding:20px 24px;border-bottom:1px solid #f0f2f7">' .
                                '    <h1 style="margin:0;font-size:20px;color:#111">REDJAN Page</h1>' .
                                '    <p style="margin:6px 0 0;color:#6b7280;font-size:13px">Password reset</p>' .
                                '  </div>' .
                                '  <div style="padding:24px">' .
                                '    <p style="margin:0 0 12px">Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>' .
                                '    <p style="margin:0 0 12px">Click the link below to set a new password. This link expires in 1 hour.</p>' .
                                '    <p style="margin:8px 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="color:#dc2626;text-decoration:underline">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>' .
                                '  </div>' .
                                '  <div style="padding:16px 24px;border-top:1px solid #f0f2f7;color:#6b7280;font-size:12px">Sent by REDJAN Page</div>' .
                                '</div>' .
                                '</body></html>';
                        $mail->Body = $html;
                        $mail->AltBody = 'Hi ' . $username . "\n\nUse this link to reset your password (expires in 1 hour):\n" . $link;
                        $mail->send();
                        $emailSent = true;
                    } catch (Throwable $e) { $mailErr = $e->getMessage(); }
                } else { $mailErr = $err ?: 'mailer_init_failed'; }
            } else { $mailErr = 'mailer_config_missing'; }
        }
    }
    echo json_encode(['success'=>true,'message'=>'If that email exists, a reset link will be sent.']);
    $conn->close();
    exit;
}

if ($method === 'GET' && isset($_GET['token'])){
    $token = trim($_GET['token']);
    if ($token === '') { echo json_encode(['success'=>false,'message'=>'Missing token.']); $conn->close(); exit; }
    $sel = $conn->prepare('SELECT id, email, username, password_reset_expires FROM users WHERE password_reset_token=? LIMIT 1');
    if (!$sel) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
    $sel->bind_param('s', $token);
    $sel->execute();
    $sel->store_result();
    $uid=0; $email=''; $username=''; $exp=null; $sel->bind_result($uid,$email,$username,$exp);
    $found = $sel->fetch();
    $sel->close();
    if (!$found) { echo json_encode(['success'=>false,'message'=>'Invalid token.']); $conn->close(); exit; }
    if ($exp !== null && strtotime($exp) < time()) { echo json_encode(['success'=>false,'message'=>'Reset token expired.']); $conn->close(); exit; }
    echo json_encode(['success'=>true,'message'=>'Token valid.','email'=>$email,'username'=>$username]);
    $conn->close();
    exit;
}

if ($method === 'POST' && $action === 'reset'){
    $token = trim($_POST['token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($token === '' || $password === '') { echo json_encode(['success'=>false,'message'=>'Missing token or password.']); $conn->close(); exit; }
    $sel = $conn->prepare('SELECT id, password_reset_expires FROM users WHERE password_reset_token=? LIMIT 1');
    if (!$sel) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
    $sel->bind_param('s', $token);
    $sel->execute();
    $sel->store_result();
    $uid=0; $exp=null; $sel->bind_result($uid,$exp);
    $found = $sel->fetch();
    $sel->close();
    if (!$found) { echo json_encode(['success'=>false,'message'=>'Invalid token.']); $conn->close(); exit; }
    if ($exp !== null && strtotime($exp) < time()) { echo json_encode(['success'=>false,'message'=>'Reset token expired.']); $conn->close(); exit; }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upd = $conn->prepare('UPDATE users SET password_hash=?, password_plain=?, password_reset_token=NULL, password_reset_expires=NULL WHERE id=?');
    if (!$upd) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare update).','hint'=>$conn->error]); $conn->close(); exit; }
    $upd->bind_param('ssi', $hash, $password, $uid);
    $ok = $upd->execute();
    $upd->close();
    echo json_encode(['success'=>$ok?true:false,'message'=>$ok?'Password has been reset.':'Password reset failed.']);
    $conn->close();
    exit;
}

echo json_encode(['success'=>false,'message'=>'Invalid request.']);
$conn->close();
