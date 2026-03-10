<?php

require_once '../config.php';
require_once '../Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : null;

if (!$slug) {
    http_response_code(400);
    echo json_encode(['error' => 'Slug is required']);
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare('SELECT * FROM pages WHERE url_slug = ?');
    $stmt->execute([$slug]);
    $page = $stmt->fetch();

    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Page not found']);
        exit();
    }

    $stmt = $db->prepare('SELECT * FROM link_groups WHERE page_id = ? ORDER BY position, id');
    $stmt->execute([$page['id']]);
    $groups = $stmt->fetchAll();

    foreach ($groups as &$group) {
        $stmt = $db->prepare("SELECT *, (event_date IS NOT NULL AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? ORDER BY position, id");
        $stmt->execute([$group['id']]);
        $group['links'] = $stmt->fetchAll();
    }

    $page['groups'] = $groups;

    // Get follower count
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM page_followers WHERE page_id = ?');
    $stmt->execute([$page['id']]);
    $page['follower_count'] = (int) $stmt->fetch()['count'];

    echo json_encode(['page' => $page]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
