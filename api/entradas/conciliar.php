<?php

/**
 * Repasa las reservas sin pago registrado y le pregunta a Mercado Pago si
 * alguna se pagó.
 *
 * Existe porque el aviso de pago es un mensaje, y un mensaje puede no llegar.
 * Cuando eso pasa nadie se entera: la orden vence sola, el comprador ve
 * "vencida" con la plata ya cobrada y en la base no queda rastro del intento.
 * Esto va y pregunta en vez de esperar.
 *
 *   Por cron:  /opt/alt/php83/usr/bin/php /ruta/api/entradas/conciliar.php
 *   Por web:   /api/entradas/conciliar.php?cron_key=<CRON_SECRET_KEY>
 *
 * Es seguro que se solape con el aviso o con otra corrida: acreditarPago sólo
 * mueve órdenes que sigan en reservada, así que la segunda no hace nada.
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

$db = (new Database())->connect();

$codigos = Entradas::sinConciliar($db);

$acreditadas = [];
$revisadas = 0;

foreach ($codigos as $codigo) {
    $r = CheckoutHandler::conciliar($db, $codigo);

    if ($r['revisada']) {
        $revisadas++;
    }

    if ($r['acreditada']) {
        $acreditadas[] = $codigo;
    }
}

// Segunda pasada: ventas ya acreditadas a las que les falta el desglose de
// comisiones. No mueve estados, sólo completa números que no teníamos.
$completadas = 0;

foreach (Entradas::pagadasSinDesglose($db) as $venta) {
    if (CheckoutHandler::completarDesglose($db, $venta['codigo'], $venta['mp_payment_id'])) {
        $completadas++;
    }
}

$resumen = [
    'candidatas'  => count($codigos),
    'revisadas'   => $revisadas,
    'acreditadas' => $acreditadas,
    'desgloses'   => $completadas,
];

if ($esCli) {
    echo date('Y-m-d H:i:s') . ' conciliar: '
        . $resumen['candidatas'] . ' candidatas, '
        . $resumen['revisadas'] . ' revisadas, '
        . count($acreditadas) . ' acreditadas, '
        . $completadas . ' desgloses'
        . ($acreditadas ? ' (' . implode(', ', $acreditadas) . ')' : '')
        . PHP_EOL;
    exit(0);
}

echo json_encode($resumen);
