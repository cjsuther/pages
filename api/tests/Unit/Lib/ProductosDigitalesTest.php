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

    // =========================================================== borrado

    private function productosASoltar(array $filas)
    {
        $this->db->onSelect('FOR UPDATE', $filas);
    }

    public function testSoltarUnProductoSinOrdenesLoBorraConSuArchivo()
    {
        $this->productosASoltar([['id' => 40, 'link_id' => 5, 'archivo_ruta' => 'productos/x', 'con_ordenes' => 0, 'con_compras' => 0]]);

        $rutas = ProductosDigitales::soltar($this->db, 'link', 5);

        $this->assertSame(['productos/x'], $rutas);
        $this->assertSame([40], $this->db->paramsFor('DELETE FROM digital_products WHERE id = ?'));
        $this->assertFalse($this->db->ran('SET borrado_en'));
        $this->assertSame([5], $this->db->paramsFor('FOR UPDATE'));
    }

    /** Las ventas sobreviven: la fila se queda, marcada, y el archivo también. */
    public function testSoltarUnProductoConComprasConservaFilaYArchivo()
    {
        $this->productosASoltar([['id' => 40, 'link_id' => 5, 'archivo_ruta' => 'productos/x', 'con_ordenes' => 1, 'con_compras' => 1]]);

        $rutas = ProductosDigitales::soltar($this->db, 'link', 5);

        $this->assertSame([], $rutas);
        $this->assertFalse($this->db->ran('DELETE FROM digital_products'));
        $this->assertTrue($this->db->ran('SET dp.titulo = l.text, dp.page_id = lg.page_id'));
        $update = $this->db->callsFor('SET borrado_en = NOW()')[0];
        $this->assertStringContainsString('link_original = link_id', $update['sql']);
        $this->assertStringNotContainsString('archivo_ruta', $update['sql']);
        $this->assertSame([40], $update['params']);
    }

    /** Si todas las órdenes se cancelaron nadie puede descargar: el archivo se va, la fila no. */
    public function testSoltarUnProductoConOrdenesCanceladasBorraSoloElArchivo()
    {
        $this->productosASoltar([['id' => 40, 'link_id' => 5, 'archivo_ruta' => 'productos/x', 'con_ordenes' => 1, 'con_compras' => 0]]);

        $rutas = ProductosDigitales::soltar($this->db, 'link', 5);

        $this->assertSame(['productos/x'], $rutas);
        $this->assertFalse($this->db->ran('DELETE FROM digital_products'));
        $this->assertStringContainsString('archivo_ruta = NULL', $this->db->callsFor('SET borrado_en = NOW()')[0]['sql']);
    }

    public function testSoltarFiltraPorElAlcance()
    {
        ProductosDigitales::soltar($this->db, 'grupo', 3);
        ProductosDigitales::soltar($this->db, 'pagina', 9);

        $consultas = $this->db->callsFor('FOR UPDATE');
        $this->assertStringContainsString('WHERE l.group_id = ?', $consultas[0]['sql']);
        $this->assertSame([3], $consultas[0]['params']);
        $this->assertStringContainsString('WHERE lg.page_id = ?', $consultas[1]['sql']);
        $this->assertSame([9], $consultas[1]['params']);

        $this->expectException(\InvalidArgumentException::class);
        ProductosDigitales::soltar($this->db, 'usuario', 1);
    }

    // ===================================================== oculto / público

    public function testUnProductoOcultoOBorradoNoAceptaPedidos()
    {
        $this->assertTrue(ProductosDigitales::aceptaPedidos(['oculto' => 0, 'borrado_en' => null]));
        $this->assertFalse(ProductosDigitales::aceptaPedidos(['oculto' => 1, 'borrado_en' => null]));
        $this->assertFalse(ProductosDigitales::aceptaPedidos(['oculto' => 0, 'borrado_en' => '2026-10-01 00:00:00']));
    }

    public function testGuardarOculto()
    {
        ProductosDigitales::guardarOculto($this->db, 5, true);
        ProductosDigitales::guardarOculto($this->db, 5, false);

        $llamadas = $this->db->callsFor('UPDATE digital_products SET oculto = ?');
        $this->assertSame([1, 5], $llamadas[0]['params']);
        $this->assertSame([0, 5], $llamadas[1]['params']);
    }

    /** Lo que va a usar la página pública: sólo lo que está en venta. */
    public function testLosProductosPublicosSonLosQueEstanEnVenta()
    {
        ProductosDigitales::publicosDelGrupo($this->db, 3);

        $sql = $this->db->callsFor('FROM links l')[0]['sql'];
        $this->assertStringContainsString(ProductosDigitales::CONDICION_EN_VENTA, $sql);
        $this->assertStringContainsString('dp.oculto = 0', ProductosDigitales::CONDICION_EN_VENTA);
        $this->assertStringContainsString('dp.borrado_en IS NULL', ProductosDigitales::CONDICION_EN_VENTA);
        $this->assertStringNotContainsString('archivo_ruta,', $sql);
    }

    public function testUnLinkBorradoConVentasSeReconoce()
    {
        $this->assertFalse(ProductosDigitales::fueBorrado($this->db, 5));

        $this->db->onSelect('WHERE link_original = ?', [[1]]);
        $this->assertTrue(ProductosDigitales::fueBorrado($this->db, 5));
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
