<?php
require_once __DIR__ . '/db.php';

try {
    $name = trim($_POST['name'] ?? $_GET['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? $_GET['email'] ?? ''));
    $password = $_POST['password'] ?? $_GET['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        json_response(['success' => false, 'message' => 'Missing required fields']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'Invalid email']);
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_response(['success' => false, 'message' => 'Email is already registered']);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$name, $email, $hash]);

    json_response(['success' => true, 'message' => 'Registration successful']);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'Server error']);
}
