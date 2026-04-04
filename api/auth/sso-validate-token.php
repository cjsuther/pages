<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../JWT.php';

// Leer el token del header Authorization: Bearer <token>
$headers    = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'error' => 'missing_token']);
    exit;
}

$token = $matches[1];

// Validar el JWT contra JWT_SECRET del proyecto
$payload = JWT::decode($token, JWT_SECRET);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['valid' => false]);
    exit;
}

// Obtener datos del usuario desde la DB
$database = new Database();
$db       = $database->connect();

$stmt = $db->prepare('SELECT email, name FROM users WHERE id = ?');
$stmt->execute([$payload['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['valid' => false]);
    exit;
}

http_response_code(200);
echo json_encode([
    'valid' => true,
    'email' => $user['email'],
    'name'  => $user['name']
]);
