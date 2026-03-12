<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$pageId = isset($_GET['page_id']) ? (int) $_GET['page_id'] : null;

if (!$pageId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de página requerido']);
    exit();
}

$database = new Database();
$db = $database->connect();

$stmt = $db->prepare('SELECT id FROM pages WHERE id = ?');
$stmt->execute([$pageId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Página no encontrada']);
    exit();
}

$stmt = $db->prepare('
    SELECT
        u.email,
        (SELECT title FROM pages WHERE user_id = u.id ORDER BY id ASC LIMIT 1) as page_title,
        (SELECT url_slug FROM pages WHERE user_id = u.id ORDER BY id ASC LIMIT 1) as page_slug,
        pf.created_at as followed_at
    FROM page_followers pf
    JOIN users u ON pf.user_id = u.id
    WHERE pf.page_id = ?
    ORDER BY pf.created_at DESC
');
$stmt->execute([$pageId]);
$followers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['followers' => $followers]);
