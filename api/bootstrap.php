<?php

/**
 * Bootstrap de producción: carga la configuración (que además emite las
 * cabeceras CORS/JSON) y registra el autoload de lib/ y handlers/.
 *
 * Los tests NO usan este archivo; usan tests/bootstrap.php, que define las
 * constantes sin emitir cabeceras ni abrir conexiones.
 */

require_once __DIR__ . '/config.php';

// Dependencias de composer. Hasta que llegaron las notificaciones push nada
// del lado web las necesitaba, así que no estaban cargadas y PushSender no
// encontraba la clase WebPush.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JWT.php';
require_once __DIR__ . '/PageAccess.php';

require_once __DIR__ . '/lib/autoload.php';
