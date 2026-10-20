<?php

/**
 * Parte pública de los productos digitales: comprar, recibir el aviso de pago,
 * consultar una orden y descargar. Nada de esto requiere sesión.
 *
 * El cobro es el de las entradas: las mismas credenciales (Cobros), la misma
 * comisión (Comision) y la misma preferencia (MercadoPago). Lo que no se
 * comparte es la orden: vive en product_orders.
 */
class CompraProductoHandler
{
    const MENSAJE_LINK_INVALIDO = 'Este link de descarga no es válido.';

    /** Los largos de las columnas de product_orders. */
    const LARGOS = ['nombre' => 150, 'email' => 255, 'telefono' => 50];

    // ---------------------------------------------------------------- comprar

    /**
     * Crea la orden. Si el producto es pago devuelve a dónde mandar al
     * comprador, y el token para que la pantalla de vuelta pueda ofrecer la
     * descarga sin ponerlo en ninguna URL.
     *
     * Un producto gratis no pasa por Mercado Pago: queda pagado y el link sale
     * por mail. No se devuelve el token: lo que se pide a cambio es el email, y
     * entregarlo en pantalla haría que cualquier dirección inventada sirviera.
     */
    public static function comprar($db, Request $req, $http = null, $mailer = null)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $linkId = (int) $req->input('link_id');

        if (!$linkId) {
            return Response::error(400, 'link_id requerido');
        }

        $comprador = [
            'nombre'   => self::texto($req->input('nombre')),
            'email'    => self::texto($req->input('email')),
            'telefono' => self::texto($req->input('telefono')),
        ];

        // Antes que nada: un campo más largo que su columna sería un 500.
        $problema = self::problemaDeLargo($comprador);

        if ($problema !== null) {
            return Response::error(400, $problema);
        }

        $producto = OrdenesDeProductos::productoEnVenta($db, $linkId);

        if ($producto === null) {
            return ProductosDigitales::fueBorrado($db, $linkId)
                ? Response::error(409, ProductosDigitales::MENSAJE_NO_DISPONIBLE)
                : Response::notFound('El producto no existe');
        }

        // Oculto: no acepta pedidos nuevos. Lo ya pedido sigue su curso.
        if (!ProductosDigitales::aceptaPedidos($producto)) {
            return Response::error(409, ProductosDigitales::MENSAJE_NO_DISPONIBLE);
        }

        // Lo mismo que se le pide a quien compra una entrada.
        $problema = Entradas::validarComprador($comprador + ['cantidad' => 1]);

        if ($problema !== null) {
            return Response::error(400, $problema);
        }

        $productoId = (int) $producto['producto_id'];
        $total = round((float) $producto['precio'], 2);

        if ($total <= 0) {
            return self::pedirGratis($db, $productoId, $comprador, $req->ip(), $mailer);
        }

        $tokenMp = Cobros::tokenDelEvento($db, $linkId, $http);

        if ($tokenMp === null) {
            return Response::error(503, 'Este producto no se puede cobrar en este momento. Probá más tarde.');
        }

        $orden = OrdenesDeProductos::crear($db, $productoId, $comprador, $total, $req->ip());

        // La misma regla que en las entradas: la comisión sólo se manda si la
        // página conectó por OAuth, porque con una credencial pegada a mano
        // Mercado Pago la ignora en silencio.
        $comision = Cobros::eventoAdmiteSplit($db, $linkId) ? Comision::sobre($total) : 0.0;

        $preferencia = (new MercadoPago($tokenMp, $http))->crearPreferencia([
            'titulo'     => $producto['text'],
            'cantidad'   => 1,
            'precio'     => $total,
            'moneda'     => 'ARS',
            'referencia' => $orden['codigo'],
            'urlRetorno' => self::urlDeRetorno($orden['codigo']),
            'urlAviso'   => self::urlDeAviso($orden['codigo']),
            'comision'   => $comision,
            'comprador'  => [
                'nombre'   => $comprador['nombre'],
                'email'    => $comprador['email'],
                'telefono' => $comprador['telefono'],
            ],
        ]);

        if (!$preferencia['ok']) {
            OrdenesDeProductos::cancelar($db, $orden['codigo']);

            return Response::error(502, 'No se pudo iniciar el pago: ' . $preferencia['error']);
        }

        OrdenesDeProductos::guardarPreferencia($db, $orden['id'], $preferencia['id']);
        OrdenesDeProductos::guardarComision($db, $orden['id'], $comision, $comision > 0 ? Comision::porcentaje() : 0);

        return Response::created([
            'success' => true,
            'codigo'  => $orden['codigo'],
            'estado'  => 'pendiente',
            'url'     => $preferencia['url'],
            'total'   => $total,
            'token'   => $orden['token'],
        ]);
    }

    /**
     * Un producto gratis: la orden nace pagada y sale el mail.
     *
     * Si ese email ya lo pidió no se crea otra: se le reenvía el link que ya
     * tiene. La respuesta es la misma en los dos casos, para que el formulario
     * no sirva para averiguar qué emails pidieron qué.
     *
     * Una orden nueva cuenta para el límite por hora, por email y por IP. El
     * reenvío no crea orden y tiene su propio mínimo entre envíos.
     */
    private static function pedirGratis($db, $productoId, array $comprador, $ip, $mailer)
    {
        $codigo = OrdenesDeProductos::gratisDelEmail($db, $productoId, $comprador['email']);
        $reenviar = $codigo !== null;

        if (!$reenviar) {
            if (OrdenesDeProductos::superaLimiteGratis($db, $comprador['email'], $ip)) {
                return Response::error(429, OrdenesDeProductos::MENSAJE_DEMASIADOS_PEDIDOS);
            }

            $codigo = OrdenesDeProductos::crear($db, $productoId, $comprador, 0, $ip)['codigo'];
        }

        self::mandarLink($db, $codigo, $mailer, $reenviar);

        return Response::created([
            'success' => true,
            'codigo'  => $codigo,
            'estado'  => 'pagada',
            'url'     => null,
        ]);
    }

    // ----------------------------------------------------------------- aviso

    /**
     * Aviso de pago de Mercado Pago. Las mismas reglas que CheckoutHandler::aviso:
     * no se cree nada del cuerpo, se le pregunta el pago a la API con la
     * credencial del dueño, y se responde 200 aunque el aviso no aplique para
     * que Mercado Pago no lo reintente para siempre.
     */
    public static function aviso($db, Request $req, $http = null, $mailer = null)
    {
        if ($req->method !== 'POST' && $req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $codigo = $req->param('orden');
        $pagoId = CheckoutHandler::idDePagoDelAviso($req);

        if (!$codigo || !$pagoId) {
            return Response::ok(['recibido' => true, 'motivo' => 'aviso sin datos utilizables']);
        }

        $orden = OrdenesDeProductos::orden($db, $codigo);

        if ($orden === null) {
            return Response::ok(['recibido' => true, 'motivo' => 'orden inexistente']);
        }

        $tokenMp = self::tokenDeLaOrden($db, $orden, $http);

        if ($tokenMp === null) {
            return Response::ok(['recibido' => true, 'motivo' => 'la página no tiene credenciales']);
        }

        $pago = (new MercadoPago($tokenMp, $http))->consultarPago($pagoId);

        if (!$pago['ok']) {
            return Response::error(503, 'No se pudo consultar el pago');
        }

        $problema = self::problemaDelPago($pago, $orden);

        if ($problema !== null) {
            return Response::ok(['recibido' => true, 'motivo' => $problema]);
        }

        $resultado = OrdenesDeProductos::acreditarPago($db, $orden['codigo'], $pagoId, $pago['estado'], $pago);

        if ($resultado['acreditada']) {
            self::mandarLink($db, $orden['codigo'], $mailer);
        }

        return Response::ok(['recibido' => true, 'motivo' => $resultado['motivo']]);
    }

    // ----------------------------------------------------------- conciliación

    /**
     * Le pregunta a Mercado Pago si una orden se pagó, sin conocer el id del
     * pago. La red debajo del aviso, como CheckoutHandler::conciliar.
     *
     * @return array{revisada: bool, acreditada: bool, motivo: string}
     */
    public static function conciliar($db, $codigo, $http = null, $mailer = null)
    {
        $orden = OrdenesDeProductos::orden($db, $codigo);

        if ($orden === null) {
            return ['revisada' => false, 'acreditada' => false, 'motivo' => 'orden inexistente'];
        }

        if (!in_array($orden['estado'], OrdenesDeProductos::ACREDITABLES, true)) {
            return ['revisada' => false, 'acreditada' => false, 'motivo' => 'no hay nada que revisar'];
        }

        $tokenMp = self::tokenDeLaOrden($db, $orden, $http);

        if ($tokenMp === null) {
            return ['revisada' => false, 'acreditada' => false, 'motivo' => 'la página no tiene credenciales'];
        }

        $pago = (new MercadoPago($tokenMp, $http))->buscarPagoPorReferencia($orden['codigo']);

        if (!$pago['ok']) {
            return ['revisada' => true, 'acreditada' => false, 'motivo' => 'sin pagos para esta orden'];
        }

        $problema = self::problemaDelPago($pago, $orden);

        if ($problema !== null) {
            return ['revisada' => true, 'acreditada' => false, 'motivo' => $problema];
        }

        $resultado = OrdenesDeProductos::acreditarPago($db, $orden['codigo'], $pago['id'], $pago['estado'], $pago);

        if ($resultado['acreditada']) {
            self::mandarLink($db, $orden['codigo'], $mailer);
        }

        return [
            'revisada'   => true,
            'acreditada' => $resultado['acreditada'],
            'motivo'     => $resultado['motivo'],
        ];
    }

    // ----------------------------------------------------------------- orden

    /**
     * Estado de una orden, para la pantalla a la que vuelve el comprador.
     *
     * Concilia antes de contestar, como la de entradas: si el aviso todavía no
     * llegó, acá se nota. Sólo dentro de la ventana de la conciliación: el
     * código se ve en una URL, y cada GET de una orden vieja no puede ser una
     * consulta más a Mercado Pago. No devuelve el token ni datos personales:
     * el código no es una credencial.
     */
    public static function orden($db, Request $req, $http = null, $mailer = null)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $codigo = $req->param('codigo');

        if (!$codigo) {
            return Response::error(400, 'codigo requerido');
        }

        $orden = OrdenesDeProductos::orden($db, $codigo);

        if ($orden === null) {
            return Response::notFound('No encontramos esa orden');
        }

        if (in_array($orden['estado'], OrdenesDeProductos::ACREDITABLES, true)
            && OrdenesDeProductos::estaEnVentanaDeConciliacion($orden)) {
            $resultado = self::conciliar($db, $orden['codigo'], $http, $mailer);

            if ($resultado['acreditada'] || $resultado['motivo'] === 'pago rechazado') {
                $orden = OrdenesDeProductos::orden($db, $orden['codigo']);
            }
        }

        return Response::ok(['orden' => [
            'codigo'          => $orden['codigo'],
            'estado'          => OrdenesDeProductos::estaVencida($orden) ? 'vencida' : $orden['estado'],
            'total'           => (float) $orden['total'],
            'moneda'          => 'ARS',
            'producto'        => $orden['producto'],
            'producto_imagen' => $orden['producto_imagen'],
            'archivo_nombre'  => $orden['archivo_nombre'],
            'archivo_bytes'   => $orden['archivo_bytes'] === null ? null : (int) $orden['archivo_bytes'],
            'pagina'          => $orden['pagina'],
            'url_slug'        => $orden['url_slug'],
            'meta_pixel_id'   => isset($orden['meta_pixel_id']) ? $orden['meta_pixel_id'] : null,
        ]]);
    }

    // -------------------------------------------------------------- descarga

    /**
     * Lo que muestra la pantalla de descarga: qué se va a bajar.
     *
     * El token llega en el cuerpo de un POST, nunca en la URL. Un token que no
     * existe y el de una orden sin pagar dan la misma respuesta.
     */
    public static function descarga($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $orden = OrdenesDeProductos::pagadaPorToken($db, $req->input('token'));

        if ($orden === null) {
            return Response::notFound(self::MENSAJE_LINK_INVALIDO);
        }

        return Response::ok(['producto' => [
            'titulo'         => $orden['producto'],
            'imagen'         => $orden['producto_imagen'],
            'archivo_nombre' => $orden['archivo_nombre'],
            'archivo_bytes'  => $orden['archivo_bytes'] === null ? null : (int) $orden['archivo_bytes'],
            'pagina'         => $orden['pagina'],
            'url_slug'       => $orden['url_slug'],
        ]]);
    }

    /**
     * El archivo, como adjunto.
     *
     * Lo pide un <form method="post"> común y no un fetch: así el navegador
     * guarda el archivo a medida que llega, sin cargar 50 MB en memoria, que
     * en un celular es lo que hace fallar la descarga. Por eso el token llega
     * como campo de formulario, y los errores vuelven como una página corta y
     * no como JSON: es lo que va a ver la persona.
     *
     * Sin tope de descargas ni vencimiento: cada una suma al contador.
     */
    public static function archivo($db, Request $req, ?FileStorage $storage = null)
    {
        $storage = $storage === null ? new FileStorage() : $storage;

        if ($req->method !== 'POST') {
            return self::paginaDeError(405, 'La descarga se pide desde el botón “Descargar”.');
        }

        $token = $req->formInput('token', $req->input('token'));
        $orden = OrdenesDeProductos::pagadaPorToken($db, $token);

        if ($orden === null) {
            return self::paginaDeError(404, self::MENSAJE_LINK_INVALIDO);
        }

        // El archivo del producto de hoy: si se reemplazó, se baja el nuevo.
        $ruta = $orden['archivo_ruta'];
        $enDisco = ProductosDigitales::rutaValida($ruta) ? ProductosDigitales::rutaEnDisco($ruta) : null;
        $bytes = $enDisco === null ? null : $storage->bytes($enDisco);

        if ($bytes === null) {
            return self::paginaDeError(404, 'El archivo no está disponible en este momento. Probá más tarde.');
        }

        OrdenesDeProductos::registrarDescarga($db, $orden['id']);

        $nombre = ProductosDigitales::nombreOriginal($orden['archivo_nombre']);

        return Response::archivo(200, $enDisco, [
            // Siempre como binario y adjunto: lo que subió el vendedor no se
            // abre nunca como página de nuestro dominio.
            'Content-Type'           => 'application/octet-stream',
            'Content-Disposition'    => "attachment; filename*=UTF-8''" . rawurlencode($nombre),
            'Content-Length'         => (string) $bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    // -------------------------------------------------------------- internos

    /** Un campo de texto del cuerpo; cualquier otra cosa cuenta como vacío. */
    private static function texto($valor)
    {
        return is_string($valor) || is_int($valor) || is_float($valor) ? (string) $valor : '';
    }

    /** Qué campo excede el largo de su columna, o null. */
    private static function problemaDeLargo(array $comprador)
    {
        $nombres = ['nombre' => 'El nombre', 'email' => 'El email', 'telefono' => 'El teléfono'];

        foreach (self::LARGOS as $campo => $maximo) {
            $valor = trim($comprador[$campo]);
            $largo = function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);

            if ($largo > $maximo) {
                return $nombres[$campo] . ' puede tener hasta ' . $maximo . ' caracteres.';
            }
        }

        return null;
    }

    /**
     * Por qué un pago no sirve para esta orden, o null si sirve.
     *
     * Más estricto que en las entradas: la referencia y el monto tienen que
     * venir y coincidir. Un pago sin referencia o sin monto no acredita nada.
     */
    private static function problemaDelPago(array $pago, array $orden)
    {
        if (!isset($pago['referencia']) || $pago['referencia'] !== $orden['codigo']) {
            return 'el pago es de otra orden';
        }

        if (!isset($pago['monto']) || abs((float) $pago['monto'] - (float) $orden['total']) > 0.01) {
            return 'el monto no coincide con la orden';
        }

        return null;
    }

    /**
     * Las credenciales de la página del producto.
     *
     * Se buscan por la página y no por el link: el producto puede estar
     * borrado u oculto y lo que se pagó igual se tiene que acreditar.
     */
    private static function tokenDeLaOrden($db, array $orden, $http)
    {
        return empty($orden['pagina_id']) ? null : Cobros::tokenDe($db, (int) $orden['pagina_id'], $http);
    }

    /** Manda el link sin que un problema de correo tumbe la operación. */
    private static function mandarLink($db, $codigo, $mailer, $reenviar = false)
    {
        try {
            CorreoProductos::enviar($db, $codigo, $mailer, $reenviar);
        } catch (Throwable $e) {
            // Queda pendiente y el cron lo reintenta.
        }
    }

    private static function paginaDeError($status, $mensaje)
    {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Descarga</title></head>'
            . '<body style="font-family:sans-serif;padding:24px;text-align:center"><p>'
            . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
            . '</p></body></html>';

        return Response::raw($status, $html, [
            'Content-Type'           => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    private static function urlDeRetorno($codigo)
    {
        return rtrim(FRONTEND_URL, '/') . '/compra/' . $codigo;
    }

    /** Como en las entradas: UPLOAD_URL ya es la base de la API. */
    private static function urlDeAviso($codigo)
    {
        return rtrim(UPLOAD_URL, '/') . '/public/aviso-pago-producto.php?orden=' . $codigo;
    }
}
