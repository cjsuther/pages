<?php

/**
 * Los clientes de una página: quién le compró, reservó o la sigue.
 *
 * Una compra ya no depende de que el evento exista. Cada evento con entradas
 * tiene un registro (event_records) que guarda título, fecha y lugar, y que
 * queda cuando el evento se borra; las compras cuelgan de ese registro. Qué
 * páginas ven a esos clientes lo dice event_record_pages: la que organiza y
 * las que colaboran.
 *
 * Esa lista de páginas se mantiene al día mientras el evento existe —aceptar o
 * deshacer una colaboración la cambia— y se congela cuando el evento se borra:
 * a partir de ahí nadie puede sumar ni sacar una colaboración, así que queda
 * como estaba en ese momento.
 *
 * La persona se identifica por su email. No hay otro dato en común entre una
 * compra sin cuenta y un usuario que sigue la página, y quien compra dos veces
 * con el mismo email es, para quien organiza, el mismo cliente.
 */
class Clientes
{
    const ORIGENES = ['compro', 'reservo', 'sigue'];
    const ASISTENCIAS = ['vino', 'no_vino'];
    const CUENTAS = ['si', 'no'];

    // ------------------------------------------------------------ registro

    /**
     * Crea o refresca el registro del evento y devuelve su id.
     *
     * Se llama al vender y al aceptar una colaboración: son los momentos a
     * partir de los cuales hay algo que conservar. null si el evento no existe.
     */
    public static function registrarEvento($db, $linkId)
    {
        $stmt = $db->prepare('
            INSERT INTO event_records (link_id, page_id, titulo, event_date, event_time, event_address)
            SELECT l.id, lg.page_id, l.text, l.event_date, l.event_time, l.event_address
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ?
            ON DUPLICATE KEY UPDATE
                page_id = VALUES(page_id),
                titulo = VALUES(titulo),
                event_date = VALUES(event_date),
                event_time = VALUES(event_time),
                event_address = VALUES(event_address)
        ');
        $stmt->execute([(int) $linkId]);

        $recordId = self::registroDelEvento($db, $linkId);

        if ($recordId !== null) {
            self::sincronizarPaginas($db, $recordId, $linkId);
        }

        return $recordId;
    }

    /**
     * Refresca el registro si el evento ya tiene uno; si no, no hace nada.
     *
     * Es lo que se llama al editar un evento, al deshacer una colaboración y
     * justo antes de borrarlo: que el registro guarde el último título y la
     * última lista de páginas. Un evento que nunca vendió ni tuvo
     * colaboraciones no necesita registro.
     */
    public static function actualizarEvento($db, $linkId)
    {
        if (self::registroDelEvento($db, $linkId) === null) {
            return;
        }

        self::registrarEvento($db, $linkId);
    }

    /** Lo mismo para todos los eventos de un grupo, antes de borrarlo. */
    public static function actualizarEventosDelGrupo($db, $groupId)
    {
        $stmt = $db->prepare('
            SELECT r.link_id
            FROM event_records r
            INNER JOIN links l ON l.id = r.link_id
            WHERE l.group_id = ?
        ');
        $stmt->execute([(int) $groupId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $linkId) {
            self::registrarEvento($db, (int) $linkId);
        }
    }

    public static function registroDelEvento($db, $linkId)
    {
        $stmt = $db->prepare('SELECT id FROM event_records WHERE link_id = ?');
        $stmt->execute([(int) $linkId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Deja en event_record_pages la organizadora y las colaboraciones
     * aceptadas hoy. Las que se deshicieron se van: dejan de ver a los
     * clientes del evento.
     */
    private static function sincronizarPaginas($db, $recordId, $linkId)
    {
        $stmt = $db->prepare("
            INSERT IGNORE INTO event_record_pages (record_id, page_id, rol)
            SELECT id, page_id, 'organizador' FROM event_records
            WHERE id = ? AND page_id IS NOT NULL
        ");
        $stmt->execute([$recordId]);

        $stmt = $db->prepare("
            INSERT IGNORE INTO event_record_pages (record_id, page_id, rol)
            SELECT ?, collaborator_page_id, 'colaborador' FROM event_collaborations
            WHERE link_id = ? AND status = 'accepted'
        ");
        $stmt->execute([$recordId, (int) $linkId]);

        $stmt = $db->prepare("
            DELETE FROM event_record_pages
            WHERE record_id = ? AND rol = 'colaborador'
              AND page_id NOT IN (
                  SELECT collaborator_page_id FROM event_collaborations
                  WHERE link_id = ? AND status = 'accepted'
              )
        ");
        $stmt->execute([$recordId, (int) $linkId]);
    }

    // ------------------------------------------------------------- listado

    /**
     * Los clientes de una página, uno por persona.
     *
     * Los filtros de evento y fechas recortan también las participaciones: con
     * un evento elegido, cada cliente muestra lo que hizo en ese evento. Los
     * otros filtros sólo eligen personas.
     *
     * @param array $filtros q, evento, desde, hasta, origen, asistencia, cuenta
     * @return array{clientes: array[], eventos: array[], resumen: array}
     */
    public static function dePagina($db, $pageId, array $filtros = [])
    {
        $filtros = self::filtrosValidos($filtros);
        $conRecorte = $filtros['evento'] || $filtros['desde'] !== '' || $filtros['hasta'] !== '';

        $clientes = [];

        foreach (self::compras($db, $pageId) as $compra) {
            if (!self::entraEnElRecorte($compra, $filtros)) {
                continue;
            }

            $clave = self::clave($compra['email']);

            if ($clave === '') {
                continue;
            }

            if (!isset($clientes[$clave])) {
                $clientes[$clave] = self::clienteVacio($compra['email']);
            }

            self::sumarCompra($clientes[$clave], $compra);
        }

        // Quien sólo sigue la página no tiene compras que recortar: con un
        // evento o unas fechas elegidas, no entra.
        foreach (self::seguidores($db, $pageId) as $seguidor) {
            $clave = self::clave($seguidor['email']);

            if ($clave === '' || ($conRecorte && !isset($clientes[$clave]))) {
                continue;
            }

            if (!isset($clientes[$clave])) {
                $clientes[$clave] = self::clienteVacio($seguidor['email']);
            }

            self::sumarSeguidor($clientes[$clave], $seguidor);
        }

        $clientes = array_values(array_filter(array_map([self::class, 'cerrar'], $clientes), function ($c) use ($filtros) {
            return self::pasaLosFiltros($c, $filtros);
        }));

        usort($clientes, function ($a, $b) {
            return strcmp((string) $b['ultima_actividad'], (string) $a['ultima_actividad']);
        });

        return [
            'clientes' => $clientes,
            'eventos'  => self::eventos($db, $pageId),
            'resumen'  => self::resumen($clientes),
        ];
    }

    /**
     * Los eventos de la página que tienen compras, borrados incluidos. Es la
     * lista del filtro por evento.
     */
    public static function eventos($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT r.id, r.titulo, r.event_date, r.event_time, r.link_id, rp.rol,
                   (SELECT COUNT(*) FROM ticket_orders o WHERE o.record_id = r.id) AS compras
            FROM event_record_pages rp
            INNER JOIN event_records r ON r.id = rp.record_id
            WHERE rp.page_id = ?
              AND EXISTS (SELECT 1 FROM ticket_orders o WHERE o.record_id = r.id)
            ORDER BY r.event_date DESC, r.id DESC
        ');
        $stmt->execute([(int) $pageId]);

        return array_map(function ($e) {
            return [
                'id'         => (int) $e['id'],
                'titulo'     => $e['titulo'],
                'event_date' => $e['event_date'],
                'event_time' => $e['event_time'],
                'eliminado'  => $e['link_id'] === null,
                'link_id'    => $e['link_id'] === null ? null : (int) $e['link_id'],
                'rol'        => $e['rol'],
                'compras'    => (int) $e['compras'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ---------------------------------------------------------------- excel

    /**
     * El listado en un Excel de dos hojas: una fila por persona, y una por
     * cada compra con el evento al que corresponde.
     */
    public static function excel(array $clientes)
    {
        $personas = [];
        $participaciones = [];

        foreach ($clientes as $c) {
            $personas[] = [
                $c['nombre'],
                $c['email'],
                $c['telefono'],
                $c['tiene_cuenta'] ? 'Sí' : 'No',
                $c['sigue'] ? 'Sí' : 'No',
                $c['eventos'],
                $c['compras'],
                $c['reservas'],
                $c['entradas'],
                $c['asistencias'],
                self::gastadoParaExcel($c['gastado']),
                $c['primera_actividad'],
                $c['ultima_actividad'],
            ];

            foreach ($c['participaciones'] as $p) {
                $participaciones[] = [
                    $c['nombre'],
                    $c['email'],
                    $p['evento'],
                    $p['event_date'],
                    $p['eliminado'] ? 'Sí' : 'No',
                    $p['rol'] === 'organizador' ? 'Organizó' : 'Colaboró',
                    $p['codigo'],
                    $p['estado'],
                    $p['cantidad'],
                    $p['ingresadas'],
                    $p['total'],
                    $p['moneda'],
                    $p['created_at'],
                ];
            }
        }

        return Excel::libro([
            [
                'nombre' => 'Clientes',
                'encabezados' => ['Nombre', 'Email', 'Teléfono', 'Cuenta en Rezonar', 'Sigue la página',
                    'Eventos', 'Compras', 'Reservas', 'Entradas', 'Eventos a los que vino', 'Gastado',
                    'Primera vez', 'Última vez'],
                'filas' => $personas,
            ],
            [
                'nombre' => 'Compras',
                'encabezados' => ['Nombre', 'Email', 'Evento', 'Fecha del evento', 'Evento eliminado',
                    'La página', 'Código', 'Estado', 'Entradas', 'Ingresaron', 'Total', 'Moneda', 'Fecha de compra'],
                'filas' => $participaciones,
            ],
        ]);
    }

    // ------------------------------------------------------------- internos

    private static function compras($db, $pageId)
    {
        // El usuario se busca por email: una compra no guarda quién la hizo,
        // y una persona puede haber comprado antes de crearse la cuenta.
        $stmt = $db->prepare("
            SELECT o.codigo, o.nombre, o.email, o.telefono, o.cantidad, o.ingresadas, o.ingreso_en,
                   o.total, o.moneda, o.estado, o.created_at, o.pagada_en,
                   (o.estado = 'reservada' AND o.reserva_vence_en <= NOW()) AS vencida,
                   r.id AS record_id, r.titulo, r.event_date, r.event_time, r.link_id,
                   rp.rol,
                   u.id AS user_id
            FROM event_record_pages rp
            INNER JOIN event_records r ON r.id = rp.record_id
            INNER JOIN ticket_orders o ON o.record_id = r.id
            LEFT JOIN users u ON u.email = o.email
            WHERE rp.page_id = ?
            ORDER BY o.created_at ASC, o.id ASC
        ");
        $stmt->execute([(int) $pageId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function seguidores($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT u.id AS user_id, u.email, u.name, pf.created_at
            FROM page_followers pf
            INNER JOIN users u ON u.id = pf.user_id
            WHERE pf.page_id = ?
        ');
        $stmt->execute([(int) $pageId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function clave($email)
    {
        return mb_strtolower(trim((string) $email));
    }

    private static function clienteVacio($email)
    {
        return [
            'email'             => trim((string) $email),
            'nombre'            => '',
            'telefono'          => '',
            'user_id'           => null,
            'tiene_cuenta'      => false,
            'sigue'             => false,
            'sigue_desde'       => null,
            'eventos'           => 0,
            'compras'           => 0,
            'reservas'          => 0,
            'entradas'          => 0,
            'asistencias'       => 0,
            'gastado'           => [],
            'primera_actividad' => null,
            'ultima_actividad'  => null,
            'participaciones'   => [],
            // Auxiliares, se sacan en cerrar().
            '_eventos'          => [],
            '_vino'             => [],
            '_falto'            => [],
        ];
    }

    private static function sumarCompra(array &$c, array $compra)
    {
        $estado = $compra['vencida'] ? 'vencida' : $compra['estado'];
        $confirmada = $estado === 'pagada';
        $total = (float) $compra['total'];
        $recordId = (int) $compra['record_id'];

        // Las compras vienen de la más vieja a la más nueva: el nombre y el
        // teléfono que quedan son los últimos que dio.
        if (trim((string) $compra['nombre']) !== '') {
            $c['nombre'] = trim($compra['nombre']);
        }

        if (trim((string) $compra['telefono']) !== '') {
            $c['telefono'] = trim($compra['telefono']);
        }

        if ($compra['user_id'] !== null) {
            $c['user_id'] = (int) $compra['user_id'];
            $c['tiene_cuenta'] = true;
        }

        $c['_eventos'][$recordId] = true;

        if ($confirmada) {
            if ($total > 0) {
                $c['compras']++;
                $moneda = $compra['moneda'] ?: 'ARS';
                $c['gastado'][$moneda] = round((isset($c['gastado'][$moneda]) ? $c['gastado'][$moneda] : 0) + $total, 2);
            } else {
                $c['reservas']++;
            }

            $c['entradas'] += (int) $compra['cantidad'];

            if ((int) $compra['ingresadas'] > 0) {
                $c['_vino'][$recordId] = true;
            } elseif (self::yaPaso($compra['event_date'])) {
                $c['_falto'][$recordId] = true;
            }
        }

        self::anotarActividad($c, $compra['created_at']);

        $c['participaciones'][] = [
            'record_id'  => $recordId,
            'evento'     => $compra['titulo'],
            'event_date' => $compra['event_date'],
            'event_time' => $compra['event_time'],
            'eliminado'  => $compra['link_id'] === null,
            'link_id'    => $compra['link_id'] === null ? null : (int) $compra['link_id'],
            'rol'        => $compra['rol'],
            'codigo'     => $compra['codigo'],
            'estado'     => $estado,
            'cantidad'   => (int) $compra['cantidad'],
            'ingresadas' => (int) $compra['ingresadas'],
            'ingreso_en' => $compra['ingreso_en'],
            'total'      => $total,
            'moneda'     => $compra['moneda'],
            'created_at' => $compra['created_at'],
        ];
    }

    private static function sumarSeguidor(array &$c, array $seguidor)
    {
        $c['sigue'] = true;
        $c['sigue_desde'] = $seguidor['created_at'];
        $c['user_id'] = (int) $seguidor['user_id'];
        $c['tiene_cuenta'] = true;

        if ($c['nombre'] === '' && trim((string) $seguidor['name']) !== '') {
            $c['nombre'] = trim($seguidor['name']);
        }

        self::anotarActividad($c, $seguidor['created_at']);
    }

    private static function anotarActividad(array &$c, $fecha)
    {
        if ($fecha === null || $fecha === '') {
            return;
        }

        if ($c['primera_actividad'] === null || $fecha < $c['primera_actividad']) {
            $c['primera_actividad'] = $fecha;
        }

        if ($c['ultima_actividad'] === null || $fecha > $c['ultima_actividad']) {
            $c['ultima_actividad'] = $fecha;
        }
    }

    private static function cerrar(array $c)
    {
        $c['eventos'] = count($c['_eventos']);
        $c['asistencias'] = count($c['_vino']);
        // Faltó a algún evento que ya pasó y no vino a ninguno: si vino a uno,
        // para el filtro cuenta como que vino.
        $c['falto'] = count($c['_falto']) > 0;
        $c['participaciones'] = array_reverse($c['participaciones']);

        unset($c['_eventos'], $c['_vino'], $c['_falto']);

        return $c;
    }

    private static function entraEnElRecorte(array $compra, array $filtros)
    {
        if ($filtros['evento'] && (int) $compra['record_id'] !== $filtros['evento']) {
            return false;
        }

        $fecha = (string) $compra['event_date'];

        if ($filtros['desde'] !== '' && ($fecha === '' || $fecha < $filtros['desde'])) {
            return false;
        }

        if ($filtros['hasta'] !== '' && ($fecha === '' || $fecha > $filtros['hasta'])) {
            return false;
        }

        return true;
    }

    private static function pasaLosFiltros(array $c, array $filtros)
    {
        if ($filtros['q'] !== '') {
            $texto = mb_strtolower($c['nombre'] . ' ' . $c['email'] . ' ' . $c['telefono']);

            if (mb_strpos($texto, $filtros['q']) === false) {
                return false;
            }
        }

        if ($filtros['origen'] === 'compro' && $c['compras'] === 0) {
            return false;
        }

        if ($filtros['origen'] === 'reservo' && $c['reservas'] === 0) {
            return false;
        }

        if ($filtros['origen'] === 'sigue' && !$c['sigue']) {
            return false;
        }

        if ($filtros['asistencia'] === 'vino' && $c['asistencias'] === 0) {
            return false;
        }

        if ($filtros['asistencia'] === 'no_vino' && ($c['asistencias'] > 0 || !$c['falto'])) {
            return false;
        }

        if ($filtros['cuenta'] === 'si' && !$c['tiene_cuenta']) {
            return false;
        }

        if ($filtros['cuenta'] === 'no' && $c['tiene_cuenta']) {
            return false;
        }

        return true;
    }

    /**
     * Un filtro que no se entiende se ignora en vez de dar error: son una
     * comodidad de la pantalla, y un valor viejo no debería dejarla vacía.
     */
    private static function filtrosValidos(array $filtros)
    {
        $valor = function ($clave) use ($filtros) {
            return isset($filtros[$clave]) ? trim((string) $filtros[$clave]) : '';
        };

        $fecha = function ($clave) use ($valor) {
            $v = $valor($clave);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        };

        $uno = function ($clave, array $validos) use ($valor) {
            $v = $valor($clave);
            return in_array($v, $validos, true) ? $v : '';
        };

        return [
            'q'          => mb_strtolower($valor('q')),
            'evento'     => (int) $valor('evento'),
            'desde'      => $fecha('desde'),
            'hasta'      => $fecha('hasta'),
            'origen'     => $uno('origen', self::ORIGENES),
            'asistencia' => $uno('asistencia', self::ASISTENCIAS),
            'cuenta'     => $uno('cuenta', self::CUENTAS),
        ];
    }

    private static function resumen(array $clientes)
    {
        $resumen = ['clientes' => count($clientes), 'con_cuenta' => 0, 'seguidores' => 0, 'compradores' => 0, 'vinieron' => 0];

        foreach ($clientes as $c) {
            $resumen['con_cuenta'] += $c['tiene_cuenta'] ? 1 : 0;
            $resumen['seguidores'] += $c['sigue'] ? 1 : 0;
            $resumen['compradores'] += ($c['compras'] + $c['reservas']) > 0 ? 1 : 0;
            $resumen['vinieron'] += $c['asistencias'] > 0 ? 1 : 0;
        }

        return $resumen;
    }

    private static function yaPaso($fecha)
    {
        return !empty($fecha) && $fecha !== '0000-00-00' && $fecha < date('Y-m-d');
    }

    /** Un número si gastó en una sola moneda; si no, "ARS 1500 · USD 20". */
    private static function gastadoParaExcel(array $gastado)
    {
        if (count($gastado) === 0) {
            return 0;
        }

        if (count($gastado) === 1) {
            return (float) reset($gastado);
        }

        $partes = [];

        foreach ($gastado as $moneda => $monto) {
            $partes[] = $moneda . ' ' . $monto;
        }

        return implode(' · ', $partes);
    }
}
