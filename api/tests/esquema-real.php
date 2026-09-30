<?php

/**
 * Imprime el esquema de la base de producción como {"tabla": ["columna", ...]}.
 *
 * No se despliega: deploy.sh lo manda por ssh y lo ejecuta en el servidor con
 * la config.php de allá, que es la única que apunta a la base real.
 *
 *   php -- /ruta/a/public_html < tests/esquema-real.php
 */

error_reporting(0);

$raiz = $argv[1] ?? '';

require $raiz . '/api/config.php';
require $raiz . '/api/Database.php';

$db = (new Database())->connect();

if (!$db) {
    fwrite(STDERR, "no se pudo conectar a la base\n");
    exit(1);
}

$esquema = [];
$filas = $db->query('
    SELECT TABLE_NAME AS t, COLUMN_NAME AS c
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
')->fetchAll(PDO::FETCH_ASSOC);

foreach ($filas as $fila) {
    $esquema[$fila['t']][] = $fila['c'];
}

echo json_encode($esquema);
