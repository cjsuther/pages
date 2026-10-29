<?php

namespace Tests\Unit\Lib;

use ProductosFisicos;
use Tests\Support\HandlerTestCase;

class ProductosFisicosTest extends HandlerTestCase
{
    // ================================================================ valores

    public function testElTipoPorDefectoEsDigital()
    {
        $this->assertSame('digital', ProductosFisicos::tipo(null));
        $this->assertSame('digital', ProductosFisicos::tipo(''));
        $this->assertSame('fisico', ProductosFisicos::tipo('fisico'));
        $this->assertNull(ProductosFisicos::tipo('servicio'));
        $this->assertNull(ProductosFisicos::tipo(['fisico']));
    }

    /**
     * @dataProvider stocksValidos
     */
    public function testStockValido($valor, $esperado)
    {
        $this->assertSame($esperado, ProductosFisicos::stock($valor));
    }

    public function stocksValidos()
    {
        return [
            'vacío es sin límite' => ['', null],
            'null es sin límite' => [null, null],
            'cero' => [0, 0],
            'entero' => [12, 12],
            'texto' => ['7', 7],
            'texto con espacios' => [' 7 ', 7],
            'float entero' => [3.0, 3],
        ];
    }

    /**
     * @dataProvider stocksInvalidos
     */
    public function testStockInvalido($valor)
    {
        $this->assertFalse(ProductosFisicos::stock($valor));
    }

    public function stocksInvalidos()
    {
        return [
            'negativo' => [-1],
            'texto negativo' => ['-1'],
            'decimal' => ['1.5'],
            'float' => [1.5],
            'letras' => ['muchos'],
            'más que un INT' => ['99999999999'],
            'booleano' => [true],
            'lista' => [[3]],
        ];
    }

    public function testLaZonaDeEnvioVaciaEsNull()
    {
        $this->assertNull(ProductosFisicos::zonaEnvio('   '));
        $this->assertSame('Sólo CABA y GBA', ProductosFisicos::zonaEnvio('  Sólo CABA y GBA '));
        $this->assertFalse(ProductosFisicos::zonaEnvio(str_repeat('a', 256)));
        $this->assertFalse(ProductosFisicos::zonaEnvio(['CABA']));
    }

    // ========================================================= características

    private function talleYColor()
    {
        return [
            ['nombre' => 'Talle', 'opciones' => ['S', 'M', 'L']],
            ['nombre' => 'Color', 'opciones' => [['nombre' => 'Rojo'], ['id' => 9, 'nombre' => 'Azul']]],
        ];
    }

    public function testSinCaracteristicasNoSePideNada()
    {
        $this->assertSame([[], null], ProductosFisicos::caracteristicas(null));
        $this->assertSame([[], null], ProductosFisicos::caracteristicas([]));
    }

    public function testNormalizaCaracteristicasYOpciones()
    {
        list($lista, $error) = ProductosFisicos::caracteristicas([
            ['id' => '4', 'nombre' => ' Talle ', 'opciones' => [' S ', ['id' => 7, 'nombre' => 'M']]],
        ]);

        $this->assertNull($error);
        $this->assertSame([[
            'id' => 4,
            'nombre' => 'Talle',
            'opciones' => [['id' => null, 'nombre' => 'S'], ['id' => 7, 'nombre' => 'M']],
        ]], $lista);
    }

    /**
     * @dataProvider caracteristicasInvalidas
     */
    public function testValidaLasCaracteristicas($lista, $fragmento)
    {
        list($normalizadas, $error) = ProductosFisicos::caracteristicas($lista);

        $this->assertNull($normalizadas);
        $this->assertStringContainsString($fragmento, $error);
    }

    public function caracteristicasInvalidas()
    {
        $una = function ($nombre, array $opciones) {
            return ['nombre' => $nombre, 'opciones' => $opciones];
        };

        return [
            'no es una lista' => ['Talle', 'formato'],
            'nombre vacío' => [[$una('  ', ['S'])], 'necesita un nombre'],
            'nombre largo' => [[$una(str_repeat('x', 51), ['S'])], 'muy largo'],
            'nombre duplicado' => [[$una('Talle', ['S']), $una('talle ', ['M'])], 'dos características'],
            'sin opciones' => [[$una('Talle', [])], 'al menos una opción'],
            'opción vacía' => [[$una('Talle', ['S', ' '])], 'opción vacía'],
            'opción vacía como objeto' => [[$una('Talle', [['nombre' => '']])], 'opción vacía'],
            'opción duplicada' => [[$una('Talle', ['M', 'm'])], 'está repetida'],
            'opción larga' => [[$una('Talle', [str_repeat('x', 51)])], 'muy larga'],
            'más de tres' => [[$una('A', ['1']), $una('B', ['1']), $una('C', ['1']), $una('D', ['1'])], 'hasta 3'],
            'demasiadas opciones' => [[$una('Talle', array_map('strval', range(1, 31)))], 'hasta 30 opciones'],
            'demasiadas combinaciones' => [[
                $una('A', array_map('strval', range(1, 10))),
                $una('B', array_map('strval', range(1, 10))),
                $una('C', array_map('strval', range(1, 4))),
            ], '400 combinaciones'],
        ];
    }

    // ============================================================ variantes

    public function testLasVariantesSeReconocenPorNombreYLasVaciasNoSeGuardan()
    {
        list($caracteristicas) = ProductosFisicos::caracteristicas($this->talleYColor());

        list($variantes, $error) = ProductosFisicos::variantes([
            ['opciones' => ['M', 'azul'], 'stock' => '3'],
            ['opciones' => ['S', 'Rojo'], 'stock' => 0],
            ['opciones' => ['L', 'Rojo'], 'stock' => ''],
        ], $caracteristicas);

        $this->assertNull($error);
        $this->assertSame([
            ['opciones' => [1, 1], 'stock' => 3],
            ['opciones' => [0, 0], 'stock' => 0],
        ], $variantes);
    }

    /**
     * @dataProvider variantesInvalidas
     */
    public function testValidaLasVariantes($variantes, $fragmento)
    {
        list($caracteristicas) = ProductosFisicos::caracteristicas($this->talleYColor());

        list($resultado, $error) = ProductosFisicos::variantes($variantes, $caracteristicas);

        $this->assertNull($resultado);
        $this->assertStringContainsString($fragmento, $error);
    }

    public function variantesInvalidas()
    {
        return [
            'stock negativo' => [[['opciones' => ['S', 'Rojo'], 'stock' => -2]], 'entero igual o mayor que 0'],
            'stock decimal' => [[['opciones' => ['S', 'Rojo'], 'stock' => '1.5']], 'entero igual o mayor que 0'],
            'opción que no existe' => [[['opciones' => ['XL', 'Rojo'], 'stock' => 1]], 'no existe'],
            'falta una característica' => [[['opciones' => ['S'], 'stock' => 1]], 'una opción de cada'],
            'repetida' => [[
                ['opciones' => ['S', 'Rojo'], 'stock' => 1],
                ['opciones' => ['s', 'rojo'], 'stock' => 2],
            ], 'repetida'],
        ];
    }

    public function testVariantesSinCaracteristicasNoSeAceptan()
    {
        list(, $error) = ProductosFisicos::variantes([['opciones' => [], 'stock' => 1]], []);

        $this->assertStringContainsString('necesita características', $error);
    }

    // ===================================================== datos del pedido

    public function testDatosDeUnFisicoSinCaracteristicas()
    {
        list($datos, $error) = ProductosFisicos::datosDelPedido([
            'costo_envio' => '1500', 'zona_envio' => 'Sólo CABA', 'stock' => '10',
        ]);

        $this->assertNull($error);
        $this->assertSame(['costo_envio' => 1500.0, 'zona_envio' => 'Sólo CABA', 'stock' => 10], $datos);
    }

    /** Con características el stock va por combinación: el general se vacía. */
    public function testConCaracteristicasElStockGeneralSeVacia()
    {
        list($datos) = ProductosFisicos::datosDelPedido([
            'stock' => '10',
            'caracteristicas' => $this->talleYColor(),
        ]);

        $this->assertNull($datos['stock']);
        $this->assertSame([], $datos['variantes']);
        $this->assertCount(2, $datos['caracteristicas']);
    }

    public function testSoloLoQueLlega()
    {
        $this->assertSame([[], null], ProductosFisicos::datosDelPedido(['text' => 'Remera']));
    }

    /**
     * @dataProvider pedidosInvalidos
     */
    public function testDatosInvalidos(array $cuerpo, $fragmento)
    {
        list($datos, $error) = ProductosFisicos::datosDelPedido($cuerpo);

        $this->assertNull($datos);
        $this->assertStringContainsString($fragmento, $error);
    }

    public function pedidosInvalidos()
    {
        return [
            'costo negativo' => [['costo_envio' => -1], 'costo de envío'],
            'costo no numérico' => [['costo_envio' => 'caro'], 'costo de envío'],
            'stock negativo' => [['stock' => -3], 'stock'],
            'zona larga' => [['zona_envio' => str_repeat('a', 300)], 'zona de envío'],
            'variantes sin características' => [['variantes' => []], 'junto con las características'],
            'característica sin nombre' => [['caracteristicas' => [['nombre' => '', 'opciones' => ['S']]]], 'nombre'],
        ];
    }

    // ====================================================== formas de entrega

    /** Un físico de antes de #53 no tiene las columnas: es sólo envío. */
    public function testSinLasColumnasEsSoloEnvio()
    {
        $this->assertSame(['envio'], ProductosFisicos::formasDeEntrega(['tipo' => 'fisico']));
    }

    public function testLasFormasHabilitadasEnOrden()
    {
        $this->assertSame(['envio', 'retiro', 'coordinar'], ProductosFisicos::formasDeEntrega([
            'entrega_envio' => '1', 'entrega_retiro' => '1', 'entrega_coordinar' => '1',
        ]));
        $this->assertSame(['coordinar'], ProductosFisicos::formasDeEntrega([
            'entrega_envio' => 0, 'entrega_retiro' => '0', 'entrega_coordinar' => 1,
        ]));
    }

    public function testEnElAltaHaceFaltaAlMenosUnaForma()
    {
        list($datos, $error) = ProductosFisicos::datosDelPedido(['entrega_envio' => false]);

        $this->assertNull($datos);
        $this->assertSame(ProductosFisicos::ERROR_SIN_FORMAS, $error);
    }

    /** Lo que no llega queda como estaba: se suma a lo que llega para mirar la regla. */
    public function testEnLaEdicionLaReglaMiraComoQuedaElProducto()
    {
        $actual = ['entrega_envio' => 1, 'entrega_retiro' => 0, 'entrega_coordinar' => 1];

        $this->assertSame([['entrega_envio' => 0], null], ProductosFisicos::datosDelPedido(['entrega_envio' => 0], $actual));
        $this->assertSame(
            [null, ProductosFisicos::ERROR_SIN_FORMAS],
            ProductosFisicos::datosDelPedido(['entrega_envio' => 0, 'entrega_coordinar' => 0], $actual)
        );
    }

    public function testConRetiroLaDireccionEsObligatoria()
    {
        $this->assertSame(
            [null, ProductosFisicos::ERROR_RETIRO_SIN_DIRECCION],
            ProductosFisicos::datosDelPedido(['entrega_retiro' => 1])
        );

        // Destildado, la dirección no hace falta.
        list(, $error) = ProductosFisicos::datosDelPedido(['entrega_retiro' => 0, 'retiro_direccion' => '']);
        $this->assertNull($error);
    }

    public function testElPuntoDeRetiroComoLoVeQuienCompra()
    {
        $producto = [
            'entrega_retiro' => 1, 'retiro_direccion' => 'Corrientes 1234', 'retiro_maps_url' => null, 'retiro_indicaciones' => '',
        ];

        $this->assertSame([
            'direccion' => 'Corrientes 1234',
            'maps_url' => 'https://www.google.com/maps/search/?api=1&query=Corrientes%201234',
            'indicaciones' => null,
        ], ProductosFisicos::retiro($producto));
        $this->assertNull(ProductosFisicos::retiro(['entrega_retiro' => 0] + $producto));
    }

    // ================================================================ guardar

    /** El producto 30, del link 5, sin características guardadas. */
    private function productoNuevo()
    {
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['id' => 30, 'link_id' => 5, 'tipo' => 'fisico']]);
    }

    public function testGuardaElEnvioYElStockEnLaFilaDelProducto()
    {
        ProductosFisicos::guardar($this->db, 5, ['costo_envio' => 0.0, 'zona_envio' => null, 'stock' => 4]);

        $this->assertSame([0.0, null, 4, 5], $this->db->paramsFor('UPDATE digital_products SET costo_envio = ?, zona_envio = ?, stock = ?'));
        $this->assertFalse($this->db->ran('product_attributes'));
    }

    public function testGuardaCaracteristicasNuevasYElStockPorCombinacion()
    {
        $this->productoNuevo();
        $this->db->onInsert('INSERT INTO product_attributes', 70);
        $this->db->onInsert('INSERT INTO product_attribute_options', 100);
        $this->db->onInsert('INSERT INTO product_attribute_options', 101);
        $this->db->onInsert('INSERT INTO product_attributes', 71);
        $this->db->onInsert('INSERT INTO product_attribute_options', 200);
        $this->db->onInsert('INSERT INTO product_attribute_options', 102);

        list($datos) = ProductosFisicos::datosDelPedido([
            'caracteristicas' => [
                ['nombre' => 'Talle', 'opciones' => ['S', 'M']],
                ['nombre' => 'Color', 'opciones' => ['Rojo', 'Azul']],
            ],
            'variantes' => [
                ['opciones' => ['M', 'Rojo'], 'stock' => 0],
                ['opciones' => ['S', 'Azul'], 'stock' => 8],
            ],
        ]);

        ProductosFisicos::guardar($this->db, 5, $datos);

        $atributos = $this->db->callsFor('INSERT INTO product_attributes');
        $this->assertSame([30, 'Talle', 0], $atributos[0]['params']);
        $this->assertSame([30, 'Color', 1], $atributos[1]['params']);

        $opciones = array_column($this->db->callsFor('INSERT INTO product_attribute_options'), 'params');
        $this->assertSame([[70, 'S', 0], [70, 'M', 1], [71, 'Rojo', 0], [71, 'Azul', 1]], $opciones);

        $this->assertSame([30], $this->db->paramsFor('DELETE FROM product_variants WHERE product_id = ?'));
        $variantes = array_column($this->db->callsFor('INSERT INTO product_variants'), 'params');
        // M = 101, Rojo = 200: la clave va de menor a mayor.
        $this->assertSame([[30, '101-200', 0], [30, '100-102', 8]], $variantes);
    }

    /** Las opciones guardadas del producto 30: Talle (70) con S (100, usada) y M (101). */
    private function talleGuardado($sUsada = true, $mUsada = false)
    {
        $this->productoNuevo();
        $this->db->onSelect('SELECT id, nombre FROM product_attributes', [['id' => 70, 'nombre' => 'Talle']]);
        $this->db->onSelect('FROM product_attribute_options o', [
            ['id' => 100, 'attribute_id' => 70, 'nombre' => 'S', 'usada' => $sUsada ? 1 : 0],
            ['id' => 101, 'attribute_id' => 70, 'nombre' => 'M', 'usada' => $mUsada ? 1 : 0],
        ]);
    }

    public function testRenombrarUnaOpcionPorSuIdLaConserva()
    {
        $this->talleGuardado();

        ProductosFisicos::guardar($this->db, 5, [
            'caracteristicas' => [['id' => 70, 'nombre' => 'Talles', 'opciones' => [
                ['id' => 100, 'nombre' => 'Small'], ['id' => 101, 'nombre' => 'M'],
            ]]],
            'variantes' => [],
        ]);

        $this->assertSame(['Talles', 0, 70], $this->db->paramsFor('UPDATE product_attributes SET nombre = ?'));
        $updates = array_column($this->db->callsFor('UPDATE product_attribute_options SET nombre = ?'), 'params');
        $this->assertSame([['Small', 0, 100], ['M', 1, 101]], $updates);
        $this->assertFalse($this->db->ran('INSERT INTO product_attribute_options'));
        $this->assertFalse($this->db->ran('DELETE FROM product_attribute_options'));
    }

    /** No se puede quitar una opción que ya está en un pedido: se desactiva. */
    public function testQuitarUnaOpcionUsadaLaDesactivaYNoLaBorra()
    {
        $this->talleGuardado(true, false);

        ProductosFisicos::guardar($this->db, 5, [
            'caracteristicas' => [['id' => 70, 'nombre' => 'Talle', 'opciones' => [['id' => 101, 'nombre' => 'M']]]],
            'variantes' => [],
        ]);

        $this->assertSame([100], $this->db->paramsFor('UPDATE product_attribute_options SET activa = 0'));
        $this->assertFalse($this->db->ran('DELETE FROM product_attribute_options'));
    }

    public function testQuitarUnaOpcionSinPedidosLaBorra()
    {
        $this->talleGuardado(true, false);

        ProductosFisicos::guardar($this->db, 5, [
            'caracteristicas' => [['id' => 70, 'nombre' => 'Talle', 'opciones' => [['id' => 100, 'nombre' => 'S']]]],
            'variantes' => [],
        ]);

        $this->assertSame([101], $this->db->paramsFor('DELETE FROM product_attribute_options WHERE id = ?'));
        $this->assertFalse($this->db->ran('SET activa = 0'));
    }

    /** Quitar la característica entera: la opción usada queda, desactivada, y su característica también. */
    public function testQuitarUnaCaracteristicaConUnaOpcionUsadaLaDesactiva()
    {
        $this->talleGuardado(true, false);

        ProductosFisicos::guardar($this->db, 5, ['caracteristicas' => [], 'variantes' => []]);

        $this->assertSame([100], $this->db->paramsFor('UPDATE product_attribute_options SET activa = 0'));
        $this->assertSame([101], $this->db->paramsFor('DELETE FROM product_attribute_options WHERE id = ?'));
        $this->assertSame([70], $this->db->paramsFor('UPDATE product_attributes SET activa = 0'));
        $this->assertFalse($this->db->ran('DELETE FROM product_attributes'));
    }

    public function testQuitarUnaCaracteristicaSinPedidosLaBorra()
    {
        $this->talleGuardado(false, false);

        ProductosFisicos::guardar($this->db, 5, ['caracteristicas' => [], 'variantes' => []]);

        $this->assertSame([70], $this->db->paramsFor('DELETE FROM product_attributes WHERE id = ?'));
        $this->assertSame(2, $this->db->countCalls('DELETE FROM product_attribute_options'));
    }

    /** Volver a agregar una opción desactivada (mismo nombre) la reactiva, en vez de duplicarla. */
    public function testVolverAAgregarUnaOpcionPorNombreLaReactiva()
    {
        $this->talleGuardado();

        ProductosFisicos::guardar($this->db, 5, [
            'caracteristicas' => [['nombre' => 'talle', 'opciones' => [['nombre' => 's'], ['nombre' => 'M']]]],
            'variantes' => [],
        ]);

        $this->assertFalse($this->db->ran('INSERT INTO product_attribute'));
        $this->assertSame(['s', 0, 100], $this->db->paramsFor('UPDATE product_attribute_options SET nombre = ?, posicion = ?, activa = 1'));
    }

    public function testGuardarCaracteristicasDeUnProductoQueNoExisteFalla()
    {
        $this->expectException(\RuntimeException::class);

        ProductosFisicos::guardar($this->db, 5, ['caracteristicas' => [], 'variantes' => []]);
    }

    // ================================================================ lectura

    public function testCombinaciones()
    {
        $caracteristicas = [
            ['opciones' => [['id' => 3], ['id' => 1]]],
            ['opciones' => [['id' => 2]]],
        ];

        $this->assertSame(['2-3', '1-2'], ProductosFisicos::combinaciones($caracteristicas));
        $this->assertSame([], ProductosFisicos::combinaciones([]));
    }

    /**
     * @dataProvider agotados
     */
    public function testAgotado(array $producto, $esperado)
    {
        $this->assertSame($esperado, ProductosFisicos::agotado($producto + ['tipo' => 'fisico']));
    }

    public function agotados()
    {
        $talle = [['opciones' => [['id' => 1], ['id' => 2]]]];

        return [
            'sin stock cargado' => [['stock' => null], false],
            'con stock' => [['stock' => 3], false],
            'stock cero' => [['stock' => 0], true],
            'una combinación sin límite' => [['caracteristicas' => $talle, 'variantes' => [['clave' => '1', 'stock' => 0]]], false],
            'una con stock' => [['caracteristicas' => $talle, 'variantes' => [['clave' => '1', 'stock' => 0], ['clave' => '2', 'stock' => 1]]], false],
            'todas en cero' => [['caracteristicas' => $talle, 'variantes' => [['clave' => '1', 'stock' => 0], ['clave' => '2', 'stock' => 0]]], true],
        ];
    }

    public function testUnDigitalNuncaEstaAgotado()
    {
        $this->assertFalse(ProductosFisicos::agotado(['tipo' => 'digital', 'stock' => 0]));
    }

    public function testCompletarNoConsultaNadaSiNoHayFisicos()
    {
        $filas = [['id' => 5, 'producto_id' => 30, 'tipo' => 'digital']];

        $this->assertSame($filas, ProductosFisicos::completar($this->db, $filas));
        $this->assertSame([], $this->db->log());
    }

    public function testCompletarSumaCaracteristicasYStockVigente()
    {
        $this->db->onSelect('FROM product_attributes a', [
            ['atributo_id' => 70, 'product_id' => 30, 'atributo' => 'Talle', 'opcion_id' => 100, 'opcion' => 'S'],
            ['atributo_id' => 70, 'product_id' => 30, 'atributo' => 'Talle', 'opcion_id' => 101, 'opcion' => 'M'],
        ]);
        $this->db->onSelect('FROM product_variants', [
            ['product_id' => 30, 'clave' => '100', 'stock' => '0'],
            ['product_id' => 30, 'clave' => '101', 'stock' => '0'],
            // De una opción que ya no se ofrece: no sale.
            ['product_id' => 30, 'clave' => '99', 'stock' => '4'],
        ]);

        $filas = ProductosFisicos::completar($this->db, [
            ['id' => 5, 'producto_id' => 30, 'tipo' => 'fisico', 'stock' => '7'],
            ['id' => 6, 'producto_id' => 31, 'tipo' => 'digital'],
        ]);

        $this->assertSame([[
            'id' => 70, 'nombre' => 'Talle',
            'opciones' => [['id' => 100, 'nombre' => 'S'], ['id' => 101, 'nombre' => 'M']],
        ]], $filas[0]['caracteristicas']);
        $this->assertSame([['clave' => '100', 'stock' => 0], ['clave' => '101', 'stock' => 0]], $filas[0]['variantes']);
        $this->assertNull($filas[0]['stock'], 'con características el stock general no cuenta');
        $this->assertTrue($filas[0]['agotado']);
        $this->assertArrayNotHasKey('caracteristicas', $filas[1]);
        $this->assertSame([30], $this->db->paramsFor('FROM product_attributes a'));
    }
}
