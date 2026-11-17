<?php

/**
 * Invitados sin costo de un evento: la lista, el alta, el límite, la
 * anulación y el reenvío de la entrada.
 *
 *   - invitados.php  GET  ?link_id=      la lista y cuánto lugar queda
 *                    POST {link_id, nombre, email?, telefono?, cantidad?, tipo?, lugares?}
 *                    PUT  {link_id, max_invitados}   (null = sin límite)
 *   - invitado.php   POST {codigo, accion: 'anular' | 'reenviar'}
 *
 * Permisos: sólo el dueño o un administrador aceptado de la página que
 * organiza el evento. Ni el acceso de soporte de la plataforma ni las
 * páginas colaboradoras: dar entradas gratis es una decisión de quien
 * organiza, consume su capacidad y no deja rastro de cobro.
 */
class InvitadosHandler
{
    public static function invitados($db, Request $req, $mailer = null)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if (!in_array($req->method, ['GET', 'POST', 'PUT'], true)) {
            return Response::methodNotAllowed();
        }

        $linkId = (int) ($req->method === 'GET' ? $req->param('link_id') : $req->input('link_id', $req->param('link_id')));

        if (!$linkId) {
            return Response::error(400, 'link_id requerido');
        }

        if (!self::puedeInvitar($db, $linkId, $req->userId())) {
            return Response::error(403, 'Sólo quien administra la página del evento puede manejar sus invitados');
        }

        if ($req->method === 'GET') {
            return Response::ok(Invitados::lista($db, $linkId));
        }

        if ($req->method === 'PUT') {
            if (!array_key_exists('max_invitados', $req->body)) {
                return Response::error(400, 'max_invitados requerido');
            }

            $crudo = $req->body['max_invitados'];

            if ($crudo !== null && $crudo !== '' && (!is_numeric($crudo) || (int) $crudo != $crudo)) {
                return Response::error(400, 'El límite de invitados tiene que ser un número entero');
            }

            $r = Invitados::guardarLimite($db, $linkId, $crudo === null || $crudo === '' ? null : (int) $crudo);

            if (!$r['ok']) {
                return Response::error($r['status'], $r['error']);
            }

            return Response::ok(Invitados::lista($db, $linkId));
        }

        $datos = [];

        foreach (['nombre', 'email', 'telefono', 'cantidad', 'tipo', 'lugares'] as $campo) {
            if (array_key_exists($campo, $req->body)) {
                $datos[$campo] = $req->body[$campo];
            }
        }

        foreach (['nombre', 'email', 'telefono', 'tipo'] as $texto) {
            if (isset($datos[$texto]) && !is_string($datos[$texto])) {
                return Response::error(400, "El campo $texto no tiene el formato esperado");
            }
        }

        $r = Invitados::agregar($db, $linkId, $datos, $req->userId());

        if (!$r['ok']) {
            $cuerpo = ['error' => $r['error']];

            // Como en la venta: con los lugares tomados la pantalla actualiza
            // el plano sin tener que recargar.
            if (isset($r['ocupados'])) {
                $cuerpo['ocupados'] = $r['ocupados'];
            }

            return Response::json($r['status'], $cuerpo);
        }

        // Después del commit: si el mail falla, el invitado queda igual y la
        // entrada se puede pasar por link o reenviar.
        $mail = ['enviado' => false, 'motivo' => 'sin email'];

        if ($r['orden']['email'] !== '') {
            try {
                $mail = CorreoEntradas::enviar($db, $r['orden']['codigo'], $mailer);
            } catch (Throwable $e) {
                $mail = ['enviado' => false, 'motivo' => 'no se pudo enviar el mail'];
            }
        }

        return Response::json(201, [
            'invitado' => $r['orden'] + ['url' => CodigoQR::urlDeLaOrden($r['orden']['codigo'])],
            'mail' => $mail,
        ] + Invitados::lista($db, $linkId));
    }

    public static function invitado($db, Request $req, $mailer = null)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $codigo = strtoupper(trim((string) $req->input('codigo')));

        if (!preg_match('/^[A-F0-9]{12}$/', $codigo)) {
            return Response::error(400, 'codigo requerido');
        }

        $accion = (string) $req->input('accion');

        if (!in_array($accion, ['anular', 'reenviar'], true)) {
            return Response::error(400, 'accion tiene que ser anular o reenviar');
        }

        $orden = Invitados::delEvento($db, $codigo);

        // Igual para una compra que para un código que no existe: por acá no
        // se puede averiguar ni tocar nada que no sea un invitado.
        if ($orden === null) {
            return Response::error(404, 'No encontramos ese invitado');
        }

        $linkId = (int) $orden['link_id'];

        if (!self::puedeInvitar($db, $linkId, $req->userId())) {
            return Response::error(403, 'Sólo quien administra la página del evento puede manejar sus invitados');
        }

        if ($accion === 'anular') {
            $r = Invitados::anular($db, $codigo);

            if (!$r['ok']) {
                return Response::error($r['status'], $r['error']);
            }

            return Response::ok(['anulado' => true] + Invitados::lista($db, $linkId));
        }

        if ($orden['estado'] !== 'pagada') {
            return Response::error(409, 'El invitado está anulado: su entrada ya no vale');
        }

        if (trim((string) $orden['email']) === '') {
            return Response::error(409, 'Este invitado no tiene email: pasale el link de su entrada');
        }

        try {
            $mail = CorreoEntradas::reenviar($db, $codigo, $mailer);
        } catch (Throwable $e) {
            $mail = ['enviado' => false, 'motivo' => 'no se pudo enviar el mail'];
        }

        if (!$mail['enviado']) {
            return Response::json(502, ['error' => 'No pudimos mandar el mail: ' . $mail['motivo'], 'mail' => $mail]);
        }

        return Response::ok(['mail' => $mail] + Invitados::lista($db, $linkId));
    }

    /**
     * Dueño o administrador aceptado de la página que organiza el evento.
     *
     * Es canManageLink sin el acceso de plataforma: la página dueña del
     * evento es la del grupo donde está cargado, así que una página
     * colaboradora —que lo muestra pero no lo organiza— no entra.
     */
    public static function puedeInvitar($db, $linkId, $userId)
    {
        $stmt = $db->prepare('
            SELECT lg.page_id
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ?
        ');
        $stmt->execute([(int) $linkId]);
        $pageId = $stmt->fetchColumn();

        if ($pageId === false || $pageId === null) {
            return false;
        }

        return PageAccess::isOwnerOrAdmin($db, (int) $pageId, (int) $userId);
    }
}
