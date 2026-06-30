<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$eventId = isset($_GET['id']) ? (int) $_GET['id'] : null;

if (!$eventId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de evento requerido']);
    exit();
}

$database = new Database();
$db = $database->connect();

$stmt = $db->prepare('
    SELECT
        l.id,
        l.text,
        l.description,
        l.image_url,
        l.url,
        l.url_text,
        l.event_date,
        l.event_time,
        l.event_address,
        l.event_latitude,
        l.event_longitude,
        l.event_maps_url,
        p.id as page_id,
        p.title as page_title,
        p.url_slug as page_slug,
        p.profile_image as page_image
    FROM links l
    JOIN link_groups lg ON l.group_id = lg.id
    JOIN pages p ON lg.page_id = p.id
    WHERE l.id = ? AND lg.type = \'eventos\'
');
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo json_encode(['error' => 'Evento no encontrado']);
    exit();
}

echo json_encode(['event' => $event]);
