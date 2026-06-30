<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';
require_once '../PageAccess.php';

$database = new Database();
$db = $database->connect();

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// PUT: el invitado acepta o rechaza la invitación
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $adminId = isset($_GET['id']) ? intval($_GET['id']) : null;
    if (!$adminId) {
        http_response_code(400);
        echo json_encode(['error' => 'id requerido']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $status = $data['status'] ?? null;
    if (!in_array($status, ['accepted', 'rejected'])) {
        http_response_code(400);
        echo json_encode(['error' => 'status inválido. Debe ser "accepted" o "rejected"']);
        exit();
    }

    try {
        $stmt = $db->prepare('
            SELECT pa.id, pa.page_id, pa.user_id, pa.status,
                   p.title AS page_title, p.user_id AS owner_id
            FROM page_admins pa
            JOIN pages p ON pa.page_id = p.id
            WHERE pa.id = ?
        ');
        $stmt->execute([$adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['user_id'] != $user['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit();
        }
        if ($row['status'] !== 'pending') {
            http_response_code(400);
            echo json_encode(['error' => 'Esta invitación ya fue procesada']);
            exit();
        }

        $stmt = $db->prepare('SELECT name, email FROM users WHERE id = ?');
        $stmt->execute([$user['user_id']]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC);
        $meName = $me['name'] ?: $me['email'];

        if ($status === 'accepted') {
            $stmt = $db->prepare('UPDATE page_admins SET status = "accepted", updated_at = NOW() WHERE id = ?');
            $stmt->execute([$adminId]);
            $notifTitle = 'Invitación aceptada';
            $notifMsg = $meName . ' aceptó administrar tu página "' . $row['page_title'] . '"';
        } else {
            $stmt = $db->prepare('DELETE FROM page_admins WHERE id = ?');
            $stmt->execute([$adminId]);
            $notifTitle = 'Invitación rechazada';
            $notifMsg = $meName . ' rechazó administrar tu página "' . $row['page_title'] . '"';
        }

        // Notificar al dueño
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
            VALUES (?, ?, ?, ?, NULL, "admin_response", 0)
        ');
        $stmt->execute([$row['owner_id'], $notifTitle, $notifMsg, $row['page_id']]);

        // Marcar la notificación de invitación como leída
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND page_id = ? AND type = "admin_invitation"');
        $stmt->execute([$user['user_id'], $row['page_id']]);

        echo json_encode(['message' => $status === 'accepted' ? 'Invitación aceptada' : 'Invitación rechazada']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// DELETE: el dueño remueve a un admin (?id=) o un admin se va de una página (?page_id=)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $adminId = isset($_GET['id']) ? intval($_GET['id']) : null;
    $pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : null;

    try {
        if ($adminId) {
            $stmt = $db->prepare('
                SELECT pa.id, pa.user_id, p.user_id AS owner_id
                FROM page_admins pa JOIN pages p ON pa.page_id = p.id
                WHERE pa.id = ?
            ');
            $stmt->execute([$adminId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'No encontrado']);
                exit();
            }
            // El dueño puede quitar a cualquier admin; un admin puede quitarse a sí mismo
            if ($row['owner_id'] != $user['user_id'] && $row['user_id'] != $user['user_id']) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                exit();
            }
            $stmt = $db->prepare('DELETE FROM page_admins WHERE id = ?');
            $stmt->execute([$adminId]);
            echo json_encode(['message' => 'Administrador removido']);
            exit();
        }

        if ($pageId) {
            // Auto-salida: el usuario actual deja de administrar la página
            $stmt = $db->prepare('DELETE FROM page_admins WHERE page_id = ? AND user_id = ?');
            $stmt->execute([$pageId, $user['user_id']]);
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'No sos administrador de esa página']);
                exit();
            }
            echo json_encode(['message' => 'Dejaste de administrar la página']);
            exit();
        }

        http_response_code(400);
        echo json_encode(['error' => 'id o page_id requerido']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
