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

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userId = $user['user_id'];
$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'date';
    $sortOrder = isset($_GET['sortOrder']) ? $_GET['sortOrder'] : 'asc';

    if (!in_array($sortBy, ['date', 'distance'])) {
        $sortBy = 'date';
    }
    if (!in_array($sortOrder, ['asc', 'desc'])) {
        $sortOrder = 'asc';
    }

    $stmt = $db->prepare('
        SELECT
            l.id,
            l.url,
            l.text,
            l.image_url,
            l.description,
            l.event_date,
            l.event_time,
            l.event_address,
            l.event_latitude,
            l.event_longitude,
            l.event_maps_url,
            1 as is_event,
            p.id as page_id,
            p.title as page_title,
            p.url_slug as page_slug,
            p.profile_image as page_image,
            pf.notify_all_events,
            pf.max_distance_km,
            u.location_latitude as user_latitude,
            u.location_longitude as user_longitude
        FROM page_followers pf
        INNER JOIN pages p ON pf.page_id = p.id
        INNER JOIN link_groups g ON g.page_id = p.id
        INNER JOIN links l ON l.group_id = g.id
        LEFT JOIN users u ON u.id = ?
        WHERE pf.user_id = ?
        AND g.type = "eventos"
        AND l.event_date IS NOT NULL
    ');

    $stmt->execute([$userId, $userId]);
    $allEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filteredEvents = [];

    foreach ($allEvents as $event) {
        $shouldInclude = false;

        if ($event['notify_all_events']) {
            $shouldInclude = true;
        } else {
            if (
                $event['user_latitude'] && $event['user_longitude'] &&
                $event['event_latitude'] && $event['event_longitude'] &&
                $event['max_distance_km']
            ) {

                $distance = calculateDistance(
                    $event['user_latitude'],
                    $event['user_longitude'],
                    $event['event_latitude'],
                    $event['event_longitude']
                );

                if ($distance <= $event['max_distance_km']) {
                    $shouldInclude = true;
                }
            }
        }

        if ($shouldInclude) {
            $distance = null;
            if ($event['user_latitude'] && $event['user_longitude'] &&
                $event['event_latitude'] && $event['event_longitude']) {
                $distance = calculateDistance(
                    $event['user_latitude'],
                    $event['user_longitude'],
                    $event['event_latitude'],
                    $event['event_longitude']
                );
            }

            unset($event['notify_all_events']);
            unset($event['max_distance_km']);
            unset($event['user_latitude']);
            unset($event['user_longitude']);

            $event['id'] = (int) $event['id'];
            $event['page_id'] = (int) $event['page_id'];
            $event['is_event'] = (bool) $event['is_event'];
            $event['distance'] = $distance;

            if ($event['event_latitude']) {
                $event['event_latitude'] = (float) $event['event_latitude'];
            }
            if ($event['event_longitude']) {
                $event['event_longitude'] = (float) $event['event_longitude'];
            }

            $filteredEvents[] = $event;
        }
    }

    if ($sortBy === 'distance') {
        usort($filteredEvents, function($a, $b) use ($sortOrder) {
            $distanceA = $a['distance'] !== null ? $a['distance'] : PHP_FLOAT_MAX;
            $distanceB = $b['distance'] !== null ? $b['distance'] : PHP_FLOAT_MAX;

            if ($sortOrder === 'asc') {
                return $distanceA <=> $distanceB;
            } else {
                return $distanceB <=> $distanceA;
            }
        });
    } else {
        usort($filteredEvents, function($a, $b) use ($sortOrder) {
            $dateTimeA = $a['event_date'] . ' ' . ($a['event_time'] ?? '00:00:00');
            $dateTimeB = $b['event_date'] . ' ' . ($b['event_time'] ?? '00:00:00');

            if ($sortOrder === 'asc') {
                return strcmp($dateTimeA, $dateTimeB);
            } else {
                return strcmp($dateTimeB, $dateTimeA);
            }
        });
    }

    echo json_encode([
        'events' => $filteredEvents,
        'total' => count($filteredEvents)
    ]);
    exit();
}

function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
