<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../JWT.php';

$frontendUrl = FRONTEND_URL;

if (!isset($_GET['code'])) {
  $error = $_GET['error'] ?? 'No authorization code received';
  header('Location: ' . $frontendUrl . '/login?error=' . urlencode($error));
  exit;
}

$code = $_GET['code'];

$tokenUrl = 'https://oauth2.googleapis.com/token';
$postData = [
  'code' => $code,
  'client_id' => GOOGLE_CLIENT_ID,
  'client_secret' => GOOGLE_CLIENT_SECRET,
  'redirect_uri' => GOOGLE_REDIRECT_URI,
  'grant_type' => 'authorization_code'
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
if (!isset($tokenData['access_token'])) {
  header('Location: ' . $frontendUrl . '/login?error=no_access_token');
  exit;
}

$accessToken = $tokenData['access_token'];

$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
$userInfoResponse = curl_exec($ch);
curl_close($ch);

$userInfo = json_decode($userInfoResponse, true);

if (!isset($userInfo['email'])) {
  header('Location: ' . $frontendUrl . '/login?error=no_email');
  exit;
}

$database = new Database();
$db = $database->connect();

$stmt = $db->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ?');
$stmt->execute(['google', $userInfo['id']]);
$user = $stmt->fetch();

if (!$user) {
  $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
  $stmt->execute([$userInfo['email']]);
  $existingUser = $stmt->fetch();

  if ($existingUser) {
    $stmt = $db->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ?, name = ?, avatar_url = ? WHERE id = ?');
    $stmt->execute([
      'google',
      $userInfo['id'],
      $userInfo['name'] ?? null,
      $userInfo['picture'] ?? null,
      $existingUser['id']
    ]);
    $user = $existingUser;
  } else {
    $stmt = $db->prepare('INSERT INTO users (email, oauth_provider, oauth_id, name, avatar_url) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
      $userInfo['email'],
      'google',
      $userInfo['id'],
      $userInfo['name'] ?? null,
      $userInfo['picture'] ?? null
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
