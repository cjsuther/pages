<?php

/**
 * Manda los envíos a clientes por ráfagas (#37).
 *
 *   Por cron, cada 5 minutos:  /opt/alt/php83/usr/bin/php /ruta/api/correos/procesar.php
 *
 * Sólo por línea de comandos. A diferencia del importador no tiene entrada
 * por web: pedirlo de más no tiene por qué apurar los envíos, y el .htaccess
 * de esta carpeta lo cierra igual.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Acceso denegado']);
    exit();
}

require_once __DIR__ . '/../bootstrap.php';

// Una ráfaga de 100 mails, con sus esperas, puede llevar unos minutos.
set_time_limit(0);

$database = new Database();
$db = $database->connect();

$resumen = ProcesadorDeEnvios::correr($db, new Mailer());

echo "Páginas:   {$resumen['paginas']}\n";
echo "Enviados:  {$resumen['enviados']}\n";
echo "Fallidos:  {$resumen['fallidos']}\n";
echo "Omitidos:  {$resumen['omitidos']}\n\n";

foreach ($resumen['detalle'] as $linea) {
    echo "  $linea\n";
}

exit(0);
