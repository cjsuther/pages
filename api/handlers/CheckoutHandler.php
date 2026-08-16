<?php

/**
 * Parte pública del módulo de entradas: comprar, recibir el aviso de pago y
 * consultar una orden. Nada de esto requiere sesión: los compradores no son
 * usuarios de la plataforma.
 */
class CheckoutHandler
{
    // ---------------------------------------------------------------- comprar

    /**
     * Crea la orden y, si hay que cobrar, devuelve a dónde mandar al comprador.
     *
     * El cupo se toma acá, antes de ir a Mercado Pago: si se tomara al volver,
     * el evento se sobrevendería con la gente ya pagada.
     */
    public static function comprar($db, Request $req, $http = null)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $linkId = (int) $req->input('link_id');

        if (!$linkId) {
            return Response::error(400, 'link_id requerido');
        }

        $evento = self::evento($db, $linkId);

        if ($evento === null) {
            return Response::notFound('El evento no existe');
        }

        $resultado = Entradas::crearOrden($db, $linkId, [
            'nombre'   => $req->input('nombre'),
            'email'    => $req->input('email'),
            'telefono' => $req->input('telefono'),
            'cantidad' => $req->input('cantidad'),
        ]);

        if (!$resultado['ok']) {
            return Response::error(400, $resultado['error']);
        }

        $orden = $resultado['orden'];

        // Reserva sin cobro: ya quedó confirmada, no hay nada que pagar.
        if ($orden['es_gratis']) {
            return Response::created([
                'success' => true,
                'codigo'  => $orden['codigo'],
                'estado'  => 'pagada',
                'url'     => null,
            ]);
        }

        $token = Cobros::tokenDelEvento($db, $linkId);

        if ($token === null) {
            self::cancelar($db, $orden['codigo']);

            return Response::error(503, 'Este evento no puede cobrar en este momento. Probá más tarde.');
        }

        $preferencia = (new MercadoPago($token, $http))->crearPreferencia([
            'titulo'     => $evento['text'],
            'cantidad'   => $orden['cantidad'],
            'precio'     => $orden['precio'],
            'moneda'     => $orden['moneda'],
            'referencia' => $orden['codigo'],
            'urlRetorno' => self::urlDeRetorno($orden['codigo']),
            'urlAviso'   => self::urlDeAviso($orden['codigo']),
            'comprador'  => [
                'nombre'   => $req->input('nombre'),
                'email'    => $req->input('email'),
                'telefono' => $req->input('telefono'),
            ],
        ]);

        if (!$preferencia['ok']) {
            // Sin link de pago la orden no sirve, y dejarla reservada retendría
            // cupo 15 minutos por una compra que nunca pudo empezar.
            self::cancelar($db, $orden['codigo']);

            return Response::error(502, 'No se pudo iniciar el pago: ' . $preferencia['error']);
        }

        Entradas::guardarPreferencia($db, $orden['id'], $preferencia['id']);

        return Response::created([
            'success' => true,
            'codigo'  => $orden['codigo'],
            'estado'  => 'reservada',
            'url'     => $preferencia['url'],
            'total'   => $orden['total'],
        ]);
    }

    // ----------------------------------------------------------------- aviso

    /**
     * Aviso de pago de Mercado Pago.
     *
     * El aviso lo puede mandar cualquiera: sólo trae un id de pago. Por eso no
     * se cree nada de lo que viene en el cuerpo — se le pregunta el estado a la
     * API de Mercado Pago con la credencial del dueño, y recién eso se acredita.
     *
     * Mercado Pago reintenta hasta recibir un 2xx, así que se responde 200
     * incluso cuando el aviso no aplica: un 500 haría que reintente para
     * siempre un aviso que nunca va a poder procesarse.
     */
    public static function aviso($db, Request $req, $http = null)
    {
        if ($req->method !== 'POST' && $req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $codigo = $req->param('orden');
        $pagoId = self::idDePagoDelAviso($req);

        if (!$codigo || !$pagoId) {
            return Response::ok(['recibido' => true, 'motivo' => 'aviso sin datos utilizables']);
        }

        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return Response::ok(['recibido' => true, 'motivo' => 'orden inexistente']);
        }

        $token = Cobros::tokenDelEvento($db, $orden['link_id']);

        if ($token === null) {
            return Response::ok(['recibido' => true, 'motivo' => 'la página no tiene credenciales']);
        }

        $pago = (new MercadoPago($token, $http))->consultarPago($pagoId);

        if (!$pago['ok']) {
            // Acá sí conviene que reintente: el pago existe, no pudimos leerlo.
            return Response::error(503, 'No se pudo consultar el pago');
        }

        // La referencia la puso el servidor al crear la preferencia. Si no
        // coincide, el aviso es de otra orden y acreditarlo sería un error.
        if ($pago['referencia'] !== null && $pago['referencia'] !== $codigo) {
            return Response::ok(['recibido' => true, 'motivo' => 'el pago es de otra orden']);
        }

        // El monto lo dice Mercado Pago, no el navegador: si no coincide con lo
        // que la orden debía, algo se manipuló y no se acredita.
        if ($pago['monto'] !== null && abs($pago['monto'] - (float) $orden['total']) > 0.01) {
            return Response::ok(['recibido' => true, 'motivo' => 'el monto no coincide con la orden']);
        }

        $resultado = Entradas::acreditarPago($db, $codigo, $pagoId, $pago['estado']);

        return Response::ok(['recibido' => true, 'motivo' => $resultado['motivo']]);
    }

    // ----------------------------------------------------------------- orden

    /** Estado de una orden, para la pantalla a la que vuelve el comprador. */
    public static function orden($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $codigo = $req->param('codigo');

        if (!$codigo) {
            return Response::error(400, 'codigo requerido');
        }

        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return Response::notFound('No encontramos esa orden');
        }

        $vencida = $orden['estado'] === 'reservada'
            && $orden['reserva_vence_en'] !== null
            && strtotime($orden['reserva_vence_en']) <= time();

        // Sólo lo que le sirve al comprador. El teléfono y el email quedan
        // fuera: cualquiera con el código vería datos personales.
        return Response::ok(['orden' => [
            'codigo'        => $orden['codigo'],
            'estado'        => $vencida ? 'vencida' : $orden['estado'],
            'cantidad'      => (int) $orden['cantidad'],
            'total'         => (float) $orden['total'],
            'moneda'        => $orden['moneda'],
            'nombre'        => $orden['nombre'],
            'evento'        => $orden['evento'],
            'event_date'    => $orden['event_date'],
            'event_time'    => $orden['event_time'],
            'event_address' => $orden['event_address'],
            'pagina'        => $orden['pagina'],
            'url_slug'      => $orden['url_slug'],
        ]]);
    }

    // -------------------------------------------------------------- internos

    /**
     * El id del pago llega en distintos lugares según el tipo de aviso: los
     * webhooks lo mandan en data.id y las IPN viejas en query.id.
     */
    private static function idDePagoDelAviso(Request $req)
    {
        if (isset($req->body['data']['id'])) {
            return (string) $req->body['data']['id'];
        }

        if ($req->param('data.id')) {
            return (string) $req->param('data.id');
        }

        $tipo = $req->param('topic', $req->param('type'));

        if ($tipo === 'payment' && $req->param('id')) {
            return (string) $req->param('id');
        }

        return null;
    }

    private static function evento($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.text
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ? AND lg.type = "eventos"
        ');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    private static function cancelar($db, $codigo)
    {
        $stmt = $db->prepare("UPDATE ticket_orders SET estado = 'cancelada' WHERE codigo = ? AND estado = 'reservada'");
        $stmt->execute([$codigo]);
    }

    private static function urlDeRetorno($codigo)
    {
        return rtrim(FRONTEND_URL, '/') . '/entrada/' . $codigo;
    }

    private static function urlDeAviso($codigo)
    {
        return rtrim(UPLOAD_URL, '/') . '/api/public/aviso-pago.php?orden=' . $codigo;
    }
}
