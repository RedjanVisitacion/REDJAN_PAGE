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
if (($_SESSION['role'] ?? 'user') !== 'admin') { echo json_encode(['success' => false, 'message' => 'Forbidden.']); exit; }

require __DIR__ . '/db.php';
$me = (int)($_SESSION['user_id'] ?? 0);

// Ensure messages table exists (for fresh databases)
@$conn->query("CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (sender_id),
  INDEX (receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Check if users table exists
$hasUsers = false;
if ($rsChk = $conn->query("SHOW TABLES LIKE 'users'")) { $hasUsers = ($rsChk->num_rows > 0); $rsChk->close(); }

if ($hasUsers) {
    // Build list based on users table (non-admin) ordered by most recent message
    $sql = "SELECT u.id, u.username, COALESCE(MAX(m.id),0) AS last_id
            FROM users u
            LEFT JOIN messages m ON ((m.sender_id = u.id AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = u.id))
            WHERE u.id <> ? AND (u.role = 'user' OR u.role IS NULL)
            GROUP BY u.id, u.username
            ORDER BY last_id DESC, u.username ASC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error (prepare).', 'hint' => $conn->error]);
        $conn->close();
        exit;
    }
    $stmt->bind_param('iii', $me, $me, $me);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error (execute).', 'hint' => $stmt->error]);
        $stmt->close();
        $conn->close();
        exit;
    }
    $res = $stmt->get_result();
    $users = [];
    while ($row = $res->fetch_assoc()) {
        $uname = (string)($row['username'] ?? 'User');
        $users[] = [
            'id' => (int)$row['id'],
            'username' => $uname,
            'avatar' => strtoupper(substr($uname, 0, 1)),
        ];
    }
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

// Fallback: derive chat partners from messages table only
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
$stmt2 = $conn->prepare($sql2);
if (!$stmt2) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (prepare-fallback).', 'hint' => $conn->error]);
    $conn->close();
    exit;
}
$stmt2->bind_param('iiii', $me, $me, $me, $me);
if (!$stmt2->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (execute-fallback).', 'hint' => $stmt2->error]);
    $stmt2->close();
    $conn->close();
    exit;
}
$res2 = $stmt2->get_result();
$users = [];
while ($row = $res2->fetch_assoc()) {
    $id = (int)$row['id'];
    $users[] = [
        'id' => $id,
        'username' => 'User #'.$id,
        'avatar' => strtoupper(substr((string)$id, 0, 1)),
    ];
}
$stmt2->close();
$conn->close();
echo json_encode(['success' => true, 'users' => $users]);
