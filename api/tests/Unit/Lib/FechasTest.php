<?php

namespace Tests\Unit\Lib;

use Fechas;
use PHPUnit\Framework\TestCase;

class FechasTest extends TestCase
{
    public function testDevuelveLaFechaEnFormatoDeLaBase()
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', Fechas::hoy());
    }

    public function testDuranteElDiaCoincideConLaFechaUtc()
    {
        // 15:00 UTC son las 12:00 en Argentina: mismo día.
        $this->assertSame('2026-08-16', Fechas::hoy('2026-08-16 15:00:00'));
    }

    /**
     * Es la franja que rompía: a las 22:00 UTC en Argentina son las 19:00 del
     * mismo día, pero a las 00:30 UTC ya es el día siguiente para el servidor
     * mientras en Argentina siguen siendo las 21:30 del día anterior.
     */
    public function testALasNueveDeLaNocheArgentinaTodaviaEsElMismoDia()
    {
        $this->assertSame('2026-08-16', Fechas::hoy('2026-08-17 00:30:00'));
    }

    public function testAMediaNocheArgentinaReciénCambiaElDia()
    {
        // 03:00 UTC = 00:00 en Argentina.
        $this->assertSame('2026-08-17', Fechas::hoy('2026-08-17 03:00:00'));
    }

    public function testUnMinutoAntesDeMedianocheArgentinaSigueSiendoElDiaAnterior()
    {
        $this->assertSame('2026-08-16', Fechas::hoy('2026-08-17 02:59:00'));
    }

    public function testCambiaDeMesCorrectamente()
    {
        $this->assertSame('2026-08-31', Fechas::hoy('2026-09-01 02:00:00'));
    }

    public function testCambiaDeAnoCorrectamente()
    {
        $this->assertSame('2026-12-31', Fechas::hoy('2027-01-01 02:00:00'));
    }

    /** Argentina no tiene horario de verano: el desfase es siempre -3. */
    public function testElDesfaseEsElMismoEnVeranoYEnInvierno()
    {
        $this->assertSame('2026-01-15', Fechas::hoy('2026-01-16 02:00:00'));
        $this->assertSame('2026-07-15', Fechas::hoy('2026-07-16 02:00:00'));
    }
}
