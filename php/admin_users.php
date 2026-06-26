<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && !headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Server fatal error','hint'=>$e['message']]);
  }
});
header('Content-Type: application/json');
header('Cache-Control: no-store');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$domain = $_SERVER['HTTP_HOST'] ?? '';
@session_name('RPSVSESSID');
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params([
    'lifetime'=>86400*7,
    'path'=>'/',
    'domain'=>$domain,
    'secure'=>$secure,
    'httponly'=>true,
    'samesite'=>'Lax'
  ]);
} else {
  session_set_cookie_params(86400*7, '/; samesite=Lax', $domain, $secure, true);
}
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Forbidden']);
  exit;
}

require __DIR__ . '/db.php';
// Ensure required columns exist
@$conn->query("CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'user',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
@ $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER password_hash");
@ $conn->query("ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
@ $conn->query("ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) DEFAULT NULL AFTER password_hash");

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

if ($action === 'list') {
  $res = $conn->query("SELECT id, username, email, role, is_blocked, created_at, password_hash, password_plain FROM users ORDER BY id DESC LIMIT 1000");
  if (!$res) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (list).','hint'=>$conn->error]); $conn->close(); exit; }
  $users = [];
  while ($row = $res->fetch_assoc()) {
    $users[] = [
      'id'=>(int)$row['id'],
      'username'=>$row['username'],
      'email'=>$row['email'],
      'role'=>$row['role'],
      'is_blocked'=>(int)($row['is_blocked'] ?? 0),
      'created_at'=>$row['created_at'],
      'password_hash'=>$row['password_hash'],
      'password_plain'=>$row['password_plain'],
    ];
  }
  $res->close();
  $conn->close();
  echo json_encode(['success'=>true,'users'=>$users]);
  exit;
}

if ($action === 'block' && $_SERVER['REQUEST_METHOD']==='POST') {
  $uid = (int)($_POST['user_id'] ?? 0);
  if ($uid <= 0) { echo json_encode(['success'=>false,'message'=>'user_id required']); $conn->close(); exit; }
  if ($uid === (int)$_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Cannot block yourself.']); $conn->close(); exit; }
  $st = $conn->prepare("UPDATE users SET is_blocked=1 WHERE id=? LIMIT 1");
  if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (block).','hint'=>$conn->error]); $conn->close(); exit; }
  $st->bind_param('i',$uid);
  $ok = $st->execute();
  $st->close();
  $conn->close();
  echo json_encode(['success'=>$ok]);
  exit;
}

if ($action === 'unblock' && $_SERVER['REQUEST_METHOD']==='POST') {
  $uid = (int)($_POST['user_id'] ?? 0);
  if ($uid <= 0) { echo json_encode(['success'=>false,'message'=>'user_id required']); $conn->close(); exit; }
  $st = $conn->prepare("UPDATE users SET is_blocked=0 WHERE id=? LIMIT 1");
  if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (unblock).','hint'=>$conn->error]); $conn->close(); exit; }
  $st->bind_param('i',$uid);
  $ok = $st->execute();
  $st->close();
  $conn->close();
  echo json_encode(['success'=>$ok]);
  exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  $uid = (int)($_POST['user_id'] ?? 0);
  if ($uid <= 0) { echo json_encode(['success'=>false,'message'=>'user_id required']); $conn->close(); exit; }
  if ($uid === (int)$_SESSION['user_id']) { echo json_encode(['success'=>false,'message'=>'Cannot delete yourself.']); $conn->close(); exit; }
  $st = $conn->prepare("DELETE FROM users WHERE id=? LIMIT 1");
  if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (delete).','hint'=>$conn->error]); $conn->close(); exit; }
  $st->bind_param('i',$uid);
  $ok = $st->execute();
  $st->close();
  $conn->close();
  echo json_encode(['success'=>$ok]);
  exit;
}

if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD']==='POST') {
  $uid = (int)($_POST['user_id'] ?? 0);
  $newpw = trim($_POST['new_password'] ?? '');
  if ($uid <= 0) { echo json_encode(['success'=>false,'message'=>'user_id required']); $conn->close(); exit; }
  if ($newpw === '') { // generate a random password if not provided
    $newpw = bin2hex(random_bytes(4)); // 8 hex chars
  }
  $hash = password_hash($newpw, PASSWORD_DEFAULT);
  $st = $conn->prepare("UPDATE users SET password_hash=?, password_plain=? WHERE id=? LIMIT 1");
  if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (reset).','hint'=>$conn->error]); $conn->close(); exit; }
  $st->bind_param('ssi', $hash, $newpw, $uid);
  $ok = $st->execute();
  $st->close();
  $conn->close();
  echo json_encode(['success'=>$ok,'new_password'=>$newpw]);
  exit;
}

http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Invalid action']);
