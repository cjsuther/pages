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

$email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format']);
    exit();
}

if (strlen($data['password']) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 6 characters']);
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already exists']);
        exit();
    }

    $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

    $stmt = $db->prepare('INSERT INTO users (email, password) VALUES (?, ?)');
    $stmt->execute([$email, $hashedPassword]);

    $userId = $db->lastInsertId();

    $token = JWT::encode(['user_id' => $userId, 'email' => $email], JWT_SECRET);

    http_response_code(201);
    echo json_encode([
        'token' => $token,
        'user' => [
            'id' => $userId,
            'email' => $email
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
