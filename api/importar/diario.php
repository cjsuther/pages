<?php

/**
 * Corrida diaria del importador de carteleras.
 *
 *   Por cron:  /opt/alt/php83/usr/bin/php /ruta/api/importar/diario.php
 *   Por web:   /api/importar/diario.php?cron_key=<CRON_SECRET_KEY>
 *
 * Va aparte del cron de notificaciones a propósito: éste sale a sitios ajenos
 * y puede tardar minutos, y no tiene por qué demorar el despacho de los avisos.
 */

require_once __DIR__ . '/../bootstrap.php';

$esCli = php_sapi_name() === 'cli';

if (!$esCli) {
    $clave = isset($_GET['cron_key']) ? $_GET['cron_key'] : null;

    if (!defined('CRON_SECRET_KEY') || $clave !== CRON_SECRET_KEY) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit();
    }
}

// Salir a varios sitios lleva su tiempo; el límite por defecto no alcanza.
set_time_limit(600);

$database = new Database();
$db = $database->connect();

$resumen = Importaciones::correr($db);

if ($esCli) {
    echo "Fuentes:       {$resumen['fuentes']}\n";
    echo "Eventos nuevos: {$resumen['creados']}\n";
    echo "Actualizados:  {$resumen['actualizados']}\n";
    echo "Fallidas:      {$resumen['fallidas']}\n\n";

    foreach ($resumen['detalle'] as $linea) {
        echo "  $linea\n";
    }

    exit($resumen['fallidas'] > 0 ? 1 : 0);
}

echo json_encode(['success' => true] + $resumen);
