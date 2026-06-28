<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=900');

$username = 'RedjanVisitacion';
$cacheFile = sys_get_temp_dir() . '/rpsv_github_portfolio_' . strtolower($username) . '.json';
$cacheTtl = 900;

function json_out($data, $status = 200) {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function http_get_json($url) {
  $headers = [
    'Accept: application/vnd.github+json',
    'User-Agent: RPSV-Portfolio'
  ];
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body !== false && $code >= 200 && $code < 300) {
      return json_decode($body, true);
    }
    return null;
  }
  $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 8]]);
  $body = @file_get_contents($url, false, $ctx);
  return $body ? json_decode($body, true) : null;
}

function http_get_text($url) {
  $headers = "User-Agent: RPSV-Portfolio\r\nAccept: text/html\r\n";
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: RPSV-Portfolio', 'Accept: text/html']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? $body : '';
  }
  $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => $headers, 'timeout' => 8]]);
  $body = @file_get_contents($url, false, $ctx);
  return $body ?: '';
}

function contribution_count($username) {
  $html = http_get_text('https://github.com/' . rawurlencode($username));
  if ($html === '') return null;
  if (preg_match('/([0-9,]+)\s+contributions?\s+in\s+the\s+last\s+year/i', $html, $m)) {
    return intval(str_replace(',', '', $m[1]));
  }
  if (preg_match('/data-count="([0-9]+)"/i', $html, $m)) {
    return intval($m[1]);
  }
  return null;
}

if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
  $cached = @file_get_contents($cacheFile);
  if ($cached) {
    echo $cached;
    exit;
  }
}

$user = http_get_json('https://api.github.com/users/' . rawurlencode($username));
$repos = http_get_json('https://api.github.com/users/' . rawurlencode($username) . '/repos?per_page=100&sort=updated');

if (!is_array($user) || !is_array($repos)) {
  json_out(['success' => false, 'message' => 'GitHub data unavailable'], 502);
}

$payload = [
  'success' => true,
  'user' => $user,
  'repos' => $repos,
  'contributions' => contribution_count($username)
];

$json = json_encode($payload);
@file_put_contents($cacheFile, $json);
echo $json;
