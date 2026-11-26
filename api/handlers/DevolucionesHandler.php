<?php

/**
 * Devoluciones de dinero a quienes compraron entradas (api/entradas/devolucion.php).
 *
 *   GET  ?codigo=   lo que necesita la pantalla: desglose, lo que queda por
 *                   devolver, las entradas y los intentos anteriores.
 *   POST            pide la devolución (o la simula con simular=true).
 *
 * Sólo quien administra la página (o un superadmin): es la cuenta de Mercado
 * Pago del organizador la que devuelve. Quien compró tiene el código de su
 * orden, y con eso cualquiera podría devolverse plata ajena.
 *
 * La lógica, los topes y los riesgos están en lib/Devoluciones.php.
 */
class DevolucionesHandler
{
    public static function devolucion($db, Request $req, $http = null, $mailer = null)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if (!in_array($req->method, ['GET', 'POST'], true)) {
            return Response::methodNotAllowed();
        }

        $codigo = trim((string) ($req->method === 'GET' ? $req->param('codigo') : $req->input('codigo')));

        if ($codigo === '' || !preg_match('/^[A-Z0-9]{1,12}$/', $codigo)) {
            return Response::error(400, 'codigo requerido');
        }

        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return Response::error(404, 'No encontramos esa compra');
        }

        if (!PageAccess::canManageLink($db, (int) $orden['link_id'], $req->userId())) {
            return Response::error(403, 'No podés devolver compras de este evento');
        }

        if ($req->method === 'GET') {
            if (!Devoluciones::habilitadas()) {
                return Response::error(403, Devoluciones::MOTIVOS['apagadas']);
            }

            $r = Devoluciones::preparar($db, $codigo, $http);

            return $r['ok'] ? Response::ok($r['datos']) : Response::error($r['status'], $r['error']);
        }

        $pedido = [];

        foreach (['modo', 'cantidad', 'tipos', 'lugares', 'confirmacion', 'simular'] as $campo) {
            if (array_key_exists($campo, $req->body)) {
                $pedido[$campo] = $req->body[$campo];
            }
        }

        $r = Devoluciones::devolver($db, $codigo, $pedido, $req->userId(), $http, $mailer);
        $status = $r['status'];
        unset($r['status']);

        if (!$r['ok']) {
            unset($r['ok']);
            // Con lo que pasó, la pantalla muestra el intento rechazado en el
            // historial sin tener que volver a pedirlo.
            $r['intentos'] = Devoluciones::intentos($db, (int) $orden['id']);

            return Response::json($status, $r);
        }

        if (empty($r['simulada'])) {
            $r['ventas'] = Entradas::ventasDelEvento($db, (int) $orden['link_id']);
            $r['intentos'] = Devoluciones::intentos($db, (int) $orden['id']);
        }

        return Response::json($status, $r);
    }
}
