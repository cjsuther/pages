<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$clientId = GOOGLE_CLIENT_ID;
$redirectUri = GOOGLE_REDIRECT_URI;

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
  'client_id' => $clientId,
  'redirect_uri' => $redirectUri,
  'response_type' => 'code',
  'scope' => 'openid email profile',
  'access_type' => 'offline',
  'prompt' => 'consent'
]);

header('Location: ' . $authUrl);
exit;
