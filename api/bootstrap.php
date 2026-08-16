<?php

/**
 * Bootstrap de producción: carga la configuración (que además emite las
 * cabeceras CORS/JSON) y registra el autoload de lib/ y handlers/.
 *
 * Los tests NO usan este archivo; usan tests/bootstrap.php, que define las
 * constantes sin emitir cabeceras ni abrir conexiones.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JWT.php';
require_once __DIR__ . '/PageAccess.php';

require_once __DIR__ . '/lib/autoload.php';
