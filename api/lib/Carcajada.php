<?php

/**
 * Carcajada: los comediantes, los shows y la ficha de cada uno.
 *
 * Usa las cuentas de Rezonar: para anotarse hay que tener una página, que es
 * lo que el público abre cuando escanea el QR durante el show. Eso además
 * resuelve solo lo que un formulario abierto no puede —que nadie se anote dos
 * veces ni ponga la página de otro— porque el alta se hace con la sesión.
 *
 * Quien produce también puede sumar a alguien que no tiene cuenta, cargando
 * sólo nombre, foto e Instagram: un invitado. No tiene usuario ni página, y
 * sus datos los mantiene quien produce.
 *
 * La evaluación de una noche vive en el lineup y no en el comediante: la
 * ficha de alguien es el conjunto de sus noches, no un número que se pisa.
 */
class Carcajada
{
    /** Lo más alto que se puede puntuar una presentación. */
    const PUNTAJE_MAXIMO = 5;

    /** Tope de gente que alguien puede prometer: más que eso es un error de tipeo. */
    const MAX_PERSONAS = 500;

    /** Primer año de egreso que se acepta; antes de eso es un error de tipeo. */
    const PRIMER_EGRESO = 1950;

    const LARGO_MATERIAL = 2000;

    // ------------------------------------------------------------ productores

    /**
     * Quién puede armar shows y evaluar.
     *
     * Producir Carcajada no es administrar Rezonar: son listas distintas a
     * propósito, porque dar de alta a quien produce el ciclo no puede
     * significar darle acceso a las páginas de todo el mundo. Quien administra
     * la plataforma entra igual, que es el acceso de soporte de siempre.
     */
    public static function esProductor($db, $userId)
    {
        if (Plataforma::esAdmin($db, $userId)) {
            return true;
        }

        $stmt = $db->prepare('SELECT 1 FROM carcajada_productores WHERE user_id = ?');
        $stmt->execute([(int) $userId]);

        return $stmt->fetchColumn() !== false;
    }

    // ------------------------------------------------------------ comediantes

    /** Lo que ya se sabe de alguien que entra por primera vez, para no pedírselo. */
    public static function sugerenciaDesdeRezonar($db, $userId)
    {
        $stmt = $db->prepare('
            SELECT p.id, p.title, p.url_slug, p.profile_image
            FROM pages p
            WHERE p.user_id = ?
            ORDER BY p.id
            LIMIT 1
        ');
        $stmt->execute([(int) $userId]);
        $pagina = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pagina === false) {
            return null;
        }

        $instagram = null;

        foreach (Redes::deLaPagina($db, $pagina['id']) as $red) {
            if ($red['red'] === 'instagram') {
                $instagram = self::usuarioDeInstagram($red['url']);
                break;
            }
        }

        return [
            'page_id'   => (int) $pagina['id'],
            'titulo'    => $pagina['title'],
            'url_slug'  => $pagina['url_slug'],
            'foto_url'  => $pagina['profile_image'],
            'instagram' => $instagram,
        ];
    }

    /** El comediante de una cuenta, o null si todavía no se anotó. */
    public static function comedianteDelUsuario($db, $userId)
    {
        $stmt = $db->prepare('
            SELECT c.*, p.url_slug, p.title AS pagina
            FROM carcajada_comediantes c
            LEFT JOIN pages p ON p.id = c.page_id
            WHERE c.user_id = ?
        ');
        $stmt->execute([(int) $userId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : self::comediantePublico($fila);
    }

    /**
     * Anota a alguien o actualiza sus datos.
     *
     * @param array $datos ['page_id', 'nombre', 'foto_url', 'instagram', 'personas_comprometidas',
     *                      'estudio_con', 'egreso_anio', 'material']
     * @return array{ok: bool, error: string|null}
     */
    public static function guardarComediante($db, $userId, array $datos)
    {
        $nombre = isset($datos['nombre']) ? trim((string) $datos['nombre']) : '';
        $personas = isset($datos['personas_comprometidas']) ? (int) $datos['personas_comprometidas'] : 0;
        $pageId = isset($datos['page_id']) ? (int) $datos['page_id'] : 0;

        if ($nombre === '') {
            return ['ok' => false, 'error' => 'Falta el nombre con el que querés aparecer'];
        }

        if (mb_strlen($nombre) > 80) {
            return ['ok' => false, 'error' => 'El nombre es demasiado largo'];
        }

        if ($personas < 0 || $personas > self::MAX_PERSONAS) {
            return ['ok' => false, 'error' => 'La cantidad de gente tiene que estar entre 0 y ' . self::MAX_PERSONAS];
        }

        $estudioCon = isset($datos['estudio_con']) ? trim((string) $datos['estudio_con']) : '';

        if (mb_strlen($estudioCon) > 120) {
            return ['ok' => false, 'error' => 'Lo de con quién estudiaste es demasiado largo'];
        }

        $egreso = isset($datos['egreso_anio']) && $datos['egreso_anio'] !== '' && $datos['egreso_anio'] !== null
            ? (int) $datos['egreso_anio']
            : null;

        if ($egreso !== null && ($egreso < self::PRIMER_EGRESO || $egreso > (int) date('Y'))) {
            return ['ok' => false, 'error' => 'El año de egreso tiene que estar entre ' . self::PRIMER_EGRESO . ' y ' . date('Y')];
        }

        $material = isset($datos['material']) ? trim((string) $datos['material']) : '';

        if (mb_strlen($material) > self::LARGO_MATERIAL) {
            return ['ok' => false, 'error' => 'La descripción del material puede tener hasta ' . self::LARGO_MATERIAL . ' caracteres'];
        }

        // La página tiene que ser suya: es lo que el público va a abrir desde
        // el QR, y con la sesión en la mano no hay motivo para creerle al
        // navegador de quién es.
        if (!self::paginaDelUsuario($db, $pageId, $userId)) {
            return ['ok' => false, 'error' => 'Esa página no es tuya. Entrá con la cuenta de Rezonar de tu página.'];
        }

        $stmt = $db->prepare('
            INSERT INTO carcajada_comediantes
                (user_id, page_id, nombre, foto_url, instagram, personas_comprometidas, estudio_con, egreso_anio, material)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                page_id = VALUES(page_id),
                nombre = VALUES(nombre),
                foto_url = VALUES(foto_url),
                instagram = VALUES(instagram),
                personas_comprometidas = VALUES(personas_comprometidas),
                estudio_con = VALUES(estudio_con),
                egreso_anio = VALUES(egreso_anio),
                material = VALUES(material)
        ');
        $stmt->execute([
            (int) $userId,
            $pageId,
            $nombre,
            isset($datos['foto_url']) && $datos['foto_url'] !== '' ? $datos['foto_url'] : null,
            isset($datos['instagram']) ? self::usuarioDeInstagram($datos['instagram']) : null,
            $personas,
            $estudioCon === '' ? null : $estudioCon,
            $egreso,
            $material === '' ? null : $material,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Todos los comediantes con su ficha, para quien produce.
     *
     * La ficha es el resumen de sus noches: cuántas hizo, qué puntaje promedio
     * sacó, cuánta gente prometió y cuánta trajo de verdad. Esa diferencia es
     * el dato que se usa para armar la próxima fecha.
     */
    public static function fichas($db)
    {
        $stmt = $db->prepare('
            SELECT c.id, c.user_id, c.nombre, c.foto_url, c.instagram, c.personas_comprometidas,
                   c.estudio_con, c.egreso_anio, c.material,
                   p.url_slug, p.title AS pagina,
                   COUNT(l.id) AS shows,
                   AVG(l.puntaje) AS puntaje,
                   SUM(l.personas_traidas) AS traidas,
                   COUNT(l.personas_traidas) AS shows_medidos,
                   MAX(s.fecha) AS ultimo_show
            FROM carcajada_comediantes c
            LEFT JOIN pages p ON p.id = c.page_id
            LEFT JOIN carcajada_lineup l ON l.comediante_id = c.id
            LEFT JOIN carcajada_shows s ON s.id = l.show_id
            GROUP BY c.id, c.user_id, c.nombre, c.foto_url, c.instagram, c.personas_comprometidas,
                     c.estudio_con, c.egreso_anio, c.material, p.url_slug, p.title
            ORDER BY c.nombre
        ');
        $stmt->execute();

        $fichas = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $medidos = (int) $fila['shows_medidos'];

            $fichas[] = [
                'id'        => (int) $fila['id'],
                'nombre'    => $fila['nombre'],
                'foto_url'  => $fila['foto_url'],
                'instagram' => $fila['instagram'],
                'url_slug'  => $fila['url_slug'],
                'pagina'    => $fila['pagina'],
                'con_cuenta' => $fila['user_id'] !== null,
                'estudio_con' => $fila['estudio_con'],
                'egreso_anio' => $fila['egreso_anio'] === null ? null : (int) $fila['egreso_anio'],
                'material'  => $fila['material'],
                'comprometidas' => (int) $fila['personas_comprometidas'],
                'shows'     => (int) $fila['shows'],
                // null y no cero: nadie evaluado todavía no es un cero de puntaje.
                'puntaje'   => $fila['puntaje'] === null ? null : round((float) $fila['puntaje'], 1),
                'promedio_traidas' => $medidos === 0 ? null : round((float) $fila['traidas'] / $medidos, 1),
                'shows_medidos' => $medidos,
                'ultimo_show' => $fila['ultimo_show'],
            ];
        }

        return $fichas;
    }

    /**
     * Da de alta o corrige a un comediante sin cuenta.
     *
     * Sólo se tocan los invitados: los datos de quien tiene cuenta los maneja
     * esa persona desde su alta, y quien produce no puede pisárselos.
     *
     * @param int|null $comedianteId null para darlo de alta
     * @param array $datos ['nombre', 'foto_url', 'instagram']
     * @return array{ok: bool, error: string|null, id: int|null}
     */
    public static function guardarInvitado($db, $comedianteId, array $datos)
    {
        $nombre = isset($datos['nombre']) ? trim((string) $datos['nombre']) : '';

        if ($nombre === '') {
            return ['ok' => false, 'error' => 'Falta el nombre', 'id' => null];
        }

        if (mb_strlen($nombre) > 80) {
            return ['ok' => false, 'error' => 'El nombre es demasiado largo', 'id' => null];
        }

        $foto = isset($datos['foto_url']) ? trim((string) $datos['foto_url']) : '';

        // La foto se muestra en la página pública: sólo una dirección web.
        if ($foto !== '' && (!preg_match('#^https?://#i', $foto) || mb_strlen($foto) > 500)) {
            return ['ok' => false, 'error' => 'La foto tiene que ser una imagen subida o una dirección web', 'id' => null];
        }

        $valores = [
            $nombre,
            $foto === '' ? null : $foto,
            isset($datos['instagram']) ? self::usuarioDeInstagram($datos['instagram']) : null,
        ];

        if ($comedianteId) {
            $stmt = $db->prepare('SELECT user_id FROM carcajada_comediantes WHERE id = ?');
            $stmt->execute([(int) $comedianteId]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fila === false) {
                return ['ok' => false, 'error' => 'Ese comediante no existe', 'id' => null];
            }

            if ($fila['user_id'] !== null) {
                return ['ok' => false, 'error' => 'Tiene cuenta: sus datos los cambia desde su alta', 'id' => null];
            }

            $stmt = $db->prepare('UPDATE carcajada_comediantes SET nombre = ?, foto_url = ?, instagram = ? WHERE id = ?');
            $stmt->execute(array_merge($valores, [(int) $comedianteId]));

            return ['ok' => true, 'error' => null, 'id' => (int) $comedianteId];
        }

        $stmt = $db->prepare('
            INSERT INTO carcajada_comediantes (user_id, page_id, nombre, foto_url, instagram)
            VALUES (NULL, NULL, ?, ?, ?)
        ');
        $stmt->execute($valores);

        return ['ok' => true, 'error' => null, 'id' => (int) $db->lastInsertId()];
    }

    // ------------------------------------------------------------------ shows

    public static function ciclos($db)
    {
        $stmt = $db->query('SELECT id, nombre, slug FROM carcajada_ciclos ORDER BY nombre');

        return array_map(function ($fila) {
            return ['id' => (int) $fila['id'], 'nombre' => $fila['nombre'], 'slug' => $fila['slug']];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Los shows, del más nuevo al más viejo, con cuántos comediantes tienen. */
    public static function shows($db, $limite = 100)
    {
        $stmt = $db->prepare('
            SELECT s.id, s.fecha, s.hora, s.lugar, s.notas, s.ciclo_id,
                   ci.nombre AS ciclo, ci.slug AS ciclo_slug,
                   COUNT(l.id) AS comediantes,
                   COUNT(l.puntaje) AS evaluados
            FROM carcajada_shows s
            INNER JOIN carcajada_ciclos ci ON ci.id = s.ciclo_id
            LEFT JOIN carcajada_lineup l ON l.show_id = s.id
            GROUP BY s.id, s.fecha, s.hora, s.lugar, s.notas, s.ciclo_id, ci.nombre, ci.slug
            ORDER BY s.fecha DESC, s.id DESC
            LIMIT ' . (int) $limite . '
        ');
        $stmt->execute();

        return array_map(function ($fila) {
            return [
                'id'           => (int) $fila['id'],
                'ciclo_id'     => (int) $fila['ciclo_id'],
                'ciclo'        => $fila['ciclo'],
                'ciclo_slug'   => $fila['ciclo_slug'],
                'fecha'        => $fila['fecha'],
                'hora'         => $fila['hora'],
                'lugar'        => $fila['lugar'],
                'notas'        => $fila['notas'],
                'comediantes'  => (int) $fila['comediantes'],
                'evaluados'    => (int) $fila['evaluados'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array $datos ['ciclo_id', 'fecha', 'hora', 'lugar', 'notas']
     * @return array{ok: bool, error: string|null, id: int|null}
     */
    public static function guardarShow($db, $showId, array $datos)
    {
        $cicloId = isset($datos['ciclo_id']) ? (int) $datos['ciclo_id'] : 0;
        $fecha = isset($datos['fecha']) ? trim((string) $datos['fecha']) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return ['ok' => false, 'error' => 'Falta la fecha del show', 'id' => null];
        }

        if ($cicloId < 1) {
            return ['ok' => false, 'error' => 'Elegí el ciclo', 'id' => null];
        }

        $hora = isset($datos['hora']) && $datos['hora'] !== '' ? substr($datos['hora'], 0, 8) : null;
        $lugar = isset($datos['lugar']) && $datos['lugar'] !== '' ? mb_substr($datos['lugar'], 0, 255) : null;
        $notas = isset($datos['notas']) && $datos['notas'] !== '' ? $datos['notas'] : null;

        if ($showId) {
            $stmt = $db->prepare('
                UPDATE carcajada_shows SET ciclo_id = ?, fecha = ?, hora = ?, lugar = ?, notas = ?
                WHERE id = ?
            ');
            $stmt->execute([$cicloId, $fecha, $hora, $lugar, $notas, (int) $showId]);

            return ['ok' => true, 'error' => null, 'id' => (int) $showId];
        }

        $stmt = $db->prepare('
            INSERT INTO carcajada_shows (ciclo_id, fecha, hora, lugar, notas) VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$cicloId, $fecha, $hora, $lugar, $notas]);

        return ['ok' => true, 'error' => null, 'id' => (int) $db->lastInsertId()];
    }

    /**
     * La descripción pública del show. Se guarda ya limpia: lo que llega del
     * editor lo puede mandar cualquiera, y se muestra en una página abierta.
     */
    public static function guardarDescripcion($db, $showId, $html)
    {
        $stmt = $db->prepare('UPDATE carcajada_shows SET descripcion = ? WHERE id = ?');
        $stmt->execute([HtmlSimple::limpiar($html), (int) $showId]);
    }

    public static function borrarShow($db, $showId)
    {
        $stmt = $db->prepare('DELETE FROM carcajada_shows WHERE id = ?');
        $stmt->execute([(int) $showId]);
    }

    /** Un show con su lineup, para armarlo y para evaluarlo. */
    public static function show($db, $showId)
    {
        $stmt = $db->prepare('
            SELECT s.id, s.fecha, s.hora, s.lugar, s.descripcion, s.notas, s.ciclo_id,
                   ci.nombre AS ciclo, ci.slug AS ciclo_slug
            FROM carcajada_shows s
            INNER JOIN carcajada_ciclos ci ON ci.id = s.ciclo_id
            WHERE s.id = ?
        ');
        $stmt->execute([(int) $showId]);
        $show = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($show === false) {
            return null;
        }

        $show['id'] = (int) $show['id'];
        $show['ciclo_id'] = (int) $show['ciclo_id'];
        $show['lineup'] = self::lineup($db, $showId);

        return $show;
    }

    /** Quiénes se presentan, en orden. */
    public static function lineup($db, $showId)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.comediante_id, l.orden, l.puntaje, l.personas_traidas, l.comentario, l.evaluado_en,
                   c.user_id, c.nombre, c.foto_url, c.instagram, c.personas_comprometidas,
                   p.url_slug, p.title AS pagina
            FROM carcajada_lineup l
            INNER JOIN carcajada_comediantes c ON c.id = l.comediante_id
            LEFT JOIN pages p ON p.id = c.page_id
            WHERE l.show_id = ?
            ORDER BY l.orden, l.id
        ');
        $stmt->execute([(int) $showId]);

        return array_map(function ($fila) {
            return [
                'id'              => (int) $fila['id'],
                'comediante_id'   => (int) $fila['comediante_id'],
                'orden'           => (int) $fila['orden'],
                'nombre'          => $fila['nombre'],
                'foto_url'        => $fila['foto_url'],
                'instagram'       => $fila['instagram'],
                'url_slug'        => $fila['url_slug'],
                'pagina'          => $fila['pagina'],
                'con_cuenta'      => $fila['user_id'] !== null,
                'comprometidas'   => (int) $fila['personas_comprometidas'],
                'puntaje'         => $fila['puntaje'] === null ? null : (int) $fila['puntaje'],
                'personas_traidas' => $fila['personas_traidas'] === null ? null : (int) $fila['personas_traidas'],
                'comentario'      => $fila['comentario'],
                'evaluado_en'     => $fila['evaluado_en'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Suma a alguien al show. Repetirlo no lo agrega dos veces.
     *
     * @return array{ok: bool, error: string|null}
     */
    public static function sumarAlLineup($db, $showId, $comedianteId)
    {
        $stmt = $db->prepare('SELECT 1 FROM carcajada_comediantes WHERE id = ?');
        $stmt->execute([(int) $comedianteId]);

        if ($stmt->fetchColumn() === false) {
            return ['ok' => false, 'error' => 'Ese comediante no existe'];
        }

        $orden = $db->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM carcajada_lineup WHERE show_id = ?');
        $orden->execute([(int) $showId]);

        $stmt = $db->prepare('
            INSERT INTO carcajada_lineup (show_id, comediante_id, orden) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE orden = orden
        ');
        $stmt->execute([(int) $showId, (int) $comedianteId, (int) $orden->fetchColumn()]);

        return ['ok' => true, 'error' => null];
    }

    public static function sacarDelLineup($db, $showId, $comedianteId)
    {
        $stmt = $db->prepare('DELETE FROM carcajada_lineup WHERE show_id = ? AND comediante_id = ?');
        $stmt->execute([(int) $showId, (int) $comedianteId]);
    }

    /** El orden en que suben, tal como quedó en la lista. */
    public static function ordenar($db, $showId, array $comedianteIds)
    {
        $stmt = $db->prepare('UPDATE carcajada_lineup SET orden = ? WHERE show_id = ? AND comediante_id = ?');

        foreach (array_values($comedianteIds) as $posicion => $comedianteId) {
            $stmt->execute([$posicion + 1, (int) $showId, (int) $comedianteId]);
        }
    }

    /**
     * Cómo le fue a alguien esa noche.
     *
     * @param array $datos ['puntaje', 'personas_traidas', 'comentario']
     * @return array{ok: bool, error: string|null}
     */
    public static function evaluar($db, $showId, $comedianteId, array $datos)
    {
        $puntaje = isset($datos['puntaje']) && $datos['puntaje'] !== '' ? (int) $datos['puntaje'] : null;
        $traidas = isset($datos['personas_traidas']) && $datos['personas_traidas'] !== ''
            ? (int) $datos['personas_traidas']
            : null;

        if ($puntaje !== null && ($puntaje < 1 || $puntaje > self::PUNTAJE_MAXIMO)) {
            return ['ok' => false, 'error' => 'El puntaje va de 1 a ' . self::PUNTAJE_MAXIMO];
        }

        if ($traidas !== null && ($traidas < 0 || $traidas > self::MAX_PERSONAS)) {
            return ['ok' => false, 'error' => 'La cantidad de gente no parece real'];
        }

        $comentario = isset($datos['comentario']) && $datos['comentario'] !== ''
            ? mb_substr((string) $datos['comentario'], 0, 500)
            : null;

        // evaluado_en queda en NULL si se borró todo: así la ficha distingue
        // "no se evaluó" de "se evaluó y no trajo a nadie".
        $sinDatos = $puntaje === null && $traidas === null && $comentario === null;

        $stmt = $db->prepare('
            UPDATE carcajada_lineup
            SET puntaje = ?, personas_traidas = ?, comentario = ?, evaluado_en = ' . ($sinDatos ? 'NULL' : 'NOW()') . '
            WHERE show_id = ? AND comediante_id = ?
        ');
        $stmt->execute([$puntaje, $traidas, $comentario, (int) $showId, (int) $comedianteId]);

        return ['ok' => true, 'error' => null];
    }

    // ----------------------------------------------------------------- público

    /** Cuántas otras fechas se muestran debajo del show. */
    const OTRAS_FECHAS = 6;

    /**
     * El show que hay que mostrarle al público ahora.
     *
     * Es el del día; si hoy no hay, el próximo. Un QR impreso una vez tiene
     * que seguir sirviendo la fecha que viene, así que esto no puede depender
     * de que alguien cambie el cartel.
     */
    public static function showEnCurso($db)
    {
        $stmt = $db->prepare('
            SELECT s.id
            FROM carcajada_shows s
            WHERE s.fecha >= ?
            ORDER BY s.fecha, s.id
            LIMIT 1
        ');
        $stmt->execute([Fechas::hoy()]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : self::showPublico($db, (int) $id);
    }

    /**
     * Un show tal como lo ve el público: la descripción, quiénes se presentan
     * y las próximas fechas de Carcajada. null si no existe.
     */
    public static function showPublico($db, $showId)
    {
        $show = self::show($db, $showId);

        if ($show === null) {
            return null;
        }

        return [
            'show' => [
                'id'          => $show['id'],
                'ciclo'       => $show['ciclo'],
                'fecha'       => $show['fecha'],
                'hora'        => $show['hora'],
                'lugar'       => $show['lugar'],
                'descripcion' => $show['descripcion'],
                'es_hoy'      => $show['fecha'] === Fechas::hoy(),
            ],
            // Sólo lo que el público necesita para encontrar a alguien después
            // del show: cómo se llama, la cara y a dónde seguirlo. Nada de lo
            // que se anota en su ficha.
            'comediantes' => array_map(function ($c) {
                return [
                    'nombre'    => $c['nombre'],
                    'foto_url'  => $c['foto_url'],
                    'instagram' => $c['instagram'],
                    'url_slug'  => $c['url_slug'],
                ];
            }, $show['lineup']),
            'otras' => self::proximasFechas($db, $show['id']),
        ];
    }

    /** Las fechas que vienen, sin la que se está mirando. */
    public static function proximasFechas($db, $exceptoShowId)
    {
        $stmt = $db->prepare('
            SELECT s.id, s.fecha, s.hora, s.lugar, ci.nombre AS ciclo
            FROM carcajada_shows s
            INNER JOIN carcajada_ciclos ci ON ci.id = s.ciclo_id
            WHERE s.fecha >= ? AND s.id <> ?
            ORDER BY s.fecha, s.hora, s.id
            LIMIT ' . self::OTRAS_FECHAS . '
        ');
        $stmt->execute([Fechas::hoy(), (int) $exceptoShowId]);

        return array_map(function ($fila) {
            return [
                'id'    => (int) $fila['id'],
                'ciclo' => $fila['ciclo'],
                'fecha' => $fila['fecha'],
                'hora'  => $fila['hora'],
                'lugar' => $fila['lugar'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ---------------------------------------------------------------- internos

    private static function comediantePublico(array $fila)
    {
        return [
            'id'            => (int) $fila['id'],
            'page_id'       => $fila['page_id'] === null ? null : (int) $fila['page_id'],
            'nombre'        => $fila['nombre'],
            'foto_url'      => $fila['foto_url'],
            'instagram'     => $fila['instagram'],
            'comprometidas' => (int) $fila['personas_comprometidas'],
            'estudio_con'   => $fila['estudio_con'],
            'egreso_anio'   => $fila['egreso_anio'] === null ? null : (int) $fila['egreso_anio'],
            'material'      => $fila['material'],
            'url_slug'      => isset($fila['url_slug']) ? $fila['url_slug'] : null,
            'pagina'        => isset($fila['pagina']) ? $fila['pagina'] : null,
        ];
    }

    private static function paginaDelUsuario($db, $pageId, $userId)
    {
        if ($pageId < 1) {
            return false;
        }

        $stmt = $db->prepare('SELECT 1 FROM pages WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $pageId, (int) $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * El usuario de Instagram, venga como venga.
     *
     * Se guarda sin arroba y sin dirección: en la pantalla del público se arma
     * el link, y si cada uno lo pega a su manera la mitad quedan rotos.
     */
    public static function usuarioDeInstagram($valor)
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $texto, $m)) {
            $texto = $m[1];
        }

        $usuario = ltrim($texto, '@');
        $usuario = preg_replace('/[^A-Za-z0-9._]/', '', $usuario);

        return $usuario === '' ? null : mb_substr($usuario, 0, 60);
    }
}
