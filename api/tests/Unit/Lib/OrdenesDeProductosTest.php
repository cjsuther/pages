<?php

namespace Tests\Unit\Lib;

use OrdenesDeProductos;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakePdo;

class OrdenesDeProductosTest extends TestCase
{
    /** @var FakePdo */
    private $db;

    protected function setUp(): void
    {
        $this->db = new FakePdo();
    }

    public function testElTokenEsDe256Bits()
    {
        $orden = OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'ana@example.com'], 100);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $orden['token']);
        $this->assertNotSame(
            $orden['token'],
            OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'ana@example.com'], 100)['token']
        );
    }

    /** Un código no se repite entre entradas y productos: se verifica contra las dos tablas. */
    public function testElCodigoNoCoincideConElDeNingunaOrdenDeEntradasNiDeProductos()
    {
        $orden = OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'ana@example.com'], 100);

        $this->assertMatchesRegularExpression('/^[A-F0-9]{12}$/', $orden['codigo']);
        $consulta = $this->db->callsFor('SELECT 1 FROM ticket_orders WHERE codigo = ?')[0];
        $this->assertStringContainsString('SELECT 1 FROM product_orders WHERE codigo = ?', $consulta['sql']);
        $this->assertSame([$orden['codigo'], $orden['codigo']], $consulta['params']);
    }

    /** Si el código está tomado en ticket_orders se prueba otro. */
    public function testUnCodigoTomadoPorUnaEntradaSeDescarta()
    {
        $this->db->onSelect('SELECT 1 FROM ticket_orders WHERE codigo', [[1]]);

        $orden = OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'ana@example.com'], 100);

        $consultas = $this->db->callsFor('SELECT 1 FROM ticket_orders WHERE codigo');
        $this->assertCount(2, $consultas);
        $this->assertNotSame($consultas[0]['params'][0], $orden['codigo']);
        $this->assertSame($consultas[1]['params'][0], $orden['codigo']);
    }

    public function testCodigoLibreSoloAceptaTablasConocidas()
    {
        $this->expectException(\InvalidArgumentException::class);
        \Entradas::codigoLibre($this->db, 'users');
    }

    public function testUnPagoNacePendienteYUnoGratisPagado()
    {
        $pago = OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'a@example.com'], 100);
        $gratis = OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'a@example.com'], 0);

        $this->assertSame('pendiente', $pago['estado']);
        $this->assertSame('pagada', $gratis['estado']);

        $inserts = $this->db->callsFor('INSERT INTO product_orders');
        $this->assertNull($inserts[0]['params'][7], 'una pendiente no tiene pagada_en');
        $this->assertNotNull($inserts[1]['params'][7]);
    }

    public function testUnTelefonoVacioQuedaNull()
    {
        OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'a@example.com', 'telefono' => ' '], 100);

        $this->assertNull($this->db->paramsFor('INSERT INTO product_orders')[4]);
    }

    public function testElLinkLlevaElTokenDespuesDelNumeral()
    {
        $url = OrdenesDeProductos::urlDeDescarga('abc');

        $this->assertSame('https://frontend.test/descarga#abc', $url);
        $this->assertSame('/descarga', parse_url($url, PHP_URL_PATH));
        $this->assertNull(parse_url($url, PHP_URL_QUERY));
    }

    public function testUnAprobadoAcreditaDesdeRechazadaPorqueSePuedeReintentar()
    {
        $this->db->onSelect('SELECT estado FROM product_orders', [['rechazada']]);
        $this->db->onWrite("SET estado = 'pagada'", 1);

        $r = OrdenesDeProductos::acreditarPago($this->db, 'X', '9', 'approved', ['comision_plataforma' => 37.5]);

        $this->assertTrue($r['acreditada']);
        $this->assertSame(['9', 37.5, 'X'], $this->db->paramsFor("SET estado = 'pagada'"));
        $this->assertStringContainsString("estado IN ('pendiente', 'vencida', 'rechazada')", $this->db->callsFor("SET estado = 'pagada'")[0]['sql']);
    }

    /** Si dos avisos llegan juntos, el UPDATE condicionado deja pasar a uno solo. */
    public function testSiOtroAvisoGanoNoSeAcreditaDosVeces()
    {
        $this->db->onSelect('SELECT estado FROM product_orders', [['pendiente']]);
        $this->db->onWrite("SET estado = 'pagada'", 0);

        $r = OrdenesDeProductos::acreditarPago($this->db, 'X', '9', 'approved');

        $this->assertFalse($r['acreditada']);
    }

    public function testUnRechazoNoPisaUnaPagada()
    {
        $this->db->onSelect('SELECT estado FROM product_orders', [['pendiente']]);

        $r = OrdenesDeProductos::acreditarPago($this->db, 'X', '9', 'rejected');

        $this->assertSame('pago rechazado', $r['motivo']);
        $this->assertStringContainsString("estado IN ('pendiente', 'vencida')", $this->db->callsFor("SET estado = 'rechazada'")[0]['sql']);
    }

    public function testUnPagoEnCursoNoTocaNada()
    {
        $this->db->onSelect('SELECT estado FROM product_orders', [['pendiente']]);

        $r = OrdenesDeProductos::acreditarPago($this->db, 'X', '9', 'in_process');

        $this->assertSame('pago todavía en curso', $r['motivo']);
        $this->assertFalse($this->db->ran('UPDATE'));
    }

    /** Vence a los mismos minutos que una reserva de entradas. */
    public function testUnaPendienteVenceComoUnaReserva()
    {
        $minutos = \Entradas::MINUTOS_DE_RESERVA;
        $antes = date('Y-m-d H:i:s', time() - ($minutos - 1) * 60);
        $despues = date('Y-m-d H:i:s', time() - ($minutos + 1) * 60);

        $this->assertFalse(OrdenesDeProductos::estaVencida(['estado' => 'pendiente', 'creado_en' => $antes]));
        $this->assertTrue(OrdenesDeProductos::estaVencida(['estado' => 'pendiente', 'creado_en' => $despues]));
        $this->assertFalse(OrdenesDeProductos::estaVencida(['estado' => 'pagada', 'creado_en' => $despues]));
    }

    public function testLaConciliacionMiraSoloPendientesPagasSinPago()
    {
        $this->db->onSelect('FROM product_orders', [['codigo' => 'AAA']]);

        $this->assertSame(['AAA'], OrdenesDeProductos::sinConciliar($this->db));

        $sql = $this->db->callsFor('FROM product_orders')[0]['sql'];
        $this->assertStringContainsString("estado = 'pendiente'", $sql);
        $this->assertStringContainsString('mp_payment_id IS NULL', $sql);
        $this->assertStringContainsString('total > 0', $sql);
    }

    /**
     * Una rechazada puede tener un aprobado posterior (otra tarjeta en el mismo
     * checkout) cuyo aviso se perdió: entra en la conciliación, en la misma
     * ventana de días, aunque ya tenga el id del pago rechazado.
     */
    public function testLaConciliacionIncluyeLasRechazadasEnLaMismaVentana()
    {
        OrdenesDeProductos::sinConciliar($this->db, 60);

        $llamada = $this->db->callsFor('FROM product_orders')[0];
        $this->assertStringContainsString("((estado = 'pendiente' AND mp_payment_id IS NULL) OR estado = 'rechazada')", $llamada['sql']);
        $this->assertStringContainsString('creado_en >= (NOW() - INTERVAL ? DAY)', $llamada['sql']);
        $this->assertSame([60], $llamada['params']);
    }

    // ------------------------------------------------------ pedidos gratis

    public function testElLimiteGratisCuentaPorEmailNormalizado()
    {
        $this->db->onSelect('WHERE email = ? AND total = 0', [[3]]);

        $this->assertTrue(OrdenesDeProductos::superaLimiteGratis($this->db, '  Ana@Example.COM ', '10.0.0.1'));
        $this->assertSame(['ana@example.com'], $this->db->paramsFor('WHERE email = ? AND total = 0'));
        $this->assertStringContainsString('INTERVAL 1 HOUR', $this->db->callsFor('WHERE email = ?')[0]['sql']);
        // Con el email pasado ni se pregunta por la IP.
        $this->assertFalse($this->db->ran('WHERE ip = ?'));
    }

    public function testElLimiteGratisCuentaPorIp()
    {
        $this->db->onSelect('WHERE email = ? AND total = 0', [[2]]);
        $this->db->onSelect('WHERE ip = ? AND total = 0', [[5]]);

        $this->assertTrue(OrdenesDeProductos::superaLimiteGratis($this->db, 'ana@example.com', '10.0.0.1'));
        $this->assertSame(['10.0.0.1'], $this->db->paramsFor('WHERE ip = ?'));
    }

    public function testDebajoDelLimiteSePuedePedir()
    {
        $this->db->onSelect('WHERE email = ? AND total = 0', [[2]]);
        $this->db->onSelect('WHERE ip = ? AND total = 0', [[4]]);

        $this->assertFalse(OrdenesDeProductos::superaLimiteGratis($this->db, 'ana@example.com', '10.0.0.1'));
        $this->assertFalse(OrdenesDeProductos::superaLimiteGratis($this->db, 'ana@example.com', null));
    }

    public function testLaOrdenGuardaLaIpYElEmailNormalizado()
    {
        OrdenesDeProductos::crear($this->db, 40, ['nombre' => 'Ana', 'email' => ' Ana@Example.com '], 0, '2001:db8::1');

        $params = $this->db->paramsFor('INSERT INTO product_orders');
        $this->assertSame(40, $params[1]);
        $this->assertSame('ana@example.com', $params[3]);
        $this->assertSame('2001:db8::1', $params[10]);
    }

    // ---------------------------------------------- producto borrado u oculto

    /** Lo que ve el comprador sale de la foto del producto si el link ya no está. */
    public function testLaOrdenLeeElProductoDesdeSuFila()
    {
        OrdenesDeProductos::orden($this->db, 'AAAABBBBCCCC');

        $sql = $this->db->callsFor('WHERE o.codigo = ?')[0]['sql'];
        $this->assertStringContainsString('INNER JOIN digital_products dp ON dp.id = o.product_id', $sql);
        $this->assertStringContainsString('LEFT JOIN links l', $sql);
        $this->assertStringContainsString('COALESCE(l.text, dp.titulo) AS producto', $sql);
        $this->assertStringContainsString('COALESCE(lg.page_id, dp.page_id) AS pagina_id', $sql);
    }

    public function testLaVentanaDeConciliacion()
    {
        $this->assertTrue(OrdenesDeProductos::estaEnVentanaDeConciliacion(['creado_en' => date('Y-m-d H:i:s')]));
        $this->assertFalse(OrdenesDeProductos::estaEnVentanaDeConciliacion([
            'creado_en' => date('Y-m-d H:i:s', time() - (OrdenesDeProductos::DIAS_A_CONCILIAR + 1) * 86400),
        ]));
    }

    public function testUnaDescargaRegistraPrimeraYUltima()
    {
        OrdenesDeProductos::registrarDescarga($this->db, 7);

        $sql = $this->db->callsFor('UPDATE product_orders')[0]['sql'];
        $this->assertStringContainsString('descargas = descargas + 1', $sql);
        $this->assertStringContainsString('primera_descarga_en = COALESCE(primera_descarga_en, NOW())', $sql);
        $this->assertStringContainsString('ultima_descarga_en = NOW()', $sql);
    }

    public function testElTokenCifradoSeRecupera()
    {
        $this->assertSame('abc', OrdenesDeProductos::token(['token_cifrado' => \Cripto::cifrar('abc')]));
        $this->assertNull(OrdenesDeProductos::token(['token_cifrado' => 'basura']));
        $this->assertNull(OrdenesDeProductos::token([]));
    }

    // ---------------------------------------------------------------- ventas

    public function testLaColumnaDescargoDiceCuantasVeces()
    {
        $this->assertSame('No', OrdenesDeProductos::descripcionDeDescargas(['descargas' => 0]));
        $this->assertSame('Sí · 1 vez', OrdenesDeProductos::descripcionDeDescargas(['descargas' => '1']));
        $this->assertSame('Sí · 4 veces', OrdenesDeProductos::descripcionDeDescargas(['descargas' => 4]));
    }

    public function testLasVentasSoloSeFiltranPorProductoOPorPagina()
    {
        $this->expectException(\InvalidArgumentException::class);

        OrdenesDeProductos::ventas($this->db, 'grupo', 1);
    }
}
