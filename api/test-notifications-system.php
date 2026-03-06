<?php
/**
 * Script de Prueba del Sistema de Notificaciones
 *
 * Este script verifica que todos los componentes del sistema de notificaciones
 * estén correctamente configurados.
 *
 * Uso: php test-notifications-system.php
 */

require_once 'config.php';
require_once 'Database.php';

echo "==============================================\n";
echo "TEST DEL SISTEMA DE NOTIFICACIONES\n";
echo "==============================================\n\n";

$errors = [];
$warnings = [];
$success = [];

// 1. Verificar conexión a base de datos
echo "[1/8] Verificando conexión a base de datos...\n";
try {
    $db = new Database();
    $conn = $db->getConnection();
    $success[] = "✓ Conexión a base de datos exitosa";
} catch (Exception $e) {
    $errors[] = "✗ Error de conexión a base de datos: " . $e->getMessage();
}

// 2. Verificar tablas necesarias
echo "[2/8] Verificando tablas de base de datos...\n";
$requiredTables = ['page_followers', 'notifications', 'push_subscriptions'];
foreach ($requiredTables as $table) {
    try {
        $stmt = $conn->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $success[] = "✓ Tabla '$table' existe";
        } else {
            $errors[] = "✗ Tabla '$table' no existe. Ejecuta la migración migration_add_notifications_system.sql";
        }
    } catch (Exception $e) {
        $errors[] = "✗ Error verificando tabla '$table': " . $e->getMessage();
    }
}

// 3. Verificar campos en tabla users
echo "[3/8] Verificando campos en tabla users...\n";
$requiredFields = ['location_latitude', 'location_longitude', 'location_name', 'last_location_update'];
try {
    $stmt = $conn->query("DESCRIBE users");
    $fields = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredFields as $field) {
        if (in_array($field, $fields)) {
            $success[] = "✓ Campo 'users.$field' existe";
        } else {
            $errors[] = "✗ Campo 'users.$field' no existe. Ejecuta la migración migration_add_notifications_system.sql";
        }
    }
} catch (Exception $e) {
    $errors[] = "✗ Error verificando campos de users: " . $e->getMessage();
}

// 4. Verificar constantes de configuración
echo "[4/8] Verificando configuración...\n";
$requiredConstants = [
    'VAPID_PUBLIC_KEY',
    'VAPID_PRIVATE_KEY',
    'VAPID_SUBJECT',
    'CRON_SECRET_KEY'
];
foreach ($requiredConstants as $constant) {
    if (defined($constant)) {
        $value = constant($constant);
        if (strpos($value, 'TU_') !== false || strpos($value, 'CAMBIA_ESTO') !== false) {
            $warnings[] = "⚠ Constante '$constant' no está configurada (valor por defecto)";
        } else {
            $success[] = "✓ Constante '$constant' configurada";
        }
    } else {
        $errors[] = "✗ Constante '$constant' no definida en config.php";
    }
}

// 5. Verificar librería web-push
echo "[5/8] Verificando librería web-push...\n";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('Minishlink\WebPush\WebPush')) {
        $success[] = "✓ Librería web-push instalada";
    } else {
        $errors[] = "✗ Librería web-push no encontrada. Ejecuta: composer require minishlink/web-push";
    }
} else {
    $errors[] = "✗ Composer autoload no encontrado. Ejecuta: composer install";
}

// 6. Verificar archivos de API
echo "[6/8] Verificando archivos de API...\n";
$apiFiles = [
    'users/location.php',
    'pages/follow.php',
    'pages/following.php',
    'notifications/index.php',
    'notifications/subscribe.php',
    'notifications/process-daily.php'
];
foreach ($apiFiles as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $success[] = "✓ Archivo 'api/$file' existe";
    } else {
        $errors[] = "✗ Archivo 'api/$file' no existe";
    }
}

// 7. Verificar Service Worker
echo "[7/8] Verificando Service Worker...\n";
$swPath = __DIR__ . '/../frontend/public/sw.js';
if (file_exists($swPath)) {
    $success[] = "✓ Service Worker (sw.js) existe";
} else {
    $warnings[] = "⚠ Service Worker no encontrado en frontend/public/sw.js";
}

// 8. Prueba de cálculo de distancia
echo "[8/8] Probando cálculo de distancia...\n";
function testCalculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}

// Prueba: Buenos Aires a Córdoba (aprox 700km)
$distance = testCalculateDistance(-34.6037, -58.3816, -31.4201, -64.1888);
if ($distance > 650 && $distance < 750) {
    $success[] = "✓ Cálculo de distancia funciona correctamente (~" . round($distance) . "km)";
} else {
    $warnings[] = "⚠ Cálculo de distancia puede tener problemas (distancia calculada: " . round($distance) . "km, esperada: ~700km)";
}

// Resumen
echo "\n==============================================\n";
echo "RESUMEN\n";
echo "==============================================\n\n";

if (!empty($success)) {
    echo "ÉXITOS (" . count($success) . "):\n";
    foreach ($success as $msg) {
        echo "  $msg\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "ADVERTENCIAS (" . count($warnings) . "):\n";
    foreach ($warnings as $msg) {
        echo "  $msg\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "ERRORES (" . count($errors) . "):\n";
    foreach ($errors as $msg) {
        echo "  $msg\n";
    }
    echo "\n";
}

echo "==============================================\n";

if (empty($errors)) {
    if (empty($warnings)) {
        echo "✓ SISTEMA COMPLETAMENTE CONFIGURADO\n";
        echo "Todos los componentes están listos para usar.\n";
    } else {
        echo "⚠ SISTEMA PARCIALMENTE CONFIGURADO\n";
        echo "El sistema funcionará pero hay advertencias que deberías revisar.\n";
    }
} else {
    echo "✗ SISTEMA NO CONFIGURADO CORRECTAMENTE\n";
    echo "Por favor corrige los errores antes de usar el sistema.\n";
}

echo "==============================================\n\n";

// Instrucciones siguientes
if (!empty($errors) || !empty($warnings)) {
    echo "PRÓXIMOS PASOS:\n\n";

    if (in_array('✗ Tabla \'page_followers\' no existe. Ejecuta la migración migration_add_notifications_system.sql', $errors) ||
        in_array('✗ Tabla \'notifications\' no existe. Ejecuta la migración migration_add_notifications_system.sql', $errors) ||
        in_array('✗ Tabla \'push_subscriptions\' no existe. Ejecuta la migración migration_add_notifications_system.sql', $errors)) {
        echo "1. Ejecutar migración de base de datos:\n";
        echo "   mysql -u usuario -p base_de_datos < migration_add_notifications_system.sql\n\n";
    }

    if (in_array('✗ Librería web-push no encontrada. Ejecuta: composer require minishlink/web-push', $errors)) {
        echo "2. Instalar dependencias de Composer:\n";
        echo "   cd " . __DIR__ . "\n";
        echo "   composer require minishlink/web-push\n\n";
    }

    foreach ($warnings as $warning) {
        if (strpos($warning, 'VAPID') !== false) {
            echo "3. Generar claves VAPID:\n";
            echo "   php " . __DIR__ . "/generate-vapid-keys.php\n";
            echo "   Luego copia las claves generadas a config.php\n\n";
            break;
        }
    }

    foreach ($warnings as $warning) {
        if (strpos($warning, 'CRON_SECRET_KEY') !== false) {
            echo "4. Configurar clave secreta de cron en config.php:\n";
            echo "   define('CRON_SECRET_KEY', 'genera_una_clave_aleatoria_aqui');\n\n";
            break;
        }
    }

    echo "Para más información, consulta GUIA_NOTIFICACIONES.md\n\n";
}

exit(empty($errors) ? 0 : 1);
