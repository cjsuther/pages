<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$headers = getallheaders();
$token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token no proporcionado']);
    exit();
}

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userId = $user['user_id'];
$database = new Database();
$db = $database->connect();

// GET: Obtener ubicación del usuario
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT location_latitude, location_longitude, location_name, last_location_update FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit();
    }

    echo json_encode([
        'latitude' => $user['location_latitude'] ? (float) $user['location_latitude'] : null,
        'longitude' => $user['location_longitude'] ? (float) $user['location_longitude'] : null,
        'location_name' => $user['location_name'],
        'last_update' => $user['last_location_update']
    ]);
    exit();
}

// PUT: Actualizar ubicación del usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $latitude = isset($data['latitude']) ? $data['latitude'] : null;
    $longitude = isset($data['longitude']) ? $data['longitude'] : null;
    $locationName = isset($data['address']) ? $data['address'] : null;

    if ($latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Latitud y longitud son requeridas']);
        exit();
    }

    // Validar rangos de coordenadas
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        http_response_code(400);
        echo json_encode(['error' => 'Coordenadas inválidas']);
        exit();
    }

    $stmt = $db->prepare('UPDATE users SET location_latitude = ?, location_longitude = ?, location_name = ?, last_location_update = NOW() WHERE id = ?');
    $stmt->execute([$latitude, $longitude, $locationName, $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Ubicación actualizada correctamente',
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude,
        'location_name' => $locationName
    ]);
    exit();
}
