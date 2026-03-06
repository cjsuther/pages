<?php
/**
 * Script para crear datos de prueba del sistema de notificaciones
 *
 * Este script crea:
 * - Usuarios de prueba con ubicaciones
 * - Páginas de prueba
 * - Eventos de prueba con ubicaciones
 * - Relaciones de seguimiento
 *
 * Uso: php create-test-data.php
 */

require_once 'config.php';
require_once 'Database.php';

echo "==============================================\n";
echo "CREACIÓN DE DATOS DE PRUEBA\n";
echo "==============================================\n\n";

$db = new Database();
$conn = $db->getConnection();

// Función para generar hash de contraseña
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

// 1. Crear usuarios de prueba
echo "[1/5] Creando usuarios de prueba...\n";

$testUsers = [
    [
        'email' => 'usuario1@test.com',
        'password' => hashPassword('password123'),
        'latitude' => -34.6037,  // Buenos Aires
        'longitude' => -58.3816,
        'location_name' => 'Buenos Aires, Argentina'
    ],
    [
        'email' => 'usuario2@test.com',
        'password' => hashPassword('password123'),
        'latitude' => -31.4201,  // Córdoba
        'longitude' => -64.1888,
        'location_name' => 'Córdoba, Argentina'
    ],
    [
        'email' => 'usuario3@test.com',
        'password' => hashPassword('password123'),
        'latitude' => -32.8895,  // Rosario
        'longitude' => -60.6867,
        'location_name' => 'Rosario, Argentina'
    ]
];

$userIds = [];
foreach ($testUsers as $user) {
    try {
        $stmt = $conn->prepare('
            INSERT IGNORE INTO users (email, password, location_latitude, location_longitude, location_name, last_location_update)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $user['email'],
            $user['password'],
            $user['latitude'],
            $user['longitude'],
            $user['location_name']
        ]);

        // Obtener el ID del usuario
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$user['email']]);
        $userId = $stmt->fetchColumn();
        $userIds[] = $userId;

        echo "  ✓ Usuario creado: {$user['email']} (ID: $userId)\n";
    } catch (Exception $e) {
        echo "  ✗ Error creando usuario {$user['email']}: " . $e->getMessage() . "\n";
    }
}

// 2. Crear páginas de prueba
echo "\n[2/5] Creando páginas de prueba...\n";

$testPages = [
    [
        'user_id' => $userIds[0] ?? 1,
        'title' => 'Eventos Culturales BA',
        'description' => 'Los mejores eventos culturales de Buenos Aires',
        'slug' => 'eventos-ba-' . time()
    ],
    [
        'user_id' => $userIds[1] ?? 1,
        'title' => 'Tech Meetups Córdoba',
        'description' => 'Encuentros de tecnología en Córdoba',
        'slug' => 'tech-cordoba-' . time()
    ],
    [
        'user_id' => $userIds[2] ?? 1,
        'title' => 'Deportes Rosario',
        'description' => 'Eventos deportivos en Rosario',
        'slug' => 'deportes-rosario-' . time()
    ]
];

$pageIds = [];
foreach ($testPages as $page) {
    try {
        $stmt = $conn->prepare('
            INSERT INTO pages (user_id, title, description, url_slug)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $page['user_id'],
            $page['title'],
            $page['description'],
            $page['slug']
        ]);

        $pageId = $conn->lastInsertId();
        $pageIds[] = $pageId;

        echo "  ✓ Página creada: {$page['title']} (ID: $pageId, Slug: {$page['slug']})\n";
    } catch (Exception $e) {
        echo "  ✗ Error creando página {$page['title']}: " . $e->getMessage() . "\n";
    }
}

// 3. Crear grupos para las páginas
echo "\n[3/5] Creando grupos en páginas...\n";

$groupIds = [];
foreach ($pageIds as $index => $pageId) {
    try {
        $stmt = $conn->prepare('
            INSERT INTO groups (page_id, title, position, type)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$pageId, 'Próximos Eventos', 0, 'default']);

        $groupId = $conn->lastInsertId();
        $groupIds[$pageId] = $groupId;

        echo "  ✓ Grupo creado para página $pageId (ID: $groupId)\n";
    } catch (Exception $e) {
        echo "  ✗ Error creando grupo: " . $e->getMessage() . "\n";
    }
}

// 4. Crear eventos de prueba
echo "\n[4/5] Creando eventos de prueba...\n";

$testEvents = [
    [
        'page_id' => $pageIds[0] ?? 1,
        'title' => 'Concierto de Jazz',
        'url' => 'https://ejemplo.com/jazz',
        'text' => 'Un increíble concierto de jazz en el centro',
        'latitude' => -34.6037,
        'longitude' => -58.3816,
        'address' => 'Teatro Colón, Buenos Aires',
        'date' => date('Y-m-d', strtotime('+7 days'))
    ],
    [
        'page_id' => $pageIds[0] ?? 1,
        'title' => 'Exposición de Arte',
        'url' => 'https://ejemplo.com/arte',
        'text' => 'Exposición de arte contemporáneo',
        'latitude' => -34.6158,
        'longitude' => -58.3656,
        'address' => 'MALBA, Buenos Aires',
        'date' => date('Y-m-d', strtotime('+10 days'))
    ],
    [
        'page_id' => $pageIds[1] ?? 1,
        'title' => 'Meetup de React',
        'url' => 'https://ejemplo.com/react',
        'text' => 'Charla sobre React y Next.js',
        'latitude' => -31.4201,
        'longitude' => -64.1888,
        'address' => 'Centro de Innovación, Córdoba',
        'date' => date('Y-m-d', strtotime('+5 days'))
    ],
    [
        'page_id' => $pageIds[2] ?? 1,
        'title' => 'Torneo de Fútbol',
        'url' => 'https://ejemplo.com/futbol',
        'text' => 'Copa local de fútbol amateur',
        'latitude' => -32.8895,
        'longitude' => -60.6867,
        'address' => 'Complejo Deportivo, Rosario',
        'date' => date('Y-m-d', strtotime('+14 days'))
    ]
];

$eventIds = [];
foreach ($testEvents as $event) {
    try {
        $groupId = $groupIds[$event['page_id']] ?? null;
        if (!$groupId) {
            echo "  ⚠ No se encontró grupo para página {$event['page_id']}, saltando evento\n";
            continue;
        }

        $stmt = $conn->prepare('
            INSERT INTO links (page_id, group_id, title, url, text, is_event, event_date, event_address, event_latitude, event_longitude, created_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $event['page_id'],
            $groupId,
            $event['title'],
            $event['url'],
            $event['text'],
            $event['date'],
            $event['address'],
            $event['latitude'],
            $event['longitude']
        ]);

        $eventId = $conn->lastInsertId();
        $eventIds[] = $eventId;

        echo "  ✓ Evento creado: {$event['title']} (ID: $eventId)\n";
    } catch (Exception $e) {
        echo "  ✗ Error creando evento {$event['title']}: " . $e->getMessage() . "\n";
    }
}

// 5. Crear relaciones de seguimiento
echo "\n[5/5] Creando relaciones de seguimiento...\n";

// Usuario 1 sigue todas las páginas (todos los eventos)
if (isset($userIds[0]) && !empty($pageIds)) {
    foreach ($pageIds as $pageId) {
        try {
            $stmt = $conn->prepare('
                INSERT IGNORE INTO page_followers (user_id, page_id, notify_all_events, max_distance_km)
                VALUES (?, ?, 1, 50)
            ');
            $stmt->execute([$userIds[0], $pageId]);
            echo "  ✓ Usuario 1 ahora sigue página $pageId (todos los eventos)\n";
        } catch (Exception $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }
}

// Usuario 2 sigue páginas con filtro de distancia (50km)
if (isset($userIds[1]) && !empty($pageIds)) {
    foreach ($pageIds as $pageId) {
        try {
            $stmt = $conn->prepare('
                INSERT IGNORE INTO page_followers (user_id, page_id, notify_all_events, max_distance_km)
                VALUES (?, ?, 0, 50)
            ');
            $stmt->execute([$userIds[1], $pageId]);
            echo "  ✓ Usuario 2 ahora sigue página $pageId (solo eventos cercanos, 50km)\n";
        } catch (Exception $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }
}

// Usuario 3 sigue solo algunas páginas con filtro de distancia (100km)
if (isset($userIds[2]) && isset($pageIds[0]) && isset($pageIds[2])) {
    try {
        $stmt = $conn->prepare('
            INSERT IGNORE INTO page_followers (user_id, page_id, notify_all_events, max_distance_km)
            VALUES (?, ?, 0, 100)
        ');
        $stmt->execute([$userIds[2], $pageIds[0]]);
        echo "  ✓ Usuario 3 ahora sigue página {$pageIds[0]} (solo eventos cercanos, 100km)\n";

        $stmt->execute([$userIds[2], $pageIds[2]]);
        echo "  ✓ Usuario 3 ahora sigue página {$pageIds[2]} (solo eventos cercanos, 100km)\n";
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

// Resumen
echo "\n==============================================\n";
echo "RESUMEN\n";
echo "==============================================\n\n";

echo "Usuarios creados: " . count($userIds) . "\n";
echo "  - usuario1@test.com (Buenos Aires)\n";
echo "  - usuario2@test.com (Córdoba)\n";
echo "  - usuario3@test.com (Rosario)\n";
echo "  - Contraseña para todos: password123\n\n";

echo "Páginas creadas: " . count($pageIds) . "\n";
foreach ($testPages as $page) {
    echo "  - {$page['title']} (/{$page['slug']})\n";
}
echo "\n";

echo "Eventos creados: " . count($eventIds) . "\n";
foreach ($testEvents as $event) {
    echo "  - {$event['title']} ({$event['date']})\n";
}
echo "\n";

echo "==============================================\n";
echo "SIGUIENTE PASO\n";
echo "==============================================\n\n";

echo "Para probar el sistema de notificaciones:\n\n";
echo "1. Inicia sesión con usuario1@test.com / password123\n";
echo "2. Ve a Configuración para ver tu ubicación y páginas seguidas\n";
echo "3. Ejecuta el procesador de notificaciones:\n";
echo "   php api/notifications/process-daily.php\n";
echo "4. Verifica que se crearon notificaciones en el dashboard\n\n";

echo "==============================================\n";
