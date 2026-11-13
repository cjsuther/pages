<?php

namespace Tests\Unit\Lib;

use CargosAlComprador;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CargosAlCompradorTest extends TestCase
{
    const TASAS = [
        'mp_porcentaje' => 4.39, 'mp_fijo' => 0, 'mp_iva' => 21,
        'plataforma_porcentaje' => 1.5, 'plataforma_iva' => 0,
    ];

    protected function tearDown(): void
    {
        CargosAlComprador::forzar(null);
    }

    /** Los mismos casos que prueba el navegador, con esperados de aritmética exacta. */
    public function casosCompartidos()
    {
        $datos = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/cargos-al-comprador.json'), true);
        $casos = [];

        foreach ($datos['casos'] as $caso) {
            $casos[$caso['nombre']] = [$caso['precio'], $caso['tasas'], $caso['esperado']];
        }

        return $casos;
    }

    /** @dataProvider casosCompartidos */
    public function testDaLoMismoQueLaCuentaExacta($precio, $tasas, array $esperado)
    {
        $tasas = $tasas === null ? null : CargosAlComprador::validar($tasas);
        $calculado = CargosAlComprador::calcular($precio, $tasas);

        foreach ($esperado as $clave => $valor) {
            $this->assertEqualsWithDelta($valor, $calculado[$clave], 0.0000001, $clave);
        }
    }

    /**
     * Lo que se muestra suma exacto lo que se cobra, y al organizador le llega
     * el precio: nunca menos, y como mucho menos de un centavo de más.
     */
    public function testElDesgloseSumaElTotalYElOrganizadorRecibeElPrecio()
    {
        mt_srand(20261108);
        $variantes = [
            self::TASAS,
            ['mp_porcentaje' => 6.29, 'mp_fijo' => 0, 'mp_iva' => 21, 'plataforma_porcentaje' => 1.5, 'plataforma_iva' => 21],
            ['mp_porcentaje' => 1.49, 'mp_fijo' => 15.5, 'mp_iva' => 21, 'plataforma_porcentaje' => 3, 'plataforma_iva' => 0],
        ];

        foreach ($variantes as $tasas) {
            for ($i = 0; $i < 2000; $i++) {
                $precio = mt_rand(1, 50000000) / 100;
                $r = CargosAlComprador::calcular($precio, $tasas);

                $this->assertSame(
                    (int) round($r['total'] * 100),
                    (int) round($r['precio'] * 100) + (int) round($r['mercadopago'] * 100) + (int) round($r['plataforma'] * 100),
                    "no suma con $precio"
                );

                $f = $tasas['mp_porcentaje'] / 100 * (1 + $tasas['mp_iva'] / 100);
                $fijo = $tasas['mp_fijo'] * (1 + $tasas['mp_iva'] / 100);
                $recibe = $r['total'] - ($r['total'] * $f + $fijo) - $r['plataforma'];

                $this->assertGreaterThanOrEqual($precio - 0.000001, $recibe, "al organizador le falta con $precio");
                $this->assertLessThan($precio + 0.01, $recibe, "se cobra de más con $precio");
            }
        }
    }

    public function testLaComisionDeRezonarVaSobreElPrecioNoSobreElTotal()
    {
        $r = CargosAlComprador::calcular(10000, self::TASAS);

        $this->assertSame(150.0, $r['plataforma']);
        // Gross-up: más que el 5,31% del precio (531,19), porque MP cobra sobre el total.
        $this->assertGreaterThan(531.19, $r['mercadopago']);
    }

    public function testVariasEntradasDanLoMismoQueSuSuma()
    {
        // La cuenta se hace sobre lo que suma la compra, no por entrada: así
        // el redondeo es uno solo y no se multiplica por la cantidad.
        $tres = CargosAlComprador::calcular(3 * 3333.33, self::TASAS);

        $this->assertSame(9999.99, $tres['precio']);
        $this->assertSame(round($tres['precio'] + $tres['mercadopago'] + $tres['plataforma'], 2), $tres['total']);
    }

    public function testApagadaPorDefecto()
    {
        // tests/bootstrap.php no define CARGOS_AL_COMPRADOR, igual que un
        // config.php que no se tocó: la función arranca apagada.
        $this->assertFalse(defined('CARGOS_AL_COMPRADOR'));
        $this->assertNull(CargosAlComprador::tasas());
        $this->assertFalse(CargosAlComprador::disponible());
        $this->assertNull(CargosAlComprador::tasasDelEvento(['cargos_al_comprador' => 1], true));
    }

    public function testForzarEnciendeYApaga()
    {
        CargosAlComprador::forzar(self::TASAS);
        $this->assertTrue(CargosAlComprador::disponible());

        CargosAlComprador::forzar(false);
        $this->assertFalse(CargosAlComprador::disponible());
    }

    public function testForzarRechazaTasasInvalidas()
    {
        $this->expectException(InvalidArgumentException::class);
        CargosAlComprador::forzar(['mp_porcentaje' => 'mucho'] + self::TASAS);
    }

    public function testElEventoTieneQuePedirlo()
    {
        CargosAlComprador::forzar(self::TASAS);

        $this->assertNull(CargosAlComprador::tasasDelEvento(['cargos_al_comprador' => 0], true));
        $this->assertNull(CargosAlComprador::tasasDelEvento([], true));
        $this->assertSame(1.5, CargosAlComprador::tasasDelEvento(['cargos_al_comprador' => 1], true)['plataforma_porcentaje']);
    }

    /** Sin split la comisión de Rezon.ar no llega: no se le cobra al comprador. */
    public function testSinSplitSoloSeSumaLaDeMercadoPago()
    {
        CargosAlComprador::forzar(self::TASAS);

        $tasas = CargosAlComprador::tasasDelEvento(['cargos_al_comprador' => 1], false);
        $r = CargosAlComprador::calcular(10000, $tasas);

        $this->assertSame(0.0, $r['plataforma']);
        $this->assertGreaterThan(0, $r['mercadopago']);
    }

    public function testValidarRechazaLoQueNoTieneSentido()
    {
        $this->assertNull(CargosAlComprador::validar(['mp_porcentaje' => -1] + self::TASAS));
        $this->assertNull(CargosAlComprador::validar(['mp_porcentaje' => 43.9] + self::TASAS), 'error de tipeo');
        $this->assertNull(CargosAlComprador::validar(['mp_iva' => 210] + self::TASAS));
        $this->assertNull(CargosAlComprador::validar(['plataforma_iva' => 210] + self::TASAS));
        $faltante = self::TASAS;
        unset($faltante['mp_fijo']);
        $this->assertNull(CargosAlComprador::validar($faltante));
        $this->assertSame(\Comision::MAXIMO_PORCENTAJE + 0.0, CargosAlComprador::validar(['plataforma_porcentaje' => 99] + self::TASAS)['plataforma_porcentaje']);
    }

    public function testLeeLasCuentasDeUnaOrdenGuardada()
    {
        $orden = ['total' => '10719.41', 'cargo_mercadopago' => '569.41', 'cargo_plataforma' => '150.00'];

        $this->assertSame(719.41, CargosAlComprador::deLaOrden($orden));
        $this->assertSame(10000.0, CargosAlComprador::precioDeLaOrden($orden));
        // Una orden de antes de las columnas: el total es el precio.
        $this->assertSame(0.0, CargosAlComprador::deLaOrden(['total' => '5000.00']));
        $this->assertSame(5000.0, CargosAlComprador::precioDeLaOrden(['total' => '5000.00']));
    }
}
