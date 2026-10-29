<?php

/**
 * Links: alta (index) y edición/borrado (detail).
 *
 * Extraído de api/links/index.php y api/links/detail.php sin cambios de
 * comportamiento: mismos códigos de estado, mismos mensajes y mismo orden de
 * validaciones (el orden importa: detail valida el id antes que la sesión).
 */
class LinksHandler
{
    /**
     * Campos que acepta la edición.
     *
     * 'nullable' => true replica array_key_exists(): permite enviar null o ''
     * para vaciar el campo. Los demás usan isset(), así que un null se ignora.
     */
    private static $updatableFields = [
        'url' => ['nullable' => false],
        'url_text' => ['nullable' => true],
        // nullable: vaciarlo devuelve el item de galería a ser una imagen.
        'embed_url' => ['nullable' => true],
        'text' => ['nullable' => false],
        'image_url' => ['nullable' => true],
        'description' => ['nullable' => false],
        'position' => ['nullable' => false],
        'event_date' => ['nullable' => false],
        'event_time' => ['nullable' => false],
        'event_address' => ['nullable' => false],
        'event_latitude' => ['nullable' => false],
        'event_longitude' => ['nullable' => false],
        'event_maps_url' => ['nullable' => false],
        // nullable: vaciarlo es "no se sabe", que no es lo mismo que cero.
        'precio_desde' => ['nullable' => true],
    ];

    /**
     * El precio de referencia distingue tres cosas: no saberlo, que sea cero
     * —o sea, gratis— y tener un valor. Una cadena vacía es lo primero.
     */
    /** "text = ?" -> "text", para saber qué campos se guardaron. */
    private static function nombresDeCampos(array $asignaciones)
    {
        return array_map(function ($asignacion) {
            return trim(explode('=', $asignacion)[0]);
        }, $asignaciones);
    }

    public static function precioONull($valor)
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }

        // El cast explícito importa: max(0, -500.0) devuelve el entero 0, y el
        // tipo tiene que ser siempre el mismo para quien lo consuma.
        return (float) max(0, round((float) $valor, 2));
    }

    const ERROR_SIN_COORDENADAS = 'Los eventos deben tener coordenadas. Por favor selecciona una dirección de Google Maps.';

    const ERROR_SIN_PRECIO = 'Elegí si el evento es gratis o desde qué precio salen las entradas';

    /**
     * Un evento que no vende por Rezonar tiene que decir si es gratis o desde
     * cuánto sale: es lo que se muestra en las tarjetas y por lo que se filtra
     * en la home. Uno que vende acá no lo necesita, porque el precio sale de
     * la venta.
     *
     * @param mixed $precio Lo que quedaría guardado, ya pasado por precioONull()
     */
    private static function faltaElPrecio($db, $linkId, $precio)
    {
        if ($precio !== null) {
            return false;
        }

        $venta = $linkId === null ? null : Entradas::configDelEvento($db, $linkId);

        return $venta === null || empty($venta['activo']);
    }

    // ------------------------------------------------------------------ index

    /**
     * @param bool $exigirPrecio false para el asistente (MCP), que puede crear
     *                           un evento sin saber todavía el precio
     */
    public static function index($db, Request $req, $exigirPrecio = true)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->missing(['group_id', 'url', 'text'])) {
            return Response::error(400, 'Group ID, URL, and text are required');
        }

        try {
            if (!PageAccess::canManageGroup($db, $req->input('group_id'), $req->userId())) {
                return Response::notFound('Group not found');
            }

            $stmt = $db->prepare('SELECT id, type FROM link_groups WHERE id = ?');
            $stmt->execute([$req->input('group_id')]);
            $group = $stmt->fetch();

            if ($group && $group['type'] === 'eventos') {
                $lat = $req->input('event_latitude');
                $lng = $req->input('event_longitude');

                if (empty($lat) || empty($lng)) {
                    return Response::error(400, self::ERROR_SIN_COORDENADAS);
                }

                // Un evento recién creado todavía no vende por Rezonar: la
                // venta se configura después, sobre el evento ya guardado.
                if ($exigirPrecio && self::faltaElPrecio($db, null, self::precioONull($req->input('precio_desde')))) {
                    return Response::error(400, self::ERROR_SIN_PRECIO);
                }
            }

            if ($group && $group['type'] === 'productos') {
                return self::crearProducto($db, $req);
            }

            $stmt = $db->prepare('INSERT INTO links (group_id, url, url_text, embed_url, text, image_url, description, position, event_date, event_time, event_address, event_latitude, event_longitude, event_maps_url, precio_desde) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $req->input('group_id'),
                $req->input('url'),
                self::valorONull($req->input('url_text')),
                self::valorONull($req->input('embed_url')),
                $req->input('text'),
                $req->input('image_url'),
                $req->input('description'),
                $req->input('position', 0),
                $req->input('event_date'),
                $req->input('event_time'),
                $req->input('event_address'),
                $req->input('event_latitude'),
                $req->input('event_longitude'),
                $req->input('event_maps_url'),
                self::precioONull($req->input('precio_desde')),
            ]);

            $linkId = $db->lastInsertId();

            // Si el grupo es de eventos, se avisa a los seguidores en el acto.
            // Notificador es idempotente, así que el cron diario puede volver a
            // pasar por el mismo evento sin duplicar nada.
            if ($group && $group['type'] === 'eventos') {
                try {
                    Notificador::avisarEventoNuevo($db, $linkId);
                } catch (Exception $e) {
                    // Que falle el aviso no puede impedir que se cree el evento.
                    error_log('No se pudo notificar el evento ' . $linkId . ': ' . $e->getMessage());
                }
            }

            $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
            $stmt->execute([$linkId]);
            $link = $stmt->fetch();

            return Response::created(['link' => $link]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    const ERROR_PRECIO = 'El precio tiene que ser un número igual o mayor que 0';

    /**
     * Alta de un producto: el link y su fila de producto, juntos.
     *
     * El archivo de un digital no viene acá: se sube después a
     * productos/archivo.php, que necesita el id del producto. Un físico no
     * tiene archivo; trae su envío, características y stock (#42). La URL
     * queda vacía, como en los eventos: un producto no lleva a ninguna parte,
     * se compra.
     */
    private static function crearProducto($db, Request $req)
    {
        $precio = ProductosDigitales::precio($req->input('precio'));

        if ($precio === null) {
            return Response::error(400, self::ERROR_PRECIO);
        }

        // Digital o físico (#42): se elige acá y no cambia más.
        $tipo = ProductosFisicos::tipo($req->input('tipo'));

        if ($tipo === null) {
            return Response::error(400, ProductosFisicos::ERROR_TIPO);
        }

        $fisico = null;

        if ($tipo === 'fisico') {
            list($fisico, $error) = ProductosFisicos::datosDelPedido($req->body);

            if ($error !== null) {
                return Response::error(400, $error);
            }
        }

        $db->beginTransaction();

        try {
            $stmt = $db->prepare('INSERT INTO links (group_id, url, text, image_url, description, position) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $req->input('group_id'),
                '',
                $req->input('text'),
                self::valorONull($req->input('image_url')),
                $req->input('description'),
                $req->input('position', 0),
            ]);

            $linkId = $db->lastInsertId();
            ProductosDigitales::guardarPrecio($db, $linkId, $precio);
            ProductosDigitales::actualizarFoto($db, $linkId);

            if ($fisico !== null) {
                ProductosFisicos::marcarFisico($db, $linkId);
                ProductosFisicos::guardar($db, $linkId, $fisico);
            }

            if ($req->has('oculto')) {
                ProductosDigitales::guardarOculto($db, $linkId, self::esVerdadero($req->input('oculto')));
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();

            return Response::serverError($e->getMessage());
        }

        return Response::created(['link' => ProductosDigitales::linkConProducto($db, $linkId)]);
    }

    // ----------------------------------------------------------------- detail

    /**
     * @param bool $exigirPrecio false para el asistente (MCP), como en index()
     */
    public static function detail($db, Request $req, ?FileStorage $storage = null, $exigirPrecio = true)
    {
        $linkId = (int) $req->param('id');

        if (!$linkId) {
            return Response::error(400, 'Link ID is required');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'PUT') {
            return self::update($db, $req, $linkId, $exigirPrecio);
        }

        if ($req->method === 'DELETE') {
            return self::destroy($db, $req, $linkId, $storage === null ? new FileStorage() : $storage);
        }

        return Response::methodNotAllowed();
    }

    private static function update($db, Request $req, $linkId, $exigirPrecio = true)
    {
        try {
            if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
                return Response::notFound('Link not found');
            }

            $stmt = $db->prepare('
                SELECT l.id, lg.type
                FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                WHERE l.id = ?
            ');
            $stmt->execute([$linkId]);
            $linkData = $stmt->fetch();

            if ($linkData && $linkData['type'] === 'eventos') {
                $error = self::validarCoordenadasDeEvento($db, $req, $linkId);
                if ($error !== null) {
                    return $error;
                }

                // Sólo cuando el pedido trae el precio: guardar el link desde
                // la solapa de entradas, o reordenar, no puede trabarse por un
                // evento viejo que nunca lo tuvo. El editor lo manda siempre,
                // así que ahí sí se pide.
                if ($exigirPrecio && array_key_exists('precio_desde', $req->body)
                    && self::faltaElPrecio($db, $linkId, self::precioONull($req->body['precio_desde']))) {
                    return Response::error(400, self::ERROR_SIN_PRECIO);
                }
            }

            $esProducto = $linkData && $linkData['type'] === 'productos';
            $precio = null;
            $oculto = $esProducto && $req->has('oculto') ? self::esVerdadero($req->input('oculto')) : null;

            if ($esProducto && $req->has('precio')) {
                $precio = ProductosDigitales::precio($req->input('precio'));

                if ($precio === null) {
                    return Response::error(400, self::ERROR_PRECIO);
                }
            }

            $fisico = null;

            if ($esProducto) {
                $producto = ProductosDigitales::delLink($db, $linkId);
                $tipoActual = ProductosFisicos::esFisico($producto) ? 'fisico' : 'digital';

                // El editor manda el item entero, con su tipo: si es el mismo
                // no pasa nada; si es otro, no se cambia (#42).
                if ($req->has('tipo') && ProductosFisicos::tipo($req->input('tipo')) !== $tipoActual) {
                    return Response::error(400, ProductosFisicos::ERROR_CAMBIO_DE_TIPO);
                }

                if ($tipoActual === 'fisico') {
                    // Las formas de entrega se miran contra lo que ya tiene (#53).
                    list($fisico, $error) = ProductosFisicos::datosDelPedido($req->body, $producto);

                    if ($error !== null) {
                        return Response::error(400, $error);
                    }

                    $fisico = empty($fisico) ? null : $fisico;
                }
            }

            list($fields, $values) = self::camposAActualizar($req);

            // A un producto se le puede cambiar sólo el precio, si está
            // oculto o lo de un físico, que no son columnas de links.
            if (empty($fields) && $precio === null && $oculto === null && $fisico === null) {
                return Response::error(400, 'No fields to update');
            }

            // Las características de un físico se tocan en varias consultas:
            // o se guarda todo, o nada.
            if ($fisico !== null) {
                $db->beginTransaction();
            }

            try {
                self::guardarEdicion($db, $linkId, $fields, $values, $esProducto, $precio, $oculto, $fisico);

                if ($fisico !== null) {
                    $db->commit();
                }
            } catch (Exception $e) {
                if ($fisico !== null) {
                    $db->rollBack();
                }

                throw $e;
            }

            if ($esProducto) {
                return Response::ok(['link' => ProductosDigitales::linkConProducto($db, $linkId)]);
            }

            $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
            $stmt->execute([$linkId]);
            $link = $stmt->fetch();

            return Response::ok(['link' => $link]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /** Las escrituras de update(), en orden. */
    private static function guardarEdicion($db, $linkId, array $fields, array $values, $esProducto, $precio, $oculto, $fisico)
    {
        if (!empty($fields)) {
            $values[] = $linkId;
            $stmt = $db->prepare('UPDATE links SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($values);

            // Lo que se toca a mano queda congelado: el importador diario
            // actualiza el resto pero no vuelve a pisar esto. Sin la marca,
            // corregir el título de un show importado duraría hasta la
            // madrugada siguiente.
            Importador::marcarEditados($db, $linkId, self::nombresDeCampos($fields));
        }

        if ($precio !== null) {
            ProductosDigitales::guardarPrecio($db, $linkId, $precio);
        }

        if ($oculto !== null) {
            ProductosDigitales::guardarOculto($db, $linkId, $oculto);
        }

        if ($fisico !== null) {
            ProductosFisicos::guardar($db, $linkId, $fisico);
        }

        if ($esProducto) {
            ProductosDigitales::actualizarFoto($db, $linkId);
        }

        // Si el evento vende, su registro guarda el título y la fecha
        // nuevos: son los que se van a ver si algún día se borra.
        Clientes::actualizarEvento($db, $linkId);
    }

    private static function destroy($db, Request $req, $linkId, FileStorage $storage)
    {
        try {
            if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
                return Response::notFound('Link not found');
            }

            $db->beginTransaction();

            try {
                // Si es un producto: sin órdenes se va entero; con órdenes
                // queda su fila (y el archivo, si alguien puede descargarlo).
                $archivos = ProductosDigitales::soltar($db, 'link', $linkId);

                // Las compras sobreviven al evento colgadas de su registro. Se
                // refresca antes de borrar para que quede con los últimos datos
                // y con las colaboraciones de este momento.
                Clientes::actualizarEvento($db, $linkId);

                $stmt = $db->prepare('DELETE FROM links WHERE id = ?');
                $stmt->execute([$linkId]);

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();

                throw $e;
            }

            // Recién con el borrado confirmado: si la base fallaba, el
            // producto seguía entero con su archivo.
            ProductosDigitales::borrarArchivos($archivos, $storage);

            return Response::ok(['message' => 'Link deleted successfully']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /** Un booleano que puede llegar como true, 1, "1" o "true". */
    private static function esVerdadero($valor)
    {
        return $valor === true || $valor === 1 || $valor === '1' || $valor === 'true';
    }

    /**
     * Un evento no puede quedar sin coordenadas: se combinan las que llegan en
     * la petición con las ya guardadas y se valida el resultado final.
     */
    private static function validarCoordenadasDeEvento($db, Request $req, $linkId)
    {
        $stmt = $db->prepare('SELECT event_latitude, event_longitude FROM links WHERE id = ?');
        $stmt->execute([$linkId]);
        $actual = $stmt->fetch();
        $actual = $actual === false ? [] : $actual;

        $lat = $req->has('event_latitude')
            ? $req->input('event_latitude')
            : (isset($actual['event_latitude']) ? $actual['event_latitude'] : null);

        $lng = $req->has('event_longitude')
            ? $req->input('event_longitude')
            : (isset($actual['event_longitude']) ? $actual['event_longitude'] : null);

        if (empty($lat) || empty($lng)) {
            return Response::error(400, self::ERROR_SIN_COORDENADAS);
        }

        return null;
    }

    /** @return array{0: string[], 1: array} Pares "campo = ?" y sus valores. */
    private static function camposAActualizar(Request $req)
    {
        $fields = [];
        $values = [];

        foreach (self::$updatableFields as $campo => $opciones) {
            if ($opciones['nullable']) {
                if (!array_key_exists($campo, $req->body)) {
                    continue;
                }
                $fields[] = $campo . ' = ?';
                // El precio pasa por la misma regla que en el alta: vacío es
                // "no se sabe", nunca cero, y nada que no sea un número.
                $values[] = $campo === 'precio_desde'
                    ? self::precioONull($req->body[$campo])
                    : self::valorONull($req->body[$campo]);
                continue;
            }

            if (!$req->has($campo)) {
                continue;
            }
            $fields[] = $campo . ' = ?';
            $values[] = $req->body[$campo];
        }

        return [$fields, $values];
    }

    /** '' y null se guardan como NULL, para no dejar cadenas vacías en la base. */
    private static function valorONull($valor)
    {
        return ($valor === '' || $valor === null) ? null : $valor;
    }
}
