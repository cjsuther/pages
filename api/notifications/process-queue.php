<?php

/**
 * Procesa la cola de notificaciones push.
 *
 * Encolar y no enviar en línea: mandar a N dispositivos puede tardar más que
 * el tiempo máximo de un request (GUIA-PUSH-PWA.md §6). Este script corre por
 * cron cada minuto.
 *
 *   Por cron:  /opt/alt/php83/usr/bin/php /ruta/api/notifications/process-queue.php
 *   Por web:   /api/notifications/process-queue.php?cron_key=<CRON_SECRET_KEY>
 *
 * Es seguro que se solape con otra corrida: los índices únicos de
 * push_deliveries y notifications impiden duplicar envíos.
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

$database = new Database();
$db = $database->connect();

$resultado = ['encolados' => 0, 'enviados' => 0, 'fallidos' => 0, 'expiradas' => 0, 'total' => 0];
$error = null;

try {
    // 1. Toda notificación reciente sin despachar genera sus envíos.
    $resultado['encolados'] = Notificador::encolarPendientes($db);

    // 2. Se despacha lo pendiente.
    if (!PushSender::disponible()) {
        $error = 'El servidor no puede firmar VAPID (falta la extensión gmp o la librería web-push)';
    } elseif (!PushSender::subjectValido(defined('VAPID_SUBJECT') ? VAPID_SUBJECT : '')) {
        // Con un subject inválido Android recibe y iOS no, sin ningún error
        // visible. Mejor no enviar que enviar a medias (guía §2.1).
        $error = 'VAPID_SUBJECT inválido: debe ser una URL https real o un mailto con dominio existente';
    } else {
        $resumen = Notificador::procesarCola($db, new PushSender());
        $resultado = array_merge($resultado, $resumen);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($esCli) {
    echo "Encolados:  {$resultado['encolados']}\n";
    echo "Enviados:   {$resultado['enviados']}\n";
    echo "Fallidos:   {$resultado['fallidos']}\n";
    echo "Expiradas:  {$resultado['expiradas']}\n";
    if ($error) {
        echo "ERROR: $error\n";
        exit(1);
    }
    exit(0);
}

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => $error] + $resultado);
    exit();
}

echo json_encode(['success' => true] + $resultado);
