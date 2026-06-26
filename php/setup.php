<?php
header('Content-Type: application/json');
require __DIR__ . '/db.php';
$ok = true; $messages = [];
$sql = "CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'user',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if (!$conn->query($sql)) { $ok = false; $messages[] = 'Create table failed: ' . $conn->error; }
$admin_username = 'rpsv_codes';
$admin_email = 'admin@example.com';
$admin_role = 'admin';
$admin_password_hash = password_hash('RedjanPhil09', PASSWORD_DEFAULT);
$chk = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
if ($chk) {
  $chk->bind_param('s', $admin_username);
  $chk->execute();
  $chk->store_result();
  if ($chk->num_rows === 0) {
    $ins = $conn->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
    if ($ins) {
      $ins->bind_param('ssss', $admin_username, $admin_email, $admin_password_hash, $admin_role);
      if (!$ins->execute()) { $ok = false; $messages[] = 'Insert admin failed: ' . $conn->error; }
      $ins->close();
    } else {
      $ok = false; $messages[] = 'Prepare insert failed: ' . $conn->error;
    }
  }
  $chk->close();
} else {
  $ok = false; $messages[] = 'Prepare select failed: ' . $conn->error;
}
$conn->close();
echo json_encode(['success' => $ok, 'messages' => $messages]);
?>
