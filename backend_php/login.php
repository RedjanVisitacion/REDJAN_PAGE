<?php
require_once __DIR__ . '/db.php';

try {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        json_response(['success' => false, 'message' => 'Missing credentials']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'Invalid email']);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_response(['success' => false, 'message' => 'Invalid email or password']);
    }

    $token = bin2hex(random_bytes(16));

    json_response([
        'success' => true,
        'message' => 'Login successful',
        'token'   => $token,
        'user'    => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ],
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Server error']);
}
