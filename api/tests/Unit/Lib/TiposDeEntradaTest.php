<?php

namespace Tests\Unit\Lib;

use PHPUnit\Framework\TestCase;
use TiposDeEntrada;

class TiposDeEntradaTest extends TestCase
{
    private function tipo($id, $nombre, $precio, $cupo = null)
    {
        return ['id' => $id, 'nombre' => $nombre, 'precio' => $precio, 'cupo' => $cupo];
    }

    private function plano(array $elementos)
    {
        return ['ancho' => 20, 'alto' => 12, 'elementos' => $elementos];
    }

    // ------------------------------------------------------------ normalizar

    /** Sin tipos el evento vende a un solo precio, como siempre: vacío y null son lo mismo. */
    public function testSinTiposEsUnSoloPrecio()
    {
        $this->assertSame(['ok' => true, 'error' => null, 'tipos' => null], TiposDeEntrada::normalizar(null));
        $this->assertSame(['ok' => true, 'error' => null, 'tipos' => null], TiposDeEntrada::normalizar([]));
    }

    public function testDevuelveLosTiposLimpios()
    {
        $r = TiposDeEntrada::normalizar([
            ['id' => 'general', 'nombre' => '  General ', 'precio' => '8000', 'cupo' => '', 'sobra' => 'x'],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 5000.555, 'cupo' => '20'],
        ]);

        $this->assertTrue($r['ok']);
        $this->assertSame([
            ['id' => 'general', 'nombre' => 'General', 'precio' => 8000.0, 'cupo' => null],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 5000.56, 'cupo' => 20],
        ], $r['tipos']);
    }

    /** Un "Invitado" en 0 es un tipo válido: no hay que cobrar todas las entradas. */
    public function testUnTipoPuedeSerSinCosto()
    {
        $r = TiposDeEntrada::normalizar([$this->tipo('invitado', 'Invitado', 0)]);

        $this->assertTrue($r['ok']);
        $this->assertSame(0.0, $r['tipos'][0]['precio']);
    }

    /** @dataProvider tiposInvalidos */
    public function testRechazaLoQueNoSirve($tipos, $error)
    {
        $r = TiposDeEntrada::normalizar($tipos);

        $this->assertFalse($r['ok']);
        $this->assertSame($error, $r['error']);
        $this->assertNull($r['tipos']);
    }

    public function tiposInvalidos()
    {
        return [
            'no es una lista'   => ['general', 'Los tipos de entrada no tienen el formato esperado'],
            'un tipo no es objeto' => [['general'], 'Los tipos de entrada no tienen el formato esperado'],
            'id con espacios'   => [[['id' => 'a b', 'nombre' => 'A', 'precio' => 1]], 'Un tipo de entrada no tiene un identificador válido'],
            'id vacío'          => [[['nombre' => 'A', 'precio' => 1]], 'Un tipo de entrada no tiene un identificador válido'],
            'sin nombre'        => [[['id' => 'a', 'nombre' => ' ', 'precio' => 1]], 'Cada tipo de entrada necesita un nombre'],
            'nombre largo'      => [[['id' => 'a', 'nombre' => str_repeat('x', 61), 'precio' => 1]],
                                    'El nombre de un tipo de entrada no puede pasar de 60 letras'],
            'precio negativo'   => [[['id' => 'a', 'nombre' => 'VIP', 'precio' => -1]], 'El precio de "VIP" no puede ser negativo'],
            'cupo cero'         => [[['id' => 'a', 'nombre' => 'VIP', 'precio' => 1, 'cupo' => 0]],
                                    'El cupo de "VIP" tiene que ser al menos 1, o quedar vacío'],
            'id repetido'       => [[['id' => 'a', 'nombre' => 'VIP', 'precio' => 1], ['id' => 'a', 'nombre' => 'Otro', 'precio' => 1]],
                                    'Hay dos tipos de entrada que se llaman "Otro"'],
            'nombre repetido'   => [[['id' => 'a', 'nombre' => 'General', 'precio' => 1], ['id' => 'b', 'nombre' => 'general', 'precio' => 2]],
                                    'Hay dos tipos de entrada que se llaman "general"'],
        ];
    }

    public function testNoPuedeHaberMasDeVeinteTipos()
    {
        $tipos = [];
        for ($i = 0; $i <= TiposDeEntrada::MAX_TIPOS; $i++) {
            $tipos[] = $this->tipo("t$i", "Tipo $i", 100);
        }

        $this->assertSame('No puede haber más de 20 tipos de entrada', TiposDeEntrada::normalizar($tipos)['error']);
    }

    // ------------------------------------------------------------ decodificar

    public function testDecodificaLoGuardado()
    {
        $tipos = [$this->tipo('general', 'General', 100.0)];

        $this->assertEquals($tipos, TiposDeEntrada::decodificar(json_encode($tipos)));
        $this->assertNull(TiposDeEntrada::decodificar(null));
        $this->assertNull(TiposDeEntrada::decodificar(''));
        $this->assertNull(TiposDeEntrada::decodificar('[]'));
        $this->assertNull(TiposDeEntrada::decodificar('no es json'));
    }

    // ------------------------------------------------------ precio de referencia

    /**
     * El precio del evento es el más barato de los que cobran: un "Invitado"
     * en 0 no puede hacer pasar por gratis a un evento que cobra.
     */
    public function testElPrecioDeReferenciaIgnoraLosTiposSinCosto()
    {
        $tipos = [
            $this->tipo('invitado', 'Invitado', 0),
            $this->tipo('vip', 'VIP', 12000),
            $this->tipo('general', 'General', 8000),
        ];

        $this->assertSame(8000.0, TiposDeEntrada::precioDeReferencia($tipos));
    }

    public function testSiNingunTipoCobraElPrecioEsCero()
    {
        $this->assertSame(0.0, TiposDeEntrada::precioDeReferencia([$this->tipo('a', 'A', 0)]));
    }

    // -------------------------------------------------------- tipos por lugar

    public function testCadaLugarTomaElTipoDeSuZona()
    {
        $tipos = [$this->tipo('general', 'General', 100), $this->tipo('vip', 'VIP', 200)];
        $plano = $this->plano([
            ['tipo' => 'fila', 'nombre' => 'A', 'desde' => 1, 'butacas' => 2, 'x' => 0, 'y' => 0, 'entrada' => 'vip'],
            ['tipo' => 'mesa', 'nombre' => '1', 'lugares' => 2, 'forma' => 'redonda', 'x' => 5, 'y' => 5, 'entrada' => 'general'],
            ['tipo' => 'escenario', 'texto' => 'Escenario', 'ancho' => 4, 'alto' => 1, 'x' => 0, 'y' => 10],
        ]);

        $this->assertSame(
            ['f:A:1' => 'vip', 'f:A:2' => 'vip', 'm:1:1' => 'general', 'm:1:2' => 'general'],
            TiposDeEntrada::tiposPorLugar($plano, $tipos)
        );
    }

    /**
     * Una zona sin tipo —un plano de antes, o copiado de otro evento— o que
     * nombra un tipo borrado vende al primero: ningún lugar queda sin precio.
     */
    public function testUnaZonaSinTipoOConUnoQueNoExisteVendeAlPrimero()
    {
        $tipos = [$this->tipo('general', 'General', 100), $this->tipo('vip', 'VIP', 200)];
        $plano = $this->plano([
            ['tipo' => 'fila', 'nombre' => 'A', 'desde' => 1, 'butacas' => 1, 'x' => 0, 'y' => 0],
            ['tipo' => 'fila', 'nombre' => 'B', 'desde' => 1, 'butacas' => 1, 'x' => 0, 'y' => 1, 'entrada' => 'borrado'],
        ]);

        $this->assertSame(['f:A:1' => 'general', 'f:B:1' => 'general'], TiposDeEntrada::tiposPorLugar($plano, $tipos));
    }

    // ---------------------------------------------------------------- resumir

    public function testResumeLoQueSeLlevoCadaCompra()
    {
        $this->assertSame('2 General · 1 Jubilados', TiposDeEntrada::resumir([
            ['nombre' => 'General', 'cantidad' => 2],
            ['nombre' => 'Jubilados', 'cantidad' => 1],
        ]));
        $this->assertSame('', TiposDeEntrada::resumir([]));
    }

    public function testPorIdLosIndexa()
    {
        $tipos = [$this->tipo('a', 'A', 1), $this->tipo('b', 'B', 2)];

        $this->assertSame(['a' => $tipos[0], 'b' => $tipos[1]], TiposDeEntrada::porId($tipos));
    }
}
