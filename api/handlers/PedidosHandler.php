<?php

/**
 * La gestión de un pedido de un físico por quien vende (#44):
 *
 *   - detalle: GET productos/pedido.php?codigo=X — el comprador, la dirección
 *     completa, los horarios, el historial y a qué estados puede pasar.
 *   - estado:  POST productos/pedido-estado.php {codigo, estado, ...datos} —
 *     lo pasa al estado siguiente y le avisa por mail a quien compró.
 *
 * Sólo la dueña y quienes administran la página del producto, mirado en el
 * servidor sobre la página del producto (no sobre su link, que puede no
 * existir más). Las páginas colaboradoras de un evento no tienen nada que ver
 * con un producto: no entran.
 *
 * Las transiciones se validan acá (EstadosDePedidos), no sólo en la pantalla.
 */
class PedidosHandler
{
    public static function detalle($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        list($orden, $error) = self::pedidoAdministrable($db, $req->param('codigo'), $req->userId());

        if ($error !== null) {
            return $error;
        }

        return Response::ok(['pedido' => self::armar($db, $orden)]);
    }

    public static function estado($db, Request $req, $mailer = null)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $hacia = $req->input('estado');

        if (!is_string($hacia) || !isset(EstadosDePedidos::ESTADOS[$hacia]) || $hacia === 'ingresado') {
            return Response::error(400, 'El estado tiene que ser con_fecha, enviado, entregado o cancelado');
        }

        list($datos, $problema) = EstadosDePedidos::datos($hacia, [
            'fecha'       => $req->input('fecha'),
            'franja'      => $req->input('franja'),
            'empresa'     => $req->input('empresa'),
            'seguimiento' => $req->input('seguimiento'),
            'motivo'      => $req->input('motivo'),
        ]);

        if ($problema !== null) {
            return Response::error(400, $problema);
        }

        list($orden, $error) = self::pedidoAdministrable($db, $req->input('codigo'), $req->userId());

        if ($error !== null) {
            return $error;
        }

        $cambio = EstadosDePedidos::cambiar($db, (int) $orden['id'], $hacia, $datos, $req->userId());

        if (!$cambio['ok']) {
            return Response::error($cambio['status'], $cambio['error']);
        }

        // Con el estado nuevo, para el mail y para la pantalla.
        $orden = OrdenesDeProductos::orden($db, $orden['codigo']);
        $aviso = CorreoProductos::avisarEstado($orden, $hacia, $cambio['desde'] === 'con_fecha' && $hacia === 'con_fecha', $mailer);

        return Response::ok([
            'pedido'         => self::armar($db, $orden),
            'aviso_enviado'  => $aviso['enviado'],
            'aviso_error'    => $aviso['enviado'] ? null : $aviso['motivo'],
            'stock_devuelto' => $cambio['stock_devuelto'],
        ]);
    }

    /**
     * La orden de ese código si es un pedido de un físico de una página que
     * administra quien pide. Lo que no existe, lo que no es un físico y lo
     * ajeno dan lo mismo hacia afuera salvo por el código: 404 o 403.
     *
     * @return array [orden, null] o [null, Response]
     */
    private static function pedidoAdministrable($db, $codigo, $userId)
    {
        $codigo = is_string($codigo) ? trim($codigo) : '';

        if ($codigo === '') {
            return [null, Response::error(400, 'codigo requerido')];
        }

        $orden = OrdenesDeProductos::orden($db, $codigo);

        if ($orden === null || !ProductosFisicos::esFisico($orden)) {
            return [null, Response::notFound('No encontramos ese pedido')];
        }

        // Por la página del producto, como el reenvío: el link puede estar borrado.
        if (empty($orden['pagina_id']) || !PageAccess::canManage($db, (int) $orden['pagina_id'], $userId)) {
            return [null, Response::error(403, 'No podés gestionar pedidos de esta página')];
        }

        return [$orden, null];
    }

    /** Todo lo del pedido que necesita quien vende para entregarlo. */
    private static function armar($db, array $orden)
    {
        $envio = PedidosFisicos::envioDe($db, $orden['id']);
        $estadoPedido = empty($orden['estado_pedido']) ? null : $orden['estado_pedido'];

        return [
            'codigo'            => $orden['codigo'],
            'creado_en'         => $orden['creado_en'],
            'pagada_en'         => $orden['pagada_en'],
            'estado'            => OrdenesDeProductos::estaVencida($orden) ? 'vencida' : $orden['estado'],
            'estado_pedido'     => $estadoPedido,
            'sin_stock'         => !empty($orden['sin_stock']),
            'nombre'            => $orden['nombre'],
            'email'             => $orden['email'],
            'telefono'          => $orden['telefono'],
            'producto'          => $orden['producto'],
            'opciones'          => $orden['opciones_texto'],
            'cantidad'          => (int) $orden['cantidad'],
            'precio_unitario'   => round((float) $orden['precio_unitario'], 2),
            'costo_envio'       => round((float) $orden['costo_envio'], 2),
            'total'             => round((float) $orden['total'], 2),
            'moneda'            => 'ARS',
            'entrega_fecha'     => $orden['entrega_fecha'],
            'entrega_franja'    => $orden['entrega_franja'],
            'envio_empresa'     => $orden['envio_empresa'],
            'envio_seguimiento' => $orden['envio_seguimiento'],
            'cancelado_motivo'  => $orden['cancelado_motivo'],
            // Para el link a la operación cuando se cancela: la devolución se
            // hace desde Mercado Pago, no desde acá.
            'mp_payment_id'     => $orden['mp_payment_id'],
            'envio'             => $envio === null ? null : [
                'calle'         => $envio['calle'],
                'piso_depto'    => $envio['piso_depto'],
                'localidad'     => $envio['localidad'],
                'provincia'     => $envio['provincia'],
                'codigo_postal' => $envio['codigo_postal'],
                'indicaciones'  => $envio['indicaciones'],
            ],
            'horarios'          => $envio === null ? null : [
                'dias'       => $envio['dias'],
                'franjas'    => $envio['franjas'],
                'comentario' => $envio['comentario'],
            ],
            'historial'         => $estadoPedido === null ? [] : EstadosDePedidos::historial($db, $orden['id']),
            'siguientes'        => $estadoPedido === null || $orden['estado'] !== 'pagada'
                ? []
                : EstadosDePedidos::siguientes($estadoPedido),
        ];
    }
}
