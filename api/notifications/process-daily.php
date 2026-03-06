<?php

require_once '../config.php';
require_once '../Database.php';

// Este script debe ejecutarse mediante cron una vez al día
// Ejemplo de cron: 0 9 * * * php /ruta/al/proyecto/api/notifications/process-daily.php

// Solo permitir ejecución desde CLI o con una clave secreta
if (php_sapi_name() !== 'cli') {
    $cronKey = isset($_GET['cron_key']) ? $_GET['cron_key'] : null;
    if (!defined('CRON_SECRET_KEY') || $cronKey !== CRON_SECRET_KEY) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit();
    }
}

$database = new Database();
$conn = $database->connect();

// Función para calcular distancia entre dos puntos usando fórmula de Haversine
function calculateDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371; // Radio de la Tierra en km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distance = $earthRadius * $c;

    return $distance;
}

$processedCount = 0;
$notificationsSent = 0;

echo "Iniciando procesamiento de notificaciones...\n";

// Obtener eventos creados en las últimas 24 horas
$stmt = $conn->prepare('
    SELECT
        l.id,
        lg.page_id,
        p.title,
        p.url_slug,
        l.event_date,
        l.event_address,
        l.event_latitude,
        l.event_longitude,
        p.title as page_title
    FROM links l
    INNER JOIN link_groups lg ON lg.id = l.group_id
    INNER JOIN pages p ON lg.page_id = p.id
    WHERE lg.type = "eventos"
    AND l.event_date > NOW()
    AND (l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) OR l.updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
    ORDER BY l.created_at DESC
');
$stmt->execute();
$newEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Eventos nuevos encontrados: " . count($newEvents) . "\n";

foreach ($newEvents as $event) {
    $eventId = $event['id'];
    $pageId = $event['page_id'];
    $eventTitle = $event['title'];
    $eventLat = $event['event_latitude'];
    $eventLon = $event['event_longitude'];

    // Obtener seguidores de esta página
    $stmt = $conn->prepare('
        SELECT
            pf.user_id,
            pf.notify_all_events,
            pf.max_distance_km,
            u.location_latitude,
            u.location_longitude,
            u.email
        FROM page_followers pf
        INNER JOIN users u ON pf.user_id = u.id
        WHERE pf.page_id = ?
    ');
    $stmt->execute([$pageId]);
    $followers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  Procesando evento: {$eventTitle} ({$eventId}) - Seguidores: " . count($followers) . "\n";

    foreach ($followers as $follower) {
        $userId = $follower['user_id'];
        $notifyAllEvents = (bool) $follower['notify_all_events'];
        $maxDistanceKm = (float) $follower['max_distance_km'];
        $userLat = $follower['location_latitude'];
        $userLon = $follower['location_longitude'];

        $shouldNotify = false;

        // Decidir si notificar según preferencias
        if ($notifyAllEvents) {
            $shouldNotify = true;
        } else {
            // Solo notificar si el evento está cerca
            if ($eventLat && $eventLon && $userLat && $userLon) {
                $distance = calculateDistance($userLat, $userLon, $eventLat, $eventLon);
                if ($distance <= $maxDistanceKm) {
                    $shouldNotify = true;
                }
            }
        }

        if (!$shouldNotify) {
            continue;
        }

        // Verificar que no exista ya una notificación para este evento y usuario
        $stmt = $conn->prepare('SELECT id FROM notifications WHERE user_id = ? AND link_id = ?');
        $stmt->execute([$userId, $eventId]);
        if ($stmt->fetch()) {
            continue; // Ya existe la notificación
        }

        // Crear notificación
        $notificationTitle = "Nuevo evento: {$eventTitle}";
        $notificationMessage = "La página {$event['page_title']} ha publicado un nuevo evento";
        if ($event['event_date']) {
            $notificationMessage .= " para el " . date('d/m/Y', strtotime($event['event_date']));
        }

        $stmt = $conn->prepare('
            INSERT INTO notifications (user_id, page_id, link_id, title, message)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $pageId, $eventId, $notificationTitle, $notificationMessage]);
        $notificationsSent++;

        echo "    Notificación creada para usuario {$userId}\n";
    }

    $processedCount++;
}

echo "\n=== Resumen ===\n";
echo "Eventos procesados: {$processedCount}\n";
echo "Notificaciones creadas: {$notificationsSent}\n";
echo "Finalizado: " . date('Y-m-d H:i:s') . "\n";

// Si se ejecuta vía web, devolver JSON
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'events_processed' => $processedCount,
        'notifications_sent' => $notificationsSent,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
