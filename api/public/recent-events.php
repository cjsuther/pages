<?php

require_once '../config.php';
require_once '../Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare("
        SELECT l.*, lg.page_id, p.url_slug as page_slug, p.title as page_title,
               p.profile_image as page_image, u.name as owner_name, u.email as owner_email
        FROM links l
        JOIN link_groups lg ON l.group_id = lg.id
        JOIN pages p ON lg.page_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE lg.type = 'eventos'
          AND l.event_date IS NOT NULL
          AND l.event_date >= CURDATE()
        ORDER BY l.event_date ASC, l.event_time ASC
        LIMIT 30
    ");
    $stmt->execute();
    $events = $stmt->fetchAll();

    echo json_encode(['events' => $events]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
