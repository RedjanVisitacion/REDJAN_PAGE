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
// Use same session name and cookie settings as other pages
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$domain = $_SERVER['HTTP_HOST'] ?? '';
@session_name('RPSVSESSID');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'domain' => $domain,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
} else {
    session_set_cookie_params(86400 * 7, '/; samesite=Lax', $domain, $secure, true);
}
session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
require __DIR__ . '/db.php';

// Ensure two-way messages table exists
$conn->query("CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (sender_id),
  INDEX (receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Migrate from legacy schema if needed
$hasSender = false; $hasUser = false;
if ($rs0 = $conn->query("SHOW COLUMNS FROM messages LIKE 'sender_id'")) { $hasSender = ($rs0->num_rows > 0); $rs0->close(); }
if ($rs1 = $conn->query("SHOW COLUMNS FROM messages LIKE 'user_id'")) { $hasUser = ($rs1->num_rows > 0); $rs1->close(); }
if (!$hasSender) {
    @$conn->query("ALTER TABLE messages ADD COLUMN sender_id INT NULL AFTER id");
    @$conn->query("ALTER TABLE messages ADD COLUMN receiver_id INT NULL AFTER sender_id");
    // Determine admin id for backfill
    $adminId = 0; $rr = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
    if ($rr && $row = $rr->fetch_assoc()) { $adminId = (int)$row['id']; }
    if ($rr) { $rr->close(); }
    if ($hasUser) {
        if ($adminId > 0) {
            @$conn->query("UPDATE messages SET sender_id = user_id, receiver_id = $adminId WHERE sender_id IS NULL OR receiver_id IS NULL");
        } else {
            @$conn->query("UPDATE messages SET sender_id = user_id, receiver_id = user_id WHERE sender_id IS NULL OR receiver_id IS NULL");
        }
        @$conn->query("ALTER TABLE messages DROP COLUMN user_id");
    }
    @$conn->query("ALTER TABLE messages MODIFY sender_id INT NOT NULL");
    @$conn->query("ALTER TABLE messages MODIFY receiver_id INT NOT NULL");
    @$conn->query("ALTER TABLE messages ADD INDEX(sender_id)");
    @$conn->query("ALTER TABLE messages ADD INDEX(receiver_id)");
}

$me = (int)($_SESSION['user_id'] ?? 0);
$myRole = $_SESSION['role'] ?? 'user';
$with = isset($_GET['with']) ? (int)$_GET['with'] : 0;

// Ensure contacts table exists for user-to-user chats
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
// Fallback API: return chat users for admin when list_users=1
if (isset($_GET['list_users']) && $myRole === 'admin') {
    // Prefer users table if present
    $hasUsersTbl = false;
    if ($rsx = $conn->query("SHOW TABLES LIKE 'users'")) { $hasUsersTbl = ($rsx->num_rows > 0); $rsx->close(); }
    if ($hasUsersTbl) {
        $sql = "SELECT u.id, u.username, COALESCE(MAX(m.id),0) AS last_id
                FROM users u
                LEFT JOIN messages m ON ((m.sender_id = u.id AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = u.id))
                WHERE u.id <> ? AND (u.role = 'user' OR u.role IS NULL)
                GROUP BY u.id, u.username
                ORDER BY last_id DESC, u.username ASC
                LIMIT 200";
        $st = $conn->prepare($sql);
        if (!$st) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare).','hint'=>$conn->error]); $conn->close(); exit; }
        $st->bind_param('iii', $me, $me, $me);
        if (!$st->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute).','hint'=>$st->error]); $st->close(); $conn->close(); exit; }
        $rs = $st->get_result();
        $users = [];
        while ($row = $rs->fetch_assoc()) {
            $uname = (string)($row['username'] ?? 'User');
            $users[] = [
                'id' => (int)$row['id'],
                'username' => $uname,
                'avatar' => strtoupper(substr($uname, 0, 1)),
            ];
        }
        $st->close();
        $conn->close();
        echo json_encode(['success'=>true, 'users'=>$users]);
        exit;
    } else {
        // Fallback: infer partners from messages table only
        $sql2 = "SELECT t.other_id AS id, MAX(t.id) AS last_id
                 FROM (
                   SELECT id, CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id
                   FROM messages
                   WHERE sender_id = ? OR receiver_id = ?
                 ) t
                 WHERE t.other_id <> ?
                 GROUP BY t.other_id
                 ORDER BY last_id DESC
                 LIMIT 200";
        $st2 = $conn->prepare($sql2);
        if (!$st2) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (prepare-fallback).','hint'=>$conn->error]); $conn->close(); exit; }
        $st2->bind_param('iiii', $me, $me, $me, $me);
        if (!$st2->execute()) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error (execute-fallback).','hint'=>$st2->error]); $st2->close(); $conn->close(); exit; }
        $rs2 = $st2->get_result();
        $users = [];
        while ($row = $rs2->fetch_assoc()) {
            $id = (int)$row['id'];
            $users[] = [
                'id' => $id,
                'username' => 'User #'.$id,
                'avatar' => strtoupper(substr((string)$id, 0, 1)),
            ];
        }
        $st2->close();
        $conn->close();
        echo json_encode(['success'=>true, 'users'=>$users]);
        exit;
    }
}

// Determine partner
if ($myRole !== 'admin') {
    // For users: allow chatting with accepted contacts or admin (default)
    $rs = $conn->query("SELECT id, username FROM users WHERE role='admin' LIMIT 1");
    $adminId = 0; $adminName = 'Admin';
    if ($rs && $row = $rs->fetch_assoc()) { $adminId = (int)$row['id']; $adminName = $row['username']; }
    if ($rs) { $rs->close(); }
    if ($adminId === 0) { echo json_encode(['success' => false, 'message' => 'Admin account not found.']); $conn->close(); exit; }

    $useId = $adminId; $useName = $adminName;
    if ($with > 0) {
        if ($with === $adminId) {
            $useId = $adminId; $useName = $adminName;
        } else {
            $a = min($me, $with); $b = max($me, $with);
            $qry = $conn->prepare("SELECT status FROM contacts WHERE a_id=? AND b_id=? AND status='accepted' LIMIT 1");
            if ($qry) {
                $qry->bind_param('ii', $a, $b);
                if ($qry->execute()) {
                    $res2 = $qry->get_result();
                    if ($res2 && $res2->num_rows > 0) {
                        $useId = $with;
                        $rr = $conn->query("SELECT username FROM users WHERE id = ".$useId." LIMIT 1");
                        if ($rr && $rrow = $rr->fetch_assoc()) { $useName = $rrow['username']; }
                        if ($rr) { $rr->close(); }
                    }
                }
                $qry->close();
            }
        }
    }
    $with = $useId; $withName = $useName;
} else {
    // Admin: if no partner specified, pick most recent conversation partner
    if ($with === 0) {
        $rs = $conn->query("SELECT CASE WHEN sender_id = $me THEN receiver_id ELSE sender_id END AS other_id
                             FROM messages WHERE sender_id = $me OR receiver_id = $me
                             ORDER BY id DESC LIMIT 1");
        if ($rs && $row = $rs->fetch_assoc()) { $with = (int)$row['other_id']; }
        if ($rs) { $rs->close(); }
    }
    if ($with === 0) {
        // fallback: first regular user
        $rs = $conn->query("SELECT id, username FROM users WHERE role='user' ORDER BY id ASC LIMIT 1");
        if ($rs && $row = $rs->fetch_assoc()) { $with = (int)$row['id']; $withName = $row['username']; }
        if ($rs) { $rs->close(); }
    }
    if (!isset($withName)) {
        $rs = $conn->query("SELECT username FROM users WHERE id = $with LIMIT 1");
        if ($rs && $row = $rs->fetch_assoc()) { $withName = $row['username']; }
        if ($rs) { $rs->close(); }
    }
}

$limit = 200;
$stmt = $conn->prepare('SELECT id, sender_id, receiver_id, content, created_at
                        FROM messages
                        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                        ORDER BY id ASC
                        LIMIT ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (prepare).', 'hint' => $conn->error]);
    $conn->close();
    exit;
}
$stmt->bind_param('iiiii', $me, $with, $with, $me, $limit);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (execute).', 'hint' => $stmt->error]);
    $stmt->close();
    $conn->close();
    exit;
}
$res = $stmt->get_result();
$items = [];
while ($row = $res->fetch_assoc()) {
    $dt = null; $iso = null;
    try {
        $dt = new DateTime($row['created_at'], new DateTimeZone('Asia/Manila'));
        $iso = $dt->format(DateTime::ATOM);
    } catch (Throwable $e) {
        $iso = null;
    }
    $items[] = [
        'id' => (int)$row['id'],
        'sender_id' => (int)$row['sender_id'],
        'receiver_id' => (int)$row['receiver_id'],
        'content' => $row['content'],
        'created_at' => $row['created_at'],
        'created_at_iso' => $iso,
    ];
}
$stmt->close();
$conn->close();
echo json_encode(['success' => true, 'items' => $items, 'me_id' => $me, 'with_id' => $with, 'with_username' => $withName ?? null]);
?>
