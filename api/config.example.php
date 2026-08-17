<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'root');
define('DB_PASS', '');
// Este valor es público: está en el repositorio. Con él, cualquiera puede
// firmar un token válido para cualquier usuario. `composer install` genera uno
// aleatorio al crear config.php; si copiás este archivo a mano, reemplazalo:
//     php -r "echo bin2hex(random_bytes(32));"
define('JWT_SECRET', 'CAMBIA_ESTO_POR_UNA_CLAVE_SEGURA_ALEATORIA');
define('JWT_EXPIRATION', 86400);

define('FRONTEND_URL', 'http://localhost:5173');
define('UPLOAD_URL', 'http://localhost:8000');

define('GOOGLE_CLIENT_ID', 'TU_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'TU_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/google-callback.php');

define('APPLE_CLIENT_ID', 'com.tudominio.serviceid');
define('APPLE_TEAM_ID', 'TU_TEAM_ID');
define('APPLE_KEY_ID', 'TU_KEY_ID');
define('APPLE_REDIRECT_URI', 'http://localhost:8000/auth/apple-callback.php');
define('APPLE_PRIVATE_KEY', <<<EOD
-----BEGIN PRIVATE KEY-----
TU_CLAVE_PRIVADA_AQUI
-----END PRIVATE KEY-----
EOD
);

define('VAPID_PUBLIC_KEY', 'TU_CLAVE_PUBLICA_VAPID_BASE64');
define('VAPID_PRIVATE_KEY', 'TU_CLAVE_PRIVADA_VAPID_BASE64');
define('VAPID_SUBJECT', 'mailto:tu-email@ejemplo.com');

define('CRON_SECRET_KEY', 'CAMBIA_ESTO_POR_UNA_CLAVE_SEGURA_PARA_CRON');

// Cifra las credenciales de cobro de terceros (Mercado Pago) guardadas en la
// base. Si se cambia, las credenciales ya guardadas dejan de poder leerse y
// cada dueño tiene que volver a cargarlas.
define('PAYMENTS_ENCRYPTION_KEY', 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA');

// --------------------------------------------------------------------- Cobros
// Porcentaje que la plataforma se queda de cada venta de entradas. Mercado Pago
// lo descuenta solo, en la misma operación: el comprador paga una vez y el
// reparto lo hace Mercado Pago.
//
// Sólo se aplica a las páginas que conectaron su cuenta por OAuth. Con un
// access token pegado a mano la comisión se ignora, así que el alta manual
// quedó deshabilitada.
define('PLATFORM_FEE_PERCENT', 3);

// Aplicación de marketplace, desde Mercado Pago -> Tus integraciones. Es la
// cuenta a la que llega la comisión.
define('MP_APP_ID', 'TU_CLIENT_ID_DE_MERCADO_PAGO');
define('MP_APP_SECRET', 'TU_CLIENT_SECRET_DE_MERCADO_PAGO');
define('MP_OAUTH_REDIRECT_URI', 'https://tu-dominio.com/api/entradas/oauth-callback.php');

// En CLI (el cron) no hay request HTTP: ni las cabeceras ni el preflight
// tienen sentido, y tocar REQUEST_METHOD ahí sólo genera warnings.
if (php_sapi_name() !== 'cli') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
