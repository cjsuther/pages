<?php

class AppleJWT {
  public static function generateClientSecret($teamId, $clientId, $keyId, $privateKey) {
    $header = [
      'alg' => 'ES256',
      'kid' => $keyId
    ];

    $payload = [
      'iss' => $teamId,
      'iat' => time(),
      'exp' => time() + 86400 * 180,
      'aud' => 'https://appleid.apple.com',
      'sub' => $clientId
    ];

    $headerEncoded = self::base64UrlEncode(json_encode($header));
    $payloadEncoded = self::base64UrlEncode(json_encode($payload));

    $signature = '';
    $dataToSign = $headerEncoded . '.' . $payloadEncoded;

    $privateKeyResource = openssl_pkey_get_private($privateKey);
    if (!$privateKeyResource) {
      throw new Exception('Invalid private key');
    }

    openssl_sign($dataToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
    $signatureEncoded = self::base64UrlEncode($signature);

    return $dataToSign . '.' . $signatureEncoded;
  }

  private static function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }
}
