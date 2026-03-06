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

// GET: Obtener todas las páginas que sigue el usuario
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('
        SELECT
            p.id,
            p.url_slug as slug,
            p.title,
            p.description,
            p.profile_image as image_url,
            pf.notify_all_events,
            pf.max_distance_km,
            pf.created_at as following_since,
            (SELECT COUNT(*) FROM page_followers WHERE page_id = p.id) as follower_count
        FROM page_followers pf
        INNER JOIN pages p ON pf.page_id = p.id
        WHERE pf.user_id = ?
        ORDER BY pf.created_at DESC
    ');
    $stmt->execute([$userId]);
    $following = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convertir tipos de datos
    foreach ($following as &$page) {
        $page['id'] = (int) $page['id'];
        $page['notify_all_events'] = (bool) $page['notify_all_events'];
        $page['max_distance_km'] = (float) $page['max_distance_km'];
    }

    echo json_encode([
        'following' => $following,
        'total' => count($following)
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
