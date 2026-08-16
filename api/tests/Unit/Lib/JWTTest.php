<?php

namespace Tests\Unit\Lib;

use JWT;
use PHPUnit\Framework\TestCase;

/**
 * JWT es la pieza más sensible de la API: si se puede falsificar un token,
 * se puede suplantar a cualquier usuario. Estos tests fijan tanto el
 * comportamiento normal como las propiedades de seguridad.
 */
class JWTTest extends TestCase
{
    const SECRET = 'test-secret-no-usar-en-produccion';

    // ------------------------------------------------------------ ida y vuelta

    public function testEncodeDecodeDevuelveElPayloadOriginal()
    {
        $token = JWT::encode(['user_id' => 7, 'email' => 'a@b.com'], self::SECRET);
        $payload = JWT::decode($token, self::SECRET);

        $this->assertIsArray($payload);
        $this->assertSame(7, $payload['user_id']);
        $this->assertSame('a@b.com', $payload['email']);
    }

    public function testEncodeAgregaExpiracion()
    {
        $antes = time();
        $token = JWT::encode(['user_id' => 1], self::SECRET);
        $payload = JWT::decode($token, self::SECRET);

        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThanOrEqual($antes + JWT_EXPIRATION, $payload['exp']);
        $this->assertLessThanOrEqual(time() + JWT_EXPIRATION, $payload['exp']);
    }

    public function testTokenTieneTresPartes()
    {
        $token = JWT::encode(['user_id' => 1], self::SECRET);
        $this->assertCount(3, explode('.', $token));
    }

    public function testUsaBase64UrlSinRelleno()
    {
        $token = JWT::encode(['user_id' => 1, 'email' => 'con+simbolos/raros@test.com'], self::SECRET);

        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('=', $token);
    }

    public function testHeaderDeclaraHs256()
    {
        $token = JWT::encode(['user_id' => 1], self::SECRET);
        $header = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);

        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
    }

    // ------------------------------------------------------------- seguridad

    public function testRechazaFirmaAlterada()
    {
        $token = JWT::encode(['user_id' => 1], self::SECRET);
        $partes = explode('.', $token);
        $partes[2] = strrev($partes[2]);

        $this->assertFalse(JWT::decode(implode('.', $partes), self::SECRET));
    }

    public function testRechazaPayloadAlterado()
    {
        $token = JWT::encode(['user_id' => 1], self::SECRET);
        $partes = explode('.', $token);

        // Un atacante intenta escalar a user_id 999 conservando la firma original.
        $payloadFalso = json_encode(['user_id' => 999, 'exp' => time() + 3600]);
        $partes[1] = rtrim(strtr(base64_encode($payloadFalso), '+/', '-_'), '=');

        $this->assertFalse(JWT::decode(implode('.', $partes), self::SECRET));
    }

    public function testRechazaSecretoDistinto()
    {
        $token = JWT::encode(['user_id' => 1], self::SECRET);

        $this->assertFalse(JWT::decode($token, 'otro-secreto'));
    }

    public function testRechazaTokenExpirado()
    {
        // Se firma a mano un token con exp en el pasado: encode() siempre pone exp futuro.
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['user_id' => 1, 'exp' => time() - 1])), '+/', '-_'), '=');
        $firma = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true)), '+/', '-_'), '=');

        $this->assertFalse(JWT::decode($header . '.' . $payload . '.' . $firma, self::SECRET));
    }

    public function testAceptaTokenSinExpiracion()
    {
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['user_id' => 5])), '+/', '-_'), '=');
        $firma = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true)), '+/', '-_'), '=');

        $decodificado = JWT::decode($header . '.' . $payload . '.' . $firma, self::SECRET);

        $this->assertSame(5, $decodificado['user_id']);
    }

    public function testIgnoraElAlgoritmoDeclaradoEnElHeader()
    {
        // Ataque clásico "alg: none": el verificador siempre debe usar HS256,
        // sin importar lo que diga el header del token.
        $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['user_id' => 999, 'exp' => time() + 3600])), '+/', '-_'), '=');

        $this->assertFalse(JWT::decode($header . '.' . $payload . '.', self::SECRET));
    }

    /**
     * @dataProvider tokensMalformados
     */
    public function testRechazaTokensMalformados($token)
    {
        $this->assertFalse(JWT::decode($token, self::SECRET));
    }

    public function tokensMalformados()
    {
        return [
            'vacío' => [''],
            'sin puntos' => ['abcdef'],
            'dos partes' => ['abc.def'],
            'cuatro partes' => ['a.b.c.d'],
            'sólo puntos' => ['..'],
        ];
    }

    // --------------------------------------------------------- desde cabeceras

    public function testExtraeUsuarioDeCabeceraBearer()
    {
        $token = JWT::encode(['user_id' => 3, 'email' => 'x@y.com'], JWT_SECRET);

        $usuario = JWT::getUserFromHeaders(['Authorization' => 'Bearer ' . $token]);

        $this->assertSame(3, $usuario['user_id']);
    }

    public function testAceptaCabeceraEnMinusculas()
    {
        $token = JWT::encode(['user_id' => 3], JWT_SECRET);

        $usuario = JWT::getUserFromHeaders(['authorization' => 'Bearer ' . $token]);

        $this->assertSame(3, $usuario['user_id']);
    }

    /**
     * @dataProvider cabecerasInvalidas
     */
    public function testDevuelveNullSinCabeceraValida($headers)
    {
        $this->assertNull(JWT::getUserFromHeaders($headers));
    }

    public function cabecerasInvalidas()
    {
        return [
            'sin cabeceras' => [[]],
            'no es array' => [null],
            'sin prefijo Bearer' => [['Authorization' => 'abc.def.ghi']],
            'Bearer vacío' => [['Authorization' => 'Bearer ']],
            'esquema Basic' => [['Authorization' => 'Basic dXNlcjpwYXNz']],
            'otra cabecera' => [['X-Token' => 'Bearer abc']],
        ];
    }

    public function testDevuelveNullSiElTokenDeLaCabeceraEsInvalido()
    {
        $tokenAjeno = JWT::encode(['user_id' => 1], 'secreto-de-otro-sistema');

        $this->assertNull(JWT::getUserFromHeaders(['Authorization' => 'Bearer ' . $tokenAjeno]));
    }

    /**
     * encode() pisaba el exp que le pasaran, así que un token pensado para
     * durar minutos —el estado del OAuth de Mercado Pago— duraba un día entero.
     */
    public function testSeRespetaUnVencimientoExplicito()
    {
        $token = JWT::encode(['user_id' => 7, 'exp' => time() + 60], JWT_SECRET);
        $datos = JWT::decode($token, JWT_SECRET);

        $this->assertLessThan(time() + 120, $datos['exp'], 'no puede durar más de lo pedido');
    }

    public function testUnTokenConVencimientoPasadoNoSeAcepta()
    {
        $token = JWT::encode(['user_id' => 7, 'exp' => time() - 10], JWT_SECRET);

        $this->assertFalse(JWT::decode($token, JWT_SECRET));
    }

    /** Los tokens de sesión no traen exp y siguen usando el general. */
    public function testSinVencimientoExplicitoSeUsaElDeLaConfiguracion()
    {
        $token = JWT::encode(['user_id' => 7], JWT_SECRET);
        $datos = JWT::decode($token, JWT_SECRET);

        $this->assertEqualsWithDelta(time() + JWT_EXPIRATION, $datos['exp'], 5);
    }

}
