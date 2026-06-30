<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = JWT::getUserFromToken();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['page_id']) || !isset($data['title'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Page ID and title are required']);
        exit();
    }

    try {
        $stmt = $db->prepare('SELECT id FROM pages WHERE id = ? AND user_id = ?');
        $stmt->execute([$data['page_id'], $user['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
            exit();
        }

        $stmt = $db->prepare('INSERT INTO link_groups (page_id, title, type, position) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $data['page_id'],
            $data['title'],
            $data['type'] ?? 'links',
            $data['position'] ?? 0
        ]);

        $groupId = $db->lastInsertId();

        // Para grupos de tipo "redes", pre-cargar los links de redes sociales con su logo
        if (($data['type'] ?? 'links') === 'redes') {
            $redes = [
                ['Instagram', 'https://instagram.com/', '/social/instagram.svg'],
                ['TikTok',    'https://tiktok.com/',    '/social/tiktok.svg'],
                ['YouTube',   'https://youtube.com/',   '/social/youtube.svg'],
                ['Facebook',  'https://facebook.com/',  '/social/facebook.svg'],
                ['WhatsApp',  'https://wa.me/',         '/social/whatsapp.svg'],
                ['Cafecito',  'https://cafecito.app/',  '/social/cafecito.svg'],
            ];
            $linkStmt = $db->prepare('INSERT INTO links (group_id, url, text, image_url, position) VALUES (?, ?, ?, ?, ?)');
            foreach ($redes as $i => $r) {
                $linkStmt->execute([$groupId, $r[1], $r[0], $r[2], $i]);
            }
        }

        $stmt = $db->prepare('SELECT * FROM link_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        http_response_code(201);
        echo json_encode(['group' => $group]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
