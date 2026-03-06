<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare('SELECT id, email, password FROM users WHERE email = ?');
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($data['password'], $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }

    $token = JWT::encode(['user_id' => $user['id'], 'email' => $user['email']], JWT_SECRET);

    echo json_encode([
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
