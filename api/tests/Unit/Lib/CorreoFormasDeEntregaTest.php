<?php

namespace Tests\Unit\Lib;

use CorreoProductos;
use Cripto;
use EstadosDePedidos;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMailer;
use Tests\Support\FakePdo;

/**
 * Los mails de un pedido de un físico según su forma de entrega (#53):
 * envío (como en #43), retiro en un lugar y coordinar después de la compra.
 * También los avisos de cada cambio de estado, con sus nombres por forma.
 */
class CorreoFormasDeEntregaTest extends TestCase
{
    const CODIGO = 'AAAABBBBCCCC';
    const DIRECCION = 'Av. Corrientes 1234, CABA';
    const MAPS = 'https://maps.google.com/?cid=123&x=1';
    const INDICACIONES = 'Lunes a viernes de 10 a 18, timbre 3';

    /** @var FakePdo */
    private $db;

    /** @var FakeMailer */
    private $mailer;

    protected function setUp(): void
    {
        $this->db = new FakePdo();
        $this->mailer = new FakeMailer();
    }

    private function orden(array $overrides = [])
    {
        return array_merge([
            'id' => 7, 'codigo' => self::CODIGO, 'estado' => 'pagada', 'tipo' => 'fisico',
            'nombre' => 'Ana Gómez', 'email' => 'ana@example.com', 'telefono' => '11 5555-1234',
            'token_cifrado' => Cripto::cifrar(str_repeat('9f', 32)),
            'mail_enviado_en' => null, 'mail_intentos' => 0, 'aviso_vendedor_en' => null,
            'producto' => 'Disco', 'opciones_texto' => null,
            'cantidad' => 2, 'precio_unitario' => '3000.00', 'costo_envio' => '0.00', 'total' => '6000.00',
            'sin_stock' => 0, 'producto_imagen' => null, 'archivo_nombre' => null,
            'pagina' => 'La Sala', 'email_contacto' => 'sala@example.com', 'duena_email' => 'duena@example.com',
            'forma_entrega' => 'retiro', 'retiro_direccion' => self::DIRECCION, 'retiro_maps_url' => self::MAPS,
            'retiro_indicaciones' => self::INDICACIONES,
            'entrega_fecha' => null, 'entrega_franja' => null, 'envio_empresa' => null, 'envio_seguimiento' => null,
            'cancelado_motivo' => null,
        ], $overrides);
    }

    /** Un pedido sin envío no tiene fila en product_order_envios. */
    private function hayPedido(array $overrides = [])
    {
        $this->db->onSelect('FROM product_orders o', [$this->orden($overrides)]);
        $this->db->onSelect('FROM product_order_envios WHERE order_id = ?', []);
    }

    // --------------------------------------------------------------- retiro

    public function testRetiroAlCompradorLeLlegaElPuntoConElLinkYLasIndicaciones()
    {
        $this->hayPedido();

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);
        $mensaje = $this->mailer->mensajePara('ana@example.com');

        foreach ([$mensaje['html'], $mensaje['texto']] as $cuerpo) {
            $this->assertStringContainsString('Lo retirás en ' . self::DIRECCION, $cuerpo);
            $this->assertStringContainsString(self::INDICACIONES, $cuerpo);
            $this->assertStringContainsString('Retiro', $cuerpo);
            $this->assertStringContainsString('$ 6.000', $cuerpo);
            $this->assertStringNotContainsString('Envío', $cuerpo, 'sin envío no hay renglón de envío');
        }

        $this->assertStringContainsString('href="https://maps.google.com/?cid=123&amp;x=1"', $mensaje['html']);
        $this->assertStringContainsString('Google Maps: ' . self::MAPS, $mensaje['texto']);
    }

    public function testRetiroAQuienVendeLeLlegaElPunto()
    {
        $this->hayPedido();

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);
        $aviso = $this->mailer->mensajePara('sala@example.com');

        $this->assertSame('Pedido nuevo: Disco (' . self::CODIGO . ')', $aviso['asunto']);
        foreach ([$aviso['html'], $aviso['texto']] as $cuerpo) {
            $this->assertStringContainsString(self::DIRECCION, $cuerpo);
            $this->assertStringContainsString(self::INDICACIONES, $cuerpo);
            $this->assertStringContainsString('11 5555-1234', $cuerpo);
        }
    }

    // ------------------------------------------------------------ coordinar

    public function testCoordinarAlCompradorLeDiceQueLoVanAContactar()
    {
        $this->hayPedido(['forma_entrega' => 'coordinar', 'retiro_direccion' => null, 'retiro_maps_url' => null]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);
        $mensaje = $this->mailer->mensajePara('ana@example.com');

        foreach ([$mensaje['html'], $mensaje['texto']] as $cuerpo) {
            $this->assertStringContainsString(
                'Quien vende te va a contactar al 11 5555-1234 para coordinar la entrega',
                $cuerpo
            );
            $this->assertStringNotContainsString('Envío', $cuerpo);
            $this->assertStringNotContainsString('Google Maps', $cuerpo);
        }
    }

    public function testCoordinarAQuienVendeLeDiceConQuienYAQueTelefono()
    {
        $this->hayPedido(['forma_entrega' => 'coordinar', 'retiro_direccion' => null]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);
        $aviso = $this->mailer->mensajePara('sala@example.com');

        foreach ([$aviso['html'], $aviso['texto']] as $cuerpo) {
            $this->assertStringContainsString('Coordiná la entrega con Ana Gómez al 11 5555-1234', $cuerpo);
        }
    }

    // ---------------------------------------------------------------- envío

    public function testEnvioSigueComoAntes()
    {
        $this->db->onSelect('FROM product_orders o', [$this->orden([
            'forma_entrega' => 'envio', 'costo_envio' => '700.00', 'total' => '6700.00',
            'retiro_direccion' => null, 'retiro_maps_url' => null, 'retiro_indicaciones' => null,
        ])]);
        $this->db->onSelect('FROM product_order_envios WHERE order_id = ?', [[
            'order_id' => 7, 'calle' => 'Av. Siempreviva 742', 'piso_depto' => null, 'localidad' => 'Springfield',
            'provincia' => 'Buenos Aires', 'codigo_postal' => '1414', 'indicaciones' => null,
            'dias' => 'lunes', 'franjas' => 'tarde', 'comentario_horario' => null,
        ]]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);
        $texto = $this->mailer->mensajePara('ana@example.com')['texto'];

        $this->assertStringContainsString('La Sala se va a poner en contacto para coordinar la entrega', $texto);
        $this->assertStringContainsString('Envío: $ 700', $texto);
        $this->assertStringContainsString('Av. Siempreviva 742', $texto);
        $this->assertStringNotContainsString('Google Maps', $texto);
    }

    // ------------------------------------------------------------- estados

    private function aviso(array $orden, $estado, $correccion = false)
    {
        $r = CorreoProductos::avisarEstado($this->orden($orden), $estado, $correccion, $this->mailer);
        $this->assertTrue($r['enviado']);

        return $this->mailer->mensajePara('ana@example.com');
    }

    public function testRetiroConFechaDiceFechaDeRetiroYDonde()
    {
        $mensaje = $this->aviso(['entrega_fecha' => '2026-11-03', 'entrega_franja' => 'tarde'], 'con_fecha');

        $this->assertSame('Tu pedido ya tiene fecha de retiro: Disco', $mensaje['asunto']);
        $this->assertStringContainsString('Podés retirarlo el martes 3 de noviembre, a la tarde, en ' . self::DIRECCION, $mensaje['texto']);
        $this->assertStringContainsString('FECHA DE RETIRO', $mensaje['html']);
        $this->assertStringContainsString('Ver en Google Maps', $mensaje['html']);
    }

    public function testRetiroCorregirLaFecha()
    {
        $mensaje = $this->aviso(['entrega_fecha' => '2026-11-03'], 'con_fecha', true);

        $this->assertSame('Cambió la fecha de retiro de tu pedido: Disco', $mensaje['asunto']);
    }

    public function testRetiroEnviadoEsListoParaRetirarSinSeguimiento()
    {
        $mensaje = $this->aviso(['envio_empresa' => 'Andreani', 'envio_seguimiento' => 'AN1'], 'enviado');

        $this->assertSame('Tu pedido está listo para retirar: Disco', $mensaje['asunto']);
        $this->assertStringContainsString('Ya podés retirar tu pedido en ' . self::DIRECCION, $mensaje['texto']);
        $this->assertStringContainsString(self::INDICACIONES, $mensaje['texto']);
        $this->assertStringNotContainsString('Andreani', $mensaje['html']);
        $this->assertStringNotContainsString('seguimiento del envío', $mensaje['html']);
    }

    public function testRetiroEntregadoEsRetirado()
    {
        $mensaje = $this->aviso([], 'entregado');

        $this->assertSame('Retiraste tu pedido: Disco', $mensaje['asunto']);
        $this->assertStringContainsString('marcó tu pedido como retirado', $mensaje['texto']);
    }

    /** Coordinar usa los textos del envío: Con fecha de entrega, Enviado, Entregado. */
    public function testCoordinarUsaLosTextosDelEnvio()
    {
        $base = ['forma_entrega' => 'coordinar', 'retiro_direccion' => null, 'retiro_maps_url' => null];

        $this->assertSame('Tu pedido ya tiene fecha de entrega: Disco',
            $this->aviso($base + ['entrega_fecha' => '2026-11-03'], 'con_fecha')['asunto']);
        $this->assertSame('Tu pedido está en camino: Disco', $this->aviso($base, 'enviado')['asunto']);
        $this->assertSame('Tu pedido fue entregado: Disco', $this->aviso($base, 'entregado')['asunto']);
    }

    public function testCancelarEsIgualEnTodasLasFormas()
    {
        $mensaje = $this->aviso(['cancelado_motivo' => 'Se rayó'], 'cancelado');

        $this->assertSame('Se canceló tu pedido: Disco', $mensaje['asunto']);
    }

    // ------------------------------------------------------ nombres de estados

    /** @dataProvider nombresPorForma */
    public function testLosNombresDeLosEstadosPorForma($forma, $conFecha, $enviado, $entregado)
    {
        $this->assertSame($conFecha, EstadosDePedidos::etiqueta('con_fecha', $forma));
        $this->assertSame($enviado, EstadosDePedidos::etiqueta('enviado', $forma));
        $this->assertSame($entregado, EstadosDePedidos::etiqueta('entregado', $forma));
        $this->assertSame('Ingresado', EstadosDePedidos::etiqueta('ingresado', $forma));
        $this->assertSame('Cancelado', EstadosDePedidos::etiqueta('cancelado', $forma));
    }

    public function nombresPorForma()
    {
        return [
            'envío'     => ['envio', 'Con fecha de entrega', 'Enviado', 'Entregado'],
            'retiro'    => ['retiro', 'Con fecha de retiro', 'Listo para retirar', 'Retirado'],
            'coordinar' => ['coordinar', 'Con fecha de entrega', 'Enviado', 'Entregado'],
        ];
    }

    public function testLaLineaDeTiempoDeUnRetiro()
    {
        $this->assertSame(
            ['Ingresado', 'Con fecha de retiro', 'Listo para retirar', 'Retirado'],
            array_column(EstadosDePedidos::lineaDeTiempo('ingresado', [], 'retiro'), 'etiqueta')
        );
    }
}
