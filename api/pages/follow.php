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

// POST: Seguir una página o actualizar preferencias
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $pageId = isset($data['page_id']) ? (int) $data['page_id'] : null;
    $notifyAllEvents = isset($data['notify_all_events']) ? (bool) $data['notify_all_events'] : true;
    $maxDistanceKm = isset($data['max_distance_km']) ? (float) $data['max_distance_km'] : 50.00;

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de página requerido']);
        exit();
    }

    // Verificar que la página existe
    $stmt = $db->prepare('SELECT id FROM pages WHERE id = ?');
    $stmt->execute([$pageId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Página no encontrada']);
        exit();
    }

    // Insertar o actualizar seguimiento
    $stmt = $db->prepare('
        INSERT INTO page_followers (user_id, page_id, notify_all_events, max_distance_km)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        notify_all_events = VALUES(notify_all_events),
        max_distance_km = VALUES(max_distance_km)
    ');
    $stmt->execute([$userId, $pageId, $notifyAllEvents ? 1 : 0, $maxDistanceKm]);

    echo json_encode([
        'success' => true,
        'message' => 'Preferencias de seguimiento actualizadas',
        'page_id' => $pageId,
        'notify_all_events' => $notifyAllEvents,
        'max_distance_km' => $maxDistanceKm
    ]);
    exit();
}

// DELETE: Dejar de seguir una página
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pageId = isset($_GET['page_id']) ? (int) $_GET['page_id'] : null;

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de página requerido']);
        exit();
    }

    $stmt = $db->prepare('DELETE FROM page_followers WHERE user_id = ? AND page_id = ?');
    $stmt->execute([$userId, $pageId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'No seguías esta página']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Dejaste de seguir la página'
    ]);
    exit();
}

// GET: Obtener configuración de seguimiento para una página específica
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pageId = isset($_GET['page_id']) ? (int) $_GET['page_id'] : null;

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de página requerido']);
        exit();
    }

    $stmt = $db->prepare('
        SELECT notify_all_events, max_distance_km, created_at
        FROM page_followers
        WHERE user_id = ? AND page_id = ?
    ');
    $stmt->execute([$userId, $pageId]);
    $follow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$follow) {
        echo json_encode([
            'is_following' => false
        ]);
        exit();
    }

    echo json_encode([
        'is_following' => true,
        'notify_all_events' => (bool) $follow['notify_all_events'],
        'max_distance_km' => (float) $follow['max_distance_km'],
        'following_since' => $follow['created_at']
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
