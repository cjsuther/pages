<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$headers = getallheaders();
$token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token no proporcionado']);
    exit();
}

$payload = JWT::decode($token, JWT_SECRET);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido o expirado']);
    exit();
}

$user = JWT::getUserFromToken();
$userId = $user['user_id'];
$database = new Database();
$db = $database->connect();

// GET: Obtener notificaciones del usuario
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';

    $whereClause = 'WHERE n.user_id = ?';
    $params = [$userId];

    if ($unreadOnly) {
        $whereClause .= ' AND n.is_read = 0';
    }

    $stmt = $db->prepare("
        SELECT
            n.id,
            n.title,
            n.message,
            n.type,
            n.is_read,
            n.created_at,
            p.id as page_id,
            p.url_slug as page_slug,
            p.title as page_title,
            l.id as link_id,
            l.text as event_title,
            l.url as event_url
        FROM notifications n
        INNER JOIN pages p ON n.page_id = p.id
        INNER JOIN links l ON n.link_id = l.id
        $whereClause
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convertir tipos
    foreach ($notifications as &$notification) {
        $notification['id'] = (int) $notification['id'];
        $notification['is_read'] = (bool) $notification['is_read'];
        $notification['page_id'] = (int) $notification['page_id'];
        $notification['link_id'] = (int) $notification['link_id'];
    }

    // Contar no leídas
    $stmt = $db->prepare('SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    $unreadCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

    echo json_encode([
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'total' => count($notifications)
    ]);
    exit();
}

// PUT: Marcar notificaciones como leídas
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);

    $notificationIds = isset($data['notification_ids']) ? $data['notification_ids'] : null;
    $markAllAsRead = isset($data['mark_all_as_read']) && $data['mark_all_as_read'] === true;

    if ($markAllAsRead) {
        // Marcar todas como leídas
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $count = $stmt->rowCount();

        echo json_encode([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leídas',
            'updated_count' => $count
        ]);
        exit();
    }

    if (!$notificationIds || !is_array($notificationIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'IDs de notificaciones requeridos']);
        exit();
    }

    // Marcar específicas como leídas
    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $notificationIds));

    echo json_encode([
        'success' => true,
        'message' => 'Notificaciones marcadas como leídas',
        'updated_count' => $stmt->rowCount()
    ]);
    exit();
}

// DELETE: Eliminar notificaciones
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $notificationIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : null;

    if (!$notificationIds || !is_array($notificationIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'IDs de notificaciones requeridos']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $notificationIds));

    echo json_encode([
        'success' => true,
        'message' => 'Notificaciones eliminadas',
        'deleted_count' => $stmt->rowCount()
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
