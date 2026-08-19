<?php

/**
 * Borra las imágenes subidas que ya no usa ningún evento, página ni perfil.
 *
 *   Por cron:  /opt/alt/php83/usr/bin/php /ruta/api/mantenimiento/limpiar-imagenes.php
 *   Simular:   ... limpiar-imagenes.php --simular
 *   Por web:   /api/mantenimiento/limpiar-imagenes.php?cron_key=<CRON_SECRET_KEY>
 *              (agregando &simular=1 para ver qué haría)
 *
 * Se puede correr en seco con --simular, y conviene hacerlo la primera vez:
 * lista lo que borraría sin tocar nada.
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

$simular = $esCli
    ? in_array('--simular', $argv, true)
    : !empty($_GET['simular']);

$directorio = dirname(__DIR__) . '/uploads/';

$database = new Database();
$db = $database->connect();

/** Lo que hay en el disco, con su fecha de modificación. */
$listar = function () use ($directorio) {
    $archivos = [];

    foreach (scandir($directorio) ?: [] as $nombre) {
        $ruta = $directorio . $nombre;

        if (is_file($ruta)) {
            $archivos[$nombre] = filemtime($ruta);
        }
    }

    return $archivos;
};

$huerfanas = ImagenesHuerfanas::huerfanas($db, $listar);

$borradas = 0;
$liberado = 0;

foreach ($huerfanas as $nombre) {
    $ruta = $directorio . $nombre;
    $tamano = is_file($ruta) ? filesize($ruta) : 0;

    if ($simular) {
        $borradas++;
        $liberado += $tamano;
        continue;
    }

    if (@unlink($ruta)) {
        $borradas++;
        $liberado += $tamano;
    }
}

$resumen = [
    'simulacion' => $simular,
    'sin_usar'   => count($huerfanas),
    'borradas'   => $borradas,
    'liberado_mb' => round($liberado / 1048576, 2),
];

if (!$esCli) {
    echo json_encode(['success' => true] + $resumen);
    exit();
}

echo $simular ? "SIMULACIÓN: no se borró nada\n" : "Limpieza de imágenes\n";
echo "  sin usar:  {$resumen['sin_usar']}\n";
echo "  borradas:  {$resumen['borradas']}\n";
echo "  liberado:  {$resumen['liberado_mb']} MB\n";

if ($simular && !empty($huerfanas)) {
    echo "\n  Se borrarían:\n";

    foreach (array_slice($huerfanas, 0, 20) as $nombre) {
        echo "    $nombre\n";
    }

    if (count($huerfanas) > 20) {
        echo '    ... y ' . (count($huerfanas) - 20) . " más\n";
    }
}
