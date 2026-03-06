<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = JWT::getUserFromToken();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    try {
        $stmt = $db->prepare('SELECT * FROM pages WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$user['user_id']]);
        $pages = $stmt->fetchAll();

        echo json_encode(['pages' => $pages]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = JWT::getUserFromToken();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['title']) || !isset($data['url_slug'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Title and URL slug are required']);
        exit();
    }

    $urlSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($data['url_slug']));
    if (empty($urlSlug)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL slug']);
        exit();
    }

    $reservedSlugs = ['login', 'register', 'dashboard', 'page', 'api', 'admin', 'auth', 'public', 'pages', 'groups', 'links', 'user', 'users', 'config', 'settings', 'logout', 'profile', 'account'];
    if (in_array($urlSlug, $reservedSlugs)) {
        http_response_code(400);
        echo json_encode(['error' => 'This URL is reserved and cannot be used']);
        exit();
    }

    try {
        $stmt = $db->prepare('SELECT id FROM pages WHERE url_slug = ?');
        $stmt->execute([$urlSlug]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'URL slug already exists']);
            exit();
        }

        $stmt = $db->prepare('INSERT INTO pages (user_id, title, description, url_slug, primary_color, secondary_color, background_color, text_color, profile_image, background_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $user['user_id'],
            $data['title'],
            $data['description'] ?? '',
            $urlSlug,
            $data['primary_color'] ?? '#3B82F6',
            $data['secondary_color'] ?? '#1E40AF',
            $data['background_color'] ?? '#FFFFFF',
            $data['text_color'] ?? '#000000',
            $data['profile_image'] ?? null,
            $data['background_image'] ?? null
        ]);

        $pageId = $db->lastInsertId();

        $stmt = $db->prepare('SELECT * FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();

        http_response_code(201);
        echo json_encode(['page' => $page]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
