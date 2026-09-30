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

    // ------------------------------------------------------ límites y formas

    private function escenario($ancho = 6, $alto = 2, $texto = 'Escenario', $x = 0, $y = 0)
    {
        return ['tipo' => 'escenario', 'texto' => $texto, 'ancho' => $ancho, 'alto' => $alto, 'x' => $x, 'y' => $y];
    }

    /** Un tope de elementos: un plano enorme hace lento el editor y el checkout de todos. */
    public function testElPlanoTieneTopeDeElementos()
    {
        $filas = [];
        for ($i = 0; $i <= Plano::MAX_ELEMENTOS; $i++) {
            $filas[] = $this->fila('F' . $i, 1, 1, $i % 20, intdiv($i, 20));
        }

        $r = Plano::normalizar($this->plano($filas, 20, 12));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('más de ' . Plano::MAX_ELEMENTOS, $r['error']);
    }

    /** Aunque cada fila respete su tope, el total de lugares también tiene uno. */
    public function testElPlanoTieneTopeDeLugares()
    {
        $filas = [];
        $cuantas = intdiv(Plano::MAX_LUGARES, Plano::MAX_BUTACAS_POR_FILA) + 1;
        for ($i = 0; $i < $cuantas; $i++) {
            $filas[] = $this->fila('F' . $i, Plano::MAX_BUTACAS_POR_FILA, 1, 0, $i);
        }

        $r = Plano::normalizar($this->plano($filas, 80, 80));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('más de ' . Plano::MAX_LUGARES . ' lugares', $r['error']);
    }

    /** @dataProvider butacasFueraDeRango */
    public function testUnaFilaTieneTopeDeButacas($butacas)
    {
        $r = Plano::normalizar($this->plano([$this->fila('A', $butacas)]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Elemento 1', $r['error']);
        $this->assertStringContainsString('entre 1 y ' . Plano::MAX_BUTACAS_POR_FILA . ' butacas', $r['error']);
    }

    public function butacasFueraDeRango()
    {
        return ['ninguna' => [0], 'demasiadas' => [Plano::MAX_BUTACAS_POR_FILA + 1]];
    }

    /** @dataProvider numeracionesInvalidas */
    public function testLaNumeracionDeUnaFilaTieneRango($desde)
    {
        $r = Plano::normalizar($this->plano([$this->fila('A', 2, $desde)]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('numeración', $r['error']);
    }

    public function numeracionesInvalidas()
    {
        return ['cero' => [0], 'cuatro cifras' => [1000]];
    }

    public function testElNombreDeUnaMesaTieneLasMismasReglas()
    {
        $r = Plano::normalizar($this->plano([$this->mesa('mesa:1')]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nombre de la mesa', $r['error']);
    }

    public function testUnElementoSinTipoNoSirve()
    {
        $r = Plano::normalizar($this->plano([['nombre' => 'A', 'x' => 0, 'y' => 0], $this->fila()]));

        $this->assertFalse($r['ok']);
        $this->assertSame('Elemento 1: no tiene tipo', $r['error']);
    }

    /**
     * El escenario sólo orienta al comprador: se guarda limpio, con un texto
     * por defecto y acotado, y sin claves que el editor no conoce.
     */
    public function testElEscenarioSeGuardaLimpio()
    {
        $largo = str_repeat('Escenario principal ', 5);
        $r = Plano::normalizar($this->plano([
            $this->escenario(6, 2, '  ') + ['color' => 'rojo'],
            $this->escenario(4, 1, $largo, 8, 0),
            $this->fila('A', 2, 1, 0, 5),
        ]));

        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame(
            ['tipo' => 'escenario', 'texto' => 'Escenario', 'ancho' => 6, 'alto' => 2, 'x' => 0, 'y' => 0],
            $r['plano']['elementos'][0]
        );
        $this->assertSame(40, mb_strlen($r['plano']['elementos'][1]['texto']));
        $this->assertSame(['f:A:1', 'f:A:2'], Plano::lugares($r['plano']));
    }

    /** @dataProvider escenariosQueNoEntran */
    public function testUnEscenarioQueNoEntraNoSirve($ancho, $alto)
    {
        $r = Plano::normalizar($this->plano([$this->escenario($ancho, $alto), $this->fila('A', 2, 1, 0, 5)], 20, 12));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no entra en el plano', $r['error']);
    }

    public function escenariosQueNoEntran()
    {
        return ['sin ancho' => [0, 2], 'más ancho que el plano' => [21, 2], 'sin alto' => [6, 0], 'más alto que el plano' => [6, 13]];
    }

    /** Un código viejo o roto se muestra tal cual en vez de inventar una butaca. */
    public function testUnLugarQueNoSeEntiendeSeMuestraTalCual()
    {
        $this->assertSame('general', Plano::describir('general'));
        $this->assertSame('x:1:2', Plano::describir('x:1:2'));
        $this->assertSame('Fila A: 1 · general', Plano::resumir(['f:A:1', 'general']));
    }
}
