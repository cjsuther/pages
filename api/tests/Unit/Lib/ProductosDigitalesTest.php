<?php

namespace Tests\Unit\Lib;

use FileStorage;
use ProductosDigitales;
use Tests\Support\FakeDisco;
use Tests\Support\HandlerTestCase;

class ProductosDigitalesTest extends HandlerTestCase
{
    // =============================================================== carpeta

    /**
     * uploads/ no se despliega —el rsync lo excluye—, así que el .htaccess de
     * la carpeta privada no puede venir del repo: lo escribe el código.
     */
    public function testPrepararLaCarpetaEscribeUnHtaccessQueNiegaTodo()
    {
        $disco = new FakeDisco();

        $this->assertTrue(ProductosDigitales::prepararCarpeta($disco));

        foreach ([ProductosDigitales::raiz(), ProductosDigitales::raiz() . 'productos/'] as $carpeta) {
            $this->assertContains($carpeta, $disco->carpetas);
            $this->assertArrayHasKey($carpeta . '.htaccess', $disco->archivos);
            $this->assertStringContainsString('Require all denied', $disco->archivos[$carpeta . '.htaccess']);
        }
    }

    /**
     * Va dentro de uploads/ y no en otra carpeta de api/: deploy.sh hace
     * rsync --delete de api/ excluyendo uploads/, y cualquier otra carpeta se
     * borraría en cada deploy.
     */
    public function testLaCarpetaPrivadaEstaDentroDeUploads()
    {
        $this->assertSame(dirname(__DIR__, 3) . '/uploads/privado/', ProductosDigitales::raiz());
    }

    public function testUnHtaccessQueYaEstaNoSePisa()
    {
        $disco = new FakeDisco();
        $htaccess = ProductosDigitales::raiz() . 'productos/.htaccess';
        $disco->archivos[$htaccess] = 'el de siempre';

        ProductosDigitales::prepararCarpeta($disco);

        $this->assertSame('el de siempre', $disco->archivos[$htaccess]);
    }

    public function testSiNoSePuedeEscribirElHtaccessAvisa()
    {
        $disco = new FakeDisco();
        $disco->escribeArchivos = false;

        $this->assertFalse(ProductosDigitales::prepararCarpeta($disco));
    }

    /**
     * El .htaccess de uploads/ habilita las imágenes con un FilesMatch. El de
     * la carpeta privada tiene el suyo, que se aplica después y gana.
     */
    public function testElHtaccessTambienNiegaPorNombreDeArchivo()
    {
        $this->assertMatchesRegularExpression('#<FilesMatch "\.">\s*Require all denied\s*</FilesMatch>#', ProductosDigitales::HTACCESS);
    }

    // ================================================================ rutas

    public function testCadaRutaNuevaEsAleatoriaYSinExtension()
    {
        $una = ProductosDigitales::rutaNueva();
        $otra = ProductosDigitales::rutaNueva();

        $this->assertMatchesRegularExpression('#^productos/[0-9a-f]{32}$#', $una);
        $this->assertNotSame($una, $otra);
    }

    /** @dataProvider rutasInvalidas */
    public function testSoloSeBorranRutasConLaFormaQueGeneraElCodigo($ruta)
    {
        $disco = new FakeDisco();

        ProductosDigitales::borrarArchivos([$ruta], $disco);

        $this->assertSame([], $disco->borrados);
    }

    public function rutasInvalidas()
    {
        return [
            'traversal' => ['productos/../../config.php'],
            'absoluta' => ['/etc/passwd'],
            'otra carpeta' => ['otra/' . str_repeat('a', 32)],
            'con extensión' => ['productos/' . str_repeat('a', 32) . '.php'],
            'vacía' => [''],
            'null' => [null],
        ];
    }

    public function testBorraLasRutasValidas()
    {
        $disco = new FakeDisco();
        $ruta = 'productos/' . str_repeat('b', 32);

        ProductosDigitales::borrarArchivos([$ruta], $disco);

        $this->assertSame([ProductosDigitales::raiz() . $ruta], $disco->borrados);
    }

    // =============================================================== precio

    /** @dataProvider preciosValidos */
    public function testPreciosQueSeAceptan($valor, $esperado)
    {
        $this->assertSame($esperado, ProductosDigitales::precio($valor));
    }

    public function preciosValidos()
    {
        return [
            'vacío es gratis' => ['', 0.0],
            'null es gratis' => [null, 0.0],
            'cero' => ['0', 0.0],
            'entero' => [2500, 2500.0],
            'con decimales' => ['1500.554', 1500.55],
        ];
    }

    /** @dataProvider preciosInvalidos */
    public function testPreciosQueSeRechazan($valor)
    {
        $this->assertNull(ProductosDigitales::precio($valor));
    }

    public function preciosInvalidos()
    {
        return [
            'negativo' => [-1],
            'texto' => ['a consultar'],
            'más que DECIMAL(12,2)' => ['10000000000'],
        ];
    }

    // ======================================================= nombre original

    /** @dataProvider nombres */
    public function testElNombreOriginalQuedaAptoParaLaDescarga($recibido, $esperado)
    {
        $this->assertSame($esperado, ProductosDigitales::nombreOriginal($recibido));
    }

    public function nombres()
    {
        return [
            'normal' => ['Guía de viaje.pdf', 'Guía de viaje.pdf'],
            'con carpetas' => ['../../etc/passwd', 'passwd'],
            'con barras de windows' => ['C:\\Users\\ana\\pack.zip', 'pack.zip'],
            'con comillas' => ['mi "pack".zip', 'mi pack.zip'],
            'con saltos de línea' => ["a\r\nb.pdf", 'ab.pdf'],
            'vacío' => ['', 'archivo'],
            'puntos' => ['..', 'archivo'],
        ];
    }

    public function testUnNombreLargoSeRecortaA255()
    {
        $this->assertSame(255, mb_strlen(ProductosDigitales::nombreOriginal(str_repeat('ñ', 300) . '.pdf')));
    }

    // ================================================================= base

    public function testSeGuardaElPrecioAunqueLaFilaNoExista()
    {
        ProductosDigitales::guardarPrecio($this->db, 5, 1200.0);

        $sql = $this->db->callsFor('INSERT INTO digital_products')[0]['sql'];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertSame([5, 1200.0], $this->db->paramsFor('INSERT INTO digital_products'));
    }

    public function testLasRutasDeUnGrupoSalenDeSusProductos()
    {
        $this->db->onSelect('WHERE l.group_id = ? AND dp.archivo_ruta IS NOT NULL', [['productos/x']]);

        $this->assertSame(['productos/x'], ProductosDigitales::rutasDelGrupo($this->db, 3));
        $this->assertSame([3], $this->db->paramsFor('WHERE l.group_id = ?'));
    }

    public function testLasRutasDeUnaPaginaSalenDeTodosSusGrupos()
    {
        $this->db->onSelect('WHERE lg.page_id = ? AND dp.archivo_ruta IS NOT NULL', [['productos/x'], ['productos/y']]);

        $this->assertSame(['productos/x', 'productos/y'], ProductosDigitales::rutasDeLaPagina($this->db, 9));
    }

    public function testUnProductoSinVentasSePuedeBorrar()
    {
        $this->assertNull(ProductosDigitales::motivoParaNoBorrar($this->db, 5));
    }

    /** Quien lo compró tiene que poder seguir descargándolo (#18). */
    public function testUnProductoConVentasPagadasNoSePuedeBorrar()
    {
        $this->db->onSelect("FROM product_orders WHERE link_id = ? AND estado = 'pagada'", [[2]]);

        $this->assertStringContainsString('ya tiene ventas', ProductosDigitales::motivoParaNoBorrar($this->db, 5));
        $this->assertSame([5], $this->db->paramsFor('FROM product_orders'));
    }

    public function testUnGrupoConVentasPagadasNoSePuedeBorrar()
    {
        $this->assertNull(ProductosDigitales::motivoParaNoBorrarGrupo($this->db, 3));

        $this->db->onSelect('WHERE l.group_id = ? AND o.estado', [[1]]);
        $this->assertNotNull(ProductosDigitales::motivoParaNoBorrarGrupo($this->db, 3));
    }

    /** Se pregunta con la dueña: a otra persona no se le dice si la página vende. */
    public function testUnaPaginaConVentasPagadasNoSePuedeBorrar()
    {
        $this->assertNull(ProductosDigitales::motivoParaNoBorrarPagina($this->db, 9, 1));

        $this->db->onSelect('p.user_id = ?', [[1]]);
        $this->assertNotNull(ProductosDigitales::motivoParaNoBorrarPagina($this->db, 9, 1));
        $this->assertSame([9, 1], $this->db->paramsFor('p.user_id = ?'));
    }

    // ========================================================== FileStorage

    /** El tipo sale de los bytes, no del nombre. */
    public function testElTipoSeDetectaMirandoElContenido()
    {
        $ruta = tempnam(sys_get_temp_dir(), 'rezonar-producto');
        file_put_contents($ruta, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n");

        $this->assertSame('application/pdf', (new FileStorage())->tipoMime($ruta));

        unlink($ruta);
    }

    public function testLoQuePesaUnArchivo()
    {
        $almacen = new FileStorage();
        $ruta = sys_get_temp_dir() . '/rezonar-prueba-' . uniqid();
        file_put_contents($ruta, '12345');

        $this->assertSame(5, $almacen->bytes($ruta));
        $this->assertNull($almacen->bytes($ruta . '-no-existe'));
        $this->assertNull($almacen->bytes(sys_get_temp_dir()), 'una carpeta no es un archivo');

        unlink($ruta);
    }

    public function testEscribirYExisteEnDisco()
    {
        $almacen = new FileStorage();
        $ruta = sys_get_temp_dir() . '/rezonar-prueba-' . uniqid();

        $this->assertFalse($almacen->existe($ruta));
        $this->assertTrue($almacen->escribir($ruta, 'Require all denied'));
        $this->assertTrue($almacen->existe($ruta));

        unlink($ruta);
    }
}
