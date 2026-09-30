<?php

/**
 * El esquema de la base según el repositorio.
 *
 *   php tests/esquema.php generar
 *       Arma la base desde database.sql y las migraciones (en una MariaDB
 *       descartable) y guarda el resultado en tests/esquema-esperado.json.
 *       Hay que correrlo después de agregar una migración.
 *
 *   php tests/esquema.php faltantes < real.json
 *       Recibe el esquema de una base real ({"tabla": ["columna", ...]}) y
 *       dice qué le falta respecto del esperado, agrupado por migración. Sale
 *       con 1 si falta algo. Es lo que usa deploy.sh contra producción.
 */

require_once __DIR__ . '/Support/BaseDescartable.php';
require_once __DIR__ . '/Support/Esquema.php';

use Tests\Support\BaseDescartable;
use Tests\Support\Esquema;

$archivo = __DIR__ . '/esquema-esperado.json';
$accion = $argv[1] ?? '';

if ($accion === 'generar') {
    $esquema = Esquema::aplicar(BaseDescartable::baseNueva('rz_esquema'));
    file_put_contents($archivo, json_encode($esquema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo 'tests/esquema-esperado.json: ' . count($esquema) . " tablas\n";
    exit(0);
}

if ($accion === 'faltantes') {
    $esperado = json_decode(file_get_contents($archivo), true);
    $real = json_decode(stream_get_contents(STDIN), true);

    if (!is_array($esperado) || !is_array($real) || $real === []) {
        fwrite(STDERR, "no se pudo leer el esquema esperado o el real\n");
        exit(2);
    }

    $faltan = Esquema::faltantes($esperado, $real);

    foreach ($faltan as $migracion => $cosas) {
        echo "$migracion\n";
        foreach ($cosas as $cosa) {
            echo "    falta $cosa\n";
        }
    }

    exit($faltan === [] ? 0 : 1);
}

fwrite(STDERR, "uso: php tests/esquema.php generar | faltantes < real.json\n");
exit(2);
