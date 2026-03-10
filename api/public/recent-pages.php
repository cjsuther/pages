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

    $stmt = $db->prepare('
        SELECT p.*, u.name as owner_name, u.email as owner_email,
               (SELECT COUNT(*) FROM page_followers pf WHERE pf.page_id = p.id) as follower_count
        FROM pages p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC
        LIMIT 12
    ');
    $stmt->execute();
    $pages = $stmt->fetchAll();

    echo json_encode(['pages' => $pages]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
