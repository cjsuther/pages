<?php

namespace Tests\Unit\Lib;

use PHPUnit\Framework\TestCase;
use PrecioDeReferencia;

class PrecioDeReferenciaTest extends TestCase
{
    // ---------------------------------------------------------------- valor

    public function testDeUnEventoQueNoVendeEsSuPrecioDesde()
    {
        $this->assertSame(15000.0, PrecioDeReferencia::deEvento(['precio_desde' => '15000.00'], null));
        $this->assertSame(0.0, PrecioDeReferencia::deEvento(['precio_desde' => '0.00'], null));
        $this->assertNull(PrecioDeReferencia::deEvento(['precio_desde' => null], null));
        $this->assertNull(PrecioDeReferencia::deEvento([], null));
    }

    /** Si vende acá, manda la venta aunque haya un precio cargado a mano. */
    public function testDeUnEventoQueVendeEsElPrecioDeLaVenta()
    {
        $this->assertSame(8000.0, PrecioDeReferencia::deEvento(['precio_desde' => '99999.00'], ['precio' => 8000]));
        $this->assertSame(0.0, PrecioDeReferencia::deEvento(['precio_desde' => '5000.00'], ['precio' => 0.0]));
        $this->assertSame(0.0, PrecioDeReferencia::deEvento(['precio_desde' => null], ['precio' => 0.0]));
    }

    public function testCompletarDejaNullONumero()
    {
        $filas = PrecioDeReferencia::completar([
            ['id' => 1, 'precio_referencia' => '15000.00'],
            ['id' => 2, 'precio_referencia' => '0.00'],
            ['id' => 3, 'precio_referencia' => null],
            ['id' => 4],
        ]);

        $this->assertSame([15000.0, 0.0, null, null], array_column($filas, 'precio_referencia'));
    }

    /** La columna y la condición salen de la misma expresión y del mismo JOIN. */
    public function testLaColumnaUsaLaVentaActivaOElPrecioDesde()
    {
        $this->assertStringContainsString('pr_venta.activo = 1', PrecioDeReferencia::JOIN);
        $this->assertStringContainsString('pr_venta.precio', PrecioDeReferencia::COLUMNA);
        $this->assertStringContainsString('l.precio_desde', PrecioDeReferencia::COLUMNA);
        $this->assertStringEndsWith('AS precio_referencia', PrecioDeReferencia::COLUMNA);
    }

    // --------------------------------------------------------------- filtro

    public function testSinFiltroEsCualquierPrecio()
    {
        $this->assertSame(['ok' => true, 'filtro' => null], PrecioDeReferencia::filtro(null));
        $this->assertSame(['ok' => true, 'filtro' => null], PrecioDeReferencia::filtro(''));
        $this->assertSame(['', []], PrecioDeReferencia::condicion(null));
    }

    public function testElFiltroGratis()
    {
        $filtro = PrecioDeReferencia::filtro('gratis');

        $this->assertSame(['tipo' => 'gratis'], $filtro['filtro']);
        $this->assertSame([PrecioDeReferencia::EXPRESION . ' = 0', []], PrecioDeReferencia::condicion($filtro['filtro']));
        $this->assertSame(['tipo' => 'gratis'], PrecioDeReferencia::filtro(' Gratis ')['filtro']);
    }

    public function testElFiltroHasta()
    {
        $filtro = PrecioDeReferencia::filtro('hasta:25000');

        $this->assertSame(['tipo' => 'hasta', 'monto' => 25000.0], $filtro['filtro']);
        $this->assertSame([PrecioDeReferencia::EXPRESION . ' <= ?', [25000.0]], PrecioDeReferencia::condicion($filtro['filtro']));
    }

    /**
     * @dataProvider filtrosInvalidos
     */
    public function testUnFiltroQueNoSeEntiendeEsInvalido($valor)
    {
        $this->assertFalse(PrecioDeReferencia::filtro($valor)['ok']);
    }

    public function filtrosInvalidos()
    {
        return [
            'otra palabra' => ['barato'],
            'hasta sin monto' => ['hasta:'],
            'hasta cero' => ['hasta:0'],
            'monto negativo' => ['hasta:-5'],
            'monto con letras' => ['hasta:25mil'],
            'inyección' => ["hasta:1 OR 1=1"],
            'demasiado grande' => ['hasta:999999999'],
            'un array' => [['gratis']],
        ];
    }
}
