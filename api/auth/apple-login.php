<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$clientId = APPLE_CLIENT_ID;
$redirectUri = APPLE_REDIRECT_URI;

$authUrl = 'https://appleid.apple.com/auth/authorize?' . http_build_query([
  'client_id' => $clientId,
  'redirect_uri' => $redirectUri,
  'response_type' => 'code',
  'response_mode' => 'form_post',
  'scope' => 'name email'
]);

header('Location: ' . $authUrl);
exit;
