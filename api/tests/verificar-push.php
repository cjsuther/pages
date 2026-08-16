<?php
/**
 * Verificación de integración del sistema de notificaciones push.
 *
 * Los tests unitarios usan un doble de PDO: comprueban la lógica, no que los
 * índices únicos existan y hagan su trabajo. Este script corre contra una base
 * MySQL de verdad y verifica justamente eso: que una notificación se genere
 * una sola vez y que un push se encole una sola vez por dispositivo.
 *
 *   mysql -u root -e "CREATE DATABASE rezonar_test"
 *   mysql -u root rezonar_test < esquema.sql
 *   mysql -u root rezonar_test < migration_push_notifications.sql
 *   php api/tests/verificar-push.php
 */

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$nombre = getenv('TEST_DB_NAME') ?: 'rezonar_test';
$usuario = getenv('TEST_DB_USER') ?: 'root';
$clave = getenv('TEST_DB_PASS') ?: '';

// Constantes mínimas para poder cargar las clases sin config.php.
define('VAPID_SUBJECT', 'https://rezon.ar');
define('VAPID_PUBLIC_KEY', 'clave-publica-de-prueba');
define('VAPID_PRIVATE_KEY', 'clave-privada-de-prueba');

require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/Support/FakePushSender.php';

$db = new PDO("mysql:host=$host;dbname=$nombre;charset=utf8mb4", $usuario, $clave, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$fallos = 0;
$sufijo = substr(md5(uniqid('', true)), 0, 8);

// Restos de corridas anteriores: sin esto los contadores globales mezclan
// datos viejos y el script deja de ser repetible.
$db->exec("DELETE FROM users WHERE email LIKE 'dueno-%@test.local'
            OR email LIKE 'seguidor-%@test.local' OR email = 'dedupe@test'");

function comprobar($descripcion, $esperado, $obtenido)
{
    global $fallos;

    if ($esperado === $obtenido) {
        echo "  ✅ $descripcion\n";
        return;
    }

    echo "  ❌ $descripcion — esperaba " . var_export($esperado, true)
        . ", obtuvo " . var_export($obtenido, true) . "\n";
    $fallos++;
}

// --------------------------------------------------------------- preparación

$db->prepare('INSERT INTO users (email, password) VALUES (?, ?)')
   ->execute(["dueno-$sufijo@test.local", 'x']);
$duenoId = (int) $db->lastInsertId();

$db->prepare('INSERT INTO users (email, password, location_latitude, location_longitude) VALUES (?, ?, ?, ?)')
   ->execute(["seguidor-$sufijo@test.local", 'x', -34.6037, -58.3816]);
$seguidorId = (int) $db->lastInsertId();

$db->prepare('INSERT INTO pages (user_id, title, url_slug) VALUES (?, ?, ?)')
   ->execute([$duenoId, 'Página de prueba', "prueba-$sufijo"]);
$pageId = (int) $db->lastInsertId();

$db->prepare('INSERT INTO link_groups (page_id, title, type) VALUES (?, ?, "eventos")')
   ->execute([$pageId, 'Agenda']);
$groupId = (int) $db->lastInsertId();

// El seguidor quiere todos los eventos; el dueño también sigue su propia página.
$db->prepare('INSERT INTO page_followers (user_id, page_id, notify_all_events, max_distance_km) VALUES (?, ?, 1, 50)')
   ->execute([$seguidorId, $pageId]);
$db->prepare('INSERT INTO page_followers (user_id, page_id, notify_all_events, max_distance_km) VALUES (?, ?, 1, 50)')
   ->execute([$duenoId, $pageId]);

$db->prepare('INSERT INTO links (group_id, url, text, event_date, event_latitude, event_longitude) VALUES (?, ?, ?, ?, ?, ?)')
   ->execute([$groupId, 'https://x', 'Evento de prueba', '2026-12-01', -34.6037, -58.3816]);
$linkId = (int) $db->lastInsertId();

echo "Notificaciones generadas una sola vez\n";

// ------------------------------------------------- una sola notificación

$primera = Notificador::avisarEventoNuevo($db, $linkId);
comprobar('el primer aviso crea la notificación del seguidor', 1, $primera);

// Se llama tres veces más: es lo que pasa si el alta del evento y el cron
// coinciden, o si el evento se edita dentro de las 24 horas.
$repeticiones = 0;
for ($i = 0; $i < 3; $i++) {
    $repeticiones += Notificador::avisarEventoNuevo($db, $linkId);
}
comprobar('llamarlo tres veces más no crea ninguna', 0, $repeticiones);

$stmt = $db->prepare('SELECT COUNT(*) c FROM notifications WHERE link_id = ? AND type = ?');
$stmt->execute([$linkId, Notificador::TIPO_EVENTO]);
comprobar('en total hay exactamente una notificación', 1, (int) $stmt->fetch()['c']);

$stmt = $db->prepare('SELECT user_id FROM notifications WHERE link_id = ?');
$stmt->execute([$linkId]);
comprobar('y es la del seguidor, no la del dueño', $seguidorId, (int) $stmt->fetch()['user_id']);

// El cron diario pasa por el mismo evento y tampoco duplica.
$resumen = NotificationsHandler::procesarEventosNuevos($db);
$stmt->execute([$linkId]);
$stmt2 = $db->prepare('SELECT COUNT(*) c FROM notifications WHERE link_id = ?');
$stmt2->execute([$linkId]);
comprobar('el cron diario tampoco duplica', 1, (int) $stmt2->fetch()['c']);

// ------------------------------------------------------ un solo envío push

echo "\nEnvíos encolados una sola vez por dispositivo\n";

$db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, platform) VALUES (?, ?, ?, ?, ?)')
   ->execute([$seguidorId, "https://push.test/$sufijo", 'clave-p', 'clave-a', 'Android']);

$encolados = Notificador::encolarPendientes($db);
comprobar('se encola al menos el envío del dispositivo', true, $encolados >= 1);

$deNuevo = Notificador::encolarPendientes($db) + Notificador::encolarPendientes($db);
comprobar('volver a encolar no agrega nada', 0, $deNuevo);

$stmt = $db->prepare('
    SELECT COUNT(*) c FROM push_deliveries d
    JOIN notifications n ON n.id = d.notification_id
    WHERE n.link_id = ?
');
$stmt->execute([$linkId]);
comprobar('en total hay exactamente un envío', 1, (int) $stmt->fetch()['c']);

// ------------------------------------------------------------ procesamiento

echo "\nProcesamiento de la cola\n";

$sender = new Tests\Support\FakePushSender();
$resultado = Notificador::procesarCola($db, $sender);

comprobar('se envía el pendiente', true, $resultado['enviados'] >= 1);
comprobar('el payload lleva el id de correlación para el ack', true, isset($sender->encolados[0]['payload']['id']));
comprobar('y la marca de tiempo para medir latencia', true, isset($sender->encolados[0]['payload']['enviadoEn']));

$stmt = $db->prepare("SELECT estado, envio_id FROM push_deliveries WHERE subscription_id IN (SELECT id FROM push_subscriptions WHERE endpoint = ?)");
$stmt->execute(["https://push.test/$sufijo"]);
$envio = $stmt->fetch();
comprobar('el envío queda marcado como enviado', 'enviado', $envio['estado']);

// Volver a procesar no reenvía: ya no está pendiente.
$otraVez = Notificador::procesarCola($db, new Tests\Support\FakePushSender());
comprobar('volver a procesar no reenvía', 0, $otraVez['total']);

// -------------------------------------------------------------------- ack

echo "\nConfirmación de entrega\n";

$req = new Request('POST', ['id' => $envio['envio_id'], 'recibidoEn' => 1760000002300, 'latenciaMs' => 2300]);
PushHandler::ack($db, $req);

$stmt->execute(["https://push.test/$sufijo"]);
$tras = $db->prepare('SELECT estado, latencia_ms FROM push_deliveries WHERE envio_id = ?');
$tras->execute([$envio['envio_id']]);
$fila = $tras->fetch();

comprobar('el ack marca la entrega como confirmada', 'confirmado', $fila['estado']);
comprobar('y registra la latencia', 2300, (int) $fila['latencia_ms']);

// Un ack repetido no debe pisar nada.
PushHandler::ack($db, new Request('POST', ['id' => $envio['envio_id'], 'latenciaMs' => 99999]));
$tras->execute([$envio['envio_id']]);
comprobar('un ack repetido no pisa la latencia', 2300, (int) $tras->fetch()['latencia_ms']);

// ------------------------------------------------------------- limpieza

$db->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$duenoId, $seguidorId]);

echo "\n";
if ($fallos === 0) {
    echo "✅ Verificación completa: todo correcto\n";
    exit(0);
}

echo "❌ $fallos comprobación(es) fallaron\n";
exit(1);
