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
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
require __DIR__ . '/db.php';

// Ensure contacts table exists
@$conn->query("CREATE TABLE IF NOT EXISTS contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  a_id INT NOT NULL,
  b_id INT NOT NULL,
  requested_by INT NOT NULL,
  status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pair (a_id,b_id),
  INDEX idx_req (requested_by),
  INDEX idx_a (a_id),
  INDEX idx_b (b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$me = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

function pair_ab($x,$y){
  $a = min($x,$y); $b = max($x,$y); return [$a,$b];
}

if ($action === 'list') {
  // Contacts list (accepted) and incoming requests for this user
  $sql = "SELECT u.id, u.username
          FROM contacts c
          JOIN users u ON u.id = (CASE WHEN c.a_id = ? THEN c.b_id ELSE c.a_id END)
          WHERE (c.a_id = ? OR c.b_id = ?) AND c.status = 'accepted'
          ORDER BY u.username ASC";
  $st = $conn->prepare($sql);
  if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
  $st->bind_param('iii', $me, $me, $me);
  if (!$st->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute).','hint'=>$st->error]); $st->close(); $conn->close(); exit; }
  $rs = $st->get_result();
  $contacts = [];
  while ($row = $rs->fetch_assoc()) { $contacts[] = ['id'=>(int)$row['id'], 'username'=>$row['username'], 'avatar'=>strtoupper(substr($row['username'],0,1))]; }
  $st->close();

  $sql2 = "SELECT c.requested_by AS id, u.username
           FROM contacts c
           JOIN users u ON u.id = c.requested_by
           WHERE (c.a_id = ? OR c.b_id = ?) AND c.status = 'pending' AND c.requested_by <> ?";
  $st2 = $conn->prepare($sql2);
  if (!$st2) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare-requests).','hint'=>$conn->error]); $conn->close(); exit; }
  $st2->bind_param('iii', $me, $me, $me);
  if (!$st2->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute-requests).','hint'=>$st2->error]); $st2->close(); $conn->close(); exit; }
  $rs2 = $st2->get_result();
  $incoming = [];
  while ($row = $rs2->fetch_assoc()) { $incoming[] = ['id'=>(int)$row['id'], 'username'=>$row['username']]; }
  $st2->close();

  // Friend suggestions: all users (excluding admin) not yet connected or pending with me
  $sql3 = "SELECT u.id, u.username
           FROM users u
           WHERE u.id <> ? AND (u.role IS NULL OR u.role <> 'admin')
             AND NOT EXISTS (
               SELECT 1 FROM contacts c
               WHERE c.a_id = LEAST(u.id, ?)
                 AND c.b_id = GREATEST(u.id, ?)
             )
           ORDER BY u.username ASC";
  $st3 = $conn->prepare($sql3);
  if (!$st3) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare-suggestions).','hint'=>$conn->error]); $conn->close(); exit; }
  $st3->bind_param('iii', $me, $me, $me);
  if (!$st3->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute-suggestions).','hint'=>$st3->error]); $st3->close(); $conn->close(); exit; }
  $rs3 = $st3->get_result();
  $suggestions = [];
  while ($row = $rs3->fetch_assoc()) { $suggestions[] = ['id'=>(int)$row['id'], 'username'=>$row['username']]; }
  $st3->close();

  // Ensure admin always appears in user Chats (do not require request)
  $adminId = 0; $adminName = null;
  $qa = $conn->query("SELECT id, username FROM users WHERE role='admin' LIMIT 1");
  if ($qa && $ar = $qa->fetch_assoc()) { $adminId = (int)$ar['id']; $adminName = $ar['username']; }
  if ($qa) { $qa->close(); }
  if ($adminId > 0 && $adminId !== $me) {
    $already = false;
    foreach ($contacts as $c) { if ((int)$c['id'] === $adminId) { $already = true; break; } }
    if (!$already) {
      $adminAvatar = strtoupper(substr($adminName,0,1));
      array_unshift($contacts, ['id'=>$adminId, 'username'=>$adminName, 'avatar'=>$adminAvatar]);
    }
  }

  $conn->close();
  echo json_encode(['success'=>true, 'contacts'=>$contacts, 'incoming'=>$incoming, 'suggestions'=>$suggestions]);
  exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  if ($username === '') { echo json_encode(['success'=>false,'message'=>'Username required.']); $conn->close(); exit; }
  $st = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
  if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare user).','hint'=>$conn->error]); $conn->close(); exit; }
  $st->bind_param('s', $username);
  $st->execute();
  $st->bind_result($targetId);
  $found = $st->fetch();
  $st->close();
  if (!$found) { echo json_encode(['success'=>false,'message'=>'User not found.']); $conn->close(); exit; }
  $targetId = (int)$targetId;
  if ($targetId === $me) { echo json_encode(['success'=>false,'message'=>'You cannot add yourself.']); $conn->close(); exit; }

  [$a,$b] = pair_ab($me, $targetId);
  $q = $conn->prepare('SELECT status, requested_by FROM contacts WHERE a_id=? AND b_id=? LIMIT 1');
  if (!$q) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare check).','hint'=>$conn->error]); $conn->close(); exit; }
  $q->bind_param('ii', $a, $b);
  $q->execute();
  $q->bind_result($status, $requested_by);
  $exists = $q->fetch();
  $q->close();

  if ($exists) {
    if ($status === 'accepted') { echo json_encode(['success'=>false,'message'=>'Already in your contacts.']); $conn->close(); exit; }
    if ((int)$requested_by === $me) { echo json_encode(['success'=>true,'message'=>'Request already sent.']); $conn->close(); exit; }
    echo json_encode(['success'=>false,'message'=>'This user already sent you a request. Check Requests.']); $conn->close(); exit; 
  }

  $st2 = $conn->prepare('INSERT INTO contacts (a_id,b_id,requested_by,status) VALUES (?,?,?,\'pending\')');
  if (!$st2) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare insert).','hint'=>$conn->error]); $conn->close(); exit; }
  $st2->bind_param('iii', $a, $b, $me);
  if (!$st2->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute insert).','hint'=>$st2->error]); $st2->close(); $conn->close(); exit; }
  $st2->close();
  $conn->close();
  echo json_encode(['success'=>true,'message'=>'Contact request sent.']);
  exit;
}

if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $fromId = (int)($_POST['from_id'] ?? 0);
  if ($fromId <= 0) { echo json_encode(['success'=>false,'message'=>'from_id required.']); $conn->close(); exit; }
  [$a,$b] = pair_ab($me, $fromId);
  $q = $conn->prepare('SELECT status, requested_by FROM contacts WHERE a_id=? AND b_id=? LIMIT 1');
  if (!$q) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare check).','hint'=>$conn->error]); $conn->close(); exit; }
  $q->bind_param('ii', $a, $b);
  $q->execute();
  $q->bind_result($status, $requested_by);
  $exists = $q->fetch();
  $q->close();
  if (!$exists) { echo json_encode(['success'=>false,'message'=>'No request found.']); $conn->close(); exit; }
  if ($status === 'accepted') { echo json_encode(['success'=>true,'message'=>'Already contacts.']); $conn->close(); exit; }
  if ((int)$requested_by === $me) { echo json_encode(['success'=>false,'message'=>'You sent this request. Wait for confirmation.']); $conn->close(); exit; }

  $u = $conn->prepare("UPDATE contacts SET status='accepted' WHERE a_id=? AND b_id=? LIMIT 1");
  if (!$u) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare update).','hint'=>$conn->error]); $conn->close(); exit; }
  $u->bind_param('ii', $a, $b);
  if (!$u->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute update).','hint'=>$u->error]); $u->close(); $conn->close(); exit; }
  $u->close();
  $conn->close();
  echo json_encode(['success'=>true,'message'=>'Contact confirmed.']);
  exit;
}

if ($action === 'add_id' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $targetId = (int)($_POST['user_id'] ?? 0);
  if ($targetId <= 0) { echo json_encode(['success'=>false,'message'=>'user_id required.']); $conn->close(); exit; }
  if ($targetId === $me) { echo json_encode(['success'=>false,'message'=>'You cannot add yourself.']); $conn->close(); exit; }
  $chk = $conn->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
  if (!$chk) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare user by id).','hint'=>$conn->error]); $conn->close(); exit; }
  $chk->bind_param('i', $targetId);
  $chk->execute();
  $chk->bind_result($uid);
  $found = $chk->fetch();
  $chk->close();
  if (!$found) { echo json_encode(['success'=>false,'message'=>'User not found.']); $conn->close(); exit; }

  [$a,$b] = pair_ab($me, $targetId);
  $q = $conn->prepare('SELECT status, requested_by FROM contacts WHERE a_id=? AND b_id=? LIMIT 1');
  if (!$q) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare check).','hint'=>$conn->error]); $conn->close(); exit; }
  $q->bind_param('ii', $a, $b);
  $q->execute();
  $q->bind_result($status, $requested_by);
  $exists = $q->fetch();
  $q->close();
  if ($exists) {
    if ($status === 'accepted') { echo json_encode(['success'=>false,'message'=>'Already in your contacts.']); $conn->close(); exit; }
    if ((int)$requested_by === $me) { echo json_encode(['success'=>true,'message'=>'Request already sent.']); $conn->close(); exit; }
    echo json_encode(['success'=>false,'message'=>'This user already sent you a request. Check Requests.']); $conn->close(); exit; 
  }

  $ins = $conn->prepare("INSERT INTO contacts (a_id,b_id,requested_by,status) VALUES (?,?,?,'pending')");
  if (!$ins) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare insert).','hint'=>$conn->error]); $conn->close(); exit; }
  $ins->bind_param('iii', $a, $b, $me);
  if (!$ins->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute insert).','hint'=>$ins->error]); $ins->close(); $conn->close(); exit; }
  $ins->close();
  $conn->close();
  echo json_encode(['success'=>true,'message'=>'Contact request sent.']);
  exit;
}

http_response_code(400);
echo json_encode(['success'=>false,'message'=>'Invalid action.']);
