<?php
/**
 * Script para generar claves VAPID
 *
 * Este script genera las claves públicas y privadas necesarias para
 * las notificaciones push del navegador.
 *
 * Uso:
 * php generate-vapid-keys.php
 *
 * Las claves generadas deben copiarse a config.php en las constantes:
 * - VAPID_PUBLIC_KEY
 * - VAPID_PRIVATE_KEY
 */

require 'vendor/autoload.php';

use Minishlink\WebPush\VAPID;

try {
    echo "Generando claves VAPID...\n\n";

    $keys = VAPID::createVapidKeys();

    echo "================================================\n";
    echo "CLAVES VAPID GENERADAS EXITOSAMENTE\n";
    echo "================================================\n\n";

    echo "Copia estas claves a tu archivo api/config.php:\n\n";

    echo "define('VAPID_PUBLIC_KEY', '" . $keys['publicKey'] . "');\n";
    echo "define('VAPID_PRIVATE_KEY', '" . $keys['privateKey'] . "');\n";
    echo "define('VAPID_SUBJECT', 'mailto:tu-email@ejemplo.com');\n\n";

    echo "================================================\n";
    echo "IMPORTANTE:\n";
    echo "================================================\n";
    echo "1. Estas claves son PERMANENTES para tu aplicación\n";
    echo "2. No las regeneres a menos que sea absolutamente necesario\n";
    echo "3. Si regeneras las claves, todos los usuarios deberán\n";
    echo "   volver a suscribirse a las notificaciones push\n";
    echo "4. Guarda estas claves en un lugar seguro\n";
    echo "5. NUNCA las compartas públicamente o las subas a Git\n";
    echo "================================================\n\n";

} catch (Exception $e) {
    var_dump($e);
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\nAsegúrate de que la librería web-push está instalada:\n";
    echo "composer require minishlink/web-push\n";
    exit(1);
}
