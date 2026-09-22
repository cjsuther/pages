<?php

namespace Tests\Unit\Lib;

use PHPUnit\Framework\TestCase;
use Plano;

class PlanoTest extends TestCase
{
    private function plano(array $elementos, $ancho = 20, $alto = 12)
    {
        return ['ancho' => $ancho, 'alto' => $alto, 'elementos' => $elementos];
    }

    private function fila($nombre = 'A', $butacas = 4, $desde = 1, $x = 0, $y = 0)
    {
        return ['tipo' => 'fila', 'nombre' => $nombre, 'butacas' => $butacas, 'desde' => $desde, 'x' => $x, 'y' => $y];
    }

    private function mesa($nombre = '1', $lugares = 4, $x = 5, $y = 5)
    {
        return ['tipo' => 'mesa', 'nombre' => $nombre, 'lugares' => $lugares, 'x' => $x, 'y' => $y];
    }

    // --------------------------------------------------------------- lugares

    public function testLasFilasYLasMesasDanSusLugares()
    {
        $plano = $this->plano([$this->fila('A', 3), $this->mesa('2', 2)]);

        $this->assertSame(['f:A:1', 'f:A:2', 'f:A:3', 'm:2:1', 'm:2:2'], Plano::lugares($plano));
    }

    /** Una fila partida por un pasillo sigue la numeración del tramo anterior. */
    public function testUnaFilaPuedeEmpezarANumerarDesdeOtroNumero()
    {
        $plano = $this->plano([$this->fila('A', 2, 7)]);

        $this->assertSame(['f:A:7', 'f:A:8'], Plano::lugares($plano));
    }

    public function testElEscenarioNoTieneLugares()
    {
        $plano = $this->plano([
            ['tipo' => 'escenario', 'texto' => 'Escenario', 'ancho' => 6, 'alto' => 2, 'x' => 0, 'y' => 0],
        ]);

        $this->assertSame([], Plano::lugares($plano));
    }

    // ------------------------------------------------------------ normalizar

    public function testUnPlanoValidoVuelveLimpio()
    {
        $r = Plano::normalizar($this->plano([$this->fila() + ['basura' => 'x'], $this->mesa()]));

        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey('basura', $r['plano']['elementos'][0]);
        $this->assertSame('redonda', $r['plano']['elementos'][1]['forma']);
    }

    public function testUnPlanoSinLugaresNoSirve()
    {
        $r = Plano::normalizar($this->plano([]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ningún lugar', $r['error']);
    }

    /** Dos butacas A1 no permitirían saber cuál se vendió. */
    public function testNoSePuedenRepetirLugares()
    {
        $r = Plano::normalizar($this->plano([$this->fila('A', 4, 1), $this->fila('A', 4, 3, 0, 2)]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('repetidos', $r['error']);
        $this->assertStringContainsString('Fila A: 3, 4', $r['error']);
    }

    /** Una fila y una mesa con el mismo nombre no chocan: son lugares distintos. */
    public function testUnaFilaYUnaMesaPuedenLlamarseIgual()
    {
        $this->assertTrue(Plano::normalizar($this->plano([$this->fila('1'), $this->mesa('1')]))['ok']);
    }

    /** Los dos puntos separan las partes del identificador del lugar. */
    public function testElNombreNoPuedeTenerSeparadores()
    {
        $r = Plano::normalizar($this->plano([$this->fila('A:1')]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Elemento 1', $r['error']);
    }

    public function testUnElementoFueraDelPlanoNoSirve()
    {
        $this->assertFalse(Plano::normalizar($this->plano([$this->fila('A', 4, 1, 25, 0)]))['ok']);
    }

    public function testUnaMesaTieneTopeDeLugares()
    {
        $this->assertFalse(Plano::normalizar($this->plano([$this->mesa('1', Plano::MAX_LUGARES_POR_MESA + 1)]))['ok']);
    }

    public function testUnTipoDesconocidoNoSirve()
    {
        $this->assertFalse(Plano::normalizar($this->plano([['tipo' => 'palco', 'x' => 0, 'y' => 0]]))['ok']);
    }

    public function testAlgoQueNoEsUnPlanoNoSirve()
    {
        $this->assertFalse(Plano::normalizar('un plano')['ok']);
        $this->assertFalse(Plano::normalizar(['ancho' => 10])['ok']);
    }

    public function testElPlanoTieneTamanioMaximo()
    {
        $this->assertFalse(Plano::normalizar($this->plano([$this->fila()], Plano::ANCHO_MAXIMO + 1))['ok']);
    }

    // -------------------------------------------------------------- describir

    public function testUnLugarSeDescribeParaLeerse()
    {
        $this->assertSame('Fila A, butaca 7', Plano::describir('f:A:7'));
        $this->assertSame('Mesa 3, lugar 2', Plano::describir('m:3:2'));
    }

    public function testVariosLugaresSeAgrupan()
    {
        $this->assertSame(
            'Fila A: 7, 8 · Mesa 3: 1',
            Plano::resumir(['f:A:8', 'f:A:7', 'm:3:1'])
        );
    }
}
