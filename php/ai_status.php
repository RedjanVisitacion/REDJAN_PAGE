<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
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
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

require __DIR__ . '/db.php';
@require_once __DIR__ . '/ai_config.php';
@include_once __DIR__ . '/config.local.php';

$source = 'none'; $key = '';
// env
$k = getenv('GEMINI_API_KEY');
if (!is_string($k) || $k === '') {
  $tmp = isset($_ENV['GEMINI_API_KEY']) ? (string)$_ENV['GEMINI_API_KEY'] : '';
  if ($tmp !== '') { $k = $tmp; }
  else { $tmp2 = isset($_SERVER['GEMINI_API_KEY']) ? (string)$_SERVER['GEMINI_API_KEY'] : ''; if ($tmp2 !== '') { $k = $tmp2; } }
}
if (is_string($k)) { $k = trim($k); if ($k !== '') { $key = $k; $source = 'env'; } }

// constant
if ($key === '' && defined('GEMINI_API_KEY')) { $v = trim((string)constant('GEMINI_API_KEY')); if ($v !== '') { $key = $v; $source = 'constant'; } }

// file
if ($key === '') {
  $paths = [__DIR__.'/.gemini.key', __DIR__.'/../.gemini.key'];
  foreach ($paths as $p) { if (is_file($p)) { $v = trim((string)@file_get_contents($p)); if ($v !== '') { $key=$v; $source='file'; break; } } }
}

// db
if ($key === '') {
  @$conn->query("CREATE TABLE IF NOT EXISTS site_settings (skey VARCHAR(64) NOT NULL PRIMARY KEY, svalue TEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  $st = $conn->prepare("SELECT svalue FROM site_settings WHERE skey='gemini_api_key' LIMIT 1");
  if ($st) { $st->execute(); $st->bind_result($v); $found = $st->fetch(); $st->close(); if ($found && is_string($v)) { $v = trim($v); if ($v !== '') { $key = $v; $source='db'; } } }
}

$has = ($key !== '');
$mask = $has ? (substr($key,0,4) . '***' . substr($key,-4)) : null;

// Groq key (optional)
$groq_key = function_exists('ai_get_groq_api_key') ? ai_get_groq_api_key($conn) : '';
$groq_has = ($groq_key !== '');
$groq_mask = $groq_has ? (substr($groq_key,0,4) . '***' . substr($groq_key,-4)) : null;

// Selected provider
$provider = function_exists('ai_select_provider') ? ai_select_provider($conn) : 'gemini';

$probe = null; $probe_groq = null;
if ($has) {
  $verList = ['v1beta','v1'];
  $ok = false; $code = 0; $err = null; $models = 0; $msg = null;
  foreach ($verList as $ver) {
    $u = 'https://generativelanguage.googleapis.com/' . $ver . '/models?key=' . rawurlencode($key);
    if (function_exists('curl_init')) {
      $ch = curl_init($u);
      curl_setopt($ch, CURLOPT_HTTPGET, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
      curl_setopt($ch, CURLOPT_TIMEOUT, 6);
      curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
      curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
      $body = curl_exec($ch);
      $err = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
    } else {
      $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>"Accept: application/json\r\n",'timeout'=>6]]);
      $body = @file_get_contents($u, false, $ctx);
      $code = $body !== false ? 200 : 0; $err = $body === false ? 'stream_failed' : null;
    }
    if ($body && $code >= 200 && $code < 300) {
      $j = json_decode($body, true);
      if (isset($j['models']) && is_array($j['models'])) { $models = count($j['models']); }
      $ok = true; break;
    } else if (is_string($body) && $body !== '') {
      $jj = json_decode($body, true);
      if (isset($jj['error']['message'])) { $msg = (string)$jj['error']['message']; }
    }
  }
  $probe = ['ok'=>$ok,'code'=>$code,'err'=>$err,'msg'=>$msg,'models'=>$models];
}

// Probe Groq models if key present
if ($groq_has) {
  $ok = false; $code = 0; $err = null; $models = 0; $msg = null;
  $u = 'https://api.groq.com/openai/v1/models';
  if (function_exists('curl_init')) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json','Authorization: Bearer ' . $groq_key]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>"Accept: application/json\r\nAuthorization: Bearer " . $groq_key . "\r\n",'timeout'=>6]]);
    $body = @file_get_contents($u, false, $ctx);
    $code = $body !== false ? 200 : 0; $err = $body === false ? 'stream_failed' : null;
  }
  if ($body && $code >= 200 && $code < 300) {
    $j = json_decode($body, true);
    if (isset($j['data']) && is_array($j['data'])) { $models = count($j['data']); }
    $ok = true;
  } else if (is_string($body) && $body !== '') {
    $jj = json_decode($body, true);
    if (isset($jj['error']['message'])) { $msg = (string)$jj['error']['message']; }
  }
  $probe_groq = ['ok'=>$ok,'code'=>$code,'err'=>$err,'msg'=>$msg,'models'=>$models];
}

echo json_encode([
  'success'=>true,
  'provider'=>$provider,
  'gemini'=>['has_key'=>$has, 'source'=>$source, 'mask'=>$mask, 'probe'=>$probe],
  'groq'=>['has_key'=>$groq_has, 'mask'=>$groq_mask, 'probe'=>$probe_groq]
]);
