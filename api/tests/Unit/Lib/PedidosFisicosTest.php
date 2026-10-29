<?php

namespace Tests\Unit\Lib;

use PedidosFisicos;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakePdo;

/**
 * Lo que se le pide a quien compra un físico (#43): cantidad, teléfono,
 * opciones, dirección y horarios; el total con envío, el stock y lo que se
 * muestra de la dirección en la pantalla pública.
 */
class PedidosFisicosTest extends TestCase
{
    private function envioValido(array $overrides = [])
    {
        return array_merge([
            'calle' => 'Av. Corrientes 1234', 'piso_depto' => '3° B', 'localidad' => 'CABA',
            'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => 'c1043aaz',
            'indicaciones' => 'Timbre B',
        ], $overrides);
    }

    private function talleYColor()
    {
        return [
            ['id' => 1, 'nombre' => 'Talle', 'opciones' => [['id' => 11, 'nombre' => 'S'], ['id' => 12, 'nombre' => 'M']]],
            ['id' => 2, 'nombre' => 'Color', 'opciones' => [['id' => 21, 'nombre' => 'Negro'], ['id' => 22, 'nombre' => 'Blanco']]],
        ];
    }

    // ------------------------------------------------------------- cantidad

    public function testLaCantidadVaDeUnoADiez()
    {
        $this->assertSame(1, PedidosFisicos::cantidad(1));
        $this->assertSame(10, PedidosFisicos::cantidad('10'));
        $this->assertNull(PedidosFisicos::cantidad(0));
        $this->assertNull(PedidosFisicos::cantidad(11));
        $this->assertNull(PedidosFisicos::cantidad(-2));
        $this->assertNull(PedidosFisicos::cantidad('2.5'));
        $this->assertNull(PedidosFisicos::cantidad('dos'));
        $this->assertNull(PedidosFisicos::cantidad([3]));
        $this->assertNull(PedidosFisicos::cantidad(2.0));
    }

    // ------------------------------------------------------------- teléfono

    public function testElTelefonoEsObligatorio()
    {
        $this->assertStringContainsString('Falta el teléfono', PedidosFisicos::problemaDeTelefono(''));
        $this->assertStringContainsString('Falta el teléfono', PedidosFisicos::problemaDeTelefono('   '));
        $this->assertStringContainsString('Falta el teléfono', PedidosFisicos::problemaDeTelefono(null));
        $this->assertSame('El teléfono no es válido', PedidosFisicos::problemaDeTelefono('12-34'));
        $this->assertNull(PedidosFisicos::problemaDeTelefono('+54 9 11 5555-1234'));
    }

    // ------------------------------------------------------------- dirección

    public function testUnaDireccionCompletaSeNormaliza()
    {
        list($envio, $error) = PedidosFisicos::envio($this->envioValido());

        $this->assertNull($error);
        $this->assertSame('Av. Corrientes 1234', $envio['calle']);
        $this->assertSame('C1043AAZ', $envio['codigo_postal']);
        $this->assertSame('Ciudad Autónoma de Buenos Aires', $envio['provincia']);
    }

    public function testElPisoYLasIndicacionesSonOpcionales()
    {
        list($envio, $error) = PedidosFisicos::envio($this->envioValido(['piso_depto' => '  ', 'indicaciones' => null]));

        $this->assertNull($error);
        $this->assertNull($envio['piso_depto']);
        $this->assertNull($envio['indicaciones']);
    }

    /** @dataProvider obligatorios */
    public function testLosObligatoriosDeLaDireccion($campo, $mensaje)
    {
        list($envio, $error) = PedidosFisicos::envio($this->envioValido([$campo => '']));

        $this->assertNull($envio);
        $this->assertSame($mensaje, $error);
    }

    public function obligatorios()
    {
        return [
            ['calle', 'Falta la calle y el número'],
            ['localidad', 'Falta la localidad'],
            ['provincia', 'Falta la provincia'],
            ['codigo_postal', 'Falta el código postal'],
        ];
    }

    public function testSinDireccion()
    {
        $this->assertSame([null, 'Falta la dirección de envío'], PedidosFisicos::envio(null));
        $this->assertSame([null, 'Falta la dirección de envío'], PedidosFisicos::envio('Corrientes 1234'));
    }

    public function testLaProvinciaTieneQueSerUnaDeLas24()
    {
        $this->assertCount(24, PedidosFisicos::PROVINCIAS);
        $this->assertSame([null, 'Elegí una provincia de la lista'], PedidosFisicos::envio($this->envioValido(['provincia' => 'Narnia'])));
        $this->assertNull(PedidosFisicos::envio($this->envioValido(['provincia' => 'Tierra del Fuego']))[1]);
    }

    public function testElCodigoPostal()
    {
        $this->assertSame('1414', PedidosFisicos::envio($this->envioValido(['codigo_postal' => '1414']))[0]['codigo_postal']);
        $this->assertSame('C1414BAD', PedidosFisicos::envio($this->envioValido(['codigo_postal' => 'C 1414 BAD']))[0]['codigo_postal']);

        foreach (['141', '14145', 'ABCD', 'C1414BA'] as $malo) {
            $this->assertStringContainsString('El código postal no es válido', PedidosFisicos::envio($this->envioValido(['codigo_postal' => $malo]))[1], $malo);
        }
    }

    public function testLosLargosDeLaDireccion()
    {
        $this->assertStringContainsString('hasta 150', PedidosFisicos::envio($this->envioValido(['calle' => str_repeat('a', 151)]))[1]);
        $this->assertStringContainsString('hasta 255', PedidosFisicos::envio($this->envioValido(['indicaciones' => str_repeat('a', 256)]))[1]);
        $this->assertNull(PedidosFisicos::envio($this->envioValido(['calle' => str_repeat('ñ', 150)]))[1]);
    }

    // -------------------------------------------------------------- horarios

    public function testLosHorariosValidos()
    {
        list($h, $error) = PedidosFisicos::horarios([
            'dias' => ['sabado', 'lunes', 'lunes'], 'franjas' => ['noche', 'manana'], 'comentario' => ' después de las 18 ',
        ]);

        $this->assertNull($error);
        // En el orden de la semana y sin repetir.
        $this->assertSame(['lunes', 'sabado'], $h['dias']);
        $this->assertSame(['manana', 'noche'], $h['franjas']);
        $this->assertSame('después de las 18', $h['comentario']);
    }

    public function testHaceFaltaAlMenosUnDiaYUnaFranja()
    {
        $this->assertStringContainsString('al menos un día', PedidosFisicos::horarios(['dias' => [], 'franjas' => ['tarde']])[1]);
        $this->assertStringContainsString('al menos una franja', PedidosFisicos::horarios(['dias' => ['lunes']])[1]);
        $this->assertStringContainsString('al menos un día', PedidosFisicos::horarios(null)[1]);
    }

    public function testUnDiaOUnaFranjaQueNoExisten()
    {
        $this->assertSame('Los días no tienen el formato esperado', PedidosFisicos::horarios(['dias' => ['feriado'], 'franjas' => ['tarde']])[1]);
        $this->assertSame('Las franjas no tienen el formato esperado', PedidosFisicos::horarios(['dias' => ['lunes'], 'franjas' => 'tarde'])[1]);
    }

    public function testElComentarioEsOpcional()
    {
        $this->assertNull(PedidosFisicos::horarios(['dias' => ['lunes'], 'franjas' => ['tarde'], 'comentario' => ''])[0]['comentario']);
        $this->assertStringContainsString('hasta 255', PedidosFisicos::horarios([
            'dias' => ['lunes'], 'franjas' => ['tarde'], 'comentario' => str_repeat('x', 256),
        ])[1]);
    }

    // -------------------------------------------------------------- opciones

    public function testLasOpcionesElegidasSeEscribenEnTexto()
    {
        list($opciones, $error) = PedidosFisicos::opciones($this->talleYColor(), [21, 12]);

        $this->assertNull($error);
        $this->assertSame([12, 21], $opciones['ids']);
        $this->assertSame('Talle M · Color Negro', $opciones['texto']);
    }

    public function testFaltaElegirUnaCaracteristica()
    {
        $this->assertSame('Elegí color', PedidosFisicos::opciones($this->talleYColor(), [12])[1]);
        $this->assertSame('Elegí talle', PedidosFisicos::opciones($this->talleYColor(), null)[1]);
    }

    public function testDosOpcionesDeLaMismaCaracteristica()
    {
        $this->assertSame('Elegí una sola opción de talle', PedidosFisicos::opciones($this->talleYColor(), [11, 12, 21])[1]);
    }

    public function testUnaOpcionQueNoEsDelProducto()
    {
        $this->assertSame('Alguna de las opciones elegidas ya no existe', PedidosFisicos::opciones($this->talleYColor(), [12, 21, 99])[1]);
    }

    public function testSinCaracteristicasNoSeEligeNada()
    {
        $this->assertSame([['ids' => [], 'texto' => null], null], PedidosFisicos::opciones([], [5]));
    }

    // ----------------------------------------------------------------- stock

    public function testElStockDeUnaCombinacion()
    {
        $producto = ['caracteristicas' => $this->talleYColor(), 'variantes' => [
            ['clave' => '12-21', 'stock' => 3], ['clave' => '11-21', 'stock' => 0],
        ]];

        $this->assertSame(3, PedidosFisicos::stockDisponible($producto, [21, 12]));
        $this->assertSame(0, PedidosFisicos::stockDisponible($producto, [11, 21]));
        // Sin fila: sin límite.
        $this->assertNull(PedidosFisicos::stockDisponible($producto, [12, 22]));
    }

    public function testElStockSinCaracteristicas()
    {
        $this->assertSame(4, PedidosFisicos::stockDisponible(['caracteristicas' => [], 'stock' => '4'], []));
        $this->assertNull(PedidosFisicos::stockDisponible(['caracteristicas' => [], 'stock' => null], []));
    }

    // ----------------------------------------------------------------- total

    public function testElTotalEsPrecioPorCantidadMasEnvio()
    {
        $this->assertSame(5500.0, PedidosFisicos::total('2500.00', 2, '500.00'));
        $this->assertSame(2500.0, PedidosFisicos::total(2500, 1, 0));
        $this->assertSame(0.0, PedidosFisicos::total(0, 3, 0));
        $this->assertSame(1500.0, PedidosFisicos::total(0, 3, 1500));
        $this->assertSame(30.3, PedidosFisicos::total('10.10', 3, 0));
    }

    // ------------------------------------------------- descuento del stock

    private function ordenFisica($cantidad = 2)
    {
        return ['id' => 7, 'product_id' => 40, 'cantidad' => $cantidad];
    }

    public function testDescuentaLaCombinacionConForUpdate()
    {
        $db = new FakePdo();
        $db->onSelect('SELECT option_id FROM product_order_options', [['option_id' => 21], ['option_id' => 12]]);
        $db->onSelect('FROM product_variants WHERE product_id = ? AND clave = ? FOR UPDATE', [['stock' => 5]]);

        $this->assertTrue(PedidosFisicos::descontarStock($db, $this->ordenFisica(2)));

        $this->assertSame([40, '12-21'], $db->paramsFor('FROM product_variants WHERE product_id = ? AND clave = ? FOR UPDATE'));
        $this->assertSame([2, 40, '12-21'], $db->paramsFor('UPDATE product_variants SET stock = stock - ?'));
        $this->assertFalse($db->ran('SET sin_stock = 1'));
    }

    public function testDescuentaElStockDelProductoSinCaracteristicas()
    {
        $db = new FakePdo();
        $db->onSelect('SELECT stock FROM digital_products WHERE id = ? FOR UPDATE', [['stock' => 2]]);

        $this->assertTrue(PedidosFisicos::descontarStock($db, $this->ordenFisica(2)));
        $this->assertSame([2, 40], $db->paramsFor('UPDATE digital_products SET stock = stock - ?'));
    }

    public function testSinLimiteNoDescuentaNada()
    {
        $db = new FakePdo();
        $db->onSelect('SELECT stock FROM digital_products WHERE id = ? FOR UPDATE', [['stock' => null]]);

        $this->assertTrue(PedidosFisicos::descontarStock($db, $this->ordenFisica(3)));
        $this->assertFalse($db->ran('SET stock = stock - ?'));
        $this->assertFalse($db->ran('SET sin_stock = 1'));
    }

    /** Dos compraron la última unidad: al segundo ya no le alcanza y queda sin_stock. */
    public function testSiYaNoAlcanzaQuedaSinStockYNoSeDescuenta()
    {
        $db = new FakePdo();
        $db->onSelect('SELECT option_id FROM product_order_options', [['option_id' => 12]]);
        $db->onSelect('FROM product_variants WHERE product_id = ? AND clave = ? FOR UPDATE', [['stock' => 0]]);

        $this->assertFalse(PedidosFisicos::descontarStock($db, $this->ordenFisica(1)));
        $this->assertSame([7], $db->paramsFor('UPDATE product_orders SET sin_stock = 1'));
        $this->assertFalse($db->ran('SET stock = stock - ?'));
    }

    // ------------------------------------------------------ lo que se muestra

    public function testLaCalleSeRecortaEnElPrimerDigito()
    {
        $this->assertSame('Av. Corrientes 1…', PedidosFisicos::calleParcial('Av. Corrientes 1234'));
        $this->assertSame('Calle 5…', PedidosFisicos::calleParcial('Calle 50 n° 742'));
        $this->assertSame('Los Aro…', PedidosFisicos::calleParcial('Los Aromos s/n'));
        $this->assertSame('', PedidosFisicos::calleParcial(''));
    }

    public function testLosDiasYLasFranjasSeLeen()
    {
        $this->assertSame('Lunes, Miércoles y Sábado', PedidosFisicos::describirDias(['lunes', 'miercoles', 'sabado']));
        $this->assertSame('Mañana', PedidosFisicos::describirFranjas(['manana']));
        $this->assertSame('Mañana y Noche', PedidosFisicos::describirFranjas(['manana', 'noche']));
    }

    public function testElEnvioDeUnaOrden()
    {
        $db = new FakePdo();
        $db->onSelect('FROM product_order_envios WHERE order_id = ?', [[
            'order_id' => 7, 'calle' => 'Av. Corrientes 1234', 'piso_depto' => null, 'localidad' => 'CABA',
            'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043', 'indicaciones' => null,
            'dias' => 'lunes,viernes', 'franjas' => 'tarde', 'comentario_horario' => null,
        ]]);

        $envio = PedidosFisicos::envioDe($db, 7);

        $this->assertSame(['lunes', 'viernes'], $envio['dias']);
        $this->assertSame(['tarde'], $envio['franjas']);
        $this->assertNull(PedidosFisicos::envioDe(new FakePdo(), 8));
    }
}
