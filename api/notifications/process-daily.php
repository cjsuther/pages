<?php

/**
 * Proceso diario de notificaciones de eventos nuevos.
 *
 * Por cron:  php /ruta/api/notifications/process-daily.php
 * Por web:   /api/notifications/process-daily.php?cron_key=<CRON_SECRET_KEY>
 */

require_once __DIR__ . '/../bootstrap.php';

if (php_sapi_name() === 'cli') {
    $database = new Database();
    $db = $database->connect();

    echo "Iniciando procesamiento de notificaciones...\n";

    $resumen = NotificationsHandler::procesarEventosNuevos($db);

    foreach ($resumen['log'] as $linea) {
        echo $linea . "\n";
    }

    echo "\n=== Resumen ===\n";
    echo "Eventos procesados: {$resumen['events_processed']}\n";
    echo "Notificaciones creadas: {$resumen['notifications_sent']}\n";
    echo 'Finalizado: ' . date('Y-m-d H:i:s') . "\n";
    exit(0);
}

Api::run(['NotificationsHandler', 'processDaily']);
