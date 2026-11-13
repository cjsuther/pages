<?php

namespace Tests\Integration;

use CargosAlComprador;
use CheckoutHandler;
use Cobros;
use CodigoQR;
use Entradas;
use EntradasHandler;
use Tests\Support\Esquema;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Comisiones a cargo de quien compra, de punta a punta contra MariaDB: la
 * llave del evento, la orden, lo que se le manda a Mercado Pago, la
 * acreditación, el mail, la pantalla de la compra, el panel y la
 * modificación sin recobro.
 *
 * Tasas de los tests: Mercado Pago 7,25% sin IVA, Rezonar 10%.
 */
class CargosAlCompradorTest extends IntegracionTestCase
{
    private $duenaId;
    private $paginaId;
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;
    /** @var FakeMailer */
    private $mailer;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Ver ReservasTest: la librería del QR avisa deprecaciones al cargarse.
        ob_start();
        CodigoQR::png('https://rezonar.test/entrada/PRECARGA');
        ob_end_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId);
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
    }

    protected function tearDown(): void
    {
        CargosAlComprador::forzar(null);
        parent::tearDown();
    }

    // ============================================================ fixtures

    private function encender()
    {
        CargosAlComprador::forzar([
            'mp_porcentaje' => MP_FEE_PERCENT, 'mp_fijo' => 0, 'mp_iva' => 0,
            'plataforma_porcentaje' => PLATFORM_FEE_PERCENT, 'plataforma_iva' => 0,
        ]);
    }

    private function conectarPorOAuth()
    {
        $r = Cobros::guardarDesdeOAuth($this->db, $this->paginaId, [
            'user_id'       => '987654',
            'access_token'  => 'APP_USR-1234567890-token-de-prueba',
            'refresh_token' => 'TG-refresh-de-prueba',
            'public_key'    => 'APP_USR-public-key',
            'modo'          => 'produccion',
            'expira_en'     => gmdate('Y-m-d H:i:s', time() + 90 * 86400),
        ]);

        $this->assertTrue($r['ok']);
    }

    /** Un evento configurado por el mismo endpoint que usa el editor. */
    private function eventoConVenta(array $config = [])
    {
        $linkId = $this->evento($this->grupoId, ['text' => 'Recital']);

        $this->assertStatus(200, EntradasHandler::config($this->db, $this->post(array_merge([
            'activo' => 1, 'capacidad' => 10, 'precio' => 1500, 'max_por_compra' => 10,
        ], $config), $this->user($this->duenaId), ['link_id' => $linkId])));

        return $linkId;
    }

    private function comprar($linkId, array $datos = [])
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);

        return CheckoutHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre' => 'Ana Compradora',
            'email' => 'ana@test.local',
            'cantidad' => 2,
        ], $datos)), $this->http, $this->mailer);
    }

    private function pagar($codigo, $monto)
    {
        $this->http->responde('/v1/payments/', 200, [
            'id' => 555001,
            'status' => 'approved',
            'external_reference' => $codigo,
            'transaction_amount' => $monto,
            // Lo que de verdad le llega al organizador: el precio.
            'transaction_details' => ['net_received_amount' => 3000.0],
            'fee_details' => [
                ['type' => 'mercadopago_fee', 'amount' => 257.96],
                ['type' => 'application_fee', 'amount' => 300.0],
            ],
        ]);

        return CheckoutHandler::aviso(
            $this->db,
            $this->post(['type' => 'payment', 'data' => ['id' => '555001']], null, ['orden' => $codigo]),
            $this->http,
            $this->mailer
        );
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM ticket_orders WHERE codigo = ?', [$codigo]);
    }

    private static function sumaDeLaPreferencia(array $preferencia)
    {
        $centavos = 0;

        foreach ($preferencia['items'] as $item) {
            $centavos += $item['quantity'] * (int) round($item['unit_price'] * 100);
        }

        return $centavos / 100.0;
    }

    // =============================================================== tests

    private function llave($linkId)
    {
        return (int) $this->valor('SELECT cargos_al_comprador FROM event_ticketing WHERE link_id = ?', [$linkId]);
    }

    /** Lo que deja la migración en un evento que ya vendía: la fila con la llave en 0. */
    private function eventoConfiguradoAntesDelDeploy()
    {
        $linkId = $this->evento($this->grupoId, ['text' => 'Festival WIP']);
        $this->insertar('event_ticketing', [
            'link_id' => $linkId, 'activo' => 1, 'capacidad' => 500, 'precio' => 1500,
            'moneda' => 'ARS', 'max_por_compra' => 10,
        ]);

        return $linkId;
    }

    public function testUnEventoNuevoConLaFuncionPrendidaArrancaConLaLlave()
    {
        $this->encender();
        $this->conectarPorOAuth();

        $this->assertSame(1, $this->llave($this->eventoConVenta()));
    }

    public function testUnEventoNuevoConLaFuncionApagadaArrancaSinLaLlave()
    {
        $this->conectarPorOAuth();

        $this->assertSame(0, $this->llave($this->eventoConVenta()));
    }

    public function testElOrganizadorApagaLaLlaveEnUnEventoNuevo()
    {
        $this->encender();
        $this->conectarPorOAuth();

        $this->assertSame(0, $this->llave($this->eventoConVenta(['cargos_al_comprador' => false])));
    }

    /**
     * El festival que se está vendiendo: la migración lo dejó en 0 y, con la
     * función prendida, reconfigurarlo sin mandar la llave —desde el editor de
     * antes del deploy o desde el asistente— no lo cambia. Contra MariaDB,
     * porque lo que importa es el ON DUPLICATE KEY UPDATE de verdad.
     */
    public function testUnEventoQueYaVendiaSigueEnCeroAlReconfigurarlo()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConfiguradoAntesDelDeploy();
        $this->assertSame(0, $this->llave($linkId), 'la migración lo deja en 0');
        $this->encender();

        $this->assertStatus(200, EntradasHandler::config($this->db, $this->post([
            'activo' => 1, 'capacidad' => 600, 'precio' => 1800, 'max_por_compra' => 10,
        ], $this->user($this->duenaId), ['link_id' => $linkId])));

        $this->assertSame(0, $this->llave($linkId));
        $this->assertSame('600', (string) $this->valor('SELECT capacidad FROM event_ticketing WHERE link_id = ?', [$linkId]));

        // Lo mismo guardando directo, con plano y tipos pero sin la llave.
        Entradas::guardarConfig($this->db, $linkId, [
            'activo' => 1, 'capacidad' => 600, 'precio' => 1800, 'max_por_compra' => 10, 'plano' => null, 'tipos' => null,
        ]);
        $this->assertSame(0, $this->llave($linkId));
    }

    /** El organizador sí puede prenderla a mano en un evento que ya vendía. */
    public function testUnEventoQueYaVendiaSePuedePrenderAMano()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConfiguradoAntesDelDeploy();
        $this->encender();

        $this->assertStatus(200, EntradasHandler::config($this->db, $this->post([
            'activo' => 1, 'capacidad' => 500, 'precio' => 1500, 'cargos_al_comprador' => true,
        ], $this->user($this->duenaId), ['link_id' => $linkId])));

        $this->assertSame(1, $this->llave($linkId));
    }

    /** La compra completa: cobrar, acreditar, mandar la entrada y mostrarla. */
    public function testDePuntaAPuntaElCompradorPagaLasComisionesYElOrganizadorRecibeElPrecio()
    {
        $this->encender();
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['cargos_al_comprador' => true]);

        // Lo que ve la pantalla antes de elegir: las tasas para repetir la cuenta.
        $this->assertSame(7.25, Entradas::disponibilidad($this->db, $linkId)['cargos']['mp_porcentaje']);

        $res = $this->comprar($linkId);
        $this->assertStatus(201, $res);
        $codigo = $res->body['codigo'];

        // La orden: total cobrado, cargos y comisión congelados.
        $orden = $this->orden($codigo);
        $this->assertSame('3557.96', $orden['total']);
        $this->assertSame('1500.00', $orden['precio_unitario']);
        $this->assertSame('257.96', $orden['cargo_mercadopago']);
        $this->assertSame('300.00', $orden['cargo_plataforma']);
        $this->assertSame('300.00', $orden['comision'], 'lo que se reparte Rezonar es lo que pagó el comprador');

        // Mercado Pago: el monto de la preferencia es el total mostrado.
        $preferencia = $this->http->jsonDe('/checkout/preferences');
        $this->assertSame(3557.96, self::sumaDeLaPreferencia($preferencia));
        $this->assertEquals(300, $preferencia['marketplace_fee']);

        // Acreditar con el total.
        $this->assertSame('pago acreditado', $this->pagar($codigo, 3557.96)->body['motivo']);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);

        // El mail, con el desglose.
        $mail = $this->mailer->mensajePara('ana@test.local');
        $this->assertStringContainsString('Comisión de Mercado Pago: $ 257,96', $mail['texto']);
        $this->assertStringContainsString('Total pagado: $ 3.557,96', $mail['texto']);

        // La pantalla de la compra.
        $vista = CheckoutHandler::orden($this->db, $this->get(['codigo' => $codigo]))->body['orden'];
        $this->assertEquals(
            ['entradas' => 3000, 'mercadopago' => 257.96, 'plataforma' => 300, 'total' => 3557.96],
            $vista['desglose']
        );

        // El panel: lo que pagaron, lo que se sumó y lo que le queda.
        $resumen = Entradas::ventasDelEvento($this->db, $linkId)['resumen'];
        $this->assertSame(3557.96, $resumen['recaudado']);
        $this->assertSame(557.96, $resumen['cargos_al_comprador']);
        $this->assertSame(3000.0, $resumen['precio_entradas']);
        $this->assertSame(3000.0, $resumen['neto']);
    }

    public function testUnPagoPorElPrecioSinComisionesNoSeAcredita()
    {
        $this->encender();
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['cargos_al_comprador' => true]);
        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->assertSame('el monto no coincide con la orden', $this->pagar($codigo, 3000.0)->body['motivo']);
        $this->assertSame('reservada', $this->orden($codigo)['estado']);
    }

    /** Si Cristian apaga la función global, los eventos con la llave vuelven al precio de siempre. */
    public function testApagadaLaFuncionLaLlaveDelEventoNoHaceNada()
    {
        $this->encender();
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['cargos_al_comprador' => true]);
        CargosAlComprador::forzar(false);

        $codigo = $this->comprar($linkId)->body['codigo'];
        $orden = $this->orden($codigo);

        $this->assertSame('3000.00', $orden['total']);
        $this->assertSame('0.00', $orden['cargo_mercadopago']);
        $this->assertSame('0.00', $orden['cargo_plataforma']);
        $this->assertSame(3000.0, self::sumaDeLaPreferencia($this->http->jsonDe('/checkout/preferences')));
    }

    public function testUnaReservaGratisNoSumaComisiones()
    {
        $this->encender();
        $linkId = $this->eventoConVenta(['precio' => 0, 'cargos_al_comprador' => true]);

        $codigo = $this->comprar($linkId)->body['codigo'];
        $orden = $this->orden($codigo);

        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('0.00', $orden['total']);
        $this->assertSame('0.00', $orden['cargo_mercadopago']);
    }

    /** Modificar no vuelve a cobrar: total y cargos quedan como se pagaron. */
    public function testModificarLaCompraNoTocaLoCobrado()
    {
        $this->encender();
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['cargos_al_comprador' => true]);
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagar($codigo, 3557.96);

        $res = EntradasHandler::modificar(
            $this->db,
            $this->post(['codigo' => $codigo, 'cantidad' => 4], $this->user($this->duenaId)),
            $this->mailer
        );
        $this->assertStatus(200, $res);

        $orden = $this->orden($codigo);
        $this->assertSame(4, (int) $orden['cantidad']);
        $this->assertSame('3557.96', $orden['total']);
        $this->assertSame('257.96', $orden['cargo_mercadopago']);
        $this->assertSame('300.00', $orden['cargo_plataforma']);
    }

    /** Hay que poder correrla dos veces: se aplica a mano en producción antes del merge. */
    public function testLaMigracionEsIdempotente()
    {
        $sql = file_get_contents(Esquema::raiz() . '/migration_cargos_al_comprador.sql');

        foreach ([1, 2] as $vez) {
            foreach (Esquema::sentencias($sql) as $sentencia) {
                $this->db->exec($sentencia);
            }
        }

        $columnas = $this->db->query("SHOW COLUMNS FROM ticket_orders LIKE 'cargo_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['cargo_mercadopago', 'cargo_plataforma'], $columnas);
    }
}
