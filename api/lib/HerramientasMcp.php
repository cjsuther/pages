<?php

/**
 * Las herramientas que el server MCP le ofrece a un asistente.
 *
 * Todas delegan en los handlers que ya usa el editor web. Eso no es sólo para
 * no repetir código: es lo que garantiza que el asistente no pueda hacer nada
 * que la persona no pudiera hacer a mano, porque los permisos, las
 * validaciones y los avisos a seguidores son exactamente los mismos.
 *
 * Los nombres y las descripciones están en castellano porque el modelo las lee
 * para decidir qué herramienta usar, y quien las va a leer escribe en
 * castellano.
 */
class HerramientasMcp
{
    /**
     * Catálogo que se le muestra al asistente.
     *
     * Las descripciones dicen cuándo usar cada una y qué esperar, no sólo qué
     * hace: es lo único que el modelo tiene para elegir bien.
     */
    public static function catalogo()
    {
        return [
            [
                'name' => 'listar_paginas',
                'description' => 'Las páginas de Rezonar que administra quien te dio la clave. '
                    . 'Empezá por acá: el resto de las herramientas trabaja sobre una página.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ],
            [
                'name' => 'listar_eventos',
                'description' => 'Los eventos de una página, con su id, fecha y estado de venta de entradas.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pagina' => ['type' => 'string', 'description' => 'El slug de la página, como aparece en rezon.ar/<slug>'],
                    ],
                    'required' => ['pagina'],
                ],
            ],
            [
                'name' => 'crear_evento',
                'description' => 'Crea un evento en una página. La dirección se convierte en coordenadas '
                    . 'automáticamente, así que alcanza con escribirla como se la diría a alguien. '
                    . 'Para vender por una ticketera externa, pasá su enlace en "url"; para vender por '
                    . 'Rezonar, creá el evento y después usá configurar_entradas.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pagina'      => ['type' => 'string', 'description' => 'Slug de la página'],
                        'titulo'      => ['type' => 'string'],
                        'fecha'       => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
                        'hora'        => ['type' => 'string', 'description' => 'HH:MM, opcional'],
                        'direccion'   => ['type' => 'string', 'description' => 'Dirección completa; se geocodifica sola'],
                        'descripcion' => ['type' => 'string'],
                        'imagen'      => [
                            'type' => 'string',
                            'description' => 'El afiche en base64 (o data URI). Se sube a Rezonar y queda alojado acá. '
                                . 'Usá esto para subir una imagen; imagen_url es sólo para una que ya esté publicada en otro lado.',
                        ],
                        'imagen_url'  => ['type' => 'string', 'description' => 'URL absoluta de un afiche ya publicado'],
                        'url'         => ['type' => 'string', 'description' => 'Enlace de entradas o más info'],
                        'precio_desde' => ['type' => 'number', 'description' => 'Precio de referencia, sólo informativo'],
                    ],
                    'required' => ['pagina', 'titulo', 'fecha'],
                ],
            ],
            [
                'name' => 'actualizar_evento',
                'description' => 'Cambia los datos de un evento. Sólo se tocan los campos que mandes. '
                    . 'Si cambiás la dirección, las coordenadas se recalculan.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'evento_id'   => ['type' => 'integer'],
                        'titulo'      => ['type' => 'string'],
                        'fecha'       => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
                        'hora'        => ['type' => 'string', 'description' => 'HH:MM'],
                        'direccion'   => ['type' => 'string'],
                        'descripcion' => ['type' => 'string'],
                        'imagen'      => ['type' => 'string', 'description' => 'Afiche nuevo en base64 (o data URI); reemplaza al anterior'],
                        'imagen_url'  => ['type' => 'string'],
                        'url'         => ['type' => 'string'],
                        'precio_desde' => ['type' => 'number'],
                    ],
                    'required' => ['evento_id'],
                ],
            ],
            [
                'name' => 'borrar_evento',
                'description' => 'Borra un evento. No se puede deshacer, y si tenía entradas vendidas '
                    . 'las compras quedan sin evento: preguntá antes de usarla.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['evento_id' => ['type' => 'integer']],
                    'required' => ['evento_id'],
                ],
            ],
            [
                'name' => 'configurar_entradas',
                'description' => 'Pone o saca la venta de entradas por Rezonar en un evento. '
                    . 'modo "gratis" es reserva sin costo; modo "pago" necesita precio y que la página '
                    . 'tenga Mercado Pago conectado; modo "desactivar" corta la venta.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'evento_id'      => ['type' => 'integer'],
                        'modo'           => ['type' => 'string', 'enum' => ['gratis', 'pago', 'desactivar']],
                        'precio'         => ['type' => 'number', 'description' => 'Sólo para modo "pago"'],
                        'capacidad'      => ['type' => 'integer', 'description' => 'Cupo total; vacío es sin tope'],
                        'max_por_compra' => ['type' => 'integer', 'description' => 'Entradas por compra, por defecto 10'],
                    ],
                    'required' => ['evento_id', 'modo'],
                ],
            ],
            [
                'name' => 'ver_ventas',
                'description' => 'Resumen de ventas de un evento y el detalle de cada compra. '
                    . 'Incluye datos de contacto de los compradores: no los repitas si no te los piden.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['evento_id' => ['type' => 'integer']],
                    'required' => ['evento_id'],
                ],
            ],
            [
                'name' => 'cancelar_compra',
                'description' => 'Cancela una compra y devuelve sus lugares al cupo. La compra queda '
                    . 'registrada como cancelada. Confirmá con la persona antes de usarla.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['codigo' => ['type' => 'string', 'description' => 'Código de la compra']],
                    'required' => ['codigo'],
                ],
            ],
        ];
    }

    /**
     * Corre una herramienta.
     *
     * @return array{ok: bool, datos: mixed}
     * @throws InvalidArgumentException si la herramienta no existe
     */
    public static function ejecutar($db, array $usuario, $nombre, array $args, FileStorage $storage = null)
    {
        $metodos = [
            'listar_paginas'      => 'listarPaginas',
            'listar_eventos'      => 'listarEventos',
            'crear_evento'        => 'crearEvento',
            'actualizar_evento'   => 'actualizarEvento',
            'borrar_evento'       => 'borrarEvento',
            'configurar_entradas' => 'configurarEntradas',
            'ver_ventas'          => 'verVentas',
            'cancelar_compra'     => 'cancelarCompra',
        ];

        if (!isset($metodos[$nombre])) {
            throw new InvalidArgumentException("No existe la herramienta '$nombre'");
        }

        return call_user_func([self::class, $metodos[$nombre]], $db, $usuario, $args, $storage);
    }

    // ------------------------------------------------------------- páginas

    private static function listarPaginas($db, array $usuario, array $args, $storage = null)
    {
        $stmt = $db->prepare('
            SELECT id, title AS titulo, url_slug AS pagina, description AS descripcion
            FROM pages
            WHERE user_id = ?
            ORDER BY title
        ');
        $stmt->execute([$usuario['user_id']]);

        return self::bien(['paginas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private static function listarEventos($db, array $usuario, array $args, $storage = null)
    {
        $pagina = self::pagina($db, $usuario, isset($args['pagina']) ? $args['pagina'] : '');

        if (!$pagina['ok']) {
            return $pagina;
        }

        $stmt = $db->prepare('
            SELECT l.id AS evento_id, l.text AS titulo, l.event_date AS fecha, l.event_time AS hora,
                   l.event_address AS direccion, l.url, l.precio_desde,
                   t.activo AS vende_entradas, t.precio, t.capacidad
            FROM links l
            INNER JOIN link_groups g ON g.id = l.group_id
            LEFT JOIN event_ticketing t ON t.link_id = l.id
            WHERE g.page_id = ? AND g.type = "eventos"
            ORDER BY l.event_date, l.event_time
        ');
        $stmt->execute([$pagina['id']]);

        return self::bien(['eventos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ------------------------------------------------------------- eventos

    private static function crearEvento($db, array $usuario, array $args, $storage = null)
    {
        $pagina = self::pagina($db, $usuario, isset($args['pagina']) ? $args['pagina'] : '');

        if (!$pagina['ok']) {
            return $pagina;
        }

        $coordenadas = self::coordenadas($db, isset($args['direccion']) ? $args['direccion'] : '');

        if (!$coordenadas['ok']) {
            return $coordenadas;
        }

        $imagen = self::imagen($args, $storage);

        if (!$imagen['ok']) {
            return $imagen;
        }

        $cuerpo = [
            'group_id' => self::grupoDeEventos($db, $pagina['id']),
            // La API pide url y text siempre; un evento sin enlace es válido,
            // así que se manda vacío en lugar de inventar uno.
            'url'  => isset($args['url']) ? $args['url'] : '',
            'text' => isset($args['titulo']) ? $args['titulo'] : '',
            'description' => isset($args['descripcion']) ? $args['descripcion'] : null,
            'image_url' => $imagen['url'],
            'event_date' => isset($args['fecha']) ? $args['fecha'] : null,
            'event_time' => self::hora(isset($args['hora']) ? $args['hora'] : null),
            'event_address' => isset($args['direccion']) ? $args['direccion'] : null,
            'event_latitude' => $coordenadas['latitud'],
            'event_longitude' => $coordenadas['longitud'],
            'precio_desde' => isset($args['precio_desde']) ? $args['precio_desde'] : null,
        ];

        $r = LinksHandler::index($db, self::pedido('POST', $cuerpo, [], $usuario));

        return self::desdeRespuesta($r, 'evento creado');
    }

    private static function actualizarEvento($db, array $usuario, array $args, $storage = null)
    {
        $eventoId = isset($args['evento_id']) ? (int) $args['evento_id'] : 0;

        $cuerpo = self::soloLosCampos($args, [
            'titulo' => 'text',
            'descripcion' => 'description',
            'imagen_url' => 'image_url',
            'url' => 'url',
            'fecha' => 'event_date',
            'direccion' => 'event_address',
            'precio_desde' => 'precio_desde',
        ]);

        if (isset($args['hora'])) {
            $cuerpo['event_time'] = self::hora($args['hora']);
        }

        if (isset($args['imagen'])) {
            $imagen = self::imagen($args, $storage);

            if (!$imagen['ok']) {
                return $imagen;
            }

            $cuerpo['image_url'] = $imagen['url'];
        }

        // Mover el evento de dirección sin mover el punto del mapa dejaría la
        // ficha diciendo una cosa y el mapa otra.
        if (isset($args['direccion'])) {
            $coordenadas = self::coordenadas($db, $args['direccion']);

            if (!$coordenadas['ok']) {
                return $coordenadas;
            }

            $cuerpo['event_latitude'] = $coordenadas['latitud'];
            $cuerpo['event_longitude'] = $coordenadas['longitud'];
        }

        if (empty($cuerpo)) {
            return self::mal('No mandaste ningún campo para cambiar');
        }

        $r = LinksHandler::detail($db, self::pedido('PUT', $cuerpo, ['id' => $eventoId], $usuario));

        return self::desdeRespuesta($r, 'evento actualizado');
    }

    private static function borrarEvento($db, array $usuario, array $args, $storage = null)
    {
        $eventoId = isset($args['evento_id']) ? (int) $args['evento_id'] : 0;

        $r = LinksHandler::detail($db, self::pedido('DELETE', [], ['id' => $eventoId], $usuario));

        return self::desdeRespuesta($r, 'evento borrado');
    }

    // ------------------------------------------------------------ entradas

    private static function configurarEntradas($db, array $usuario, array $args, $storage = null)
    {
        $eventoId = isset($args['evento_id']) ? (int) $args['evento_id'] : 0;
        $modo = isset($args['modo']) ? $args['modo'] : '';

        $cuerpos = [
            'gratis' => ['activo' => 1, 'precio' => 0],
            'pago' => ['activo' => 1, 'precio' => isset($args['precio']) ? (float) $args['precio'] : 0],
            'desactivar' => ['activo' => 0, 'precio' => 0],
        ];

        if (!isset($cuerpos[$modo])) {
            return self::mal('El modo tiene que ser "gratis", "pago" o "desactivar"');
        }

        if ($modo === 'pago' && $cuerpos['pago']['precio'] <= 0) {
            return self::mal('Para el modo "pago" hace falta un precio mayor a cero');
        }

        $cuerpo = $cuerpos[$modo] + [
            'capacidad' => isset($args['capacidad']) ? (int) $args['capacidad'] : null,
            'max_por_compra' => isset($args['max_por_compra']) ? (int) $args['max_por_compra'] : 10,
        ];

        $r = EntradasHandler::config($db, self::pedido('POST', $cuerpo, ['link_id' => $eventoId], $usuario));

        return self::desdeRespuesta($r, 'entradas configuradas');
    }

    private static function verVentas($db, array $usuario, array $args, $storage = null)
    {
        $eventoId = isset($args['evento_id']) ? (int) $args['evento_id'] : 0;

        $r = EntradasHandler::ventas($db, self::pedido('GET', [], ['link_id' => $eventoId], $usuario));

        return self::desdeRespuesta($r, 'ventas');
    }

    private static function cancelarCompra($db, array $usuario, array $args, $storage = null)
    {
        $codigo = isset($args['codigo']) ? $args['codigo'] : '';

        $r = EntradasHandler::cancelar($db, self::pedido('POST', ['codigo' => $codigo], [], $usuario));

        return self::desdeRespuesta($r, 'compra cancelada');
    }

    // ------------------------------------------------------------- internos

    /**
     * La imagen del evento, ya alojada en Rezonar.
     *
     * Un asistente no puede mandar un formulario con un archivo, así que manda
     * los bytes en base64 y acá se guardan igual que una subida cualquiera:
     * misma validación, mismo directorio, misma comprobación de que sea
     * realmente una imagen.
     *
     * `imagen_url` sigue existiendo para un afiche que ya esté publicado en
     * otro lado. Si vienen las dos, gana la que se sube: es la que la persona
     * eligió mandar en este momento.
     *
     * @return array{ok: bool, url?: string|null, datos?: mixed}
     */
    private static function imagen(array $args, $storage = null)
    {
        if (empty($args['imagen'])) {
            return ['ok' => true, 'url' => isset($args['imagen_url']) ? $args['imagen_url'] : null, 'datos' => null];
        }

        $guardada = UploadHandler::guardarBase64($args['imagen'], $storage);

        if (!$guardada['ok']) {
            return self::mal($guardada['error']);
        }

        return ['ok' => true, 'url' => $guardada['url'], 'datos' => null];
    }

    /** Arma el pedido como si viniera del editor, con la sesión de la clave. */
    private static function pedido($metodo, array $cuerpo, array $query, array $usuario)
    {
        return new Request($metodo, $cuerpo, $query, $usuario);
    }

    /**
     * Traduce la respuesta del handler al resultado de la herramienta.
     *
     * Un 4xx no es una falla del server: es el handler diciendo que el pedido
     * estaba mal. Se devuelve como error de la herramienta con su mensaje, que
     * es lo que le permite al modelo corregir y volver a intentar.
     */
    private static function desdeRespuesta(Response $r, $queSalioBien)
    {
        $cuerpo = $r->body;

        if ($r->status >= 400) {
            $motivo = is_array($cuerpo) && isset($cuerpo['error']) ? $cuerpo['error'] : 'no se pudo';

            return self::mal($motivo);
        }

        return self::bien(['resultado' => $queSalioBien] + (is_array($cuerpo) ? $cuerpo : []));
    }

    /** La página por su slug, si esta persona la administra. */
    private static function pagina($db, array $usuario, $slug)
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return self::mal('Falta el slug de la página');
        }

        $stmt = $db->prepare('SELECT id FROM pages WHERE url_slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return self::mal("No existe la página '$slug'");
        }

        if (!PageAccess::canManage($db, (int) $id, $usuario['user_id'])) {
            return self::mal("No administrás la página '$slug'");
        }

        return ['ok' => true, 'id' => (int) $id, 'datos' => null];
    }

    /** El grupo de eventos de la página; se crea si todavía no hay. */
    private static function grupoDeEventos($db, $pageId)
    {
        $stmt = $db->prepare('SELECT id FROM link_groups WHERE page_id = ? AND type = "eventos" ORDER BY position, id LIMIT 1');
        $stmt->execute([$pageId]);
        $existente = $stmt->fetchColumn();

        if ($existente !== false) {
            return (int) $existente;
        }

        $stmt = $db->prepare('INSERT INTO link_groups (page_id, title, type, position) VALUES (?, "Agenda", "eventos", 0)');
        $stmt->execute([$pageId]);

        return (int) $db->lastInsertId();
    }

    /**
     * Coordenadas de la dirección.
     *
     * La API exige latitud y longitud para un evento, y en el editor las pone
     * el mapa. Un asistente no tiene mapa: tiene una dirección escrita, así
     * que se resuelve acá y el resto del circuito queda igual.
     */
    private static function coordenadas($db, $direccion)
    {
        $direccion = trim((string) $direccion);

        if ($direccion === '') {
            return self::mal('Falta la dirección: el evento se ubica en un mapa y sin ella no se puede publicar');
        }

        $coords = (new Geocodificador())->coordenadas($db, $direccion);

        if (empty($coords)) {
            return self::mal("No pudimos ubicar '$direccion' en el mapa. Probá con la calle, la altura y la ciudad.");
        }

        return ['ok' => true, 'latitud' => $coords['latitud'], 'longitud' => $coords['longitud'], 'datos' => null];
    }

    /** Normaliza HH:MM a HH:MM:SS, que es lo que espera la columna. */
    private static function hora($hora)
    {
        if (!is_string($hora) || !preg_match('/^(\d{1,2}):(\d{2})/', trim($hora), $m)) {
            return null;
        }

        return sprintf('%02d:%s:00', (int) $m[1], $m[2]);
    }

    /** Pasa sólo los campos que vinieron, con el nombre que espera la API. */
    private static function soloLosCampos(array $args, array $mapa)
    {
        $cuerpo = [];

        foreach ($mapa as $desde => $hacia) {
            if (isset($args[$desde])) {
                $cuerpo[$hacia] = $args[$desde];
            }
        }

        return $cuerpo;
    }

    private static function bien($datos)
    {
        return ['ok' => true, 'datos' => $datos];
    }

    private static function mal($motivo)
    {
        return ['ok' => false, 'datos' => ['error' => $motivo]];
    }
}
