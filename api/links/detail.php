<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';
require_once '../PageAccess.php';

$database = new Database();
$db = $database->connect();

$linkId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$linkId) {
    http_response_code(400);
    echo json_encode(['error' => 'Link ID is required']);
    exit();
}

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);

    try {
        if (!PageAccess::canManageLink($db, $linkId, $user['user_id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Link not found']);
            exit();
        }

        $stmt = $db->prepare('
            SELECT l.id, lg.type
            FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            WHERE l.id = ?
        ');
        $stmt->execute([$linkId]);
        $linkData = $stmt->fetch();

        if ($linkData['type'] === 'eventos') {
            $stmt = $db->prepare('SELECT event_latitude, event_longitude FROM links WHERE id = ?');
            $stmt->execute([$linkId]);
            $currentLink = $stmt->fetch();

            $finalLatitude = isset($data['event_latitude']) ? $data['event_latitude'] : $currentLink['event_latitude'];
            $finalLongitude = isset($data['event_longitude']) ? $data['event_longitude'] : $currentLink['event_longitude'];

            if (empty($finalLatitude) || empty($finalLongitude)) {
                http_response_code(400);
                echo json_encode(['error' => 'Los eventos deben tener coordenadas. Por favor selecciona una dirección de Google Maps.']);
                exit();
            }
        }

        $fields = [];
        $values = [];

        if (isset($data['url'])) {
            $fields[] = 'url = ?';
            $values[] = $data['url'];
        }
        if (array_key_exists('url_text', $data)) {
            $fields[] = 'url_text = ?';
            $values[] = ($data['url_text'] === '' || $data['url_text'] === null) ? null : $data['url_text'];
        }
        if (isset($data['text'])) {
            $fields[] = 'text = ?';
            $values[] = $data['text'];
        }
        if (array_key_exists('image_url', $data)) {
            $fields[] = 'image_url = ?';
            $values[] = ($data['image_url'] === '' || $data['image_url'] === null) ? null : $data['image_url'];
        }
        if (isset($data['description'])) {
            $fields[] = 'description = ?';
            $values[] = $data['description'];
        }
        if (isset($data['position'])) {
            $fields[] = 'position = ?';
            $values[] = $data['position'];
        }
        if (isset($data['event_date'])) {
            $fields[] = 'event_date = ?';
            $values[] = $data['event_date'];
        }
        if (isset($data['event_time'])) {
            $fields[] = 'event_time = ?';
            $values[] = $data['event_time'];
        }
        if (isset($data['event_address'])) {
            $fields[] = 'event_address = ?';
            $values[] = $data['event_address'];
        }
        if (isset($data['event_latitude'])) {
            $fields[] = 'event_latitude = ?';
            $values[] = $data['event_latitude'];
        }
        if (isset($data['event_longitude'])) {
            $fields[] = 'event_longitude = ?';
            $values[] = $data['event_longitude'];
        }
        if (isset($data['event_maps_url'])) {
            $fields[] = 'event_maps_url = ?';
            $values[] = $data['event_maps_url'];
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit();
        }

        $values[] = $linkId;
        $sql = 'UPDATE links SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);

        $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
        $stmt->execute([$linkId]);
        $link = $stmt->fetch();

        echo json_encode(['link' => $link]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        if (!PageAccess::canManageLink($db, $linkId, $user['user_id'])) {
            http_response_code(404);
            echo json_encode(['error' => 'Link not found']);
            exit();
        }

        $stmt = $db->prepare('DELETE FROM links WHERE id = ?');
        $stmt->execute([$linkId]);

        echo json_encode(['message' => 'Link deleted successfully']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
