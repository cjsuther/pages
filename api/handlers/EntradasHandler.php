<?php

/**
 * Parte privada del módulo de entradas: lo que ve y toca el dueño de la página.
 *
 * Tres endpoints:
 *   - credenciales: alta y baja de las credenciales de Mercado Pago
 *   - config:       capacidad y precio de cada evento
 *   - ventas:       listado de órdenes de un evento
 */
class EntradasHandler
{
    // ----------------------------------------------------------- credenciales

    public static function credenciales($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés administrar esta página');
        }

        if ($req->method === 'GET') {
            return Response::ok(['cobros' => Cobros::estado($db, $pageId)]);
        }

        if ($req->method === 'POST' || $req->method === 'PUT') {
            $resultado = Cobros::guardar(
                $db,
                $pageId,
                $req->input('access_token'),
                $req->input('public_key')
            );

            if (!$resultado['ok']) {
                return Response::error(400, $resultado['error']);
            }

            return Response::ok([
                'success' => true,
                'cuenta'  => $resultado['cuenta'],
                'cobros'  => Cobros::estado($db, $pageId),
            ]);
        }

        if ($req->method === 'DELETE') {
            // Los eventos que ya venden quedarían con checkout roto: mejor
            // avisar que dejar que falle recién cuando alguien intente pagar.
            $enVenta = self::eventosPagosEnVenta($db, $pageId);

            if ($enVenta > 0 && !$req->input('confirmar')) {
                return Response::error(409, "Hay $enVenta evento(s) cobrando entradas con esta credencial. "
                    . 'Volvé a enviar con confirmar=true si querés desconectarla igual.');
            }

            Cobros::borrar($db, $pageId);

            return Response::ok(['success' => true, 'cobros' => Cobros::estado($db, $pageId)]);
        }

        return Response::methodNotAllowed();
    }

    // ------------------------------------------------------------------ config

    /** Configuración de venta de un evento. */
    public static function config($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        $linkId = (int) $req->param('link_id');

        if (!$linkId) {
            return Response::error(400, 'link_id requerido');
        }

        if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
            return Response::error(403, 'No podés administrar este evento');
        }

        if ($req->method === 'GET') {
            return Response::ok([
                'entradas' => Entradas::configDelEvento($db, $linkId),
                'ocupadas' => Entradas::ocupadas($db, $linkId),
                'cobros'   => Cobros::estado($db, self::pageIdDelLink($db, $linkId)),
            ]);
        }

        if ($req->method === 'POST' || $req->method === 'PUT') {
            $precio = (float) $req->input('precio', 0);

            // Cobrar sin credencial cargada deja el checkout roto para el
            // comprador. Una reserva sin precio no necesita Mercado Pago.
            if ($precio > 0 && !Cobros::estaConfigurado($db, self::pageIdDelLink($db, $linkId))) {
                return Response::error(400,
                    'Para cobrar entradas primero tenés que conectar Mercado Pago en la sección Entradas de la página');
            }

            $resultado = Entradas::guardarConfig($db, $linkId, [
                'activo'         => $req->input('activo', 1),
                'capacidad'      => $req->input('capacidad'),
                'precio'         => $precio,
                'moneda'         => $req->input('moneda', 'ARS'),
                'max_por_compra' => $req->input('max_por_compra', 10),
            ]);

            if (!$resultado['ok']) {
                return Response::error(400, $resultado['error']);
            }

            return Response::ok([
                'success'  => true,
                'entradas' => Entradas::configDelEvento($db, $linkId),
            ]);
        }

        if ($req->method === 'DELETE') {
            $ocupadas = Entradas::ocupadas($db, $linkId);

            if ($ocupadas > 0 && !$req->input('confirmar')) {
                return Response::error(409, "Ya hay $ocupadas entrada(s) vendidas o reservadas. "
                    . 'Volvé a enviar con confirmar=true si querés desactivar la venta igual.');
            }

            Entradas::borrarConfig($db, $linkId);

            return Response::ok(['success' => true]);
        }

        return Response::methodNotAllowed();
    }

    // ------------------------------------------------------------------ ventas

    public static function ventas($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $linkId = (int) $req->param('link_id');

        if (!$linkId) {
            return Response::error(400, 'link_id requerido');
        }

        // Los datos de contacto de los compradores son de terceros: sólo los ve
        // quien administra la página.
        if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
            return Response::error(403, 'No podés ver las ventas de este evento');
        }

        $ventas = Entradas::ventasDelEvento($db, $linkId);
        $config = Entradas::configDelEvento($db, $linkId);

        $ventas['capacidad'] = $config === null ? 0 : (int) $config['capacidad'];

        if ($req->param('formato') === 'csv') {
            return self::csv($ventas['ordenes']);
        }

        return Response::ok($ventas);
    }

    // --------------------------------------------------------------- internos

    private static function csv(array $ordenes)
    {
        $filas = ['Codigo,Nombre,Email,Telefono,Cantidad,Total,Moneda,Estado,Fecha'];

        foreach ($ordenes as $o) {
            $filas[] = implode(',', array_map(['EntradasHandler', 'campoCsv'], [
                $o['codigo'], $o['nombre'], $o['email'], $o['telefono'],
                $o['cantidad'], $o['total'], $o['moneda'], $o['estado'], $o['created_at'],
            ]));
        }

        return Response::raw(200, implode("\n", $filas), [
            'Content-Type: text/csv; charset=utf-8',
            'Content-Disposition: attachment; filename="ventas.csv"',
        ]);
    }

    /**
     * Un nombre con coma partiría la fila en dos columnas, y uno que empieza
     * con = lo interpreta Excel como fórmula.
     */
    public static function campoCsv($valor)
    {
        $valor = (string) $valor;

        if (preg_match('/^[=+\-@]/', $valor)) {
            $valor = "'" . $valor;
        }

        return '"' . str_replace('"', '""', $valor) . '"';
    }

    private static function pageIdDelLink($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT lg.page_id
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ?
        ');
        $stmt->execute([(int) $linkId]);

        return (int) $stmt->fetchColumn();
    }

    /** Eventos de la página que cobran entradas (precio > 0) y están activos. */
    private static function eventosPagosEnVenta($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT COUNT(*)
            FROM event_ticketing et
            INNER JOIN links l ON l.id = et.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE lg.page_id = ? AND et.activo = 1 AND et.precio > 0
        ');
        $stmt->execute([(int) $pageId]);

        return (int) $stmt->fetchColumn();
    }
}
