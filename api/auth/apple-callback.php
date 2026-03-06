<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../JWT.php';
require_once __DIR__ . '/AppleJWT.php';

$frontendUrl = FRONTEND_URL;

if (!isset($_POST['code'])) {
  $error = $_POST['error'] ?? 'No authorization code received';
  header('Location: ' . $frontendUrl . '/login?error=' . urlencode($error));
  exit;
}

$code = $_POST['code'];

$clientSecret = AppleJWT::generateClientSecret(
  APPLE_TEAM_ID,
  APPLE_CLIENT_ID,
  APPLE_KEY_ID,
  APPLE_PRIVATE_KEY
);

$tokenUrl = 'https://appleid.apple.com/auth/token';
$postData = [
  'client_id' => APPLE_CLIENT_ID,
  'client_secret' => $clientSecret,
  'code' => $code,
  'grant_type' => 'authorization_code',
  'redirect_uri' => APPLE_REDIRECT_URI
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
  header('Location: ' . $frontendUrl . '/login?error=token_exchange_failed');
  exit;
}

$tokenData = json_decode($response, true);
if (!isset($tokenData['id_token'])) {
  header('Location: ' . $frontendUrl . '/login?error=no_id_token');
  exit;
}

$idToken = $tokenData['id_token'];
$parts = explode('.', $idToken);
if (count($parts) !== 3) {
  header('Location: ' . $frontendUrl . '/login?error=invalid_token');
  exit;
}

$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

if (!isset($payload['email'])) {
  header('Location: ' . $frontendUrl . '/login?error=no_email');
  exit;
}

$userName = null;
if (isset($_POST['user'])) {
  $userObject = json_decode($_POST['user'], true);
  if (isset($userObject['name'])) {
    $name = $userObject['name'];
    $userName = trim(($name['firstName'] ?? '') . ' ' . ($name['lastName'] ?? ''));
  }
}

$database = new Database();
$db = $database->connect();

$stmt = $db->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ?');
$stmt->execute(['apple', $payload['sub']]);
$user = $stmt->fetch();

if (!$user) {
  $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
  $stmt->execute([$payload['email']]);
  $existingUser = $stmt->fetch();

  if ($existingUser) {
    $stmt = $db->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ?, name = ? WHERE id = ?');
    $stmt->execute([
      'apple',
      $payload['sub'],
      $userName ?? $existingUser['name'],
      $existingUser['id']
    ]);
    $user = $existingUser;
  } else {
    $stmt = $db->prepare('INSERT INTO users (email, oauth_provider, oauth_id, name) VALUES (?, ?, ?, ?)');
    $stmt->execute([
      $payload['email'],
      'apple',
      $payload['sub'],
      $userName
    ]);
    $userId = $db->lastInsertId();

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
  }
}

$token = JWT::encode(['user_id' => $user['id'], 'email' => $user['email']], JWT_SECRET);

$userData = [
  'id' => $user['id'],
  'email' => $user['email'],
  'name' => $user['name'],
  'avatar_url' => $user['avatar_url']
];

$redirectUrl = $frontendUrl . '/login?token=' . $token . '&user=' . urlencode(json_encode($userData));
header('Location: ' . $redirectUrl);
exit;
