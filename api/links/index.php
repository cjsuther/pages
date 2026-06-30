<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';
require_once '../PageAccess.php';

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

    if (!isset($data['group_id']) || !isset($data['url']) || !isset($data['text'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Group ID, URL, and text are required']);
        exit();
    }

    try {
        if (!PageAccess::canManageGroup($db, $data['group_id'], $user['user_id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Group not found']);
            exit();
        }

        $stmt = $db->prepare('SELECT id, type FROM link_groups WHERE id = ?');
        $stmt->execute([$data['group_id']]);
        $group = $stmt->fetch();

        if ($group['type'] === 'eventos') {
            if (empty($data['event_latitude']) || empty($data['event_longitude'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Los eventos deben tener coordenadas. Por favor selecciona una dirección de Google Maps.']);
                exit();
            }
        }

        $stmt = $db->prepare('INSERT INTO links (group_id, url, url_text, text, image_url, description, position, event_date, event_time, event_address, event_latitude, event_longitude, event_maps_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['group_id'],
            $data['url'],
            (isset($data['url_text']) && $data['url_text'] !== '') ? $data['url_text'] : null,
            $data['text'],
            $data['image_url'] ?? null,
            $data['description'] ?? null,
            $data['position'] ?? 0,
            $data['event_date'] ?? null,
            $data['event_time'] ?? null,
            $data['event_address'] ?? null,
            $data['event_latitude'] ?? null,
            $data['event_longitude'] ?? null,
            $data['event_maps_url'] ?? null
        ]);

        $linkId = $db->lastInsertId();

        $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
        $stmt->execute([$linkId]);
        $link = $stmt->fetch();

        http_response_code(201);
        echo json_encode(['link' => $link]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
