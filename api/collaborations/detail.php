<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$database = new Database();
$db = $database->connect();

$collabId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$collabId) {
    http_response_code(400);
    echo json_encode(['error' => 'Collaboration ID required']);
    exit();
}

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// PUT: accept or reject
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $status = $data['status'] ?? null;
    $groupId = isset($data['group_id']) ? intval($data['group_id']) : null;

    if (!in_array($status, ['accepted', 'rejected'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Estado inválido. Debe ser "accepted" o "rejected"']);
        exit();
    }

    if ($status === 'accepted' && !$groupId) {
        http_response_code(400);
        echo json_encode(['error' => 'group_id es requerido al aceptar']);
        exit();
    }

    try {
        $stmt = $db->prepare('
            SELECT ec.*, ec.requester_page_id,
                cp.user_id as collaborator_owner_id, cp.title as collaborator_page_title,
                rp.user_id as requester_owner_id, rp.title as requester_page_title,
                l.text as event_title
            FROM event_collaborations ec
            JOIN pages cp ON ec.collaborator_page_id = cp.id
            JOIN pages rp ON ec.requester_page_id = rp.id
            JOIN links l ON ec.link_id = l.id
            WHERE ec.id = ?
        ');
        $stmt->execute([$collabId]);
        $collab = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$collab || $collab['collaborator_owner_id'] != $user['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }

        if ($collab['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['error' => 'Esta colaboración ya fue procesada']);
            exit();
        }

        if ($status === 'accepted') {
            // Verify the chosen group belongs to the collaborator and is of type eventos
            $stmt = $db->prepare('
                SELECT lg.id FROM link_groups lg
                JOIN pages p ON lg.page_id = p.id
                WHERE lg.id = ? AND p.user_id = ? AND lg.type = "eventos"
            ');
            $stmt->execute([$groupId, $user['user_id']]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Grupo inválido: debe ser un grupo de eventos de tu página']);
                exit();
            }
        }

        $stmt = $db->prepare('UPDATE event_collaborations SET status = ?, collaborator_group_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $status === 'accepted' ? $groupId : null, $collabId]);

        // Notify the event owner about the response
        $notifTitle = $status === 'accepted' ? 'Colaboración aceptada' : 'Colaboración rechazada';
        $notifMsg = $status === 'accepted'
            ? 'La página "' . $collab['collaborator_page_title'] . '" aceptó colaborar en el evento "' . $collab['event_title'] . '"'
            : 'La página "' . $collab['collaborator_page_title'] . '" rechazó la colaboración en el evento "' . $collab['event_title'] . '"';

        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
            VALUES (?, ?, ?, ?, ?, "collaboration_response", 0)
        ');
        $stmt->execute([
            $collab['requester_owner_id'],
            $notifTitle,
            $notifMsg,
            $collab['requester_page_id'],
            $collab['link_id']
        ]);

        // Mark invitation notification as read
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE collaboration_id = ? AND user_id = ?');
        $stmt->execute([$collabId, $user['user_id']]);

        echo json_encode(['message' => 'Colaboración ' . ($status === 'accepted' ? 'aceptada' : 'rechazada')]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// DELETE: remove collaboration (by either party)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $stmt = $db->prepare('
            SELECT ec.*,
                rp.user_id as requester_owner_id,
                cp.user_id as collaborator_owner_id
            FROM event_collaborations ec
            JOIN pages rp ON ec.requester_page_id = rp.id
            JOIN pages cp ON ec.collaborator_page_id = cp.id
            WHERE ec.id = ?
        ');
        $stmt->execute([$collabId]);
        $collab = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$collab) {
            http_response_code(404);
            echo json_encode(['error' => 'Colaboración no encontrada']);
            exit();
        }

        if ($collab['requester_owner_id'] != $user['user_id'] && $collab['collaborator_owner_id'] != $user['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }

        $stmt = $db->prepare('DELETE FROM event_collaborations WHERE id = ?');
        $stmt->execute([$collabId]);

        echo json_encode(['message' => 'Colaboración eliminada']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
