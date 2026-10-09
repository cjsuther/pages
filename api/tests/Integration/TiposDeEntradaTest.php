<?php

namespace Tests\Integration;

use CheckoutHandler;
use Cobros;
use CodigoQR;
use Entradas;
use EntradasHandler;
use Puerta;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;
use VentasCompartidas;

/**
 * Tipos de entrada —General, Jubilados, VIP— contra una MariaDB de verdad.
 *
 * Lo que se cuida acá es que cada compra pague lo que corresponde a lo que se
 * lleva, que el cupo de cada tipo no se pase, y que lo que se llevó quede
 * escrito en la compra aunque los tipos cambien después.
 */
class TiposDeEntradaTest extends IntegracionTestCase
{
    /** @var int */
    private $duenaId;
    /** @var int */
    private $paginaId;
    /** @var int */
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;
    /** @var FakeMailer */
    private $mailer;

    /** Ver ReservasTest: la librería del QR avisa deprecaciones la primera vez. */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

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

    // ============================================================ fixtures

    private function tipos()
    {
        return [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 8000, 'cupo' => null],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 5000, 'cupo' => 2],
            ['id' => 'invitado', 'nombre' => 'Invitado', 'precio' => 0, 'cupo' => null],
        ];
    }

    private function configurar($linkId, array $config)
    {
        return EntradasHandler::config($this->db, $this->post(array_merge([
            'activo' => 1, 'capacidad' => 10, 'precio' => 0, 'max_por_compra' => 10,
        ], $config), $this->user($this->duenaId), ['link_id' => $linkId]));
    }

    private function eventoConTipos(array $config = [])
    {
        $this->conectarPorOAuth();
        $linkId = $this->evento($this->grupoId, ['text' => 'Noche de Tango']);

        $this->assertStatus(200, $this->configurar($linkId, array_merge(['tipos' => $this->tipos()], $config)));

        return $linkId;
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

    private function preferenciaOk()
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);
    }

    private function comprar($linkId, array $datos = [])
    {
        return CheckoutHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre'  => 'Ana Compradora',
            'email'   => 'ana@test.local',
        ], $datos)), $this->http, $this->mailer);
    }

    private function items($codigo)
    {
        $stmt = $this->db->prepare('
            SELECT i.tipo, i.nombre, i.precio_unitario, i.cantidad
            FROM ticket_order_items i
            INNER JOIN ticket_orders o ON o.id = i.order_id
            WHERE o.codigo = ?
            ORDER BY i.id
        ');
        $stmt->execute([$codigo]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM ticket_orders WHERE codigo = ?', [$codigo]);
    }

    private function planoConZonas()
    {
        return [
            'ancho' => 12, 'alto' => 6,
            'elementos' => [
                ['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 3, 'desde' => 1, 'x' => 0, 'y' => 0, 'entrada' => 'vip'],
                ['tipo' => 'fila', 'nombre' => 'B', 'butacas' => 3, 'desde' => 1, 'x' => 0, 'y' => 1, 'entrada' => 'general'],
                // Sin tipo: vende al primero.
                ['tipo' => 'fila', 'nombre' => 'C', 'butacas' => 3, 'desde' => 1, 'x' => 0, 'y' => 2],
            ],
        ];
    }

    // ======================================================= configuración

    public function testLosTiposSeGuardanYElPrecioEsElDeReferencia()
    {
        $linkId = $this->eventoConTipos(['precio' => 99999]);

        $fila = $this->fila('SELECT precio, tipos FROM event_ticketing WHERE link_id = ?', [$linkId]);

        // El más barato de los que cobran: el Invitado en 0 no lo hace gratis.
        $this->assertSame('5000.00', $fila['precio']);
        $this->assertSame(['general', 'jubilados', 'invitado'], array_column(json_decode($fila['tipos'], true), 'id'));

        $res = EntradasHandler::config($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)));
        $this->assertSame('Jubilados', $res->body['entradas']['tipos'][1]['nombre']);

        $disponibilidad = Entradas::disponibilidad($this->db, $linkId);
        $this->assertFalse($disponibilidad['es_gratis']);
        $this->assertSame(5000.0, $disponibilidad['precio']);
        $this->assertSame([10, 2, 10], array_column($disponibilidad['tipos'], 'disponibles'));
    }

    /** Guardar sin mandar los tipos —el asistente, que sólo sabe de cupo— los deja como estaban. */
    public function testGuardarSinTiposConservaLosQueHabia()
    {
        $linkId = $this->eventoConTipos();

        $this->assertStatus(200, $this->configurar($linkId, ['capacidad' => 30, 'precio' => 100]));

        $config = Entradas::configDelEvento($this->db, $linkId);
        $this->assertCount(3, $config['tipos']);
        $this->assertSame('5000.00', $config['precio']);
        $this->assertSame(30, (int) $config['capacidad']);
    }

    public function testMandarTiposEnNullVuelveAUnSoloPrecio()
    {
        $linkId = $this->eventoConTipos();

        $this->assertStatus(200, $this->configurar($linkId, ['tipos' => null, 'precio' => 7000]));

        $config = Entradas::configDelEvento($this->db, $linkId);
        $this->assertNull($config['tipos']);
        $this->assertSame('7000.00', $config['precio']);
    }

    /** Un tipo que cobra necesita Mercado Pago aunque el precio que mande el editor sea 0. */
    public function testUnTipoQueCobraPideMercadoPago()
    {
        $linkId = $this->evento($this->grupoId);

        $res = $this->configurar($linkId, ['precio' => 0, 'tipos' => $this->tipos()]);

        $this->assertStatus(400, $res);
        $this->assertStringContainsString('conectar Mercado Pago', $res->body['error']);
        $this->assertNull(Entradas::configDelEvento($this->db, $linkId));
    }

    public function testTiposSinCostoNoPidenMercadoPago()
    {
        $linkId = $this->evento($this->grupoId);

        $res = $this->configurar($linkId, ['tipos' => [
            ['id' => 'socios', 'nombre' => 'Socios', 'precio' => 0],
            ['id' => 'invitados', 'nombre' => 'Invitados', 'precio' => 0],
        ]]);

        $this->assertStatus(200, $res);
        $this->assertTrue(Entradas::disponibilidad($this->db, $linkId)['es_gratis']);
    }

    public function testLosTiposInvalidosNoSeGuardan()
    {
        $linkId = $this->evento($this->grupoId);

        $res = $this->configurar($linkId, ['tipos' => [['id' => 'a', 'nombre' => '', 'precio' => 0]]]);

        $this->assertStatus(400, $res);
        $this->assertSame('Cada tipo de entrada necesita un nombre', $res->body['error']);
    }

    public function testElCupoDeUnTipoNoPuedeBajarDeLoVendido()
    {
        $linkId = $this->eventoConTipos();
        $this->comprar($linkId, ['tipos' => ['invitado' => 3]]);

        $tipos = $this->tipos();
        $tipos[2]['cupo'] = 2;
        $res = $this->configurar($linkId, ['tipos' => $tipos]);

        $this->assertStatus(400, $res);
        $this->assertSame('Ya hay 3 entradas "Invitado" tomadas: su cupo no puede ser menor', $res->body['error']);
    }

    // ============================================================ compras

    /** Cada tipo paga lo suyo, y Mercado Pago recibe un renglón por tipo. */
    public function testUnaCompraConVariosTiposPagaLoDeCadaUno()
    {
        $linkId = $this->eventoConTipos();
        $this->preferenciaOk();

        $res = $this->comprar($linkId, ['tipos' => ['general' => 2, 'jubilados' => 1, 'invitado' => 0]]);

        $this->assertStatus(201, $res);
        $this->assertSame('reservada', $res->body['estado']);
        $this->assertEquals(21000, $res->body['total']);

        $orden = $this->orden($res->body['codigo']);
        $this->assertSame(3, (int) $orden['cantidad']);
        $this->assertSame('21000.00', $orden['total']);
        $this->assertSame('7000.00', $orden['precio_unitario']);

        $this->assertSame([
            ['tipo' => 'general', 'nombre' => 'General', 'precio_unitario' => '8000.00', 'cantidad' => '2'],
            ['tipo' => 'jubilados', 'nombre' => 'Jubilados', 'precio_unitario' => '5000.00', 'cantidad' => '1'],
        ], array_map(function ($i) { $i['cantidad'] = (string) $i['cantidad']; return $i; }, $this->items($res->body['codigo'])));

        $preferencia = $this->http->jsonDe('/checkout/preferences');
        $this->assertCount(2, $preferencia['items']);
        $this->assertSame('Noche de Tango — General', $preferencia['items'][0]['title']);
        $this->assertSame(2, $preferencia['items'][0]['quantity']);
        $this->assertEquals(8000, $preferencia['items'][0]['unit_price']);
        $this->assertSame('Noche de Tango — Jubilados', $preferencia['items'][1]['title']);
        $this->assertEquals(5000, $preferencia['items'][1]['unit_price']);
    }

    /** El aviso de pago compara contra el total de la compra, que con tipos es la suma. */
    public function testElPagoDeUnaCompraConTiposSeAcredita()
    {
        $linkId = $this->eventoConTipos();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId, ['tipos' => ['general' => 1, 'jubilados' => 1]])->body['codigo'];

        $this->http->responde('/v1/payments/', 200, [
            'id' => 555001, 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => 13000,
        ]);

        CheckoutHandler::aviso(
            $this->db,
            $this->post(['type' => 'payment', 'data' => ['id' => '555001']], null, ['orden' => $codigo]),
            $this->http,
            $this->mailer
        );

        $this->assertSame('pagada', $this->orden($codigo)['estado']);

        $mail = $this->mailer->mensajePara('ana@test.local');
        $this->assertStringContainsString('Entradas: 1 General · 1 Jubilados', $mail['texto']);
    }

    /** Una compra sólo de entradas sin costo no tiene nada que pagar: se confirma en el acto. */
    public function testUnaCompraSoloDeInvitadosSeConfirmaSinPasarPorMercadoPago()
    {
        $linkId = $this->eventoConTipos();

        $res = $this->comprar($linkId, ['tipos' => ['invitado' => 2]]);

        $this->assertStatus(201, $res);
        $this->assertSame('pagada', $res->body['estado']);
        $this->assertNull($res->body['url']);
        $this->assertFalse($this->http->llamoA('/checkout/preferences'));
        $this->assertSame('0.00', $this->orden($res->body['codigo'])['total']);
    }

    public function testElCupoDeUnTipoNoSePasa()
    {
        $linkId = $this->eventoConTipos();
        $this->preferenciaOk();
        $this->preferenciaOk();

        $this->assertStatus(201, $this->comprar($linkId, ['tipos' => ['jubilados' => 1]]));

        $res = $this->comprar($linkId, ['tipos' => ['jubilados' => 2], 'email' => 'b@test.local']);
        $this->assertStatus(400, $res);
        $this->assertSame('Sólo quedan 1 entradas "Jubilados"', $res->body['error']);

        $this->assertStatus(201, $this->comprar($linkId, ['tipos' => ['jubilados' => 1], 'email' => 'c@test.local']));

        $res = $this->comprar($linkId, ['tipos' => ['jubilados' => 1, 'general' => 1], 'email' => 'd@test.local']);
        $this->assertStatus(400, $res);
        $this->assertSame('Se agotaron las entradas "Jubilados"', $res->body['error']);
        $this->assertSame(2, (int) $this->valor('SELECT COUNT(*) FROM ticket_orders'));

        $tipos = Entradas::disponibilidad($this->db, $linkId)['tipos'];
        $this->assertTrue($tipos[1]['agotado']);
        $this->assertSame(8, $tipos[0]['disponibles']);
    }

    /** Con varios tipos, una pantalla vieja que sólo manda la cantidad no puede adivinar cuál era. */
    public function testConVariosTiposHayQueDecirCuales()
    {
        $linkId = $this->eventoConTipos();

        $res = $this->comprar($linkId, ['cantidad' => 2]);

        $this->assertStatus(400, $res);
        $this->assertSame('Elegí qué entradas querés', $res->body['error']);
    }

    public function testConUnSoloTipoAlcanzaConLaCantidad()
    {
        $linkId = $this->eventoConTipos(['tipos' => [['id' => 'general', 'nombre' => 'General', 'precio' => 0]]]);

        $res = $this->comprar($linkId, ['cantidad' => 2]);

        $this->assertStatus(201, $res);
        $this->assertSame('2', (string) $this->items($res->body['codigo'])[0]['cantidad']);
    }

    public function testUnTipoQueNoExisteSeRechaza()
    {
        $linkId = $this->eventoConTipos();

        $res = $this->comprar($linkId, ['tipos' => ['vip' => 1]]);

        $this->assertStatus(400, $res);
        $this->assertSame('Uno de los tipos de entrada ya no existe. Volvé a cargar la página.', $res->body['error']);
    }

    public function testElPedidoPorTipoMalFormadoSeRechaza()
    {
        $linkId = $this->eventoConTipos();

        foreach ([['general' => -1], ['general' => 'dos'], 'general'] as $tipos) {
            $res = $this->comprar($linkId, ['tipos' => $tipos]);
            $this->assertStatus(400, $res);
        }

        $res = $this->comprar($linkId, ['tipos' => ['general' => 0]]);
        $this->assertSame('Hay que pedir al menos una entrada', $res->body['error']);
    }

    // ============================================================== plano

    /**
     * Con plano el tipo lo pone la zona. Lo que mande el comprador no cuenta:
     * pedir "Invitado" para una butaca VIP sería no pagarla.
     */
    public function testConPlanoCadaLugarPagaElTipoDeSuZona()
    {
        $linkId = $this->eventoConTipos([
            'tipos' => [
                ['id' => 'general', 'nombre' => 'General', 'precio' => 8000],
                ['id' => 'vip', 'nombre' => 'VIP', 'precio' => 15000],
            ],
            'plano' => $this->planoConZonas(),
        ]);
        $this->preferenciaOk();

        $res = $this->comprar($linkId, [
            'lugares' => ['f:A:1', 'f:B:2', 'f:C:3'],
            'tipos'   => ['general' => 0, 'vip' => 0, 'invitado' => 3],
        ]);

        $this->assertStatus(201, $res);
        $this->assertEquals(31000, $res->body['total']);

        $items = $this->items($res->body['codigo']);
        $this->assertSame(['general', 'vip'], array_column($items, 'tipo'));
        $this->assertSame(['2', '1'], array_map('strval', array_column($items, 'cantidad')));
    }

    /** Un tipo agotado agota sus butacas aunque el plano tenga lugar. */
    public function testConPlanoElCupoDelTipoTambienCuenta()
    {
        $linkId = $this->eventoConTipos([
            'tipos' => [
                ['id' => 'general', 'nombre' => 'General', 'precio' => 0],
                ['id' => 'vip', 'nombre' => 'VIP', 'precio' => 0, 'cupo' => 1],
            ],
            'plano' => $this->planoConZonas(),
        ]);

        $this->assertStatus(201, $this->comprar($linkId, ['lugares' => ['f:A:1']]));

        $res = $this->comprar($linkId, ['lugares' => ['f:A:2'], 'email' => 'b@test.local']);

        $this->assertStatus(400, $res);
        $this->assertSame('Se agotaron las entradas "VIP"', $res->body['error']);
    }

    // ======================================================= lo que se ve

    public function testLasVentasLaPuertaYLaOrdenDicenQueSeLlevoCadaUno()
    {
        $linkId = $this->eventoConTipos();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId, ['tipos' => ['invitado' => 1]])->body['codigo'];
        $this->comprar($linkId, ['tipos' => ['general' => 2, 'jubilados' => 1], 'email' => 'b@test.local']);

        $ventas = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)))->body;

        $invitado = array_values(array_filter($ventas['ordenes'], function ($o) use ($codigo) {
            return $o['codigo'] === $codigo;
        }))[0];
        $this->assertSame('Invitado', $invitado['items'][0]['nombre']);
        // Sólo lo pagado: la otra compra todavía está reservada.
        $this->assertSame([['tipo' => 'invitado', 'nombre' => 'Invitado', 'vendidas' => 1, 'recaudado' => 0.0]], $ventas['resumen']['por_tipo']);

        $puerta = Puerta::lista($this->db, $linkId);
        $this->assertSame('1 Invitado', $puerta['ordenes'][0]['tipos']);

        $orden = CheckoutHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http);
        $this->assertSame('1 Invitado', $orden->body['orden']['tipos']);

        $compartida = VentasCompartidas::estado($this->db, $linkId);
        $this->assertSame([1, 0, 1], [
            $compartida['venta']['tipos'][2]['vendidas'],
            $compartida['venta']['tipos'][1]['vendidas'],
            $compartida['venta']['vendidas'],
        ]);
    }

    /** Si el tipo se renombra o cambia de precio, la compra sigue diciendo lo que se pagó. */
    public function testLaCompraConservaElNombreYElPrecioDelMomento()
    {
        $linkId = $this->eventoConTipos();
        $codigo = $this->comprar($linkId, ['tipos' => ['invitado' => 1]])->body['codigo'];

        $tipos = $this->tipos();
        $tipos[2]['nombre'] = 'Prensa';
        $tipos[2]['precio'] = 1000;
        $this->assertStatus(200, $this->configurar($linkId, ['tipos' => $tipos]));

        $this->assertSame('Invitado', $this->items($codigo)[0]['nombre']);
        $this->assertSame('0.00', $this->items($codigo)[0]['precio_unitario']);
        // El cupo sigue contando por id: la compra es del tipo renombrado.
        $this->assertSame(['invitado' => 1], Entradas::ocupadasPorTipo($this->db, $linkId));
    }
}
