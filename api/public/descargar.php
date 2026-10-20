<?php

// El archivo de un producto comprado. Lo pide un <form method="post"> con el
// token como campo: nunca va en la URL.

require_once __DIR__ . '/../bootstrap.php';

Api::run(['CompraProductoHandler', 'archivo']);
