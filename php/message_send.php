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
// Use same session name and cookie settings as pages
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }
require __DIR__ . '/db.php';
// Optional AI integration helpers
@require_once __DIR__ . '/ai_config.php';

// Ensure table exists with two-way columns
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
if ($rs = $conn->query("SHOW COLUMNS FROM messages LIKE 'sender_id'")) { $hasSender = ($rs->num_rows > 0); $rs->close(); }
if ($rs = $conn->query("SHOW COLUMNS FROM messages LIKE 'user_id'")) { $hasUser = ($rs->num_rows > 0); $rs->close(); }
if (!$hasSender) {
    // Add columns as NULLable to allow backfill
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
            // Fallback: set receiver to self to avoid NULLs; admin not found
            @$conn->query("UPDATE messages SET sender_id = user_id, receiver_id = user_id WHERE sender_id IS NULL OR receiver_id IS NULL");
        }
        // Drop legacy column if present
        @$conn->query("ALTER TABLE messages DROP COLUMN user_id");
    }
    // Enforce NOT NULL and indexes
    @$conn->query("ALTER TABLE messages MODIFY sender_id INT NOT NULL");
    @$conn->query("ALTER TABLE messages MODIFY receiver_id INT NOT NULL");
    @$conn->query("ALTER TABLE messages ADD INDEX(sender_id)");
    @$conn->query("ALTER TABLE messages ADD INDEX(receiver_id)");
}

$me = (int)($_SESSION['user_id'] ?? 0);
$myRole = $_SESSION['role'] ?? 'user';
$content = trim($_POST['content'] ?? '');
$to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
if ($content === '') { echo json_encode(['success' => false, 'message' => 'Message cannot be empty.']); $conn->close(); exit; }
if (mb_strlen($content) > 2000) { $content = mb_substr($content, 0, 2000); }

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

// If non-admin, default recipient is admin; otherwise require accepted contact
$aid = 0; $rsAid = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
if ($rsAid && $rowAid = $rsAid->fetch_assoc()) { $aid = (int)$rowAid['id']; }
if ($rsAid) { $rsAid->close(); }

if ($to === 0) {
    if ($myRole !== 'admin') {
        if ($aid === 0) { echo json_encode(['success' => false, 'message' => 'Admin account not found.']); $conn->close(); exit; }
        $to = $aid;
    } else {
        echo json_encode(['success' => false, 'message' => 'Recipient is required.']);
        $conn->close();
        exit;
    }
} else if ($myRole !== 'admin' && $to !== $aid) {
    // Only allow sending to accepted contacts
    $a = min($me, $to); $b = max($me, $to);
    $q = $conn->prepare("SELECT 1 FROM contacts WHERE a_id=? AND b_id=? AND status='accepted' LIMIT 1");
    if ($q) {
        $q->bind_param('ii', $a, $b);
        if ($q->execute()) {
            $res = $q->get_result();
            if (!$res || $res->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'You can only message accepted contacts.']); $q->close(); $conn->close(); exit; }
        } else {
            http_response_code(500);
            echo json_encode(['success'=>false,'message'=>'Database error (check contact).','hint'=>$q->error]);
            $q->close(); $conn->close(); exit;
        }
        $q->close();
    }
}

$stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error (prepare).', 'hint' => $conn->error]);
    $conn->close();
    exit;
}
$stmt->bind_param('iis', $me, $to, $content);
$ok = $stmt->execute();
$insertId = $ok ? (int)$stmt->insert_id : 0;
$stmt->close();

// Attempt AI auto-reply when a regular user messages the admin
$ai_reply_inserted = false; $ai_error = null;
if ($ok && $myRole !== 'admin' && $to === $aid && function_exists('ai_get_gemini_api_key') && function_exists('ai_generate_with_gemini')) {
    // Basic per-session throttle: 1 AI call every 10 seconds
    $now = time();
    $lastAi = isset($_SESSION['ai_last']) ? (int)$_SESSION['ai_last'] : 0;
    if ($now - $lastAi < 0) {
        $ai_error = 'rate_limited';
    } else {
        // Select provider and resolve keys
        $provider = function_exists('ai_select_provider') ? ai_select_provider($conn) : 'gemini';
        $geminiKey = ai_get_gemini_api_key($conn);
        $groqKey = function_exists('ai_get_groq_api_key') ? ai_get_groq_api_key($conn) : '';
        $hasKey = ($provider === 'groq') ? ($groqKey !== '') : ($geminiKey !== '');
        if ($hasKey) {
            // Global cooldown after 429s to avoid spamming a blocked quota
            @$conn->query("CREATE TABLE IF NOT EXISTS site_settings (skey VARCHAR(64) NOT NULL PRIMARY KEY, svalue TEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $blockKey = 'ai_block_until_' . ($provider === 'groq' ? 'groq' : 'gemini');
            $blockUntil = 0;
            if ($st0 = $conn->prepare("SELECT svalue FROM site_settings WHERE skey=? LIMIT 1")) {
                $st0->bind_param('s', $blockKey);
                $st0->execute(); $st0->bind_result($v0); if ($st0->fetch() && is_string($v0)) { $blockUntil = (int)$v0; } $st0->close();
            }
            if ($blockUntil > $now) {
                $ai_error = 'quota_cooldown';
            } else {
                // Build a short conversation context (last 10 messages including the one just sent)
                $hist = [];
                $h = $conn->prepare('SELECT sender_id, receiver_id, content FROM messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?) ORDER BY id ASC LIMIT 20');
                if ($h) {
                    $h->bind_param('iiii', $me, $aid, $aid, $me);
                    if ($h->execute()) {
                        $rs = $h->get_result();
                        while ($row = $rs->fetch_assoc()) { $hist[] = $row; }
                    }
                    $h->close();
                }
                // Map to AI chat contents
                $contents = [];
                // System instruction: respond to the last user message only; mention creator only if explicitly asked
                $contents[] = [
                    'role' => 'system',
                    'parts' => [[ 'text' => "You are the site admin AI assistant. Reply in a friendly, concise manner. Keep answers under 100 words and plain text only. Respond directly to the latest user message. Do not mention your creator/owner/builder unless the latest user message explicitly asks about it. If and only if asked, answer exactly: Created by Redjan Phil S. Visitacion (rpsv_codes)." ]]
                ];
                // Provide only the newest user message for direct reply
                $contents[] = [ 'role' => 'user', 'parts' => [[ 'text' => $content ]] ];
                // Generate reply
                list($okGen, $errGen, $replyText) = ai_generate_with_gemini($geminiKey, $contents, 10);
                if ($okGen && is_string($replyText) && trim($replyText) !== '') {
                    $replyText = trim($replyText);
                    if (mb_strlen($replyText) > 2000) { $replyText = mb_substr($replyText, 0, 2000); }
                    $ins = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)');
                    if ($ins) {
                        $ins->bind_param('iis', $aid, $me, $replyText);
                        $ai_reply_inserted = $ins->execute();
                        $ins->close();
                        $_SESSION['ai_last'] = time();
                    }
                } else {
                    // Normalize error codes for the UI
                    $errNorm = $errGen ?: 'gen_failed';
                    if (strpos((string)$errNorm, '429') !== false) {
                        $errNorm = 'quota';
                        // Set a 10-minute cooldown to avoid repeating 429s
                        $until = time() + 600;
                        if ($st1 = $conn->prepare("INSERT INTO site_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = CURRENT_TIMESTAMP")) {
                            $sv = (string)$until; $st1->bind_param('ss', $blockKey, $sv); $st1->execute(); $st1->close();
                        }
                    }
                    $ai_error = $errNorm;
                }
            }
        } else {
            $ai_error = 'no_api_key';
        }
    }
}

if ($ok) {
    echo json_encode([
        'success' => true,
        'message' => 'Message sent.',
        'id' => $insertId,
        'ai' => [ 'attempted' => ($myRole !== 'admin' && $to === $aid), 'inserted' => $ai_reply_inserted, 'error' => $ai_error ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send message.', 'hint' => $conn->error]);
}
$conn->close();
?>
