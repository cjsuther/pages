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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Mis invitaciones de administración pendientes
    if (($_GET['type'] ?? '') === 'pending') {
        $stmt = $db->prepare('
            SELECT pa.id, pa.page_id, pa.status, pa.created_at,
                   p.title AS page_title, p.url_slug AS page_slug, p.profile_image AS page_image,
                   owner.name AS owner_name, owner.email AS owner_email
            FROM page_admins pa
            JOIN pages p ON pa.page_id = p.id
            JOIN users owner ON p.user_id = owner.id
            WHERE pa.user_id = ? AND pa.status = "pending"
            ORDER BY pa.created_at DESC
        ');
        $stmt->execute([$user['user_id']]);
        echo json_encode(['invitations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit();
    }

    // Listar administradores de una página (dueño o admin puede ver)
    $pageId = isset($_GET['page_id']) ? intval($_GET['page_id']) : null;
    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'page_id requerido']);
        exit();
    }
    if (!PageAccess::canManage($db, $pageId, $user['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }

    $stmt = $db->prepare('
        SELECT pa.id, pa.user_id, pa.status, pa.created_at,
               u.name AS user_name, u.email AS user_email
        FROM page_admins pa
        JOIN users u ON pa.user_id = u.id
        WHERE pa.page_id = ?
        ORDER BY pa.status, pa.created_at
    ');
    $stmt->execute([$pageId]);
    echo json_encode(['admins' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Invitar a un usuario por email (solo el dueño)
    $data = json_decode(file_get_contents('php://input'), true);
    $pageId = isset($data['page_id']) ? intval($data['page_id']) : null;
    $email = isset($data['email']) ? trim(strtolower($data['email'])) : '';

    if (!$pageId || $email === '') {
        http_response_code(400);
        echo json_encode(['error' => 'page_id y email son requeridos']);
        exit();
    }

    if (!PageAccess::isOwner($db, $pageId, $user['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Solo el dueño de la página puede invitar administradores']);
        exit();
    }

    try {
        $stmt = $db->prepare('SELECT id, name, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $invitee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invitee) {
            http_response_code(404);
            echo json_encode(['error' => 'No hay ningún usuario registrado con ese email']);
            exit();
        }
        if ($invitee['id'] == $user['user_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'No podés invitarte a vos mismo']);
            exit();
        }

        $stmt = $db->prepare('SELECT title FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $pageTitle = $stmt->fetchColumn();

        $stmt = $db->prepare('SELECT id, status FROM page_admins WHERE page_id = ? AND user_id = ?');
        $stmt->execute([$pageId, $invitee['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            http_response_code(409);
            echo json_encode(['error' => $existing['status'] === 'accepted'
                ? 'Ese usuario ya es administrador de esta página'
                : 'Ya hay una invitación pendiente para ese usuario']);
            exit();
        }

        $stmt = $db->prepare('INSERT INTO page_admins (page_id, user_id, status, invited_by) VALUES (?, ?, "pending", ?)');
        $stmt->execute([$pageId, $invitee['id'], $user['user_id']]);
        $adminId = $db->lastInsertId();

        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
            VALUES (?, ?, ?, ?, NULL, "admin_invitation", 0)
        ');
        $stmt->execute([
            $invitee['id'],
            'Invitación para administrar una página',
            'Te invitaron a administrar la página "' . $pageTitle . '". Aceptala desde "Mis páginas".',
            $pageId
        ]);

        http_response_code(201);
        echo json_encode([
            'message' => 'Invitación enviada',
            'admin' => [
                'id' => $adminId,
                'user_id' => $invitee['id'],
                'user_name' => $invitee['name'],
                'user_email' => $invitee['email'],
                'status' => 'pending'
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
