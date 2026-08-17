<?php

/**
 * Sincroniza la cartelera de una fuente con una página de Rezonar.
 *
 * Cada adaptador sabe leer un sitio y devuelve eventos con la misma forma; de
 * ahí en adelante el trabajo es el mismo para todos: crear la página si no
 * existe, dar de alta lo nuevo y actualizar lo que cambió.
 *
 * La regla que ordena el resto: el cron no pisa lo que una persona editó a
 * mano. Cada evento guarda qué campos se tocaron desde el editor, y esos
 * quedan congelados. Sin eso, corregir el título de un show importado duraría
 * hasta la madrugada siguiente.
 */
class Importador
{
    /** Campos del evento que el importador mantiene al día. */
    const CAMPOS = [
        'text', 'description', 'image_url', 'url',
        'event_date', 'event_time', 'event_address',
        'event_latitude', 'event_longitude', 'precio_desde',
    ];

    /** Se traen sólo eventos futuros: la cartelera vieja no aporta. */
    const DIAS_HACIA_ATRAS = 0;

    /**
     * Corre una fuente.
     *
     * @param array    $fuente    Fila de import_sources
     * @param callable $adaptador Recibe los parámetros y devuelve los eventos
     * @return array{ok: bool, creados: int, actualizados: int, error: string|null}
     */
    public static function sincronizar($db, array $fuente, $adaptador)
    {
        $resumen = ['ok' => false, 'creados' => 0, 'actualizados' => 0, 'error' => null];

        try {
            $parametros = empty($fuente['parametros']) ? [] : json_decode($fuente['parametros'], true);
            $eventos = call_user_func($adaptador, is_array($parametros) ? $parametros : []);
        } catch (Throwable $e) {
            $resumen['error'] = 'la fuente falló: ' . substr($e->getMessage(), 0, 180);
            self::anotarCorrida($db, $fuente['id'], $resumen);

            return $resumen;
        }

        if (!is_array($eventos)) {
            $resumen['error'] = 'la fuente no devolvió eventos';
            self::anotarCorrida($db, $fuente['id'], $resumen);

            return $resumen;
        }

        // Cero eventos casi siempre significa que el sitio cambió y el
        // adaptador dejó de encontrar nada, no que se hayan quedado sin
        // cartelera. Se avisa en vez de dar la corrida por buena.
        if (empty($eventos)) {
            $resumen['error'] = 'la fuente no devolvió ningún evento (¿cambió el sitio?)';
            self::anotarCorrida($db, $fuente['id'], $resumen);

            return $resumen;
        }

        $pageId = self::pagina($db, $fuente);
        $groupId = self::grupoDeEventos($db, $pageId);

        foreach ($eventos as $evento) {
            $problema = self::validar($evento);

            if ($problema !== null) {
                continue;
            }

            $existente = self::buscarExistente($db, $groupId, $fuente['adaptador'], $evento['id']);

            if ($existente === null) {
                self::crear($db, $groupId, $fuente['adaptador'], $evento);
                $resumen['creados']++;
            } elseif (self::actualizar($db, $existente, $evento)) {
                $resumen['actualizados']++;
            }
        }

        $resumen['ok'] = true;
        self::anotarCorrida($db, $fuente['id'], $resumen);

        return $resumen;
    }

    /**
     * Un evento sin fecha o sin coordenadas no se puede mostrar en Rezonar:
     * el mapa y el orden de la agenda dependen de eso.
     */
    public static function validar(array $evento)
    {
        if (empty($evento['id']) || empty($evento['titulo'])) {
            return 'sin id o sin título';
        }

        if (empty($evento['fecha']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $evento['fecha'])) {
            return 'sin fecha utilizable';
        }

        if ($evento['fecha'] < date('Y-m-d', strtotime('-' . self::DIAS_HACIA_ATRAS . ' days'))) {
            return 'ya pasó';
        }

        if (!isset($evento['latitud'], $evento['longitud'])
            || !is_numeric($evento['latitud']) || !is_numeric($evento['longitud'])) {
            return 'sin coordenadas';
        }

        return null;
    }

    // ------------------------------------------------------------- la página

    /** Página destino de la fuente; se crea en la primera corrida. */
    private static function pagina($db, array $fuente)
    {
        if (!empty($fuente['page_id'])) {
            $stmt = $db->prepare('SELECT id FROM pages WHERE id = ?');
            $stmt->execute([(int) $fuente['page_id']]);

            if ($stmt->fetchColumn() !== false) {
                return (int) $fuente['page_id'];
            }
        }

        // Puede existir de una corrida anterior en la que no se llegó a
        // guardar el page_id.
        $stmt = $db->prepare('SELECT id FROM pages WHERE url_slug = ?');
        $stmt->execute([$fuente['slug']]);
        $existente = $stmt->fetchColumn();

        if ($existente !== false) {
            self::asociarPagina($db, $fuente['id'], (int) $existente);

            return (int) $existente;
        }

        $stmt = $db->prepare('
            INSERT INTO pages (user_id, title, description, url_slug, template, origen)
            VALUES (?, ?, ?, ?, "cards", ?)
        ');
        $stmt->execute([
            (int) $fuente['user_id'],
            $fuente['nombre'],
            'Agenda actualizada automáticamente',
            $fuente['slug'],
            $fuente['adaptador'],
        ]);

        $pageId = (int) $db->lastInsertId();
        self::asociarPagina($db, $fuente['id'], $pageId);

        return $pageId;
    }

    private static function asociarPagina($db, $fuenteId, $pageId)
    {
        $stmt = $db->prepare('UPDATE import_sources SET page_id = ? WHERE id = ?');
        $stmt->execute([$pageId, (int) $fuenteId]);
    }

    /** Grupo de eventos de la página; se crea si no está. */
    private static function grupoDeEventos($db, $pageId)
    {
        $stmt = $db->prepare('SELECT id FROM link_groups WHERE page_id = ? AND type = "eventos" ORDER BY position, id LIMIT 1');
        $stmt->execute([(int) $pageId]);
        $existente = $stmt->fetchColumn();

        if ($existente !== false) {
            return (int) $existente;
        }

        $stmt = $db->prepare('INSERT INTO link_groups (page_id, title, type, position) VALUES (?, "Agenda", "eventos", 0)');
        $stmt->execute([(int) $pageId]);

        return (int) $db->lastInsertId();
    }

    // ------------------------------------------------------------ los eventos

    private static function buscarExistente($db, $groupId, $adaptador, $origenId)
    {
        $stmt = $db->prepare('SELECT * FROM links WHERE group_id = ? AND origen = ? AND origen_id = ?');
        $stmt->execute([(int) $groupId, $adaptador, (string) $origenId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    private static function crear($db, $groupId, $adaptador, array $evento)
    {
        $stmt = $db->prepare('
            INSERT INTO links
                (group_id, text, description, image_url, url, position,
                 event_date, event_time, event_address, event_latitude, event_longitude,
                 precio_desde, origen, origen_id)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            (int) $groupId,
            $evento['titulo'],
            isset($evento['descripcion']) ? $evento['descripcion'] : null,
            isset($evento['imagen']) ? $evento['imagen'] : null,
            isset($evento['url']) ? $evento['url'] : '',
            $evento['fecha'],
            isset($evento['hora']) ? $evento['hora'] : null,
            isset($evento['direccion']) ? $evento['direccion'] : null,
            $evento['latitud'],
            $evento['longitud'],
            isset($evento['precio_desde']) ? $evento['precio_desde'] : null,
            $adaptador,
            (string) $evento['id'],
        ]);
    }

    /**
     * Actualiza sólo lo que cambió y no está congelado por una edición manual.
     *
     * @return bool true si hubo algo que cambiar
     */
    private static function actualizar($db, array $existente, array $evento)
    {
        $editados = self::camposEditados($existente);
        $nuevos = self::aColumnas($evento);
        $cambios = [];

        foreach ($nuevos as $columna => $valor) {
            if (in_array($columna, $editados, true)) {
                continue;
            }

            // Comparación laxa a propósito: la base devuelve todo como texto,
            // así que 21:00:00 contra 21:00 o "0.00" contra 0 son iguales.
            if ((string) $existente[$columna] === (string) $valor) {
                continue;
            }

            $cambios[$columna] = $valor;
        }

        if (empty($cambios)) {
            return false;
        }

        $asignaciones = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($cambios)));
        $stmt = $db->prepare("UPDATE links SET $asignaciones WHERE id = ?");
        $stmt->execute(array_merge(array_values($cambios), [(int) $existente['id']]));

        return true;
    }

    /** Nombres de columna que el usuario tocó a mano. */
    public static function camposEditados(array $link)
    {
        if (empty($link['campos_editados'])) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $link['campos_editados']))));
    }

    /**
     * Anota que un campo se editó a mano, para que el cron no lo pise.
     * Lo llama el handler de links cuando alguien guarda desde el editor.
     */
    public static function marcarEditados($db, $linkId, array $campos)
    {
        $campos = array_values(array_intersect($campos, self::CAMPOS));

        if (empty($campos)) {
            return;
        }

        $stmt = $db->prepare('SELECT campos_editados, origen FROM links WHERE id = ?');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        // Un evento cargado a mano no tiene nada que proteger: no hay cron que
        // lo vaya a tocar.
        if ($fila === false || empty($fila['origen'])) {
            return;
        }

        $todos = array_values(array_unique(array_merge(self::camposEditados($fila), $campos)));

        $stmt = $db->prepare('UPDATE links SET campos_editados = ? WHERE id = ?');
        $stmt->execute([implode(',', $todos), (int) $linkId]);
    }

    /** Traduce el evento del adaptador a columnas de links. */
    private static function aColumnas(array $evento)
    {
        return [
            'text'            => $evento['titulo'],
            'description'     => isset($evento['descripcion']) ? $evento['descripcion'] : null,
            'image_url'       => isset($evento['imagen']) ? $evento['imagen'] : null,
            'url'             => isset($evento['url']) ? $evento['url'] : '',
            'event_date'      => $evento['fecha'],
            'event_time'      => isset($evento['hora']) ? $evento['hora'] : null,
            'event_address'   => isset($evento['direccion']) ? $evento['direccion'] : null,
            'event_latitude'  => $evento['latitud'],
            'event_longitude' => $evento['longitud'],
            'precio_desde'    => isset($evento['precio_desde']) ? $evento['precio_desde'] : null,
        ];
    }

    private static function anotarCorrida($db, $fuenteId, array $resumen)
    {
        $texto = $resumen['error'] !== null
            ? $resumen['error']
            : "{$resumen['creados']} nuevos, {$resumen['actualizados']} actualizados";

        $stmt = $db->prepare('
            UPDATE import_sources
            SET ultima_corrida = NOW(), ultimo_resultado = ?, eventos_importados = eventos_importados + ?
            WHERE id = ?
        ');
        $stmt->execute([substr($texto, 0, 255), $resumen['creados'], (int) $fuenteId]);
    }
}
