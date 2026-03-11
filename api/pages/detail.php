<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$database = new Database();
$db = $database->connect();

$pageId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$pageId) {
    http_response_code(400);
    echo json_encode(['error' => 'Page ID is required']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = JWT::getUserFromToken();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    try {
        $stmt = $db->prepare('SELECT * FROM pages WHERE id = ? AND user_id = ?');
        $stmt->execute([$pageId, $user['user_id']]);
        $page = $stmt->fetch();

        if (!$page) {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
            exit();
        }

        $stmt = $db->prepare('SELECT * FROM link_groups WHERE page_id = ? ORDER BY position, id');
        $stmt->execute([$pageId]);
        $groups = $stmt->fetchAll();

        foreach ($groups as &$group) {
            if ($group['type'] == 'eventos') {
                $stmt = $db->prepare("SELECT *, (event_date <> '0000-00-00' AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? ORDER BY event_date, id");
                $stmt->execute([$group['id']]);
                $links = $stmt->fetchAll();

                // Load collaborators for each event link
                foreach ($links as &$link) {
                    $collabStmt = $db->prepare('
                        SELECT ec.id, ec.status, ec.collaborator_page_id,
                            p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
                        FROM event_collaborations ec
                        JOIN pages p ON ec.collaborator_page_id = p.id
                        WHERE ec.link_id = ?
                        ORDER BY ec.created_at
                    ');
                    $collabStmt->execute([$link['id']]);
                    $link['collaborations'] = $collabStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                unset($link);
                $group['links'] = $links;

                // Load accepted collaborated events for this group (events from other pages)
                $collabEvtStmt = $db->prepare("
                    SELECT l.*, ec.id as collaboration_id, ec.requester_page_id,
                        rp.title as source_page_title, rp.url_slug as source_page_slug, rp.profile_image as source_page_image,
                        (l.event_date <> '0000-00-00' AND l.event_date < CURDATE()) as event_due
                    FROM event_collaborations ec
                    JOIN links l ON ec.link_id = l.id
                    JOIN pages rp ON ec.requester_page_id = rp.id
                    WHERE ec.collaborator_group_id = ? AND ec.status = 'accepted'
                    ORDER BY l.event_date, l.id
                ");
                $collabEvtStmt->execute([$group['id']]);
                $group['collaborated_events'] = $collabEvtStmt->fetchAll(PDO::FETCH_ASSOC);

            } else {
                $stmt = $db->prepare("SELECT *, (event_date <> '0000-00-00' AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? ORDER BY position, id");
                $stmt->execute([$group['id']]);
                $group['links'] = $stmt->fetchAll();
            }
        }

        $page['groups'] = $groups;

        echo json_encode(['page' => $page]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $user = JWT::getUserFromToken();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);

    try {
        $stmt = $db->prepare('SELECT id FROM pages WHERE id = ? AND user_id = ?');
        $stmt->execute([$pageId, $user['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
            exit();
        }

        $fields = [];
        $values = [];

        if (isset($data['title'])) {
            $fields[] = 'title = ?';
            $values[] = $data['title'];
        }
        if (isset($data['description'])) {
            $fields[] = 'description = ?';
            $values[] = $data['description'];
        }
        if (isset($data['primary_color'])) {
            $fields[] = 'primary_color = ?';
            $values[] = $data['primary_color'];
        }
        if (isset($data['secondary_color'])) {
            $fields[] = 'secondary_color = ?';
            $values[] = $data['secondary_color'];
        }
        if (isset($data['background_color'])) {
            $fields[] = 'background_color = ?';
            $values[] = $data['background_color'];
        }
        if (isset($data['text_color'])) {
            $fields[] = 'text_color = ?';
            $values[] = $data['text_color'];
        }
        if (array_key_exists('profile_image', $data)) {
            $fields[] = 'profile_image = ?';
            $values[] = ($data['profile_image'] === '' || $data['profile_image'] === null) ? null : $data['profile_image'];
        }
        if (array_key_exists('background_image', $data)) {
            $fields[] = 'background_image = ?';
            $values[] = ($data['background_image'] === '' || $data['background_image'] === null) ? null : $data['background_image'];
        }
        if (isset($data['template'])) {
            $fields[] = 'template = ?';
            $values[] = $data['template'];
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit();
        }

        $values[] = $pageId;
        $sql = 'UPDATE pages SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);

        $stmt = $db->prepare('SELECT * FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();

        echo json_encode(['page' => $page]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $user = JWT::getUserFromToken();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    try {
        $stmt = $db->prepare('DELETE FROM pages WHERE id = ? AND user_id = ?');
        $stmt->execute([$pageId, $user['user_id']]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
            exit();
        }

        echo json_encode(['message' => 'Page deleted successfully']);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
