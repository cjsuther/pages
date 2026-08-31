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

    /**
     * Estado del cobro de una página y desconexión.
     *
     * El alta ya no admite pegar credenciales: se hace por OAuth, que es lo
     * único que permite descontar la comisión de la plataforma.
     */
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
            return Response::ok([
                'cobros'      => Cobros::estado($db, $pageId),
                'comision'    => Comision::porcentaje(),
                // Lo que cobra Mercado Pago va con lo nuestro: el dueño hace
                // la cuenta con los dos descuentos o no le cierra.
                'mercadopago' => Comision::mercadoPago(),
                // Sin la aplicación de marketplace configurada no hay forma de
                // conectar ninguna cuenta, y conviene decirlo en vez de mostrar
                // un botón que lleva a un error de Mercado Pago.
                'disponible'  => MercadoPagoOAuth::configurado(),
            ]);
        }

        if ($req->method === 'DELETE') {
            // Los eventos que ya venden quedarían con checkout roto: mejor
            // avisar que dejar que falle recién cuando alguien intente pagar.
            $enVenta = self::eventosPagosEnVenta($db, $pageId);

            if ($enVenta > 0 && !$req->input('confirmar')) {
                return Response::error(409, "Hay $enVenta evento(s) cobrando entradas con esta cuenta. "
                    . 'Volvé a enviar con confirmar=true si querés desconectarla igual.');
            }

            Cobros::borrar($db, $pageId);

            return Response::ok(['success' => true, 'cobros' => Cobros::estado($db, $pageId)]);
        }

        return Response::methodNotAllowed();
    }

    // ------------------------------------------------------------------ OAuth

    /** Devuelve a dónde mandar al dueño para que autorice su cuenta. */
    public static function conectar($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST' && $req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés administrar esta página');
        }

        if (!MercadoPagoOAuth::configurado()) {
            return Response::error(503, 'La plataforma todavía no tiene configurada su aplicación de Mercado Pago');
        }

        $estado = MercadoPagoOAuth::firmarEstado($pageId, $req->userId());

        return Response::ok(['url' => MercadoPagoOAuth::urlDeAutorizacion($estado)]);
    }

    /**
     * Vuelta desde Mercado Pago con el código de autorización.
     *
     * No lleva sesión: el navegador viene redirigido desde Mercado Pago. Quién
     * autorizó y para qué página lo dice el `state` firmado, que es lo que
     * impide que alguien conecte una cuenta a una página ajena.
     */
    public static function oauthCallback($db, Request $req, $http = null)
    {
        $estado = MercadoPagoOAuth::leerEstado($req->param('state'));

        if ($estado === null) {
            return self::volverAlEditor(null, 'estado_invalido');
        }

        if ($req->param('error')) {
            // El dueño canceló en la pantalla de Mercado Pago.
            return self::volverAlEditor($estado['page_id'], 'cancelado');
        }

        $codigo = $req->param('code');

        if (!$codigo) {
            return self::volverAlEditor($estado['page_id'], 'sin_codigo');
        }

        // Se vuelve a comprobar el permiso: entre que se firmó el estado y la
        // vuelta pudieron sacarle el acceso a la página.
        if (!PageAccess::canManage($db, $estado['page_id'], $estado['user_id'])) {
            return self::volverAlEditor($estado['page_id'], 'sin_permiso');
        }

        $canje = (new MercadoPagoOAuth($http))->canjearCodigo($codigo);

        if (!$canje['ok']) {
            return self::volverAlEditor($estado['page_id'], 'fallo_mercadopago');
        }

        $guardado = Cobros::guardarDesdeOAuth($db, $estado['page_id'], $canje['credenciales']);

        if (!$guardado['ok']) {
            return self::volverAlEditor($estado['page_id'], 'no_se_pudo_guardar');
        }

        return self::volverAlEditor($estado['page_id'], null);
    }

    private static function volverAlEditor($pageId, $error)
    {
        $destino = rtrim(FRONTEND_URL, '/') . '/page/' . (int) $pageId . '?seccion=entradas';

        return Response::redirect($destino . ($error === null ? '&conectado=1' : '&error=' . $error));
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
                // El dueño define el precio acá: necesita ver qué se le
                // descuenta en la misma pantalla, no en otra sección. Y los
                // dos descuentos, no sólo el nuestro.
                'comision' => Comision::porcentaje(),
                'mercadopago' => Comision::mercadoPago(),
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

    // -------------------------------------------------------------- cancelar

    /**
     * Cancela una compra y le devuelve los lugares al evento.
     *
     * La cancela quien administra la página, no quien compró: es el que sabe
     * si la persona avisó que no va o si el pago nunca entró. El comprador
     * tiene el código de su orden, y con eso cualquiera que lo viera podría
     * cancelar entradas ajenas.
     */
    public static function cancelar($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $codigo = trim((string) $req->input('codigo'));

        if ($codigo === '') {
            return Response::error(400, 'codigo requerido');
        }

        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return Response::error(404, 'No encontramos esa compra');
        }

        if (!PageAccess::canManageLink($db, (int) $orden['link_id'], $req->userId())) {
            return Response::error(403, 'No podés cancelar compras de este evento');
        }

        $resultado = Entradas::cancelar($db, $codigo);

        if (!$resultado['cancelada']) {
            return Response::error(409, $resultado['motivo']);
        }

        return Response::ok([
            'cancelada' => true,
            'mensaje' => $resultado['motivo'],
            'ventas' => Entradas::ventasDelEvento($db, (int) $orden['link_id']),
        ]);
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
