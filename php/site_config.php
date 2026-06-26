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

// Settings table
$conn->query("CREATE TABLE IF NOT EXISTS site_settings (
  skey VARCHAR(64) NOT NULL PRIMARY KEY,
  svalue TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$action = $_GET['action'] ?? 'get';
$allowed_get_keys = ['home_bg','login_bg'];

function json_out($arr){ echo json_encode($arr); exit; }

if ($action === 'get') {
  $keysParam = $_GET['keys'] ?? '';
  $keys = [];
  if ($keysParam) {
    foreach (explode(',', $keysParam) as $k) { $k = trim($k); if ($k !== '' && preg_match('/^[a-z0-9_]+$/i', $k)) { $keys[] = $k; } }
  }
  if (!$keys) { $keys = $allowed_get_keys; }
  // Never allow gemini_api_key to be fetched via GET
  $keys = array_values(array_intersect($keys, $allowed_get_keys));
  // build placeholders
  $ph = implode(',', array_fill(0, count($keys), '?'));
  $vals = array_values($keys);
  $out = [];
  if ($ph) {
    $types = str_repeat('s', count($vals));
    $stmt = $conn->prepare("SELECT skey, svalue FROM site_settings WHERE skey IN ($ph)");
    if ($stmt) {
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) {
        while ($row = $res->fetch_assoc()) { $out[$row['skey']] = $row['svalue']; }
      }
      $stmt->close();
    }
  }
  // Ensure keys exist in output with null values
  foreach ($keys as $k) { if (!array_key_exists($k, $out)) { $out[$k] = null; } }
  json_out(['success' => true] + $out);
}

// Admin-only for set
$is_admin = (($_SESSION['role'] ?? '') === 'admin');
if (!$is_admin) { json_out(['success' => false, 'message' => 'Unauthorized']); }

if ($action === 'set') {
  $key = $_POST['key'] ?? '';
  $value = $_POST['value'] ?? '';
  $allowed_set_keys = array_merge($allowed_get_keys, ['gemini_api_key','groq_api_key','ai_provider']);
  if (!in_array($key, $allowed_set_keys, true)) { json_out(['success' => false, 'message' => 'Invalid key']); }
  // Validation: URLs for backgrounds; free-form for gemini_api_key
  $val = trim($value);
  if (in_array($key, ['home_bg','login_bg'], true)) {
    if ($val !== '' && !preg_match('/^https?:\/\//i', $val)) { json_out(['success' => false, 'message' => 'Value must be a URL']); }
  }
  $stmt = $conn->prepare('INSERT INTO site_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = CURRENT_TIMESTAMP');
  if ($stmt) {
    $stmt->bind_param('ss', $key, $val);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) { json_out(['success' => true]); }
  }
  json_out(['success' => false, 'message' => 'db']);
}

json_out(['success' => false, 'message' => 'bad_action']);
