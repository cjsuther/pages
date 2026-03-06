<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'root');
define('DB_PASS', '');
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

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if (!$_SERVER && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
