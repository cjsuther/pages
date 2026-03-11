<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$database = new Database();
$db = $database->connect();

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// GET: list collaborations
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $linkId = isset($_GET['link_id']) ? intval($_GET['link_id']) : null;
    $type = $_GET['type'] ?? null;

    try {
        if ($linkId) {
            // Verify event ownership
            $stmt = $db->prepare('
                SELECT l.id FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                JOIN pages p ON lg.page_id = p.id
                WHERE l.id = ? AND p.user_id = ?
            ');
            $stmt->execute([$linkId, $user['user_id']]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit();
            }

            $stmt = $db->prepare('
                SELECT ec.id, ec.status, ec.collaborator_page_id,
                    p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
                FROM event_collaborations ec
                JOIN pages p ON ec.collaborator_page_id = p.id
                WHERE ec.link_id = ?
                ORDER BY ec.created_at
            ');
            $stmt->execute([$linkId]);
            echo json_encode(['collaborations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } elseif ($type === 'pending') {
            // Pending requests for all pages owned by this user
            $stmt = $db->prepare('
                SELECT ec.id, ec.status, ec.link_id, ec.requester_page_id, ec.collaborator_page_id,
                    l.text as event_title, l.event_date, l.event_time, l.image_url as event_image,
                    rp.title as requester_page_title, rp.url_slug as requester_page_slug, rp.profile_image as requester_page_image,
                    cp.title as collaborator_page_title, cp.id as collaborator_page_id
                FROM event_collaborations ec
                JOIN links l ON ec.link_id = l.id
                JOIN pages rp ON ec.requester_page_id = rp.id
                JOIN pages cp ON ec.collaborator_page_id = cp.id
                WHERE cp.user_id = ? AND ec.status = "pending"
                ORDER BY ec.created_at DESC
            ');
            $stmt->execute([$user['user_id']]);
            echo json_encode(['pending' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing parameters: link_id or type=pending required']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// POST: invite a page to collaborate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $linkId = intval($data['link_id'] ?? 0);
    $collaboratorPageId = intval($data['collaborator_page_id'] ?? 0);

    if (!$linkId || !$collaboratorPageId) {
        http_response_code(400);
        echo json_encode(['error' => 'link_id and collaborator_page_id are required']);
        exit();
    }

    try {
        // Verify event ownership and get event data
        $stmt = $db->prepare('
            SELECT l.id, l.text as event_title, p.id as page_id, p.title as page_title
            FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            JOIN pages p ON lg.page_id = p.id
            WHERE l.id = ? AND p.user_id = ? AND lg.type = "eventos"
        ');
        $stmt->execute([$linkId, $user['user_id']]);
        $eventData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$eventData) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found or not authorized']);
            exit();
        }

        // Verify collaborator page exists
        $stmt = $db->prepare('SELECT id, user_id FROM pages WHERE id = ?');
        $stmt->execute([$collaboratorPageId]);
        $collabPage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$collabPage) {
            http_response_code(404);
            echo json_encode(['error' => 'Collaborator page not found']);
            exit();
        }

        // Prevent self-collaboration on same page
        if ($collaboratorPageId == $eventData['page_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'No puedes invitar tu propia página']);
            exit();
        }

        // Check for duplicate
        $stmt = $db->prepare('SELECT id FROM event_collaborations WHERE link_id = ? AND collaborator_page_id = ?');
        $stmt->execute([$linkId, $collaboratorPageId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Esta página ya fue invitada a colaborar']);
            exit();
        }

        // Create collaboration
        $stmt = $db->prepare('INSERT INTO event_collaborations (link_id, collaborator_page_id, requester_page_id) VALUES (?, ?, ?)');
        $stmt->execute([$linkId, $collaboratorPageId, $eventData['page_id']]);
        $collabId = $db->lastInsertId();

        // Send notification to collaborator page owner
        // page_id = collaboratorPageId so the notification links to the collaborator's page editor
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, page_id, link_id, type, collaboration_id, is_read)
            VALUES (?, ?, ?, ?, ?, "collaboration_request", ?, 0)
        ');
        $stmt->execute([
            $collabPage['user_id'],
            'Invitación a colaborar',
            'La página "' . $eventData['page_title'] . '" te invita a colaborar en el evento "' . $eventData['event_title'] . '"',
            $collaboratorPageId,
            $linkId,
            $collabId
        ]);

        http_response_code(201);
        echo json_encode(['message' => 'Invitación enviada', 'collaboration_id' => $collabId]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
