<?php

/**
 * "También te puede interesar": hasta tres eventos o productos para mostrar
 * debajo del detalle de un evento o de un producto (#30).
 *
 * X es lo que se está viendo; V, la página donde se lo ve; O, la página dueña
 * de X. Los lugares se llenan por niveles y se pasa al siguiente sólo si
 * quedan libres:
 *
 * 1. Productos de V.
 * 2. Otros eventos de V: los propios y los colaborados aceptados que muestra.
 * 3. Productos de O, si O ≠ V.
 * 4. Eventos de O, si O ≠ V.
 * 5. Productos y eventos de las páginas colaboradoras de X (sólo eventos).
 * 6. Si no salió nada, eventos cercanos y próximos en fecha.
 *
 * Se corta apenas hay tres: este endpoint se llama en cada detalle, y los
 * niveles siguientes no se calculan si no hacen falta. Por lo mismo, la
 * disponibilidad de entradas se pide sólo para los eventos candidatos.
 */
class Sugerencias
{
    const CANTIDAD = 3;

    /** Cuántos eventos se traen por consulta: algunos pueden estar agotados. */
    const LOTE = 10;

    /** Radio del primer círculo del nivel 6, en kilómetros. */
    const RADIO_CERCANO_KM = 30;

    /**
     * Las sugerencias para X, o null si X no existe.
     *
     * @param string   $tipo     'evento' o 'producto'
     * @param int      $id       id del link de X
     * @param int|null $paginaId la página donde se ve X; se ignora si no la muestra
     * @return array[]|null
     */
    public static function para($db, $tipo, $id, $paginaId = null)
    {
        $x = self::cargar($db, $tipo, $id);

        if ($x === null) {
            return null;
        }

        $duena = (int) $x['page_id'];
        $vista = $duena;

        if ($tipo === 'evento' && $paginaId && (int) $paginaId !== $duena && self::colaboraEn($db, $id, $paginaId)) {
            $vista = (int) $paginaId;
        }

        $sugerencias = new self($db, (int) $id);

        $niveles = [
            function ($s) use ($vista) { $s->productosDe([$vista]); },
            function ($s) use ($vista) { $s->eventosDe([$vista]); },
        ];

        if ($duena !== $vista) {
            $niveles[] = function ($s) use ($duena) { $s->productosDe([$duena]); };
            $niveles[] = function ($s) use ($duena) { $s->eventosDe([$duena]); };
        }

        if ($tipo === 'evento') {
            $niveles[] = function ($s) use ($db, $id, $vista, $duena) {
                $colaboradoras = array_values(array_diff(self::colaboradorasDe($db, $id), [$vista, $duena]));

                if ($colaboradoras !== []) {
                    $s->productosDe($colaboradoras);
                    $s->eventosDe($colaboradoras);
                }
            };
        }

        foreach ($niveles as $nivel) {
            if ($sugerencias->completa()) {
                break;
            }
            $nivel($sugerencias);
        }

        if ($sugerencias->items === []) {
            $sugerencias->cercanos(self::referencia($db, $x, $vista, $duena));
        }

        return $sugerencias->items;
    }

    // ------------------------------------------------------------ X y páginas

    /** El link de X con su página dueña y sus coordenadas, o null. */
    private static function cargar($db, $tipo, $id)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.event_latitude, l.event_longitude, lg.page_id
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ? AND lg.type = ?
        ');
        $stmt->execute([(int) $id, $tipo === 'evento' ? 'eventos' : 'productos']);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /** true si la página muestra el evento como colaboradora aceptada. */
    private static function colaboraEn($db, $linkId, $pageId)
    {
        $stmt = $db->prepare("
            SELECT 1
            FROM event_collaborations ec
            INNER JOIN link_groups cg ON cg.id = ec.collaborator_group_id
            WHERE ec.link_id = ? AND ec.status = 'accepted' AND cg.type = 'eventos' AND cg.page_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int) $linkId, (int) $pageId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Las páginas que muestran el evento como colaboradoras aceptadas, en el orden en que se sumaron. */
    private static function colaboradorasDe($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT cg.page_id
            FROM event_collaborations ec
            INNER JOIN link_groups cg ON cg.id = ec.collaborator_group_id
            WHERE ec.link_id = ? AND ec.status = 'accepted' AND cg.type = 'eventos'
            ORDER BY ec.id
        ");
        $stmt->execute([(int) $linkId]);

        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * El punto desde el que se buscan eventos cercanos: las coordenadas de X,
     * o las del próximo evento de V con coordenadas, o las del próximo de O.
     *
     * @return float[]|null [latitud, longitud]
     */
    private static function referencia($db, array $x, $vista, $duena)
    {
        if (self::tieneCoordenadas($x)) {
            return [(float) $x['event_latitude'], (float) $x['event_longitude']];
        }

        foreach (array_unique([$vista, $duena]) as $pageId) {
            list($condicion, $params) = self::eventosDeLasPaginas([$pageId]);

            $stmt = $db->prepare('
                SELECT l.event_latitude, l.event_longitude
                FROM links l
                INNER JOIN link_groups lg ON lg.id = l.group_id
                WHERE ' . $condicion . '
                    AND l.event_latitude IS NOT NULL AND l.event_longitude IS NOT NULL
                ORDER BY l.event_date, l.event_time, l.id
                LIMIT 1
            ');
            $stmt->execute($params);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fila) {
                return [(float) $fila['event_latitude'], (float) $fila['event_longitude']];
            }
        }

        return null;
    }

    private static function tieneCoordenadas(array $fila)
    {
        return isset($fila['event_latitude'], $fila['event_longitude'])
            && $fila['event_latitude'] !== '' && $fila['event_longitude'] !== '';
    }

    // ------------------------------------------------------------- consultas

    /**
     * Los eventos vigentes de estas páginas: los propios y los colaborados
     * aceptados que muestran. Sobre los alias l y lg (el grupo del link).
     *
     * Vigente es lo mismo que en la página pública (event_date >= hoy).
     *
     * @return array{0: string, 1: array} condición y parámetros
     */
    private static function eventosDeLasPaginas(array $pageIds)
    {
        $marcas = self::marcas($pageIds);

        $condicion = "lg.type = 'eventos' AND l.event_date >= ? AND (
            lg.page_id IN ($marcas)
            OR EXISTS (
                SELECT 1
                FROM event_collaborations ec
                INNER JOIN link_groups cg ON cg.id = ec.collaborator_group_id
                WHERE ec.link_id = l.id AND ec.status = 'accepted' AND cg.type = 'eventos'
                    AND cg.page_id IN ($marcas)
            )
        )";

        return [$condicion, array_merge([Fechas::hoy()], $pageIds, $pageIds)];
    }

    private static function marcas(array $valores)
    {
        return implode(', ', array_fill(0, count($valores), '?'));
    }

    // ---------------------------------------------------------------- estado

    private $db;

    /** @var array<int, true> ids que ya no se pueden sugerir: X y los elegidos */
    private $vistos = [];

    /** @var array[] */
    private $items = [];

    private function __construct($db, $excluido)
    {
        $this->db = $db;
        $this->vistos[$excluido] = true;
    }

    private function completa()
    {
        return count($this->items) >= self::CANTIDAD;
    }

    private function faltan()
    {
        return self::CANTIDAD - count($this->items);
    }

    /** Productos en venta de estas páginas, en el orden de cada página. */
    private function productosDe(array $pageIds)
    {
        if ($this->completa()) {
            return;
        }

        $marcas = self::marcas($pageIds);

        // Alcanza con pedir lo que falta más lo ya visto: un producto en venta
        // siempre se puede sugerir, así que sólo pueden sobrar los repetidos.
        $limite = $this->faltan() + count($this->vistos);

        $stmt = $this->db->prepare("
            SELECT l.id, l.text, l.image_url, COALESCE(dp.precio, 0) AS precio, p.title AS page_title
            FROM links l
            INNER JOIN digital_products dp ON dp.link_id = l.id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE lg.type = 'productos' AND lg.page_id IN ($marcas) AND " . ProductosDigitales::CONDICION_EN_VENTA . "
            ORDER BY FIELD(lg.page_id, $marcas), lg.position, lg.id, l.position, l.id
            LIMIT " . (int) $limite
        );
        $stmt->execute(array_merge($pageIds, $pageIds));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if ($this->completa()) {
                return;
            }

            if (!isset($this->vistos[(int) $fila['id']])) {
                $this->agregar(self::producto($fila));
            }
        }
    }

    /** Eventos vigentes y no agotados de estas páginas, el más próximo primero. */
    private function eventosDe(array $pageIds)
    {
        list($condicion, $params) = self::eventosDeLasPaginas($pageIds);

        $this->eventos(
            'SELECT ' . self::COLUMNAS_DE_EVENTO . '
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE ' . $condicion . '
            ORDER BY l.event_date, l.event_time, l.id',
            $params
        );
    }

    /**
     * Nivel 6: eventos vigentes de toda la plataforma. Con un punto de
     * referencia, primero los que están a menos de RADIO_CERCANO_KM por
     * fecha, y después el resto por distancia. Sin punto, por fecha.
     *
     * @param float[]|null $punto [latitud, longitud]
     */
    private function cercanos($punto)
    {
        $base = 'FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE lg.type = \'eventos\' AND l.event_date >= ?';

        if ($punto === null) {
            $this->eventos(
                'SELECT ' . self::COLUMNAS_DE_EVENTO . ' ' . $base . ' ORDER BY l.event_date, l.event_time, l.id',
                [Fechas::hoy()]
            );
            return;
        }

        // Haversine. El LEAST evita un ASIN de apenas más de 1 por redondeo,
        // que da NULL. Un evento sin coordenadas tiene distancia NULL.
        $distancia = '(' . Geo::EARTH_RADIUS_KM . ' * 2 * ASIN(LEAST(1, SQRT(
            POW(SIN(RADIANS(l.event_latitude - ?) / 2), 2)
            + COS(RADIANS(?)) * COS(RADIANS(l.event_latitude)) * POW(SIN(RADIANS(l.event_longitude - ?) / 2), 2)
        ))))';

        list($lat, $lng) = $punto;
        $params = [$lat, $lat, $lng, Fechas::hoy()];

        $this->eventos(
            'SELECT ' . self::COLUMNAS_DE_EVENTO . ', ' . $distancia . ' AS distancia ' . $base . '
            HAVING distancia <= ' . self::RADIO_CERCANO_KM . '
            ORDER BY l.event_date, l.event_time, l.id',
            $params
        );

        $this->eventos(
            'SELECT ' . self::COLUMNAS_DE_EVENTO . ', ' . $distancia . ' AS distancia ' . $base . '
            HAVING distancia IS NULL OR distancia > ' . self::RADIO_CERCANO_KM . '
            ORDER BY distancia IS NULL, distancia, l.event_date, l.event_time, l.id',
            $params
        );
    }

    const COLUMNAS_DE_EVENTO = 'l.id, l.text, l.image_url, l.event_date, l.event_time, p.title AS page_title';

    /**
     * Recorre los eventos de la consulta de a LOTE y suma los que se pueden
     * sugerir, hasta completar o quedarse sin eventos. La disponibilidad se
     * pide de a uno y sólo hasta completar.
     */
    private function eventos($sql, array $params)
    {
        for ($desde = 0; !$this->completa(); $desde += self::LOTE) {
            $stmt = $this->db->prepare($sql . ' LIMIT ' . self::LOTE . ' OFFSET ' . (int) $desde);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($filas as $fila) {
                if ($this->completa()) {
                    return;
                }

                $id = (int) $fila['id'];

                if (isset($this->vistos[$id])) {
                    continue;
                }

                if (self::agotado($this->db, $id)) {
                    // Agotado hoy, agotado en el resto de los niveles: no se
                    // vuelve a pedir su disponibilidad.
                    $this->vistos[$id] = true;
                    continue;
                }

                $this->agregar(self::evento($fila));
            }

            if (count($filas) < self::LOTE) {
                return;
            }
        }
    }

    /** Un evento sin venta de entradas no está agotado. */
    private static function agotado($db, $linkId)
    {
        $disponibilidad = Entradas::disponibilidad($db, $linkId);

        return $disponibilidad !== null && !empty($disponibilidad['agotado']);
    }

    private function agregar(array $item)
    {
        $this->vistos[$item['id']] = true;
        $this->items[] = $item;
    }

    // ------------------------------------------------------------- respuesta

    /**
     * Lo único que sale de cada sugerencia. Es una lista cerrada: ni la ruta
     * del archivo de un producto ni nada de sus ventas.
     */
    private static function evento(array $fila)
    {
        return [
            'tipo'       => 'evento',
            'id'         => (int) $fila['id'],
            'text'       => $fila['text'],
            'image_url'  => $fila['image_url'],
            'event_date' => $fila['event_date'],
            'event_time' => $fila['event_time'],
            'page_title' => $fila['page_title'],
        ];
    }

    private static function producto(array $fila)
    {
        return [
            'tipo'       => 'producto',
            'id'         => (int) $fila['id'],
            'text'       => $fila['text'],
            'image_url'  => $fila['image_url'],
            'precio'     => round((float) $fila['precio'], 2),
            'page_title' => $fila['page_title'],
        ];
    }
}
