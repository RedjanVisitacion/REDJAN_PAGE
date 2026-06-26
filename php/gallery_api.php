<?php
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
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require __DIR__ . '/db.php';

// Create gallery table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS gallery (
  id INT AUTO_INCREMENT PRIMARY KEY,
  url VARCHAR(500) NOT NULL,
  public_id VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Ensure hidden flag column exists (best-effort migration)
@$conn->query("ALTER TABLE gallery ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0");
@$conn->query("ALTER TABLE gallery ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");

// Cloudinary credentials
define('CLOUDINARY_CLOUD', 'dhhzkqmso');
define('CLOUDINARY_KEY', '871914741883427');
define('CLOUDINARY_SECRET', 'ihwwUCjI92s8tBpm24Vqj2CIWJk');

function cloudinary_upload($filePath, $folder, $publicId = '') {
  if (!is_file($filePath)) { return [false, null, 'file']; }
  $cloud = CLOUDINARY_CLOUD; $key = CLOUDINARY_KEY; $secret = CLOUDINARY_SECRET;
  if (!$cloud || !$key || !$secret) { return [false, null, 'config']; }
  $url = 'https://api.cloudinary.com/v1_1/' . $cloud . '/image/upload';
  $timestamp = time();
  $params = ['folder' => $folder, 'timestamp' => $timestamp];
  if ($publicId !== '') { $params['public_id'] = $publicId; }
  ksort($params);
  $toSign = '';
  foreach ($params as $k => $v) { if ($toSign !== '') { $toSign .= '&'; } $toSign .= $k . '=' . $v; }
  $signature = sha1($toSign . $secret);
  $post = [
    'api_key' => $key,
    'timestamp' => $timestamp,
    'signature' => $signature,
    'folder' => $folder,
  ];
  if ($publicId !== '') { $post['public_id'] = $publicId; }
  if (function_exists('curl_file_create')) {
    $post['file'] = curl_file_create($filePath);
  } else {
    $post['file'] = '@' . $filePath;
  }
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $res = curl_exec($ch);
  if ($res === false) { $err = curl_error($ch); curl_close($ch); return [false, null, $err ?: 'curl']; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($res, true);
  if ($code >= 200 && $code < 300 && isset($data['secure_url'])) { return [true, $data, null]; }
  return [false, null, isset($data['error']['message']) ? $data['error']['message'] : 'upload'];
}

function cloudinary_destroy($publicId) {
  $cloud = CLOUDINARY_CLOUD; $key = CLOUDINARY_KEY; $secret = CLOUDINARY_SECRET;
  if (!$cloud || !$key || !$secret) { return [false, 'config']; }
  $url = 'https://api.cloudinary.com/v1_1/' . $cloud . '/image/destroy';
  $timestamp = time();
  $toSign = 'public_id=' . $publicId . '&timestamp=' . $timestamp;
  $signature = sha1($toSign . $secret);
  $post = [
    'api_key' => $key,
    'timestamp' => $timestamp,
    'signature' => $signature,
    'public_id' => $publicId
  ];
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $res = curl_exec($ch);
  if ($res === false) { $err = curl_error($ch); curl_close($ch); return [false, $err ?: 'curl']; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = json_decode($res, true);
  if ($code >= 200 && $code < 300 && isset($data['result']) && in_array($data['result'], ['ok','not_found'])) { return [true, null]; }
  return [false, isset($data['error']['message']) ? $data['error']['message'] : 'destroy'];
}

$action = $_GET['action'] ?? 'list';
// Determine admin role early so list can filter appropriately
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

if ($action === 'list') {
  $out = [];
  $include_hidden = intval($_GET['include_hidden'] ?? 0) ? 1 : 0;
  $sql = "SELECT id, url, public_id, created_at, is_hidden FROM gallery";
  // By default, hide hidden items for everyone. Admins can opt-in to see all via include_hidden=1
  if (!($is_admin && $include_hidden)) { $sql .= " WHERE is_hidden = 0"; }
  $sql .= " ORDER BY created_at DESC";
  $rs = $conn->query($sql);
  if ($rs) {
    while ($row = $rs->fetch_assoc()) { $out[] = $row; }
    $rs->close();
  }
  echo json_encode(['success' => true, 'images' => $out]);
  exit;
}

// Auth for admin actions
// $is_admin is already computed above
if (!$is_admin) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

if ($action === 'upload') {
  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    echo json_encode(['success' => false, 'message' => 'No file']);
    exit;
  }
  $tmp = $_FILES['file']['tmp_name'];
  $publicId = 'gallery_' . bin2hex(random_bytes(6));
  [$ok, $data, $err] = cloudinary_upload($tmp, 'rpsv_gallery', $publicId);
  if (!$ok) {
    echo json_encode(['success' => false, 'message' => $err ?: 'upload failed']);
    exit;
  }
  $secure_url = is_array($data) && isset($data['secure_url']) ? $data['secure_url'] : (is_string($data) ? $data : '');
  $pub = is_array($data) && isset($data['public_id']) ? $data['public_id'] : $publicId;
  $stmt = $conn->prepare('INSERT INTO gallery (url, public_id) VALUES (?, ?)');
  if ($stmt) {
    $stmt->bind_param('ss', $secure_url, $pub);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['success' => true, 'id' => $id, 'url' => $secure_url, 'public_id' => $pub]);
  } else {
    echo json_encode(['success' => false, 'message' => 'db']);
  }
  exit;
}

if ($action === 'set_hidden') {
  $id = intval($_POST['id'] ?? 0);
  $hidden = intval($_POST['hidden'] ?? 0) ? 1 : 0;
  if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'id']); exit; }
  $stmt = $conn->prepare('UPDATE gallery SET is_hidden = ? WHERE id = ?');
  if ($stmt) {
    $stmt->bind_param('ii', $hidden, $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
  } else {
    echo json_encode(['success' => false, 'message' => 'db']);
  }
  exit;
}

if ($action === 'delete') {
  $id = intval($_POST['id'] ?? 0);
  if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'id']); exit; }
  $row = null;
  $stmt = $conn->prepare('SELECT public_id FROM gallery WHERE id = ?');
  if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close(); }
  if (!$row) { echo json_encode(['success' => false, 'message' => 'not_found']); exit; }
  [$ok, $err] = cloudinary_destroy($row['public_id']);
  if (!$ok) { echo json_encode(['success' => false, 'message' => $err ?: 'destroy']); exit; }
  $conn->query('DELETE FROM gallery WHERE id = ' . $id);
  echo json_encode(['success' => true]);
  exit;
}

echo json_encode(['success' => false, 'message' => 'bad_action']);
