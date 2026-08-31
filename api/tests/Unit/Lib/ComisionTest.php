<?php

namespace Tests\Unit\Lib;

use Comision;
use PHPUnit\Framework\TestCase;

class ComisionTest extends TestCase
{
    // PLATFORM_FEE_PERCENT vale 10 en tests/bootstrap.php.

    public function testTomaElPorcentajeDeLaConfiguracion()
    {
        $this->assertSame(10.0, Comision::porcentaje());
    }

    public function testCalculaElMontoSobreElTotal()
    {
        $this->assertSame(1000.0, Comision::sobre(10000));
        $this->assertSame(150.0, Comision::sobre(1500));
    }

    public function testLoQueQuedaParaElVendedorEsElResto()
    {
        $this->assertSame(9000.0, Comision::paraElVendedor(10000));
    }

    /**
     * Si la comisión se redondeara para arriba, la suma de comisión y lo que
     * recibe el dueño podría superar el total y Mercado Pago rechazaría la
     * preferencia entera.
     */
    public function testLaComisionMasLoDelVendedorNuncaSuperaElTotal()
    {
        foreach ([1, 3, 7, 33.33, 99.99, 1234.56, 10000.01] as $total) {
            $this->assertLessThanOrEqual(
                $total + 0.001,
                Comision::sobre($total) + Comision::paraElVendedor($total),
                "se pasa del total con $total"
            );
        }
    }

    public function testElMontoTieneComoMuchoDosDecimales()
    {
        $comision = Comision::sobre(33.33);

        $this->assertSame(round($comision, 2), $comision);
    }

    public function testSinVentaNoHayComision()
    {
        $this->assertSame(0.0, Comision::sobre(0));
        $this->assertSame(0.0, Comision::sobre(-100));
    }

    public function testEstaActivaCuandoElPorcentajeEsMayorACero()
    {
        $this->assertTrue(Comision::activa());
    }

    /**
     * Red para un error de tipeo en config.php: un 100 donde iba 10 se llevaría
     * la venta entera del dueño de la página.
     */
    public function testElPorcentajeTieneUnTope()
    {
        $this->assertLessThanOrEqual(Comision::MAXIMO_PORCENTAJE, Comision::porcentaje());
    }

    public function testElVendedorSiempreRecibeAlgo()
    {
        $this->assertGreaterThan(0, Comision::paraElVendedor(100));
    }

    /**
     * Lo que cobra Mercado Pago sale de config.php como todo lo demás.
     *
     * Antes estaba escrito en dos pantallas del frontend, así que cambiarlo
     * era editar código. Los valores del bootstrap son distintos de los reales
     * justamente para que esto no pase de nuevo sin que un test lo diga.
     */
    public function testInformaLoQueCobraMercadoPagoSegunLaConfiguracion()
    {
        $this->assertSame(
            ['porcentaje' => 7.25, 'dias' => 3],
            Comision::mercadoPago()
        );
    }
}
