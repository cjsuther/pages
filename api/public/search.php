<?php

require_once '../config.php';
require_once '../Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();

    $results = [];
    $searchTerm = '%' . $query . '%';

    // Search pages
    $stmt = $db->prepare('
        SELECT p.id, p.title, p.description, p.url_slug as slug, p.profile_image,
               (SELECT COUNT(*) FROM page_followers pf WHERE pf.page_id = p.id) as follower_count
        FROM pages p
        WHERE p.title LIKE ? OR p.description LIKE ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ');
    $stmt->execute([$searchTerm, $searchTerm]);
    $pages = $stmt->fetchAll();

    foreach ($pages as $page) {
        $page['type'] = 'page';
        $results[] = $page;
    }

    // Search events
    $stmt = $db->prepare("
        SELECT l.id, l.text as title, l.description, l.image_url, l.event_date as item_date,
               l.event_time, l.event_address, p.url_slug as slug
        FROM links l
        JOIN link_groups lg ON l.group_id = lg.id
        JOIN pages p ON lg.page_id = p.id
        WHERE lg.type = 'eventos'
          AND l.event_date >= CURDATE()
          AND (l.text LIKE ? OR l.description LIKE ? OR l.event_address LIKE ?)
        ORDER BY l.event_date ASC
        LIMIT 10
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $events = $stmt->fetchAll();

    foreach ($events as $event) {
        $event['type'] = 'event';
        $results[] = $event;
    }

    echo json_encode(['results' => $results]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
