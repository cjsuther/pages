<?php

namespace Tests\Unit\Lib;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use PushSender;
use PHPUnit\Framework\TestCase;

class PushSenderTest extends TestCase
{
    /**
     * El error más caro de la guía (§2.1): FCM acepta cualquier `sub` y APNs
     * responde 403 sin explicar por qué. El síntoma es que Android funciona,
     * se sale a producción y ningún iPhone recibe nada.
     *
     * @dataProvider subjectsValidos
     */
    public function testAceptaSubjectsValidos($subject)
    {
        $this->assertTrue(PushSender::subjectValido($subject));
    }

    public function subjectsValidos()
    {
        return [
            'https' => ['https://rezon.ar'],
            'https con ruta' => ['https://rezon.ar/push'],
            'https con subdominio' => ['https://api.rezon.ar'],
            'mailto con dominio real' => ['mailto:soporte@rezon.ar'],
        ];
    }

    /**
     * @dataProvider subjectsInvalidos
     */
    public function testRechazaSubjectsInvalidos($subject)
    {
        $this->assertFalse(PushSender::subjectValido($subject));
    }

    public function subjectsInvalidos()
    {
        return [
            'vacío' => [''],
            'null' => [null],
            'sin esquema' => ['rezon.ar'],
            'sólo un nombre' => ['miapp'],
            'http sin TLS' => ['http://rezon.ar'],
            'mailto sin dominio' => ['mailto:push'],
            'mailto local' => ['mailto:push@miapp.local'],
            'mailto localhost' => ['mailto:admin@localhost'],
            'dominio de ejemplo' => ['mailto:tu-email@ejemplo.com'],
            'example.com' => ['mailto:dev@example.com'],
            'dominio .test' => ['mailto:dev@rezon.test'],
        ];
    }

    public function testDisponibleExigeLibreriaYGmp()
    {
        // En el entorno de tests ambas están; el valor debe ser booleano y
        // coherente con lo que hay instalado.
        $esperado = class_exists('Minishlink\WebPush\WebPush') && extension_loaded('gmp');

        $this->assertSame($esperado, PushSender::disponible());
    }

    // ------------------------------------------------------ claveUtilizable

    /** Una P-256 sin comprimir: 65 bytes que arrancan con 0x04, en base64url. */
    private function claveValida($primerByte = "\x04", $largo = 64)
    {
        return rtrim(strtr(base64_encode($primerByte . str_repeat("\x2a", $largo)), '+/', '-_'), '=');
    }

    public function testAceptaUnaClaveConLaFormaCorrecta()
    {
        $this->assertTrue(PushSender::claveUtilizable($this->claveValida()));
    }

    /**
     * Es exactamente lo que rompió en producción: la librería valida el primer
     * byte recién al cifrar, y como cifra toda la tanda junta se llevaba puesta
     * la corrida entera del cron.
     */
    public function testRechazaUnaClaveQueNoArrancaConCeroCuatro()
    {
        $this->assertFalse(PushSender::claveUtilizable($this->claveValida("\x03")));
    }

    public function testRechazaUnaClaveDemasiadoCorta()
    {
        $this->assertFalse(PushSender::claveUtilizable($this->claveValida("\x04", 30)));
    }

    public function testRechazaUnaClaveDemasiadoLarga()
    {
        $this->assertFalse(PushSender::claveUtilizable($this->claveValida("\x04", 90)));
    }

    public function testRechazaTextoQueNoEsBase64()
    {
        $this->assertFalse(PushSender::claveUtilizable('esto no es una clave!!'));
    }

    public function testRechazaLoVacioYLoNulo()
    {
        $this->assertFalse(PushSender::claveUtilizable(''));
        $this->assertFalse(PushSender::claveUtilizable(null));
    }

    /** Los navegadores mandan base64url sin relleno; con "=" también sirve. */
    public function testAceptaBase64UrlConYSinRelleno()
    {
        $sinRelleno = $this->claveValida();

        $this->assertTrue(PushSender::claveUtilizable($sinRelleno));
        $this->assertTrue(PushSender::claveUtilizable($sinRelleno . '='));
    }

    /** La clave real que manda Chrome usa - y _ en lugar de + y /. */
    public function testAceptaElAlfabetoUrlSafe()
    {
        $bytes = "\x04" . str_repeat("\xfb\xff", 32);
        $urlSafe = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        $this->assertStringContainsString('_', $urlSafe, 'la clave de prueba debe ejercitar el alfabeto');
        $this->assertTrue(PushSender::claveUtilizable($urlSafe));
    }

    // ------------------------------------------------------ armado del envío

    /**
     * Arma un envío real con la librería, pero contra un handler de Guzzle que
     * no sale a la red, y verifica lo que recibiría el servicio de push: la
     * firma VAPID y el payload cifrado en aes128gcm (RFC 8291 / RFC 8292).
     */
    public function testArmaElEnvioConVapidYCifradoAes128gcm()
    {
        $vapid = VAPID::createVapidKeys();
        $dispositivo = $this->clavesDeDispositivo();
        $enviados = [];
        $pila = HandlerStack::create(new MockHandler([new Response(201)]));
        $pila->push(Middleware::history($enviados));

        $sender = new PushSender(
            ['subject' => 'https://rezon.ar', 'publicKey' => $vapid['publicKey'], 'privateKey' => $vapid['privateKey']],
            ['handler' => $pila]
        );
        $payload = ['title' => 'Función de hoy', 'url' => '/carcajada'];
        $sender->encolar([
            'endpoint'   => 'https://fcm.googleapis.com/fcm/send/abc123',
            'p256dh_key' => $this->base64Url($dispositivo['publica']),
            'auth_key'   => $this->base64Url($dispositivo['auth']),
        ], $payload);

        $resultados = $sender->enviar();

        $this->assertSame([[
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'exito'    => true,
            'expirada' => false,
            'motivo'   => null,
        ]], $resultados);
        $this->assertCount(1, $enviados);

        $request = $enviados[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertFalse($request->hasHeader('Crypto-Key'), 'aes128gcm no usa Crypto-Key');
        $this->assertFalse($request->hasHeader('Encryption'), 'aes128gcm no usa Encryption');

        // VAPID: "vapid t=<JWT>, k=<clave pública>" (RFC 8292).
        $this->assertMatchesRegularExpression('/^vapid t=([^,]+), k=(.+)$/', $request->getHeaderLine('Authorization'));
        preg_match('/^vapid t=([^,]+), k=(.+)$/', $request->getHeaderLine('Authorization'), $partes);
        $this->assertSame($vapid['publicKey'], $partes[2]);
        $this->assertJwtVapidValido($partes[1], $this->decodificarBase64Url($vapid['publicKey']));

        // El dispositivo puede descifrar el cuerpo y obtiene el JSON original.
        $texto = $this->descifrarAes128gcm((string) $request->getBody(), $dispositivo);
        $this->assertSame(json_encode($payload, JSON_UNESCAPED_UNICODE), $texto);
    }

    public function testInformaSuscripcionExpiradaSinCortarElEnvio()
    {
        $vapid = VAPID::createVapidKeys();
        $dispositivo = $this->clavesDeDispositivo();
        $pila = HandlerStack::create(new MockHandler([new Response(410, [], 'Gone'), new Response(201)]));

        $sender = new PushSender(
            ['subject' => 'https://rezon.ar', 'publicKey' => $vapid['publicKey'], 'privateKey' => $vapid['privateKey']],
            ['handler' => $pila]
        );
        foreach (['https://fcm.googleapis.com/fcm/send/vieja', 'https://web.push.apple.com/nueva'] as $endpoint) {
            $sender->encolar([
                'endpoint'   => $endpoint,
                'p256dh_key' => $this->base64Url($dispositivo['publica']),
                'auth_key'   => $this->base64Url($dispositivo['auth']),
            ], ['title' => 'x']);
        }

        $resultados = $sender->enviar();

        $this->assertSame('https://fcm.googleapis.com/fcm/send/vieja', $resultados[0]['endpoint']);
        $this->assertFalse($resultados[0]['exito']);
        $this->assertTrue($resultados[0]['expirada']);
        $this->assertNotSame('', $resultados[0]['motivo']);
        $this->assertSame('https://web.push.apple.com/nueva', $resultados[1]['endpoint']);
        $this->assertTrue($resultados[1]['exito']);
    }

    /** Par de claves P-256 y secreto de autenticación, como los genera el navegador. */
    private function clavesDeDispositivo()
    {
        $clave = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($clave)['ec'];

        return [
            'privada' => $clave,
            'publica' => "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT),
            'auth'    => random_bytes(16),
        ];
    }

    /** Descifra como lo haría el navegador (RFC 8291 §3.4 y RFC 8188 §2). */
    private function descifrarAes128gcm($cuerpo, array $dispositivo)
    {
        $salt = substr($cuerpo, 0, 16);
        $largoId = ord($cuerpo[20]);
        $this->assertSame(65, $largoId, 'el keyid es la clave pública efímera del servidor');
        $publicaServidor = substr($cuerpo, 21, $largoId);
        $cifrado = substr($cuerpo, 21 + $largoId);

        $secretoEcdh = openssl_pkey_derive($this->clavePublicaPem($publicaServidor), $dispositivo['privada']);
        $prkClave = hash_hmac('sha256', $secretoEcdh, $dispositivo['auth'], true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $dispositivo['publica'] . $publicaServidor . "\x01", $prkClave, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $plano = openssl_decrypt(substr($cifrado, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cifrado, -16));
        $this->assertNotFalse($plano, 'el cuerpo no se pudo descifrar');

        // Último registro: el contenido termina en 0x02 seguido de relleno con ceros.
        $plano = rtrim($plano, "\0");
        $this->assertSame("\x02", substr($plano, -1));

        return substr($plano, 0, -1);
    }

    private function assertJwtVapidValido($jwt, $publicaVapid)
    {
        list($cabecera, $datos, $firma) = explode('.', $jwt);

        $this->assertSame('ES256', json_decode($this->decodificarBase64Url($cabecera), true)['alg']);

        $claims = json_decode($this->decodificarBase64Url($datos), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame('https://rezon.ar', $claims['sub']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 86400, $claims['exp'], 'los servicios de push rechazan exp a más de 24 h');

        $this->assertSame(1, openssl_verify(
            "$cabecera.$datos",
            $this->firmaDer($this->decodificarBase64Url($firma)),
            $this->clavePublicaPem($publicaVapid),
            OPENSSL_ALGO_SHA256
        ), 'la firma del JWT no verifica con la clave pública VAPID');
    }

    /** JWS ES256 firma con r||s crudos; openssl_verify espera DER. */
    private function firmaDer($firma)
    {
        $entero = function ($bytes) {
            $bytes = ltrim($bytes, "\0");
            if ($bytes === '' || ord($bytes[0]) > 0x7f) {
                $bytes = "\0" . $bytes;
            }
            return "\x02" . chr(strlen($bytes)) . $bytes;
        };
        $secuencia = $entero(substr($firma, 0, 32)) . $entero(substr($firma, 32, 32));

        return "\x30" . chr(strlen($secuencia)) . $secuencia;
    }

    /** Clave P-256 sin comprimir (65 bytes) a PEM SubjectPublicKeyInfo. */
    private function clavePublicaPem($publica)
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $publica;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function base64Url($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decodificarBase64Url($texto)
    {
        return base64_decode(strtr($texto, '-_', '+/'));
    }
}
