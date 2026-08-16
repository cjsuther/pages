<?php

/**
 * Bootstrap de los tests.
 *
 * Deliberadamente NO carga config.php: ese archivo emite cabeceras HTTP y
 * contiene credenciales reales. Aquí se definen las mismas constantes con
 * valores de test, de modo que la suite jamás pueda hablar con la base de
 * datos de producción.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// --- Base de datos: valores imposibles a propósito. Los tests unitarios usan
// --- FakePdo; si algún handler intentara conectarse de verdad, fallaría acá.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'rezonar_test');
define('DB_USER', 'rezonar_test');
define('DB_PASS', 'rezonar_test');

define('JWT_SECRET', 'test-secret-no-usar-en-produccion');
define('JWT_EXPIRATION', 86400);

define('FRONTEND_URL', 'https://frontend.test');
define('UPLOAD_URL', 'https://api.test');

define('GOOGLE_CLIENT_ID', 'test-google-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'test-google-client-secret');
define('GOOGLE_REDIRECT_URI', 'https://api.test/auth/google-callback.php');

define('APPLE_CLIENT_ID', 'com.test.serviceid');
define('APPLE_TEAM_ID', 'TESTTEAMID');
define('APPLE_KEY_ID', 'TESTKEYID');
define('APPLE_REDIRECT_URI', 'https://api.test/auth/apple-callback.php');

// Apple firma su client secret con ES256, así que hace falta una clave EC de
// verdad. Se genera al vuelo: la suite no depende de ningún archivo externo ni
// de la clave real de producción.
$clavePrueba = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
openssl_pkey_export($clavePrueba, $clavePruebaPem);
define('APPLE_PRIVATE_KEY', $clavePruebaPem);
unset($clavePrueba, $clavePruebaPem);

define('VAPID_PUBLIC_KEY', 'test-vapid-public');
define('VAPID_PRIVATE_KEY', 'test-vapid-private');
define('VAPID_SUBJECT', 'mailto:test@test.local');

define('PAYMENTS_ENCRYPTION_KEY', 'clave-de-prueba-para-cifrar-credenciales-de-cobro');

define('CRON_SECRET_KEY', 'test-cron-secret');

// Clases del dominio y autoload de lib/ + handlers/.
// Se usa realpath() para que las rutas queden canónicas y el reporte de
// cobertura pueda asociarlas con los archivos del proyecto.
require_once realpath(__DIR__ . '/../JWT.php');
require_once realpath(__DIR__ . '/../PageAccess.php');
require_once realpath(__DIR__ . '/../lib/autoload.php');

// Dobles de test
require_once __DIR__ . '/Support/FakePdo.php';
require_once __DIR__ . '/Support/FakeStatement.php';
require_once __DIR__ . '/Support/FakeHttpClient.php';
require_once __DIR__ . '/Support/FakePushSender.php';
require_once __DIR__ . '/Support/HandlerTestCase.php';
