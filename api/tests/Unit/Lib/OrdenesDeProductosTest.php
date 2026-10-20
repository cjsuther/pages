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

    public function testElCodigoSaleDelMismoGeneradorQueLasEntradasPeroDeSuTabla()
    {
        $orden = OrdenesDeProductos::crear($this->db, 300, ['nombre' => 'Ana', 'email' => 'ana@example.com'], 100);

        $this->assertMatchesRegularExpression('/^[A-F0-9]{12}$/', $orden['codigo']);
        $this->assertTrue($this->db->ran('SELECT 1 FROM product_orders WHERE codigo = ?'));
        $this->assertFalse($this->db->ran('SELECT 1 FROM ticket_orders'));
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
}
