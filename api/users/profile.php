<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userId = $user['user_id'];
$database = new Database();
$db = $database->connect();

// GET: obtener datos del perfil
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT id, email, name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['user' => $profile]);
    exit();
}

// PUT: actualizar nombre
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);

    $name = isset($data['name']) ? trim($data['name']) : null;

    if ($name !== null && strlen($name) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'El nombre no puede superar los 255 caracteres']);
        exit();
    }

    $stmt = $db->prepare('UPDATE users SET name = ? WHERE id = ?');
    $stmt->execute([$name ?: null, $userId]);

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $userId,
            'email' => $user['email'],
            'name' => $name ?: null
        ]
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
