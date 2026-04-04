<?php
session_set_cookie_params([
    'lifetime' => 86400,
    'domain'   => '.rezon.ar',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../JWT.php';

$body     = json_decode(file_get_contents('php://input'), true);
$token    = $body['token'] ?? null;
$redirect = $body['redirect'] ?? null;

$ALLOWED_REDIRECTS = ['entradas.rezon.ar'];

if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_token']);
    exit;
}

if (!$redirect || !in_array($redirect, $ALLOWED_REDIRECTS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_redirect']);
    exit;
}

// Validar que el token sea un JWT válido de rezon.ar antes de almacenarlo
$payload = JWT::decode($token, JWT_SECRET);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

// Generar UUID v4
$uuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

if (!isset($_SESSION['sso_codes'])) {
    $_SESSION['sso_codes'] = [];
}

$_SESSION['sso_codes'][$uuid] = [
    'token'   => $token,
    'expires' => time() + 60
];

header('Location: https://entradas.rezon.ar/auth/callback.php?code=' . $uuid);
exit;
