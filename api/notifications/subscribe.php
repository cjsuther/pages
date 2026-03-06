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

// POST: Registrar suscripción push
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $endpoint = isset($data['endpoint']) ? $data['endpoint'] : null;
    $p256dhKey = isset($data['keys']['p256dh']) ? $data['keys']['p256dh'] : null;
    $authKey = isset($data['keys']['auth']) ? $data['keys']['auth'] : null;

    if (!$endpoint || !$p256dhKey || !$authKey) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos de suscripción incompletos']);
        exit();
    }

    // Insertar o actualizar suscripción
    $stmt = $db->prepare('
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        user_id = VALUES(user_id),
        p256dh_key = VALUES(p256dh_key),
        auth_key = VALUES(auth_key)
    ');
    $stmt->execute([$userId, $endpoint, $p256dhKey, $authKey]);

    echo json_encode([
        'success' => true,
        'message' => 'Suscripción registrada correctamente'
    ]);
    exit();
}

// DELETE: Eliminar suscripción push
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $endpoint = isset($data['endpoint']) ? $data['endpoint'] : null;

    if (!$endpoint) {
        http_response_code(400);
        echo json_encode(['error' => 'Endpoint requerido']);
        exit();
    }

    $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
    $stmt->execute([$userId, $endpoint]);

    echo json_encode([
        'success' => true,
        'message' => 'Suscripción eliminada'
    ]);
    exit();
}

// GET: Obtener clave pública VAPID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // La clave pública VAPID debe estar definida en config.php
    if (!defined('VAPID_PUBLIC_KEY')) {
        http_response_code(500);
        echo json_encode(['error' => 'Clave VAPID no configurada']);
        exit();
    }

    echo json_encode([
        'public_key' => VAPID_PUBLIC_KEY
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
