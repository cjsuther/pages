<?php

namespace Tests\Unit\Handlers;

use FileStorage;
use Request;
use Tests\Support\HandlerTestCase;
use UploadHandler;

class UploadHandlerTest extends HandlerTestCase
{
    public function testExigeSesion()
    {
        $res = UploadHandler::image($this->db, $this->peticion(null, $this->archivo()), $this->storage());

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testRechazaMetodosDistintosDePost()
    {
        $req = new Request('GET', [], [], $this->user(), ['image' => $this->archivo()]);

        $this->assertError(405, UploadHandler::image($this->db, $req, $this->storage()), 'Method not allowed');
    }

    public function testExigeUnArchivo()
    {
        $req = new Request('POST', [], [], $this->user(), []);

        $this->assertError(400, UploadHandler::image($this->db, $req, $this->storage()), 'No image file provided');
    }

    /**
     * @dataProvider tiposNoPermitidos
     */
    public function testRechazaTiposNoPermitidos($tipo)
    {
        $req = $this->peticion($this->user(), $this->archivo(['type' => $tipo]));

        $res = UploadHandler::image($this->db, $req, $this->storage());

        $this->assertError(400, $res, 'Invalid file type');
    }

    public function tiposNoPermitidos()
    {
        return [
            'php' => ['application/x-httpd-php'],
            'texto' => ['text/plain'],
            'svg' => ['image/svg+xml'],
            'pdf' => ['application/pdf'],
            'vacío' => [''],
        ];
    }

    public function testRechazaArchivosDemasiadoGrandes()
    {
        $req = $this->peticion($this->user(), $this->archivo(['size' => UploadHandler::MAX_BYTES + 1]));

        $res = UploadHandler::image($this->db, $req, $this->storage());

        $this->assertError(400, $res, 'File size too large. Maximum 5MB allowed');
    }

    public function testAceptaArchivoDeExactamenteElMaximo()
    {
        $req = $this->peticion($this->user(), $this->archivo(['size' => UploadHandler::MAX_BYTES]));

        $res = UploadHandler::image($this->db, $req, $this->storage());

        $this->assertStatus(200, $res);
    }

    public function testRechazaArchivoQueNoEsImagenAunqueDigaSerlo()
    {
        // El content-type lo controla el cliente: la verdad la da getimagesize.
        $storage = $this->storage(['info' => false]);

        $res = UploadHandler::image($this->db, $this->peticion($this->user(), $this->archivo()), $storage);

        $this->assertError(400, $res, 'File is not a valid image');
    }

    public function testGuardaLaImagenYDevuelveLaUrl()
    {
        $storage = $this->storage();

        $res = UploadHandler::image($this->db, $this->peticion($this->user(), $this->archivo()), $storage);

        $this->assertStatus(200, $res);
        $this->assertStringStartsWith(UPLOAD_URL . '/uploads/', $res->body['url']);
        $this->assertStringEndsWith('.jpg', $res->body['url']);
    }

    public function testDevuelve500SiFallaElMovimiento()
    {
        $storage = $this->storage(['move' => false]);

        $res = UploadHandler::image($this->db, $this->peticion($this->user(), $this->archivo()), $storage);

        $this->assertError(500, $res, 'Failed to upload file');
    }

    // =========================================== extensión derivada del contenido

    /**
     * Un archivo llamado "shell.php" con contenido de imagen real debe
     * guardarse como .jpg. En el código original se guardaba como .php dentro
     * de un directorio servido por Apache: ejecución remota de código.
     */
    public function testNoGuardaConLaExtensionDelNombreEnviado()
    {
        $storage = $this->storage();

        $res = UploadHandler::image(
            $this->db,
            $this->peticion($this->user(), $this->archivo(['name' => 'shell.php'])),
            $storage
        );

        $this->assertStatus(200, $res);
        $this->assertStringEndsWith('.jpg', $res->body['url']);
        $this->assertStringNotContainsString('.php', $res->body['url']);
        $this->assertStringEndsWith('.jpg', $storage->destino);
    }

    /**
     * @dataProvider nombresPeligrosos
     */
    public function testIgnoraNombresDeArchivoMaliciosos($nombre)
    {
        $storage = $this->storage();

        $res = UploadHandler::image(
            $this->db,
            $this->peticion($this->user(), $this->archivo(['name' => $nombre])),
            $storage
        );

        $this->assertStatus(200, $res);
        $this->assertStringEndsWith('.jpg', $storage->destino);
        $this->assertStringNotContainsString('..', $storage->destino);
    }

    public function nombresPeligrosos()
    {
        return [
            'php' => ['shell.php'],
            'phtml' => ['shell.phtml'],
            'doble extensión' => ['foto.jpg.php'],
            'path traversal' => ['../../../../etc/passwd'],
            'htaccess' => ['.htaccess'],
            'sin extensión' => ['archivo'],
        ];
    }

    /**
     * @dataProvider tiposDetectados
     */
    public function testLaExtensionSaleDelTipoDetectado($imageType, $esperado)
    {
        $this->assertSame($esperado, UploadHandler::extensionSegura([100, 100, $imageType]));
    }

    public function tiposDetectados()
    {
        return [
            'jpeg' => [IMAGETYPE_JPEG, 'jpg'],
            'png' => [IMAGETYPE_PNG, 'png'],
            'gif' => [IMAGETYPE_GIF, 'gif'],
            'webp' => [IMAGETYPE_WEBP, 'webp'],
            'bmp no permitido' => [IMAGETYPE_BMP, null],
            'tiff no permitido' => [IMAGETYPE_TIFF_II, null],
            'sin tipo' => [null, null],
        ];
    }

    public function testRechazaImagenDeFormatoNoSoportado()
    {
        // Un BMP pasa getimagesize pero no está en la lista de formatos.
        $storage = $this->storage(['info' => [100, 100, IMAGETYPE_BMP]]);

        $res = UploadHandler::image($this->db, $this->peticion($this->user(), $this->archivo()), $storage);

        $this->assertError(400, $res, 'Invalid file type');
    }

    public function testElNombreGuardadoEsUnico()
    {
        $primero = $this->storage();
        $segundo = $this->storage();

        UploadHandler::image($this->db, $this->peticion($this->user(), $this->archivo()), $primero);
        UploadHandler::image($this->db, $this->peticion($this->user(), $this->archivo()), $segundo);

        $this->assertNotSame($primero->destino, $segundo->destino);
    }

    // ------------------------------------------------------------- ayudantes

    // ============================================ subida en base64 (server MCP)

    /** Un asistente no manda un formulario: manda los bytes dentro del JSON. */
    public function testGuardaUnaImagenQueLlegaEnBase64()
    {
        $storage = $this->storage();

        $r = UploadHandler::guardarBase64(base64_encode($this->pngDeVerdad()), $storage);

        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith(UPLOAD_URL . '/uploads/', $r['url']);
        $this->assertStringEndsWith('.jpg', $r['url']);
    }

    /** Las herramientas de imagen suelen tener a mano el data URI completo. */
    public function testAceptaUnDataUriCompleto()
    {
        $r = UploadHandler::guardarBase64('data:image/png;base64,' . base64_encode($this->pngDeVerdad()), $this->storage());

        $this->assertTrue($r['ok']);
    }

    /**
     * La comprobación que importa: la extensión sale del tipo que detecta el
     * servidor, no de lo que declare quien sube. En api/uploads/ Apache
     * ejecuta PHP, así que confiar en el cliente sería ejecución remota.
     */
    public function testLaExtensionSaleDelTipoDetectadoYNoDelDataUri()
    {
        $storage = $this->storage(['info' => [10, 10, IMAGETYPE_PNG]]);

        $r = UploadHandler::guardarBase64('data:image/jpeg;base64,' . base64_encode($this->pngDeVerdad()), $storage);

        $this->assertStringEndsWith('.png', $r['url']);
    }

    public function testRechazaAlgoQueNoEsUnaImagen()
    {
        $storage = $this->storage(['info' => false]);

        $r = UploadHandler::guardarBase64(base64_encode('<?php echo 1;'), $storage);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no es una imagen', $r['error']);
    }

    /** Lo que no se guardó no puede quedar ocupando disco. */
    public function testBorraElTemporalSiElArchivoNoSirve()
    {
        $storage = $this->storage(['info' => false]);

        UploadHandler::guardarBase64(base64_encode('x'), $storage);

        $this->assertSame([$storage->temporal], $storage->borrados);
    }

    public function testRechazaUnaImagenDemasiadoGrande()
    {
        $r = UploadHandler::guardarBase64(base64_encode(str_repeat('a', UploadHandler::MAX_BYTES + 1)), $this->storage());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('5 MB', $r['error']);
    }

    /** @dataProvider base64Invalido */
    public function testRechazaUnBase64Ilegible($valor)
    {
        $r = UploadHandler::guardarBase64($valor, $this->storage());

        $this->assertFalse($r['ok']);
    }

    public function base64Invalido()
    {
        return [
            'vacío'        => [''],
            'sólo espacios' => ['   '],
            'no es base64' => ['%%% esto no %%%'],
            'no es texto'  => [null],
        ];
    }

    /** El base64 puede venir cortado en líneas. */
    public function testUnBase64ConSaltosDeLineaSeEntiende()
    {
        $partido = chunk_split(base64_encode($this->pngDeVerdad()), 8, "\n");

        $this->assertTrue(UploadHandler::guardarBase64($partido, $this->storage())['ok']);
    }

    // ==================================== una imagen cortada por la mitad

    private function pngDeVerdad($lado = 40, $conRuido = false)
    {
        $im = imagecreatetruecolor($lado, $lado);

        if ($conRuido) {
            for ($x = 0; $x < $lado; $x++) {
                for ($y = 0; $y < $lado; $y++) {
                    imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7) % 256, ($y * 13) % 256, ($x * $y) % 256));
                }
            }
        } else {
            imagefilledrectangle($im, 0, 0, $lado - 1, $lado - 1, imagecolorallocate($im, 200, 30, 30));
        }

        ob_start();
        imagepng($im);
        imagedestroy($im);

        return ob_get_clean();
    }

    /**
     * Una imagen cortada conserva la firma y las dimensiones del encabezado,
     * así que getimagesize() la da por buena: el archivo se guardaba y el
     * evento quedaba apuntando a algo que no se puede dibujar.
     */
    public function testUnaImagenCortadaPorLaMitadNoSeGuarda()
    {
        $entera = $this->pngDeVerdad();
        $cortada = substr($entera, 0, (int) (strlen($entera) * 0.4)) . 'IEND';

        $this->assertNotFalse(@getimagesize('data://image/png;base64,' . base64_encode($cortada)), 'el encabezado sigue siendo legible');
        $this->assertFalse(UploadHandler::seDecodificaEntera($cortada));
    }

    public function testUnaImagenEnteraPasaLaComprobacion()
    {
        $this->assertTrue(UploadHandler::seDecodificaEntera($this->pngDeVerdad()));
    }

    /** El caso que rompió en producción, extremo a extremo. */
    public function testUnBase64TruncadoSeRechazaConUnMensajeUtil()
    {
        // Con ruido, para que comprima poco y truncar corte de verdad.
        $entera = $this->pngDeVerdad(120, true);
        $base64 = base64_encode($entera);
        // Múltiplo de 4: así el base64 sigue siendo válido y lo que queda
        // incompleta es la imagen, que es justo el caso que se escapaba.
        $truncado = substr($base64, 0, ((int) (strlen($base64) * 0.4) >> 2) << 2);

        $r = UploadHandler::guardarBase64($truncado, $this->storage(['info' => [120, 120, IMAGETYPE_PNG]]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('incompleta', $r['error']);
    }

    public function testDecodificarDevuelveLosBytesOriginales()
    {
        $this->assertSame('hola', UploadHandler::decodificar(base64_encode('hola')));
    }

    /**
     * tempnam() crea con permisos 0600 y rename() los conserva: sin el chmod,
     * la imagen queda guardada pero Apache no la puede leer y el evento se
     * publica con un afiche que devuelve 403. Pasó en producción.
     */
    public function testLaImagenGuardadaQuedaLegibleParaElServidorWeb()
    {
        $almacen = new FileStorage();
        $temporal = $almacen->guardarTemporal('unos bytes');
        $destino = sys_get_temp_dir() . '/rezonar-prueba-' . uniqid() . '.png';

        $this->assertSame(0600, fileperms($temporal) & 0777, 'el temporal nace privado');

        $almacen->mover($temporal, $destino);

        $this->assertSame(0644, fileperms($destino) & 0777);

        unlink($destino);
    }

    // ================================== subida con link de un solo uso

    private function permisoVigente(array $overrides = [])
    {
        $this->db->onSelect('FROM image_uploads WHERE token_hash', [array_merge([
            'id' => 4, 'user_id' => 7, 'link_id' => 300,
            'expira_en' => date('Y-m-d H:i:s', time() + 600), 'usado_en' => null,
        ], $overrides)]);
    }

    private function subirConToken($storage = null)
    {
        return UploadHandler::conToken(
            $this->db,
            new Request('POST', [], ['token' => 'abc'], null, ['image' => ['tmp_name' => '/tmp/x']]),
            $storage === null ? $this->storage() : $storage
        );
    }

    /** El token es la credencial: no hace falta sesión. */
    public function testConTokenNoPideSesion()
    {
        $this->permisoVigente();
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('UPDATE image_uploads', 1);
        $this->db->onWrite('UPDATE links', 1);

        $r = $this->subirConToken();

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('/uploads/', $r->body['url']);
    }

    public function testConTokenDejaLaImagenEnElEvento()
    {
        $this->permisoVigente();
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);
        $this->db->onWrite('UPDATE image_uploads', 1);
        $this->db->onWrite('UPDATE links', 1);

        $this->subirConToken();

        $this->assertContains(300, $this->db->paramsFor('UPDATE links'));
    }

    public function testUnTokenVencidoNoDejaSubir()
    {
        $this->db->onSelect('FROM image_uploads WHERE token_hash', []);

        $this->assertSame(404, $this->subirConToken()->status);
    }

    /**
     * Entre que se pidió el link y llegó el archivo pueden pasar treinta
     * minutos: el permiso se revalida, no se da por hecho.
     */
    public function testSiElQuePidioElLinkYaNoAdministraElEventoNoSeSube()
    {
        $this->permisoVigente();
        $this->db->onSelect('SELECT 1 FROM links l', []);

        $this->assertSame(403, $this->subirConToken()->status);
    }

    public function testConTokenRechazaAlgoQueNoEsImagen()
    {
        $this->permisoVigente();
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);

        $r = $this->subirConToken($this->storage(['info' => false]));

        $this->assertSame(400, $r->status);
    }

    /** Si la imagen falló, el link tiene que seguir sirviendo. */
    public function testUnaSubidaFallidaNoQuemaElLink()
    {
        $this->permisoVigente();
        $this->db->onSelect('SELECT 1 FROM links l', [[1]]);

        $this->subirConToken($this->storage(['info' => false]));

        $this->assertSame(0, $this->db->countCalls('UPDATE image_uploads'));
    }

    private function peticion($user, array $file)
    {
        return new Request('POST', [], [], $user, ['image' => $file]);
    }

    private function archivo(array $overrides = [])
    {
        return array_merge([
            'name' => 'foto.jpg',
            'type' => 'image/jpeg',
            'size' => 1024,
            'tmp_name' => '/tmp/phpXXXX',
            'error' => 0,
        ], $overrides);
    }

    private function storage(array $opciones = [])
    {
        return new FakeFileStorage(
            array_key_exists('info', $opciones) ? $opciones['info'] : [100, 100, IMAGETYPE_JPEG],
            array_key_exists('move', $opciones) ? $opciones['move'] : true
        );
    }
}

/** Doble de FileStorage: no toca el disco y recuerda dónde se pidió escribir. */
class FakeFileStorage extends FileStorage
{
    public $destino;
    public $directorioCreado;

    private $info;
    private $exito;

    public function __construct($info, $exito)
    {
        $this->info = $info;
        $this->exito = $exito;
    }

    public function imageInfo($path)
    {
        return $this->info;
    }

    public function ensureDir($dir)
    {
        $this->directorioCreado = $dir;
    }

    public function moveUploaded($tmpPath, $destination)
    {
        $this->destino = $destination;
        return $this->exito;
    }

    public $temporal = '/tmp/fake-temporal';
    public $borrados = [];

    /** Bytes de una imagen de verdad: la validación los decodifica. */
    public function leer($ruta)
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return ob_get_clean();
    }

    public function guardarTemporal($contenido)
    {
        $this->bytes = $contenido;

        return $this->temporal;
    }

    public $bytes;

    public function mover($origen, $destino)
    {
        $this->destino = $destino;

        return $this->exito;
    }

    public function borrar($ruta)
    {
        $this->borrados[] = $ruta;
    }
}
