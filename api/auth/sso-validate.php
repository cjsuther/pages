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
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../JWT.php';

// Verificar que el caller sea entradas.rezon.ar
$headers   = getallheaders();
$ssoSecret = $headers['X-Sso-Secret'] ?? $headers['x-sso-secret'] ?? null;
$referer   = $_SERVER['HTTP_REFERER'] ?? '';

$isAuthorized = ($ssoSecret === SSO_SECRET)
    || (strpos($referer, 'entradas.rezon.ar') !== false);

if (!$isAuthorized) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$code = $_GET['code'] ?? null;

if (!$code) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_code']);
    exit;
}

if (!isset($_SESSION['sso_codes'][$code])) {
    http_response_code(404);
    echo json_encode(['error' => 'invalid_code']);
    exit;
}

$entry = $_SESSION['sso_codes'][$code];

if ($entry['expires'] < time()) {
    unset($_SESSION['sso_codes'][$code]);
    http_response_code(410);
    echo json_encode(['error' => 'expired_code']);
    exit;
}

$token = $entry['token'];
unset($_SESSION['sso_codes'][$code]); // one-time use

// Decodificar el JWT para obtener user_id
$payload = JWT::decode($token, JWT_SECRET);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

// Obtener datos del usuario desde la DB
$database = new Database();
$db       = $database->connect();

$stmt = $db->prepare('SELECT email, name FROM users WHERE id = ?');
$stmt->execute([$payload['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'user_not_found']);
    exit;
}

http_response_code(200);
echo json_encode([
    'token' => $token,
    'email' => $user['email'],
    'name'  => $user['name']
]);
