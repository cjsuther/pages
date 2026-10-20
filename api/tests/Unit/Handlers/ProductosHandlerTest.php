<?php

namespace Tests\Unit\Handlers;

use ProductosDigitales;
use ProductosHandler;
use Request;
use Tests\Support\FakeDisco;
use Tests\Support\HandlerTestCase;

class ProductosHandlerTest extends HandlerTestCase
{
    /** @var FakeDisco */
    private $disco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disco = new FakeDisco();
    }

    // ============================================================ acceso

    public function testRechazaMetodosDistintosDePost()
    {
        $req = new Request('GET', [], ['link_id' => 5], $this->user());

        $this->assertError(405, ProductosHandler::archivo($this->db, $req, $this->disco));
    }

    public function testExigeSesion()
    {
        $res = $this->subir($this->archivo(), null);

        $this->assertError(401, $res, 'Unauthorized');
        $this->assertSame([], $this->disco->movidos);
    }

    public function testExigeElProducto()
    {
        $req = new Request('POST', [], [], $this->user(), ['archivo' => $this->archivo()]);

        $this->assertError(400, ProductosHandler::archivo($this->db, $req, $this->disco), 'link_id');
    }

    public function testRechazaUnProductoDeOtraPagina()
    {
        // Sin regla para PageAccess, canManageLink devuelve false.
        $res = $this->subir($this->archivo());

        $this->assertError(404, $res, 'Producto no encontrado');
        $this->assertSame([], $this->disco->movidos);
        $this->assertNoWrites();
    }

    /** Un link común o un evento no tienen dónde guardar un archivo. */
    public function testRechazaUnLinkQueNoEsProducto()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'eventos']]);

        $res = $this->subir($this->archivo());

        $this->assertError(400, $res, 'no es un producto digital');
        $this->assertSame([], $this->disco->movidos);
    }

    // ============================================================ tamaño

    public function testUnArchivoDeMasDe50MbSeRechazaDiciendoElMaximo()
    {
        $this->esProducto();

        $res = $this->subir($this->archivo(['size' => ProductosDigitales::MAX_BYTES + 1]));

        $this->assertError(413, $res, '50 MB');
        $this->assertSame([], $this->disco->movidos);
    }

    public function testUnArchivoDeJusto50MbPasa()
    {
        $this->esProducto();

        $this->assertStatus(200, $this->subir($this->archivo(['size' => ProductosDigitales::MAX_BYTES])));
    }

    /**
     * @dataProvider cortesDePhp
     *
     * PHP puede cortar el archivo antes de que llegue: el mensaje tiene que
     * decir el máximo y no uno genérico.
     */
    public function testSiPhpCortaElArchivoElMensajeDiceElMaximo($codigo)
    {
        $this->esProducto();

        $res = $this->subir($this->archivo(['error' => $codigo, 'size' => 0, 'tmp_name' => '']));

        $this->assertError(413, $res, '50 MB');
    }

    public function cortesDePhp()
    {
        return [
            'upload_max_filesize' => [UPLOAD_ERR_INI_SIZE],
            'MAX_FILE_SIZE del formulario' => [UPLOAD_ERR_FORM_SIZE],
        ];
    }

    /**
     * Pasado post_max_size, PHP descarta el POST entero: $_FILES llega vacío
     * aunque el navegador haya mandado 80 MB.
     */
    public function testUnPostDescartadoPorPostMaxSizeDiceElMaximo()
    {
        $this->esProducto();
        $req = new Request('POST', [], ['link_id' => 5], $this->user(), [], ['Content-Length' => '83886080']);

        $res = ProductosHandler::archivo($this->db, $req, $this->disco);

        $this->assertError(413, $res, '50 MB');
    }

    public function testSinArchivoYSinCuerpoPideElArchivo()
    {
        $this->esProducto();
        $req = new Request('POST', [], ['link_id' => 5], $this->user(), []);

        $this->assertError(400, ProductosHandler::archivo($this->db, $req, $this->disco), 'No llegó ningún archivo');
    }

    public function testUnArchivoCortadoAMitadDeCaminoSeRechaza()
    {
        $this->esProducto();

        $res = $this->subir($this->archivo(['error' => UPLOAD_ERR_PARTIAL]));

        $this->assertError(400, $res, 'no llegó entero');
        $this->assertSame([], $this->disco->movidos);
    }

    public function testUnArchivoVacioSeRechaza()
    {
        $this->esProducto();

        $this->assertError(400, $this->subir($this->archivo(['size' => 0])), 'vacío');
    }

    // ===================================================== almacenamiento

    public function testElArchivoVaALaCarpetaPrivadaConNombreAleatorio()
    {
        $this->esProducto();

        $this->assertStatus(200, $this->subir($this->archivo()));

        $destino = $this->disco->movidos[0][1];
        $this->assertStringStartsWith(ProductosDigitales::raiz() . 'productos/', $destino);
        $this->assertMatchesRegularExpression('#/productos/[0-9a-f]{32}$#', $destino);
    }

    /**
     * @dataProvider nombresPeligrosos
     *
     * El nombre que manda el usuario nunca arma la ruta, y el archivo se
     * guarda sin extensión: ni .php para ejecutarse, ni .jpg para que el
     * .htaccess de uploads/ lo deje ver.
     */
    public function testElNombreEnviadoNoTocaLaRuta($nombre)
    {
        $this->esProducto();

        $this->subir($this->archivo(['name' => $nombre]));

        $destino = $this->disco->movidos[0][1];
        $this->assertStringNotContainsString('..', substr($destino, strlen(ProductosDigitales::raiz())));
        $this->assertStringNotContainsString('.', basename($destino));
    }

    public function nombresPeligrosos()
    {
        return [
            'php' => ['shell.php'],
            'imagen' => ['foto.jpg'],
            'path traversal' => ['../../../config.php'],
            'htaccess' => ['.htaccess'],
        ];
    }

    public function testLaCarpetaQuedaConUnHtaccessQueNiegaTodo()
    {
        $this->esProducto();

        $this->subir($this->archivo());

        $htaccess = ProductosDigitales::raiz() . 'productos/.htaccess';
        $this->assertArrayHasKey($htaccess, $this->disco->archivos);
        $this->assertStringContainsString('Require all denied', $this->disco->archivos[$htaccess]);
    }

    /** Sin el .htaccess el archivo quedaría público: no se guarda. */
    public function testSiNoSePuedeProtegerLaCarpetaNoSeGuardaNada()
    {
        $this->esProducto();
        $this->disco->escribeArchivos = false;

        $res = $this->subir($this->archivo());

        $this->assertStatus(500, $res);
        $this->assertSame([], $this->disco->movidos);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO digital_products'));
    }

    public function testGuardaElNombreOriginalElTamanoYElTipoDetectado()
    {
        $this->esProducto();
        $this->disco->mime = 'application/zip';

        $this->subir($this->archivo(['name' => 'Pack de fotos.zip', 'size' => 2048, 'type' => 'image/png']));

        $params = $this->db->paramsFor('INSERT INTO digital_products');
        $this->assertSame(5, $params[0]);
        $this->assertMatchesRegularExpression('#^productos/[0-9a-f]{32}$#', $params[1]);
        $this->assertSame('Pack de fotos.zip', $params[2]);
        $this->assertSame(2048, $params[3]);
        // El tipo es el que se detectó, no el que dijo el navegador.
        $this->assertSame('application/zip', $params[4]);
    }

    public function testSiNoSeDetectaElTipoQuedaComoBinario()
    {
        $this->esProducto();
        $this->disco->mime = null;

        $this->subir($this->archivo());

        $this->assertSame('application/octet-stream', $this->db->paramsFor('INSERT INTO digital_products')[4]);
    }

    public function testDevuelveElProductoSinLaRutaEnDisco()
    {
        $this->esProducto();
        $this->db->onSelect('LEFT JOIN digital_products', [[
            'id' => 5, 'text' => 'Guía', 'precio' => '0.00',
            'archivo_nombre' => 'guia.pdf', 'archivo_bytes' => 1024, 'archivo_tipo' => 'application/pdf',
        ]]);

        $res = $this->subir($this->archivo());

        $this->assertStatus(200, $res);
        $this->assertSame('guia.pdf', $res->body['link']['archivo_nombre']);
        $this->assertArrayNotHasKey('archivo_ruta', $res->body['link']);
        $sql = $this->db->callsFor('LEFT JOIN digital_products')[0]['sql'];
        $this->assertStringNotContainsString('archivo_ruta', $sql);
    }

    public function testSiFallaElMovimientoNoSeTocaLaBase()
    {
        $this->esProducto();
        $this->disco->funciona = false;
        $this->disco->escribeArchivos = true;
        // La carpeta ya estaba preparada de antes.
        $this->disco->archivos[ProductosDigitales::raiz() . '.htaccess'] = 'x';
        $this->disco->archivos[ProductosDigitales::raiz() . 'productos/.htaccess'] = 'x';

        $res = $this->subir($this->archivo());

        $this->assertStatus(500, $res);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO digital_products'));
    }

    /** Un archivo sin fila que lo nombre no lo encuentra nadie nunca más. */
    public function testSiFallaLaBaseSeBorraElArchivoRecienGuardado()
    {
        $this->esProducto();
        $this->db->failOn('INSERT INTO digital_products', 'sin conexión');

        $res = $this->subir($this->archivo());

        $this->assertStatus(500, $res);
        $this->assertSame([$this->disco->movidos[0][1]], $this->disco->borrados);
    }

    // ========================================================== reemplazo

    public function testAlReemplazarSeBorraElArchivoAnterior()
    {
        $this->esProducto(['archivo_ruta' => 'productos/' . str_repeat('a', 32)]);

        $this->subir($this->archivo());

        $this->assertSame([ProductosDigitales::raiz() . 'productos/' . str_repeat('a', 32)], $this->disco->borrados);
    }

    public function testElPrimerArchivoNoBorraNada()
    {
        $this->esProducto(['archivo_ruta' => null]);

        $this->subir($this->archivo());

        $this->assertSame([], $this->disco->borrados);
    }

    /** Si el nuevo no se pudo guardar, el producto sigue con el de siempre. */
    public function testSiFallaElReemplazoElAnteriorQueda()
    {
        $this->esProducto(['archivo_ruta' => 'productos/' . str_repeat('a', 32)]);
        $this->db->failOn('INSERT INTO digital_products', 'sin conexión');

        $this->subir($this->archivo());

        $this->assertNotContains(ProductosDigitales::raiz() . 'productos/' . str_repeat('a', 32), $this->disco->borrados);
    }

    // ========================================================== ayudantes

    private function subir(array $archivo, $user = 'default')
    {
        $req = new Request(
            'POST',
            [],
            ['link_id' => '5'],
            $user === 'default' ? $this->user() : $user,
            ['archivo' => $archivo]
        );

        return ProductosHandler::archivo($this->db, $req, $this->disco);
    }

    private function archivo(array $overrides = [])
    {
        return array_merge([
            'name' => 'guia.pdf',
            'type' => 'application/pdf',
            'size' => 1024,
            'tmp_name' => '/tmp/phpPRODUCTO',
            'error' => UPLOAD_ERR_OK,
        ], $overrides);
    }

    private function autorizarLink()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['1' => 1]]);
    }

    /** Un link autorizado de un grupo de productos, con su fila actual. */
    private function esProducto(array $producto = [])
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'productos']]);
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [array_merge([
            'id' => 1, 'link_id' => 5, 'precio' => '0.00', 'archivo_ruta' => null,
        ], $producto)]);
    }
}
