<?php
header('Content-Type: application/json');
require_once __DIR__ . '/ai_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
  exit;
}

$ct = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
$input = [];
if (stripos($ct, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  if (is_array($j)) { $input = $j; }
} else {
  $input = $_POST;
}

$messages = [];
if (isset($input['messages']) && is_array($input['messages'])) {
  $messages = $input['messages'];
} else {
  $prompt = isset($input['prompt']) ? trim((string)$input['prompt']) : '';
  if ($prompt !== '') {
    $messages = [
      ['role' => 'system', 'content' => 'You are the site assistant. Respond directly to the LAST user message based on the chat context. Use 1-3 short sentences, plain text (no markdown). Do not mention your creator unless the user explicitly asks about your creator/owner/builder. If and only if they do, answer exactly: Created by Redjan Phil S. Visitacion (rpsv_codes).'],
      ['role' => 'user', 'content' => $prompt]
    ];
  }
}

if (!$messages) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'empty_input']);
  exit;
}

$model = isset($input['model']) && is_string($input['model']) && trim($input['model']) !== ''
  ? trim($input['model'])
  : (defined('GROQ_MODEL') ? (string)constant('GROQ_MODEL') : 'llama-3.1-8b-instant');
$temperature = isset($input['temperature']) ? (float)$input['temperature'] : 0.6;
$max_tokens = isset($input['max_tokens']) ? (int)$input['max_tokens'] : 256;

$key = function_exists('ai_get_groq_api_key') ? ai_get_groq_api_key(null) : '';
if (!$key) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'no_api_key']);
  exit;
}

$payload = json_encode([
  'model' => $model,
  'messages' => $messages,
  'temperature' => $temperature,
  'top_p' => 0.95,
  'max_tokens' => $max_tokens,
  'stream' => false
]);

$url = 'https://api.groq.com/openai/v1/chat/completions';
$body = null; $err = null; $code = 0;
if (function_exists('curl_init')) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $key,
    'Accept: application/json',
    'Expect:'
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_TIMEOUT, 12);
  curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
  curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  curl_setopt($ch, CURLOPT_ENCODING, '');
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
} else {
  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\nAccept: application/json\r\n",
      'content' => $payload,
      'timeout' => 12
    ]
  ]);
  $body = @file_get_contents($url, false, $ctx);
  $code = $body !== false ? 200 : 0; $err = $body === false ? 'stream_failed' : null;
}

if ($body === false || $body === null || $code < 200 || $code >= 300) {
  $msg = $err ?: ('http_' . $code);
  if (is_string($body) && $body !== '') {
    $jj = json_decode($body, true);
    if (isset($jj['error']['message'])) { $msg .= ': ' . substr((string)$jj['error']['message'], 0, 160); }
  }
  http_response_code($code ?: 500);
  echo json_encode(['success' => false, 'error' => $msg]);
  exit;
}

$j = json_decode($body, true);
if (!is_array($j)) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'bad_json']);
  exit;
}

$out = '';
if (!empty($j['choices'][0]['message']['content'])) { $out = (string)$j['choices'][0]['message']['content']; }
else if (!empty($j['choices'][0]['text'])) { $out = (string)$j['choices'][0]['text']; }
else if (!empty($j['choices'][0]['messages'][0]['content'])) { $out = (string)$j['choices'][0]['messages'][0]['content']; }
$out = trim($out);
if ($out === '') {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'empty']);
  exit;
}

echo json_encode(['success' => true, 'content' => $out, 'model' => $model]);
