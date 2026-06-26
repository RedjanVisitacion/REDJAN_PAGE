<?php
@include_once __DIR__ . '/config.local.php';
if (!function_exists('ai_get_gemini_api_key')) {
  function ai_get_gemini_api_key($conn = null) {
    $k = getenv('GEMINI_API_KEY');
    if (!is_string($k) || $k === '') {
      $tmp = isset($_ENV['GEMINI_API_KEY']) ? (string)$_ENV['GEMINI_API_KEY'] : '';
      if ($tmp !== '') { $k = $tmp; }
      else {
        $tmp2 = isset($_SERVER['GEMINI_API_KEY']) ? (string)$_SERVER['GEMINI_API_KEY'] : '';
        if ($tmp2 !== '') { $k = $tmp2; }
      }
    }
    if (is_string($k)) { $k = trim($k); if ($k !== '') return $k; }
    if (!defined('GEMINI_API_KEY')) { @include_once __DIR__ . '/config.local.php'; }
    if (defined('GEMINI_API_KEY')) { $k = trim((string)constant('GEMINI_API_KEY')); if ($k !== '') return $k; }
    $paths = [__DIR__.'/.gemini.key', __DIR__.'/../.gemini.key'];
    foreach ($paths as $p) {
      if (is_file($p)) { $k = trim((string)@file_get_contents($p)); if ($k !== '') return $k; }
    }
    if ($conn instanceof mysqli) {
      @$conn->query("CREATE TABLE IF NOT EXISTS site_settings (
        skey VARCHAR(64) NOT NULL PRIMARY KEY,
        svalue TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
      $stmt = $conn->prepare("SELECT svalue FROM site_settings WHERE skey = 'gemini_api_key' LIMIT 1");
      if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($val);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found && is_string($val)) { $val = trim($val); if ($val !== '') return $val; }
      }
    }
    return '';
  }
}

// Alt provider: Groq
if (!function_exists('ai_get_groq_api_key')) {
  function ai_get_groq_api_key($conn = null) {
    $k = getenv('GROQ_API_KEY');
    if (!is_string($k) || $k === '') {
      $tmp = isset($_ENV['GROQ_API_KEY']) ? (string)$_ENV['GROQ_API_KEY'] : '';
      if ($tmp !== '') { $k = $tmp; }
      else {
        $tmp2 = isset($_SERVER['GROQ_API_KEY']) ? (string)$_SERVER['GROQ_API_KEY'] : '';
        if ($tmp2 !== '') { $k = $tmp2; }
      }
    }
    if (is_string($k)) { $k = trim($k); if ($k !== '') return $k; }
    if (!defined('GROQ_API_KEY')) { @include_once __DIR__ . '/config.local.php'; }
    if (defined('GROQ_API_KEY')) { $k = trim((string)constant('GROQ_API_KEY')); if ($k !== '') return $k; }
    $paths = [__DIR__.'/.groq.key', __DIR__.'/../.groq.key'];
    foreach ($paths as $p) {
      if (is_file($p)) { $k = trim((string)@file_get_contents($p)); if ($k !== '') return $k; }
    }
    if ($conn instanceof mysqli) {
      @$conn->query("CREATE TABLE IF NOT EXISTS site_settings (
        skey VARCHAR(64) NOT NULL PRIMARY KEY,
        svalue TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
      $stmt = $conn->prepare("SELECT svalue FROM site_settings WHERE skey = 'groq_api_key' LIMIT 1");
      if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($val);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found && is_string($val)) { $val = trim($val); if ($val !== '') return $val; }
      }
    }
    return '';
  }
}

if (!function_exists('ai_select_provider')) {
  function ai_select_provider($conn = null) {
    $p = '';
    if ($conn instanceof mysqli) {
      @$conn->query("CREATE TABLE IF NOT EXISTS site_settings (skey VARCHAR(64) NOT NULL PRIMARY KEY, svalue TEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
      if ($st = $conn->prepare("SELECT svalue FROM site_settings WHERE skey='ai_provider' LIMIT 1")) {
        $st->execute(); $st->bind_result($pv); if ($st->fetch() && is_string($pv)) { $p = strtolower(trim($pv)); } $st->close();
      }
    }
    if ($p === '' && defined('AI_PROVIDER')) { $p = strtolower(trim((string)constant('AI_PROVIDER'))); }
    if ($p === 'groq' || $p === 'gemini') return $p;
    if ($p === '' || $p === 'auto') {
      // auto: prefer Groq if key present
      $gk = ai_get_groq_api_key($conn);
      if ($gk !== '') return 'groq';
      return 'gemini';
    }
    return 'gemini';
  }
}

if (!function_exists('ai_generate_with_groq')) {
  function ai_generate_with_groq($apiKey, $contents, $timeoutSeconds = 10) {
    if (!$apiKey) return [false, 'no_key', null];
    $model = 'llama-3.1-8b-instant';
    if (defined('GROQ_MODEL')) {
      $m = trim((string)constant('GROQ_MODEL'));
      if ($m !== '') $model = $m;
    }
    $msgs = [];
    foreach ($contents as $c) {
      $role = isset($c['role']) ? (string)$c['role'] : 'user';
      $role = ($role === 'model') ? 'assistant' : (($role === 'user' || $role === 'assistant' || $role === 'system') ? $role : 'user');
      $text = '';
      if (!empty($c['parts']) && is_array($c['parts'])) {
        foreach ($c['parts'] as $p) { if (isset($p['text'])) { $text .= (string)$p['text']; } }
      }
      $text = trim($text);
      if ($text !== '') { $msgs[] = ['role' => $role, 'content' => $text]; }
    }
    if (!$msgs) { return [false, 'empty_input', null]; }
    $payload = json_encode([
      'model' => $model,
      'messages' => $msgs,
      'temperature' => 0.6,
      'top_p' => 0.95,
      'max_tokens' => 256,
      'stream' => false
    ]);
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $err = null; $code = 0; $body = null;
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Expect:']);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
      curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
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
          'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\nAccept: application/json\r\n",
          'content' => $payload,
          'timeout' => $timeoutSeconds
        ]
      ]);
      $body = @file_get_contents($url, false, $ctx);
      $code = $body !== false ? 200 : 0; $err = $body === false ? 'stream_failed' : null;
    }
    if ($body === false || $body === null || $code < 200 || $code >= 300) {
      $msg = $err ?: ('http_' . $code);
      if (is_string($body) && $body !== '') { $jj = json_decode($body, true); if (isset($jj['error']['message'])) { $msg .= ': ' . substr((string)$jj['error']['message'], 0, 160); } }
      return [false, $msg, null];
    }
    $j = json_decode($body, true);
    if (!is_array($j)) return [false, 'bad_json', null];
    $txt = '';
    if (!empty($j['choices'][0]['message']['content'])) { $txt = (string)$j['choices'][0]['message']['content']; }
    else if (!empty($j['choices'][0]['text'])) { $txt = (string)$j['choices'][0]['text']; }
    else if (!empty($j['choices'][0]['messages'][0]['content'])) { $txt = (string)$j['choices'][0]['messages'][0]['content']; }
    $txt = trim($txt);
    if ($txt === '') return [false, 'empty', null];
    return [true, null, $txt];
  }
}

if (!function_exists('ai_generate_with_gemini')) {
  function ai_generate_with_gemini($apiKey, $contents, $timeoutSeconds = 10) {
    // Provider switch: route to Groq if configured; if no Groq key, fallback to Gemini
    $maybeConn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    $provider = ai_select_provider($maybeConn);
    if ($provider === 'groq') {
      $groqKey = ai_get_groq_api_key($maybeConn);
      if ($groqKey) {
        return ai_generate_with_groq($groqKey, $contents, $timeoutSeconds);
      }
      // fall through to Gemini if Groq key absent
    }
    if (!$apiKey) return [false, 'no_key', null];
    $modelPaths = [
      'gemini-1.5-flash-latest',
      'gemini-1.5-pro-latest',
      'gemini-1.5-flash-8b',
      'gemini-2.0-flash',
      'gemini-1.5-pro',
      'gemini-pro'
    ];
    if (defined('AI_GEMINI_MODEL')) {
      $m = trim((string)constant('AI_GEMINI_MODEL'));
      if ($m !== '') { $modelPaths = [$m]; }
    }
    $payload = json_encode([
      'contents' => $contents,
      'generationConfig' => ['temperature' => 0.6, 'topP' => 0.95, 'maxOutputTokens' => 256],
      'safetySettings' => []
    ]);

    // Strict single-call path (use when you want to minimize API requests and avoid fallback bursts)
    $forceModel = (defined('AI_GEMINI_MODEL') && trim((string)constant('AI_GEMINI_MODEL')) !== '');
    $forceVer = (defined('AI_GEMINI_VERSION') && trim((string)constant('AI_GEMINI_VERSION')) !== '');
    $strict = (defined('AI_SINGLE_CALL') && (bool)constant('AI_SINGLE_CALL'));
    if ($strict || ($forceModel && $forceVer)) {
      $ver = $forceVer ? trim((string)constant('AI_GEMINI_VERSION')) : 'v1beta';
      $mp  = $forceModel ? trim((string)constant('AI_GEMINI_MODEL'))  : 'gemini-2.0-flash';
      $url = 'https://generativelanguage.googleapis.com/' . $ver . '/models/' . $mp . ':generateContent';
      $err = null; $code = 0; $body = null;
      if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $apiKey, 'Accept: application/json', 'Expect:']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (($body === false || $code === 0) && ($err && stripos($err, 'SSL') !== false)) {
          $ch2 = curl_init($url);
          curl_setopt($ch2, CURLOPT_POST, true);
          curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $apiKey, 'Accept: application/json', 'Expect:']);
          curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload);
          curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 3);
          curl_setopt($ch2, CURLOPT_TIMEOUT, $timeoutSeconds);
          curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
          curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
          curl_setopt($ch2, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
          curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
          curl_setopt($ch2, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
          curl_setopt($ch2, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
          curl_setopt($ch2, CURLOPT_ENCODING, '');
          $body = curl_exec($ch2);
          $err = curl_error($ch2);
          $code = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
          curl_close($ch2);
        }
      } else {
        $ctx = stream_context_create([
          'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Goog-Api-Key: " . $apiKey . "\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => $timeoutSeconds
          ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = $body !== false ? 200 : 0; $err = $body === false ? 'stream_failed' : null;
      }

      if ($body === false || $body === null || $code < 200 || $code >= 300) {
        $msg = $err ?: ('http_' . $code);
        if (is_string($body) && $body !== '') { $jj = json_decode($body, true); if (isset($jj['error']['message'])) { $msg .= ': ' . substr((string)$jj['error']['message'], 0, 160); } }
        return [false, $msg, null];
      }
      $j = json_decode($body, true);
      if (!is_array($j)) return [false, 'bad_json', null];
      $txt = '';
      if (!empty($j['candidates'][0]['content']['parts'])) {
        foreach ($j['candidates'][0]['content']['parts'] as $p) { if (isset($p['text'])) { $txt .= (string)$p['text']; } }
      }
      $txt = trim((string)$txt);
      if ($txt === '') return [false, 'empty', null];
      return [true, null, $txt];
    }

    $lastErr = null; $lastCode = 0; $resp = null;
    // Choose API versions to attempt (allow override via constant)
    $apiVersions = ['v1', 'v1beta'];
    if (defined('AI_GEMINI_VERSION')) {
      $v = trim((string)constant('AI_GEMINI_VERSION'));
      if ($v !== '') { $apiVersions = [$v]; }
    }
    // Try to discover available models to avoid 404s on unsupported aliases
    try {
      $avail = [];
      foreach ($apiVersions as $verList) {
        $listBase = 'https://generativelanguage.googleapis.com/' . $verList . '/models';
        $listUrls = [
          $listBase,
          $listBase . '?key=' . rawurlencode($apiKey)
        ];
        foreach ($listUrls as $lu) {
          $bodyL = null; $codeL = 0; $errL = null;
          if (function_exists('curl_init')) {
            $chL = curl_init($lu);
            curl_setopt($chL, CURLOPT_HTTPGET, true);
            curl_setopt($chL, CURLOPT_HTTPHEADER, ['Accept: application/json', 'X-Goog-Api-Key: ' . $apiKey]);
            curl_setopt($chL, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chL, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($chL, CURLOPT_TIMEOUT, 6);
            curl_setopt($chL, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($chL, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($chL, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
            curl_setopt($chL, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $bodyL = curl_exec($chL);
            $errL = curl_error($chL);
            $codeL = (int)curl_getinfo($chL, CURLINFO_HTTP_CODE);
            curl_close($chL);
          } else {
            $ctxL = stream_context_create([
              'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nX-Goog-Api-Key: " . $apiKey . "\r\n",
                'timeout' => 6
              ]
            ]);
            $bodyL = @file_get_contents($lu, false, $ctxL);
            $codeL = $bodyL !== false ? 200 : 0; $errL = $bodyL === false ? 'stream_failed' : null;
          }
          if ($bodyL && $codeL >= 200 && $codeL < 300) {
            $jL = json_decode($bodyL, true);
            if (isset($jL['models']) && is_array($jL['models'])) {
              foreach ($jL['models'] as $m) {
                if (!empty($m['name'])) {
                  $n = (string)$m['name'];
                  if (strpos($n, 'models/') === 0) { $n = substr($n, 7); }
                  $avail[$n] = true;
                }
              }
            }
          }
        }
      }
      if ($avail) {
        $pref = $modelPaths;
        $filtered = [];
        foreach ($pref as $p) { if (!empty($avail[$p])) { $filtered[] = $p; } }
        if ($filtered) { $modelPaths = $filtered; }
      }
    } catch (Throwable $e) { /* ignore listing errors */ }
    foreach ($apiVersions as $ver) {
      foreach ($modelPaths as $mp) {
        $baseUrl = 'https://generativelanguage.googleapis.com/' . $ver . '/models/' . $mp . ':generateContent';
        $attempts = [
          ['u' => $baseUrl . '?key=' . rawurlencode($apiKey), 'h' => ['Content-Type: application/json']],
          ['u' => $baseUrl . '?key=' . rawurlencode($apiKey), 'h' => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $apiKey]],
          ['u' => $baseUrl, 'h' => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $apiKey]]
        ];
        foreach ($attempts as $att) {
          $tryUrl = $att['u'];
          $tryHeaders = $att['h'];
          $err = null; $code = 0; $body = null;
          if (function_exists('curl_init')) {
            // First attempt with SSL verification ON
            $ch = curl_init($tryUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            // Add robust defaults (some hosts require explicit Accept/User-Agent and HTTP/1.1)
            $hdrs = $tryHeaders;
            if (!in_array('Accept: application/json', $hdrs, true)) { $hdrs[] = 'Accept: application/json'; }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            // Disable 100-continue handshake some proxies mishandle
            $hdrs[] = 'Expect:';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // If SSL failure or code 0, retry once with verification off (unsafe but needed on some free hosts)
            if (($body === false || $code === 0) && ($err && stripos($err, 'SSL') !== false)) {
              $ch2 = curl_init($tryUrl);
              curl_setopt($ch2, CURLOPT_POST, true);
              $hdrs2 = $tryHeaders;
              if (!in_array('Accept: application/json', $hdrs2, true)) { $hdrs2[] = 'Accept: application/json'; }
              curl_setopt($ch2, CURLOPT_HTTPHEADER, $hdrs2);
              curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload);
              curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
              curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 3);
              curl_setopt($ch2, CURLOPT_TIMEOUT, $timeoutSeconds);
              curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
              curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
              curl_setopt($ch2, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
              curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
              curl_setopt($ch2, CURLOPT_USERAGENT, 'RPSV-AI-Client/1.0');
              curl_setopt($ch2, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
              curl_setopt($ch2, CURLOPT_ENCODING, '');
              $hdrs2[] = 'Expect:';
              curl_setopt($ch2, CURLOPT_HTTPHEADER, $hdrs2);
              $body = curl_exec($ch2);
              $err = curl_error($ch2);
              $code = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
              curl_close($ch2);
            }
          } else {
            $ctx = stream_context_create([
              'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge($tryHeaders, ['Accept: application/json', 'User-Agent: RPSV-AI-Client/1.0'])) . "\r\n",
                'content' => $payload,
                'timeout' => $timeoutSeconds
              ]
            ]);
            $body = @file_get_contents($tryUrl, false, $ctx);
            $code = 200;
            if ($body === false) { $err = 'stream_failed'; $code = 0; }
          }

          if ($body !== false && $body !== null && $code >= 200 && $code < 300) {
            $resp = $body; $lastErr = null; $lastCode = $code; break 3;
          }
          $lastErr = $err ?: ('http_'.$code);
          if (is_string($body) && $body !== '') {
            $jj = json_decode($body, true);
            if (isset($jj['error']['message'])) {
              $lastErr .= ': ' . substr((string)$jj['error']['message'], 0, 160);
            }
          }
          $lastCode = $code;
        }
      }
    }

    if ($resp === false || $resp === null || $lastCode < 200 || $lastCode >= 300) return [false, $lastErr ?: ('http_'.$lastCode), null];
    $j = json_decode($resp, true);
    if (!is_array($j)) return [false, 'bad_json', null];
    $txt = '';
    if (!empty($j['candidates'][0]['content']['parts'])) {
      foreach ($j['candidates'][0]['content']['parts'] as $p) {
        if (isset($p['text'])) { $txt .= (string)$p['text']; }
      }
    }
    $txt = trim((string)$txt);
    if ($txt === '') return [false, 'empty', null];
    return [true, null, $txt];
  }
}
?>
