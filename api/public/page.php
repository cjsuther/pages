<?php

require_once '../config.php';
require_once '../Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : null;

if (!$slug) {
    http_response_code(400);
    echo json_encode(['error' => 'Slug is required']);
    exit();
}

try {
    $database = new Database();
    $db = $database->connect();

    $stmt = $db->prepare('SELECT * FROM pages WHERE url_slug = ?');
    $stmt->execute([$slug]);
    $page = $stmt->fetch();

    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Page not found']);
        exit();
    }

    $stmt = $db->prepare('SELECT * FROM link_groups WHERE page_id = ? ORDER BY position, id');
    $stmt->execute([$page['id']]);
    $groups = $stmt->fetchAll();

    foreach ($groups as &$group) {
        if ($group['type'] == 'eventos') {
            $stmt = $db->prepare("SELECT *, (event_date IS NOT NULL AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? AND event_date > CURDATE() ORDER BY event_date, id");
            $stmt->execute([$group['id']]);
            $links = $stmt->fetchAll();

            // Load accepted collaborators for each event link
            foreach ($links as &$link) {
                $cs = $db->prepare('
                    SELECT p.id as page_id, p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
                    FROM event_collaborations ec
                    JOIN pages p ON ec.collaborator_page_id = p.id
                    WHERE ec.link_id = ? AND ec.status = "accepted"
                ');
                $cs->execute([$link['id']]);
                $link['collaborators'] = $cs->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($link);
            $group['links'] = $links;

            // Load accepted collaborated events for this group (events from other pages)
            $ces = $db->prepare("
                SELECT l.*, 1 as is_collaborated, ec.id as collaboration_id,
                    rp.id as source_page_id, rp.title as source_page_title,
                    rp.url_slug as source_page_slug, rp.profile_image as source_page_image,
                    (l.event_date IS NOT NULL AND l.event_date < CURDATE()) as event_due
                FROM event_collaborations ec
                JOIN links l ON ec.link_id = l.id
                JOIN pages rp ON ec.requester_page_id = rp.id
                WHERE ec.collaborator_group_id = ? AND ec.status = 'accepted'
                    AND (l.event_date IS NULL OR l.event_date >= CURDATE())
                ORDER BY l.event_date, l.id
            ");
            $ces->execute([$group['id']]);
            $collaborated = $ces->fetchAll(PDO::FETCH_ASSOC);

            // Load accepted collaborators for each collaborated event too
            foreach ($collaborated as &$evt) {
                $cs = $db->prepare('
                    SELECT p.id as page_id, p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
                    FROM event_collaborations ec
                    JOIN pages p ON ec.collaborator_page_id = p.id
                    WHERE ec.link_id = ? AND ec.status = "accepted"
                ');
                $cs->execute([$evt['id']]);
                $evt['collaborators'] = $cs->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($evt);
            $group['collaborated_events'] = $collaborated;

        } else {
            $stmt = $db->prepare("SELECT *, (event_date IS NOT NULL AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? ORDER BY position, id");
            $stmt->execute([$group['id']]);
            $group['links'] = $stmt->fetchAll();
        }
    }

    $page['groups'] = $groups;

    // Get follower count
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM page_followers WHERE page_id = ?');
    $stmt->execute([$page['id']]);
    $page['follower_count'] = (int) $stmt->fetch()['count'];

    echo json_encode(['page' => $page]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
