<?php

/**
 * Mails de una página a sus clientes (#37): los mensajes guardados, los
 * envíos y la baja.
 *
 * Los mensajes y los envíos los manejan el dueño y los administradores de la
 * página, como Clientes. La baja es pública: la pide quien recibió el mail,
 * con el token firmado del link.
 */
class EnviosHandler
{
    const SIN_CUENTA = 'Primero cargá la cuenta de email de la página en Configuración → Email.';

    // ---------------------------------------------------------- mensajes

    /** GET lista, POST crea, PUT edita, DELETE borra. */
    public static function mensajes($db, Request $req)
    {
        $pageId = self::autorizar($db, $req, ['GET', 'POST', 'PUT', 'DELETE']);

        if ($pageId instanceof Response) {
            return $pageId;
        }

        if ($req->method === 'GET') {
            $stmt = $db->prepare('
                SELECT id, nombre, asunto, cuerpo, reemplazo_nombre, actualizado_en
                FROM mensajes_guardados WHERE page_id = ? ORDER BY nombre, id
            ');
            $stmt->execute([$pageId]);

            return Response::ok([
                'mensajes' => array_map([self::class, 'mensajeParaMostrar'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
                'cuenta_configurada' => CuentaSmtp::deLaPagina($db, $pageId) !== null,
            ]);
        }

        if ($req->method === 'DELETE') {
            $id = (int) $req->param('id');
            $stmt = $db->prepare('DELETE FROM mensajes_guardados WHERE id = ? AND page_id = ?');
            $stmt->execute([$id, $pageId]);

            if ($stmt->rowCount() === 0) {
                return Response::notFound('No encontramos ese mensaje');
            }

            return Response::ok(['borrado' => true]);
        }

        $validado = MensajeACliente::validar($req->body);

        if (isset($validado['error'])) {
            return Response::error(400, $validado['error']);
        }

        $m = $validado['datos'];

        if ($req->method === 'POST') {
            $stmt = $db->prepare('
                INSERT INTO mensajes_guardados (page_id, nombre, asunto, cuerpo, reemplazo_nombre)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$pageId, $m['nombre'], $m['asunto'], $m['cuerpo'], $m['reemplazo_nombre']]);

            return Response::created(['mensaje' => ['id' => (int) $db->lastInsertId()] + $m]);
        }

        $id = (int) $req->input('id');

        if (self::plantilla($db, $pageId, $id) === null) {
            return Response::notFound('No encontramos ese mensaje');
        }

        $stmt = $db->prepare('
            UPDATE mensajes_guardados SET nombre = ?, asunto = ?, cuerpo = ?, reemplazo_nombre = ?
            WHERE id = ? AND page_id = ?
        ');
        $stmt->execute([$m['nombre'], $m['asunto'], $m['cuerpo'], $m['reemplazo_nombre'], $id, $pageId]);

        return Response::ok(['mensaje' => ['id' => $id] + $m]);
    }

    // ------------------------------------------------------------ envíos

    /**
     * GET lista los envíos (o uno, con ?id, y quiénes fallaron), POST crea uno
     * y PUT lo pausa, reanuda o cancela.
     */
    public static function index($db, Request $req)
    {
        $pageId = self::autorizar($db, $req, ['GET', 'POST', 'PUT']);

        if ($pageId instanceof Response) {
            return $pageId;
        }

        if ($req->method === 'GET') {
            $id = (int) $req->param('id');

            if (!$id) {
                return Response::ok(['envios' => EnviosAClientes::dePagina($db, $pageId)]);
            }

            $envio = EnviosAClientes::uno($db, $pageId, $id);

            if ($envio === null) {
                return Response::notFound('No encontramos ese envío');
            }

            return Response::ok(['envio' => $envio, 'problemas' => EnviosAClientes::problemas($db, $id)]);
        }

        if ($req->method === 'PUT') {
            $envio = EnviosAClientes::uno($db, $pageId, (int) $req->input('id'));

            if ($envio === null) {
                return Response::notFound('No encontramos ese envío');
            }

            $resultado = EnviosAClientes::cambiarEstado($db, $envio, (string) $req->input('accion'));

            if (isset($resultado['error'])) {
                return Response::error(409, $resultado['error']);
            }

            return Response::ok(['envio' => EnviosAClientes::uno($db, $pageId, $envio['id'])]);
        }

        return self::crear($db, $req, $pageId);
    }

    private static function crear($db, Request $req, $pageId)
    {
        if (CuentaSmtp::deLaPagina($db, $pageId) === null) {
            return Response::error(409, self::SIN_CUENTA);
        }

        $validado = MensajeACliente::validar($req->input('mensaje'), false);

        if (isset($validado['error'])) {
            return Response::error(400, $validado['error']);
        }

        $mensajeId = (int) $req->input('mensaje_id');
        $plantilla = $mensajeId ? self::plantilla($db, $pageId, $mensajeId) : null;
        $elegidos = EnviosAClientes::destinatarios($db, $pageId, $req->input('seleccion'));

        if (isset($elegidos['error'])) {
            return Response::error(400, $elegidos['error']);
        }

        $total = count($elegidos['destinatarios']);

        if ($total === 0) {
            return Response::error(400, 'No queda nadie a quien mandarle: los elegidos no tienen un email válido o se dieron de baja.');
        }

        if ($total > EnviosAClientes::TOPE) {
            return Response::error(400, 'Son ' . $total . ' destinatarios y el tope es de ' . EnviosAClientes::TOPE
                . ' por envío. Achicá la selección con los filtros.');
        }

        $envioId = EnviosAClientes::crear($db, $pageId, $req->userId(), $validado['datos'], $plantilla, $elegidos['destinatarios']);

        return Response::created([
            'envio' => EnviosAClientes::uno($db, $pageId, $envioId),
            'total' => $total,
            'de_baja' => $elegidos['de_baja'],
            'ignorados' => $elegidos['ignorados'],
        ]);
    }

    // -------------------------------------------------------------- baja

    /**
     * Pública. GET dice de qué página es el link, POST da la baja.
     *
     * El POST es también el de List-Unsubscribe-Post: el lector de correo lo
     * manda solo, con el token en la URL, sin pasar por la página.
     */
    public static function baja($db, Request $req)
    {
        if (!in_array($req->method, ['GET', 'POST'], true)) {
            return Response::methodNotAllowed();
        }

        $token = $req->param('t');

        if ($token === null) {
            $token = $req->input('t');
        }

        $datos = BajasDeCorreo::leerToken($token);

        if ($datos === null) {
            return Response::notFound('El link para darte de baja no es válido. Revisá que esté completo.');
        }

        $stmt = $db->prepare('SELECT title FROM pages WHERE id = ?');
        $stmt->execute([$datos['page_id']]);
        $pagina = $stmt->fetchColumn();

        if ($pagina === false) {
            return Response::notFound('Esa página ya no existe: no vas a recibir más mails suyos.');
        }

        if ($req->method === 'POST') {
            BajasDeCorreo::dar($db, $datos['page_id'], $datos['email']);
        }

        return Response::ok([
            'pagina' => (string) $pagina,
            'email' => $datos['email'],
            'de_baja' => $req->method === 'POST' || BajasDeCorreo::estaDeBaja($db, $datos['page_id'], $datos['email']),
        ]);
    }

    // ----------------------------------------------------------- interno

    /** @return int|Response El page_id, o la respuesta de error */
    private static function autorizar($db, Request $req, array $metodos)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if (!in_array($req->method, $metodos, true)) {
            return Response::methodNotAllowed();
        }

        $pageId = $req->param('page_id');

        if ($pageId === null) {
            $pageId = $req->input('page_id');
        }

        $pageId = is_scalar($pageId) ? (int) $pageId : 0;

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        // Los mails van a los clientes: el mismo permiso que para verlos.
        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés escribirles a los clientes de esta página');
        }

        return $pageId;
    }

    private static function plantilla($db, $pageId, $id)
    {
        $stmt = $db->prepare('SELECT id, nombre FROM mensajes_guardados WHERE id = ? AND page_id = ?');
        $stmt->execute([(int) $id, (int) $pageId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    private static function mensajeParaMostrar(array $fila)
    {
        return [
            'id' => (int) $fila['id'],
            'nombre' => $fila['nombre'],
            'asunto' => $fila['asunto'],
            'cuerpo' => $fila['cuerpo'],
            'reemplazo_nombre' => (string) $fila['reemplazo_nombre'],
            'actualizado_en' => $fila['actualizado_en'],
        ];
    }
}
