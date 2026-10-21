<?php

/**
 * Corre frontend/public/index.php como si llegara un pedido a la dirección
 * del primer argumento. La API a la que consulta sale de API_URL.
 */

$_SERVER['HTTP_HOST'] = 'rezon.ar';
$_SERVER['REQUEST_URI'] = $argv[1];

require __DIR__ . '/../../../../frontend/public/index.php';
