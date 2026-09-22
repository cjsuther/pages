<?php

namespace Tests\Unit\Lib;

use Puerta;
use Tests\Support\HandlerTestCase;

class PuertaTest extends HandlerTestCase
{
    const CODIGO = 'ABC123DEF456';

    private function hayOrden(array $overrides = [])
    {
        $this->db->onSelect('WHERE o.codigo = ?', [array_merge([
            'id' => 1, 'codigo' => self::CODIGO, 'link_id' => 100, 'nombre' => 'Ana Gómez',
            'cantidad' => 2, 'ingresadas' => 0, 'ingreso_en' => null, 'estado' => 'pagada',
            'reserva_vence_en' => null, 'evento' => 'Fiesta de fin de año',
        ], $overrides)]);
    }

    // ------------------------------------------------------------ el código

    /** El QR de la entrada es la dirección de la orden, no el código suelto. */
    public function testElCodigoSaleDeLaDireccionDelQr()
    {
        $this->assertSame(self::CODIGO, Puerta::codigoDesdeQr('https://rezon.ar/entrada/ABC123DEF456'));
        $this->assertSame(self::CODIGO, Puerta::codigoDesdeQr('https://rezon.ar/entrada/ABC123DEF456?x=1'));
    }

    public function testElCodigoSePuedeTipearComoSea()
    {
        $this->assertSame(self::CODIGO, Puerta::codigoDesdeQr(' abc123 def456 '));
    }

    public function testAlgoQueNoEsUnCodigoNoSeBusca()
    {
        $this->assertNull(Puerta::codigoDesdeQr('https://otro.sitio/cualquier-cosa'));
        $this->assertNull(Puerta::codigoDesdeQr(''));
    }

    // ------------------------------------------------------------- mirar

    public function testUnaEntradaPagadaSinUsarEsValida()
    {
        $this->hayOrden();

        $r = Puerta::mirar($this->db, 100, self::CODIGO);

        $this->assertSame(Puerta::VALIDA, $r['resultado']);
        $this->assertSame(2, $r['orden']['restantes']);
    }

    /**
     * Una entrada de otro show tiene que dar un "no" claro, y no mostrar los
     * datos de una compra que no es de este evento.
     */
    public function testUnaEntradaDeOtroEventoNoVale()
    {
        $this->hayOrden(['link_id' => 200, 'evento' => 'Otro show']);

        $r = Puerta::mirar($this->db, 100, self::CODIGO);

        $this->assertSame(Puerta::OTRO_EVENTO, $r['resultado']);
        $this->assertNull($r['orden']);
        $this->assertSame('Otro show', $r['evento']);
    }

    public function testUnaCompraCanceladaNoVale()
    {
        $this->hayOrden(['estado' => 'cancelada']);

        $r = Puerta::mirar($this->db, 100, self::CODIGO);

        $this->assertSame(Puerta::NO_PAGADA, $r['resultado']);
        $this->assertSame('cancelada', $r['orden']['estado']);
    }

    /** Una reserva que venció sin pagarse figura como reservada en la base. */
    public function testUnaReservaVencidaSeMuestraComoVencida()
    {
        $this->hayOrden(['estado' => 'reservada', 'reserva_vence_en' => '2020-01-01 00:00:00']);

        $this->assertSame('vencida', Puerta::mirar($this->db, 100, self::CODIGO)['orden']['estado']);
    }

    public function testCuandoEntraronTodosDiceQueYaEntro()
    {
        $this->hayOrden(['ingresadas' => 2, 'ingreso_en' => '2026-09-21 21:14:00']);

        $this->assertSame(Puerta::YA_ENTRO, Puerta::mirar($this->db, 100, self::CODIGO)['resultado']);
    }

    /** Una compra de cuatro puede llegar en dos tandas. */
    public function testSiEntroParteDeLaCompraElRestoSigueValiendo()
    {
        $this->hayOrden(['cantidad' => 4, 'ingresadas' => 2]);

        $r = Puerta::mirar($this->db, 100, self::CODIGO);

        $this->assertSame(Puerta::VALIDA, $r['resultado']);
        $this->assertSame(2, $r['orden']['restantes']);
    }

    public function testUnCodigoQueNoExisteLoDice()
    {
        $this->assertSame(Puerta::NO_EXISTE, Puerta::mirar($this->db, 100, self::CODIGO)['resultado']);
    }

    // ----------------------------------------------------------- ingresar

    /**
     * Dos puertas escaneando el mismo QR a la vez: el tope va en el UPDATE,
     * y el evento también, para que una clave no marque entradas ajenas.
     */
    public function testElIngresoSeTopeaYSeAtaAlEventoEnLaMismaEscritura()
    {
        $this->db->onWrite('SET ingresadas = ingresadas + ?', 1);
        $this->hayOrden(['ingresadas' => 1]);

        $r = Puerta::ingresar($this->db, 100, 'https://rezon.ar/entrada/ABC123DEF456', 1);

        $sql = $this->db->callsFor('SET ingresadas = ingresadas + ?')[0]['sql'];

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('ingresadas + ? <= cantidad', $sql);
        $this->assertStringContainsString("estado = 'pagada'", $sql);
        $this->assertSame([1, self::CODIGO, 100, 1], $this->db->paramsFor('SET ingresadas = ingresadas + ?'));
    }

    public function testSiNoSePudoMarcarDevuelveComoQuedo()
    {
        $this->db->onWrite('SET ingresadas = ingresadas + ?', 0);
        $this->hayOrden(['ingresadas' => 2]);

        $r = Puerta::ingresar($this->db, 100, self::CODIGO, 1);

        $this->assertFalse($r['ok']);
        $this->assertSame(Puerta::YA_ENTRO, $r['resultado']);
    }

    /** MySQL aplica las asignaciones en orden: ingreso_en tiene que ir antes de la resta. */
    public function testDeshacerCalculaLaHoraAntesDeRestar()
    {
        $this->db->onWrite('ingresadas = ingresadas - ?', 1);
        $this->hayOrden();

        Puerta::deshacer($this->db, 100, self::CODIGO, 1);

        $sql = $this->db->callsFor('ingresadas = ingresadas - ?')[0]['sql'];

        $this->assertLessThan(strpos($sql, 'ingresadas = ingresadas - ?'), strpos($sql, 'ingreso_en = CASE'));
        $this->assertStringContainsString('ingresadas >= ?', $sql);
    }

    // --------------------------------------------------------------- lista

    /** El link se le da a gente que no administra la página: sin email ni teléfono. */
    public function testLaListaNoTraeDatosDeContacto()
    {
        $this->db->onSelect("estado = 'pagada'", [
            ['id' => 1, 'codigo' => 'A', 'nombre' => 'Ana', 'cantidad' => 2, 'ingresadas' => 1, 'ingreso_en' => null],
            ['id' => 2, 'codigo' => 'B', 'nombre' => 'Beto', 'cantidad' => 3, 'ingresadas' => 0, 'ingreso_en' => null],
        ]);
        $this->db->onSelect('FROM ticket_order_lugares WHERE link_id', [['order_id' => 2, 'lugar' => 'f:A:1']]);

        $r = Puerta::lista($this->db, 100);

        $sql = $this->db->callsFor("estado = 'pagada'")[0]['sql'];

        $this->assertStringNotContainsString('email', $sql);
        $this->assertStringNotContainsString('telefono', $sql);
        $this->assertSame(['entradas' => 5, 'ingresadas' => 1, 'compras' => 2], $r['resumen']);
        $this->assertSame(['f:A:1'], $r['ordenes'][1]['lugares']);
    }

    // ---------------------------------------------------------------- clave

    public function testUnaClaveConOtraFormaNiSiquieraSeBusca()
    {
        $this->assertNull(Puerta::eventoDeLaClave($this->db, "' OR 1=1 --"));
        $this->assertFalse($this->db->ran('FROM event_door_access'));
    }

    /** Se busca por el hash: la clave en claro no está en la base. */
    public function testLaClaveSeBuscaPorSuHash()
    {
        $clave = str_repeat('a', 32);

        Puerta::eventoDeLaClave($this->db, $clave);

        $this->assertSame([hash('sha256', $clave)], $this->db->paramsFor('FROM event_door_access'));
    }

    public function testGenerarDevuelveUnaClaveQueSeGuardaHasheadaYCifrada()
    {
        $this->db->onWrite('INSERT INTO event_door_access', 1);

        $clave = Puerta::generarClave($this->db, 100);
        $params = $this->db->paramsFor('INSERT INTO event_door_access');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $clave);
        $this->assertSame(hash('sha256', $clave), $params[1]);
        $this->assertNotSame($clave, $params[2]);
        $this->assertSame($clave, \Cripto::descifrar($params[2]));
    }

    /** La clave va después del #, para que no quede en los registros del servidor. */
    public function testLaClaveVaEnElFragmentoDelLink()
    {
        $this->assertStringEndsWith('/puerta#' . str_repeat('b', 32), Puerta::url(str_repeat('b', 32)));
    }
}
