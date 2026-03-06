<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$database = new Database();
$db = $database->connect();

$groupId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$groupId) {
    http_response_code(400);
    echo json_encode(['error' => 'Group ID is required']);
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
        $stmt = $db->prepare('
            SELECT lg.id
            FROM link_groups lg
            JOIN pages p ON lg.page_id = p.id
            WHERE lg.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$groupId, $user['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Group not found']);
            exit();
        }

        $fields = [];
        $values = [];

        if (isset($data['title'])) {
            $fields[] = 'title = ?';
            $values[] = $data['title'];
        }
        if (isset($data['type'])) {
            $fields[] = 'type = ?';
            $values[] = $data['type'];
        }
        if (isset($data['position'])) {
            $fields[] = 'position = ?';
            $values[] = $data['position'];
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit();
        }

        $values[] = $groupId;
        $sql = 'UPDATE link_groups SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);

        $stmt = $db->prepare('SELECT * FROM link_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        echo json_encode(['group' => $group]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $stmt = $db->prepare('
            DELETE lg FROM link_groups lg
            JOIN pages p ON lg.page_id = p.id
            WHERE lg.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$groupId, $user['user_id']]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Group not found']);
            exit();
        }

        echo json_encode(['message' => 'Group deleted successfully']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
