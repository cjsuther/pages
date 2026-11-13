<?php

namespace Tests\Integration;

use CheckoutHandler;
use Cobros;
use EntradasHandler;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Un evento con la llave de comisiones en 0 cobra exactamente lo mismo que
 * antes de que existiera la función, aunque la función global esté prendida.
 *
 * Es el caso del festival que se está vendiendo cuando esto se despliega: su
 * configuración es anterior, la migración le deja la llave en 0, y después se
 * prende CARGOS_AL_COMPRADOR para los eventos nuevos. Los valores esperados son
 * los que produce main sin este cambio (este mismo archivo, sin
 * prenderLaFuncion(), pasa en main): el total, la comisión y la preferencia
 * entera que se le manda a Mercado Pago.
 */
class CheckoutSinCargosTest extends IntegracionTestCase
{
    private $duenaId;
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($paginaId);
        $this->http = new FakeHttpClient();

        $this->assertTrue(Cobros::guardarDesdeOAuth($this->db, $paginaId, [
            'user_id'       => '987654',
            'access_token'  => 'APP_USR-1234567890-token-de-prueba',
            'refresh_token' => 'TG-refresh-de-prueba',
            'public_key'    => 'APP_USR-public-key',
            'modo'          => 'produccion',
            'expira_en'     => gmdate('Y-m-d H:i:s', time() + 90 * 86400),
        ])['ok']);
    }

    protected function tearDown(): void
    {
        \CargosAlComprador::forzar(null);
        parent::tearDown();
    }

    /** La función global prendida, con las tasas de los tests. */
    private function prenderLaFuncion()
    {
        \CargosAlComprador::forzar([
            'mp_porcentaje' => MP_FEE_PERCENT, 'mp_fijo' => 0, 'mp_iva' => 0,
            'plataforma_porcentaje' => PLATFORM_FEE_PERCENT, 'plataforma_iva' => 0,
        ]);
    }

    /** Configurado antes del deploy: por el editor, con la función todavía apagada. */
    private function festival(array $config)
    {
        $linkId = $this->evento($this->grupoId, ['text' => 'Festival WIP']);

        $this->assertStatus(200, EntradasHandler::config($this->db, $this->post(array_merge([
            'activo' => 1, 'capacidad' => 500, 'max_por_compra' => 10,
        ], $config), $this->user($this->duenaId), ['link_id' => $linkId])));

        return $linkId;
    }

    private function comprar($linkId, array $datos)
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);

        $res = CheckoutHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre'  => 'Ana Compradora',
            'email'   => 'ana@test.local',
        ], $datos)), $this->http, new FakeMailer());

        $this->assertStatus(201, $res);

        return $res->body['codigo'];
    }

    /** La preferencia completa que produce main para una orden. */
    private function preferenciaDeMain($codigo, array $items, $marketplaceFee)
    {
        return [
            'items' => $items,
            'payer' => ['name' => 'Ana Compradora', 'email' => 'ana@test.local'],
            'external_reference' => $codigo,
            'notification_url' => 'https://rezonar.test/api/public/aviso-pago.php?orden=' . $codigo,
            'back_urls' => [
                'success' => 'https://frontend.test/entrada/' . $codigo,
                'pending' => 'https://frontend.test/entrada/' . $codigo,
                'failure' => 'https://frontend.test/entrada/' . $codigo,
            ],
            'auto_return' => 'approved',
            'payment_methods' => ['excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']]],
            'marketplace_fee' => $marketplaceFee,
        ];
    }

    private function montos($codigo)
    {
        return $this->fila(
            'SELECT total, precio_unitario, comision, comision_porcentaje FROM ticket_orders WHERE codigo = ?',
            [$codigo]
        );
    }

    public function testUnPrecioConLaLlaveEnCeroCobraLoMismoQueMain()
    {
        $linkId = $this->festival(['precio' => 1500]);
        $this->prenderLaFuncion();

        $codigo = $this->comprar($linkId, ['cantidad' => 2]);

        $this->assertEquals(
            $this->preferenciaDeMain($codigo, [
                ['title' => 'Festival WIP', 'quantity' => 2, 'unit_price' => 1500, 'currency_id' => 'ARS'],
            ], 300),
            $this->http->jsonDe('/checkout/preferences')
        );
        $this->assertSame(
            ['total' => '3000.00', 'precio_unitario' => '1500.00', 'comision' => '300.00', 'comision_porcentaje' => '10.00'],
            $this->montos($codigo)
        );
    }

    public function testTiposYPromoConLaLlaveEnCeroCobranLoMismoQueMain()
    {
        $linkId = $this->festival(['precio' => 1000, 'tipos' => [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 1000, 'cupo' => null, 'personas' => 1],
            ['id' => 'promo', 'nombre' => 'Promo 2x1', 'precio' => 1500, 'cupo' => null, 'personas' => 2],
            ['id' => 'invitado', 'nombre' => 'Invitado', 'precio' => 0, 'cupo' => null, 'personas' => 1],
        ]]);
        $this->prenderLaFuncion();

        $codigo = $this->comprar($linkId, ['tipos' => ['general' => 1, 'promo' => 2, 'invitado' => 1]]);

        $this->assertEquals(
            $this->preferenciaDeMain($codigo, [
                ['title' => 'Festival WIP — General', 'quantity' => 1, 'unit_price' => 1000, 'currency_id' => 'ARS'],
                ['title' => 'Festival WIP — Promo 2x1', 'quantity' => 1, 'unit_price' => 1500, 'currency_id' => 'ARS'],
            ], 250),
            $this->http->jsonDe('/checkout/preferences')
        );
        $this->assertSame(
            ['total' => '2500.00', 'precio_unitario' => '625.00', 'comision' => '250.00', 'comision_porcentaje' => '10.00'],
            $this->montos($codigo)
        );
    }
}
