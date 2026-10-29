<?php

namespace Tests\Unit\Handlers;

use PedidosHandler;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

/**
 * La gestión de un pedido de un físico por quien vende (#44): quién puede,
 * qué valida el servidor y el mail a quien compró en cada cambio.
 */
class PedidosHandlerTest extends HandlerTestCase
{
    const CODIGO = 'AAAABBBBCCCC';

    /** @var FakeMailer */
    private $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new FakeMailer();
    }

    private function orden(array $extra = [])
    {
        return array_merge([
            'id' => 7, 'codigo' => self::CODIGO, 'product_id' => 40, 'tipo' => 'fisico', 'pagina_id' => 9,
            'pagina' => 'La Sala', 'email_contacto' => 'hola@lasala.test', 'nombre' => 'Ana', 'email' => 'ana@test.local',
            'telefono' => '11 5555-1234', 'producto' => 'Remera', 'opciones_texto' => 'Talle M', 'cantidad' => 2,
            'precio_unitario' => '2500.00', 'costo_envio' => '500.00', 'total' => '5500.00', 'estado' => 'pagada',
            'creado_en' => '2026-10-29 10:00:00', 'pagada_en' => '2026-10-29 10:05:00', 'sin_stock' => 0,
            'estado_pedido' => 'ingresado', 'entrega_fecha' => null, 'entrega_franja' => null, 'envio_empresa' => null,
            'envio_seguimiento' => null, 'cancelado_motivo' => null, 'mp_payment_id' => '880001',
        ], $extra);
    }

    /** La orden que lee el handler, el permiso sobre la página y la fila que toma el cambio. */
    private function hayPedido(array $extra = [], $autorizado = true)
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->orden($extra)]);

        if ($autorizado) {
            $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        }

        $this->db->onSelect('FROM product_orders WHERE id = ? FOR UPDATE', [[
            'id' => 7, 'product_id' => 40, 'cantidad' => 2, 'estado' => isset($extra['estado']) ? $extra['estado'] : 'pagada',
            'estado_pedido' => array_key_exists('estado_pedido', $extra) ? $extra['estado_pedido'] : 'ingresado',
            'sin_stock' => 0, 'entrega_fecha' => isset($extra['entrega_fecha']) ? $extra['entrega_fecha'] : null,
            'entrega_franja' => null,
        ]]);
    }

    /** Lo que se relee después del cambio, ya con el estado nuevo. */
    private function despues(array $extra)
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->orden($extra)]);
    }

    private function cambiar(array $cuerpo, $user = 'default')
    {
        return PedidosHandler::estado(
            $this->db,
            $this->post(['codigo' => self::CODIGO] + $cuerpo, $user === 'default' ? $this->user(3) : $user),
            $this->mailer
        );
    }

    // ============================================================ permisos

    public function testExigeSesionYElMetodo()
    {
        $this->assertError(401, $this->cambiar(['estado' => 'entregado'], null));
        $this->assertError(405, PedidosHandler::estado($this->db, $this->get(['codigo' => 'X'], $this->user())));
        $this->assertError(401, PedidosHandler::detalle($this->db, $this->get(['codigo' => 'X'])));
        $this->assertError(405, PedidosHandler::detalle($this->db, $this->post(['codigo' => 'X'], $this->user())));
    }

    public function testSinAccesoALaPaginaDelProductoNoSeCambiaNada()
    {
        $this->hayPedido([], false);

        $res = $this->cambiar(['estado' => 'entregado']);

        $this->assertError(403, $res);
        // El permiso se mira sobre la página del producto, con quien pide.
        $this->assertSame([9, 3, 3], $this->db->paramsFor('FROM pages p WHERE p.id = ?'));
        $this->assertNoWrites();
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testSinAccesoTampocoSeVeElDetalle()
    {
        $this->hayPedido([], false);

        $this->assertError(403, PedidosHandler::detalle($this->db, $this->get(['codigo' => self::CODIGO], $this->user(3))));
        $this->assertFalse($this->db->ran('product_order_envios'));
    }

    /** Un digital no tiene estados de pedido: no se distingue de uno que no existe. */
    public function testUnDigitalNoSeGestiona()
    {
        $this->hayPedido(['tipo' => 'digital']);

        $this->assertError(404, $this->cambiar(['estado' => 'entregado']));
        $this->assertNoWrites();
    }

    public function testUnPedidoQueNoExiste()
    {
        $this->assertError(404, $this->cambiar(['estado' => 'entregado']));
        $this->assertError(400, PedidosHandler::estado(
            $this->db, $this->post(['codigo' => ['x'], 'estado' => 'entregado'], $this->user(3)), $this->mailer
        ));
    }

    /** Un producto borrado sin página no lo gestiona nadie. */
    public function testSinPaginaNoHayPermiso()
    {
        $this->hayPedido(['pagina_id' => null], false);

        $this->assertError(403, $this->cambiar(['estado' => 'entregado']));
    }

    // ========================================================== validación

    public function testElEstadoTieneQueSerUnoDeLosQueSeEligen()
    {
        $this->assertError(400, $this->cambiar(['estado' => 'ingresado']));
        $this->assertError(400, $this->cambiar(['estado' => 'perdido']));
        $this->assertError(400, $this->cambiar([]));
        $this->assertNoWrites();
    }

    public function testConFechaSinFechaNoPasa()
    {
        $this->hayPedido();

        $this->assertError(400, $this->cambiar(['estado' => 'con_fecha', 'franja' => 'tarde']), 'Elegí la fecha de entrega');
        $this->assertNoWrites();
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testCancelarSinMotivoNoPasa()
    {
        $this->hayPedido();

        $this->assertError(400, $this->cambiar(['estado' => 'cancelado', 'motivo' => '']), 'por qué se cancela');
        $this->assertNoWrites();
    }

    /** La transición se valida en el servidor, contra lo que hay en la base. */
    public function testUnaTransicionInvalidaDa409SinMail()
    {
        $this->hayPedido(['estado_pedido' => 'entregado']);

        $res = $this->cambiar(['estado' => 'cancelado', 'motivo' => 'Tarde']);

        $this->assertError(409, $res, 'ya está entregado');
        $this->assertFalse($this->db->ran('UPDATE product_orders'));
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testUnPedidoSinPagarNoSeGestiona()
    {
        $this->hayPedido(['estado' => 'pendiente', 'estado_pedido' => null]);

        $this->assertError(409, $this->cambiar(['estado' => 'entregado']), 'no está pagado');
    }

    // =============================================================== mails

    public function testConFechaAvisaLaFechaYLaFranja()
    {
        $fecha = date('Y-m-d', time() + 4 * 86400);
        $this->hayPedido();
        $this->despues(['estado_pedido' => 'con_fecha', 'entrega_fecha' => $fecha, 'entrega_franja' => 'tarde']);

        $res = $this->cambiar(['estado' => 'con_fecha', 'fecha' => $fecha, 'franja' => 'tarde']);

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['aviso_enviado']);
        $this->assertSame('con_fecha', $res->body['pedido']['estado_pedido']);
        $mail = $this->mailer->mensajePara('ana@test.local');
        $this->assertSame('Tu pedido ya tiene fecha de entrega: Remera', $mail['asunto']);
        $this->assertStringContainsString(\CorreoProductos::fechaLarga($fecha) . ', a la tarde', $mail['texto']);
        $this->assertStringContainsString('/compra/' . self::CODIGO, $mail['html']);
        $this->assertSame('hola@lasala.test', $mail['responder']);
        $this->assertCount(1, $this->mailer->enviados);
        $this->assertNull($this->mailer->cuentas[0], 'Sale por la cuenta de la plataforma');
    }

    public function testCorregirLaFechaVuelveAAvisar()
    {
        $fecha = date('Y-m-d', time() + 6 * 86400);
        $this->hayPedido(['estado_pedido' => 'con_fecha', 'entrega_fecha' => date('Y-m-d', time() + 2 * 86400)]);
        $this->despues(['estado_pedido' => 'con_fecha', 'entrega_fecha' => $fecha]);

        $res = $this->cambiar(['estado' => 'con_fecha', 'fecha' => $fecha]);

        $this->assertStatus(200, $res);
        $this->assertSame('Cambió la fecha de entrega de tu pedido: Remera', $this->mailer->enviados[0]['asunto']);
        $this->assertStringNotContainsString(', a la', $this->mailer->enviados[0]['texto']);
    }

    public function testEnviadoAvisaElSeguimientoYSiEsUnLinkSePuedeTocar()
    {
        $this->hayPedido();
        $this->despues([
            'estado_pedido' => 'enviado', 'envio_empresa' => 'Andreani', 'envio_seguimiento' => 'https://seguimiento.test/AN1',
        ]);

        $this->cambiar(['estado' => 'enviado', 'empresa' => 'Andreani', 'seguimiento' => 'https://seguimiento.test/AN1']);

        $mail = $this->mailer->enviados[0];
        $this->assertSame('Tu pedido está en camino: Remera', $mail['asunto']);
        $this->assertStringContainsString('Lo lleva: Andreani', $mail['texto']);
        $this->assertStringContainsString('Seguimiento: https://seguimiento.test/AN1', $mail['texto']);
        $this->assertStringContainsString('href="https://seguimiento.test/AN1"', $mail['html']);
    }

    /** Un seguimiento que no es un link queda como texto, escapado. */
    public function testUnSeguimientoQueNoEsLinkNoSeEnlaza()
    {
        $this->hayPedido();
        $this->despues(['estado_pedido' => 'enviado', 'envio_seguimiento' => 'javascript:alert(1) <b>']);

        $this->cambiar(['estado' => 'enviado', 'seguimiento' => 'javascript:alert(1) <b>']);

        $html = $this->mailer->enviados[0]['html'];
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testEntregadoAvisa()
    {
        $this->hayPedido(['estado_pedido' => 'enviado']);
        $this->despues(['estado_pedido' => 'entregado']);

        $this->cambiar(['estado' => 'entregado']);

        $this->assertSame('Tu pedido fue entregado: Remera', $this->mailer->enviados[0]['asunto']);
    }

    public function testCanceladoAvisaElMotivoYNoDevuelveElPago()
    {
        $this->hayPedido();
        $this->despues(['estado_pedido' => 'cancelado', 'cancelado_motivo' => 'No llegó la tela']);

        $res = $this->cambiar(['estado' => 'cancelado', 'motivo' => 'No llegó la tela']);

        $this->assertStatus(200, $res);
        $mail = $this->mailer->enviados[0];
        $this->assertSame('Se canceló tu pedido: Remera', $mail['asunto']);
        $this->assertStringContainsString('Motivo: No llegó la tela', $mail['texto']);
        // El pago no se toca: ni el estado de la orden ni Mercado Pago.
        $this->assertFalse($this->db->ran("SET estado = 'cancelada'"));
        $this->assertSame('880001', $res->body['pedido']['mp_payment_id']);
    }

    /** Si el mail falla, el cambio queda igual y la pantalla lo sabe. */
    public function testSiFallaElMailElCambioQuedaIgual()
    {
        $this->mailer->fallarCon('SMTP caído');
        $this->hayPedido(['estado_pedido' => 'enviado']);
        $this->despues(['estado_pedido' => 'entregado']);

        $res = $this->cambiar(['estado' => 'entregado']);

        $this->assertStatus(200, $res);
        $this->assertFalse($res->body['aviso_enviado']);
        $this->assertSame('SMTP caído', $res->body['aviso_error']);
        $this->assertTrue($this->db->committed);
    }

    // ============================================================= detalle

    public function testElDetalleTraeTodoParaEntregar()
    {
        $this->hayPedido();
        $this->db->onSelect('FROM product_order_envios WHERE order_id = ?', [[
            'order_id' => 7, 'calle' => 'Av. Corrientes 1234', 'piso_depto' => '3° B', 'localidad' => 'CABA',
            'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043', 'indicaciones' => 'Timbre B',
            'dias' => 'lunes,sabado', 'franjas' => 'tarde', 'comentario_horario' => 'Después de las 18',
        ]]);
        $this->db->onSelect('FROM product_order_historial h', [
            ['id' => 1, 'estado' => 'ingresado', 'datos' => null, 'creado_en' => '2026-10-29 10:05:00', 'user_id' => null, 'quien' => null],
        ]);

        $res = PedidosHandler::detalle($this->db, $this->get(['codigo' => self::CODIGO], $this->user(3)));

        $this->assertStatus(200, $res);
        $p = $res->body['pedido'];
        $this->assertSame('Av. Corrientes 1234', $p['envio']['calle']);
        $this->assertSame('Timbre B', $p['envio']['indicaciones']);
        $this->assertSame(['lunes', 'sabado'], $p['horarios']['dias']);
        $this->assertSame('11 5555-1234', $p['telefono']);
        $this->assertSame(['con_fecha', 'enviado', 'entregado', 'cancelado'], $p['siguientes']);
        $this->assertSame('Sistema', $p['historial'][0]['quien']);
        $this->assertFalse($p['sin_stock']);
        $this->assertArrayNotHasKey('token_cifrado', $p);
        $this->assertSame('envio', $p['forma_entrega']);
        $this->assertNull($p['retiro']);
    }

    // =================================================== formas de entrega (#53)

    private function retiro(array $extra = [])
    {
        return [
            'forma_entrega' => 'retiro', 'retiro_direccion' => 'Av. Corrientes 1234, CABA',
            'retiro_maps_url' => 'https://maps.google.com/?cid=1', 'retiro_indicaciones' => 'Timbre 3',
        ] + $extra;
    }

    public function testElDetalleDeUnRetiroTraeElPuntoYLosNombresDelRetiro()
    {
        $this->hayPedido($this->retiro(['estado_pedido' => 'enviado']));
        $this->db->onSelect('FROM product_order_historial h', [
            ['id' => 1, 'estado' => 'ingresado', 'datos' => null, 'creado_en' => '2026-10-29 10:05:00', 'user_id' => null, 'quien' => null],
            ['id' => 2, 'estado' => 'enviado', 'datos' => null, 'creado_en' => '2026-10-30 10:05:00', 'user_id' => 3, 'quien' => 'Vendedora'],
        ]);

        $p = PedidosHandler::detalle($this->db, $this->get(['codigo' => self::CODIGO], $this->user(3)))->body['pedido'];

        $this->assertSame('retiro', $p['forma_entrega']);
        $this->assertSame('Av. Corrientes 1234, CABA', $p['retiro']['direccion']);
        $this->assertNull($p['envio']);
        $this->assertSame('Listo para retirar', $p['historial'][1]['etiqueta']);
    }

    /** Listo para retirar no lleva empresa ni seguimiento, aunque lleguen. */
    public function testListoParaRetirarNoGuardaEmpresaNiSeguimiento()
    {
        $this->hayPedido($this->retiro());
        $this->despues($this->retiro(['estado_pedido' => 'enviado']));

        $res = $this->cambiar(['estado' => 'enviado', 'empresa' => 'Andreani', 'seguimiento' => 'AN1']);

        $this->assertStatus(200, $res);
        $this->assertSame(['enviado', null, null, 7, 'ingresado'], $this->db->paramsFor('SET estado_pedido = ?, envio_empresa = ?'));
        $this->assertSame('Tu pedido está listo para retirar: Remera', $this->mailer->enviados[0]['asunto']);
    }

    public function testElDetalleDeCoordinarTraeElTelefono()
    {
        $this->hayPedido(['forma_entrega' => 'coordinar']);

        $p = PedidosHandler::detalle($this->db, $this->get(['codigo' => self::CODIGO], $this->user(3)))->body['pedido'];

        $this->assertSame('coordinar', $p['forma_entrega']);
        $this->assertSame('11 5555-1234', $p['telefono']);
        $this->assertNull($p['retiro']);
    }
}
