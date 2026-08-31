<?php

/**
 * Venta y reserva de entradas de un evento.
 *
 * La regla que ordena todo: el cupo se toma al crear la orden, no al pagarla.
 * Una orden 'reservada' ocupa lugar hasta que vence, así que dos personas que
 * compran a la vez las últimas entradas no pueden llevarse las mismas.
 *
 * Las reservas vencidas no necesitan que nadie las limpie: la consulta de
 * disponibilidad las ignora por fecha. Un proceso de limpieza sería una
 * segunda fuente de verdad que puede atrasarse, y el cupo quedaría mal
 * justamente mientras ese proceso no corre.
 */
class Entradas
{
    /**
     * Tope del listado de eventos con entradas.
     *
     * No es paginación: es una red. Una página con cientos de shows no puede
     * mandarlos todos de una, y para llegar a uno viejo está el buscador.
     */
    const LIMITE_EVENTOS = 200;

    /** Minutos que se sostiene el cupo mientras la persona paga. */
    const MINUTOS_DE_RESERVA = 15;

    const MAX_POR_COMPRA = 50;

    /** Configuración de venta de un evento, o null si no tiene. */
    public static function configDelEvento($db, $linkId)
    {
        $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ?');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /**
     * Guarda la configuración de venta de un evento.
     *
     * @param array $datos ['activo', 'capacidad', 'precio', 'moneda', 'max_por_compra']
     * @return array{ok: bool, error: string|null}
     */
    public static function guardarConfig($db, $linkId, array $datos)
    {
        $capacidad = isset($datos['capacidad']) ? (int) $datos['capacidad'] : 0;
        $precio = isset($datos['precio']) ? round((float) $datos['precio'], 2) : 0.0;
        $maxPorCompra = isset($datos['max_por_compra']) ? (int) $datos['max_por_compra'] : 10;
        $activo = !empty($datos['activo']) ? 1 : 0;
        $moneda = isset($datos['moneda']) ? strtoupper(substr($datos['moneda'], 0, 3)) : 'ARS';

        if ($capacidad < 1) {
            return ['ok' => false, 'error' => 'La capacidad tiene que ser al menos 1'];
        }

        if ($precio < 0) {
            return ['ok' => false, 'error' => 'El precio no puede ser negativo'];
        }

        if ($maxPorCompra < 1 || $maxPorCompra > self::MAX_POR_COMPRA) {
            return ['ok' => false, 'error' => 'El máximo por compra tiene que estar entre 1 y ' . self::MAX_POR_COMPRA];
        }

        // Bajar la capacidad por debajo de lo ya vendido dejaría el evento
        // sobrevendido de entrada, sin que nadie hiciera nada mal.
        $ocupadas = self::ocupadas($db, $linkId);

        if ($capacidad < $ocupadas) {
            return ['ok' => false, 'error' => "Ya hay $ocupadas entradas tomadas: la capacidad no puede ser menor"];
        }

        $stmt = $db->prepare('
            INSERT INTO event_ticketing (link_id, activo, capacidad, precio, moneda, max_por_compra)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                activo = VALUES(activo),
                capacidad = VALUES(capacidad),
                precio = VALUES(precio),
                moneda = VALUES(moneda),
                max_por_compra = VALUES(max_por_compra)
        ');
        $stmt->execute([(int) $linkId, $activo, $capacidad, $precio, $moneda, $maxPorCompra]);

        return ['ok' => true, 'error' => null];
    }

    public static function borrarConfig($db, $linkId)
    {
        $stmt = $db->prepare('DELETE FROM event_ticketing WHERE link_id = ?');
        $stmt->execute([(int) $linkId]);
    }

    /**
     * Entradas que ya no están disponibles: pagadas más reservas vigentes.
     *
     * Las reservas vencidas quedan fuera por la comparación de fecha, así que
     * el cupo se libera solo al pasar el tiempo.
     */
    public static function ocupadas($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(cantidad), 0)
            FROM ticket_orders
            WHERE link_id = ?
              AND (estado = 'pagada'
                   OR (estado = 'reservada' AND reserva_vence_en > NOW()))
        ");
        $stmt->execute([(int) $linkId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Estado de venta de un evento, tal como lo ve el público.
     *
     * @return array|null null si el evento no vende entradas
     */
    public static function disponibilidad($db, $linkId)
    {
        $config = self::configDelEvento($db, $linkId);

        if ($config === null || !$config['activo']) {
            return null;
        }

        $ocupadas = self::ocupadas($db, $linkId);
        $capacidad = (int) $config['capacidad'];
        $disponibles = max(0, $capacidad - $ocupadas);
        $precio = (float) $config['precio'];

        return [
            'activo'         => true,
            // Sin precio no hay cobro: es una reserva y se confirma en el acto.
            'es_gratis'      => $precio <= 0,
            'precio'         => $precio,
            'moneda'         => $config['moneda'],
            'capacidad'      => $capacidad,
            'disponibles'    => $disponibles,
            'agotado'        => $disponibles < 1,
            'max_por_compra' => min((int) $config['max_por_compra'], $disponibles),
        ];
    }

    /**
     * Crea una orden tomando el cupo de forma atómica.
     *
     * El bloqueo sobre la fila de configuración del evento serializa las
     * compras del mismo evento: entre contar lo ocupado y tomar el lugar no se
     * puede colar nadie. Sin eso, dos pedidos simultáneos leen ambos "queda 1"
     * y ambos venden.
     *
     * @param array $datos ['nombre', 'email', 'telefono', 'cantidad']
     * @return array{ok: bool, error: string|null, orden: array|null}
     */
    public static function crearOrden($db, $linkId, array $datos)
    {
        $problema = self::validarComprador($datos);

        if ($problema !== null) {
            return ['ok' => false, 'error' => $problema, 'orden' => null];
        }

        $cantidad = (int) $datos['cantidad'];

        $db->beginTransaction();

        try {
            // FOR UPDATE: el resto de las compras de este evento esperan acá.
            $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ? FOR UPDATE');
            $stmt->execute([(int) $linkId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($config === false || !$config['activo']) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'Este evento no vende entradas', 'orden' => null];
            }

            if ($cantidad > (int) $config['max_por_compra']) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'El máximo por compra es ' . (int) $config['max_por_compra'], 'orden' => null];
            }

            $disponibles = (int) $config['capacidad'] - self::ocupadas($db, $linkId);

            if ($cantidad > $disponibles) {
                $db->rollBack();

                return [
                    'ok' => false,
                    'orden' => null,
                    'error' => $disponibles < 1
                        ? 'Se agotaron las entradas'
                        : "Sólo quedan $disponibles entradas",
                ];
            }

            $precio = (float) $config['precio'];
            $esGratis = $precio <= 0;
            $codigo = self::codigoLibre($db);

            // Una reserva sin cobro se confirma en el acto: no hay pago que
            // esperar, así que dejarla vencer perdería la reserva sin motivo.
            $insert = $db->prepare('
                INSERT INTO ticket_orders
                    (codigo, link_id, nombre, email, telefono, cantidad,
                     precio_unitario, total, moneda, estado, reserva_vence_en, pagada_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                $codigo,
                (int) $linkId,
                trim($datos['nombre']),
                trim($datos['email']),
                // Opcional: la columna no acepta null, así que se guarda vacío.
                isset($datos['telefono']) ? trim($datos['telefono']) : '',
                $cantidad,
                $precio,
                round($precio * $cantidad, 2),
                $config['moneda'],
                $esGratis ? 'pagada' : 'reservada',
                $esGratis ? null : date('Y-m-d H:i:s', time() + self::MINUTOS_DE_RESERVA * 60),
                $esGratis ? date('Y-m-d H:i:s') : null,
            ]);

            $ordenId = (int) $db->lastInsertId();
            $db->commit();

            return [
                'ok' => true,
                'error' => null,
                'orden' => [
                    'id'        => $ordenId,
                    'codigo'    => $codigo,
                    'cantidad'  => $cantidad,
                    'precio'    => $precio,
                    'total'     => round($precio * $cantidad, 2),
                    'moneda'    => $config['moneda'],
                    'estado'    => $esGratis ? 'pagada' : 'reservada',
                    'es_gratis' => $esGratis,
                ],
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /** Congela la comisión con la que se creó el cobro. */
    public static function guardarComision($db, $ordenId, $comision, $porcentaje)
    {
        $stmt = $db->prepare('UPDATE ticket_orders SET comision = ?, comision_porcentaje = ? WHERE id = ?');
        $stmt->execute([round((float) $comision, 2), round((float) $porcentaje, 2), (int) $ordenId]);
    }

    /** Guarda la preferencia de Mercado Pago asociada a la orden. */
    public static function guardarPreferencia($db, $ordenId, $preferenciaId)
    {
        $stmt = $db->prepare('UPDATE ticket_orders SET mp_preference_id = ? WHERE id = ?');
        $stmt->execute([$preferenciaId, (int) $ordenId]);
    }

    /**
     * Acredita un pago sobre su orden.
     *
     * Mercado Pago reintenta los avisos y no garantiza mandarlos una sola vez,
     * así que esto tiene que poder ejecutarse muchas veces con el mismo pago y
     * dar siempre el mismo resultado. La condición sobre el estado en el UPDATE
     * es lo que lo garantiza, junto al índice único sobre mp_payment_id.
     *
     * @return array{acreditada: bool, motivo: string}
     */
    public static function acreditarPago($db, $codigo, $pagoId, $estadoMp, array $detalle = [])
    {
        $stmt = $db->prepare('SELECT * FROM ticket_orders WHERE codigo = ?');
        $stmt->execute([$codigo]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($orden === false) {
            return ['acreditada' => false, 'motivo' => 'orden inexistente'];
        }

        if ($orden['estado'] === 'pagada') {
            return ['acreditada' => false, 'motivo' => 'ya estaba pagada'];
        }

        if ($estadoMp === 'approved') {
            // Sólo pasa de reservada a pagada. Si venció mientras tanto, se
            // acredita igual: la persona pagó, y dejarla afuera por 30 segundos
            // de demora sería peor que exceder el cupo por una orden.
            // El neto y la fecha de acreditación los dice Mercado Pago y se
            // guardan tal cual. Si no vinieron —un aviso viejo, una respuesta
            // incompleta— se dejan como estaban en vez de escribir un null que
            // borraría lo que ya se sabía.
            $upd = $db->prepare("
                UPDATE ticket_orders
                SET estado = 'pagada', mp_payment_id = ?, pagada_en = NOW(),
                    mp_neto = COALESCE(?, mp_neto),
                    mp_comisiones = COALESCE(?, mp_comisiones),
                    acreditacion_en = COALESCE(?, acreditacion_en)
                WHERE codigo = ? AND estado IN ('reservada', 'vencida')
            ");
            $upd->execute([
                $pagoId,
                isset($detalle['neto']) ? $detalle['neto'] : null,
                isset($detalle['comisiones']) ? $detalle['comisiones'] : null,
                self::fechaDeAcreditacion($detalle),
                $codigo,
            ]);

            return $upd->rowCount() > 0
                ? ['acreditada' => true, 'motivo' => 'pago acreditado']
                : ['acreditada' => false, 'motivo' => 'la orden no estaba en un estado acreditable'];
        }

        if (in_array($estadoMp, ['rejected', 'cancelled'], true)) {
            $upd = $db->prepare("
                UPDATE ticket_orders
                SET estado = 'rechazada', mp_payment_id = ?
                WHERE codigo = ? AND estado = 'reservada'
            ");
            $upd->execute([$pagoId, $codigo]);

            return ['acreditada' => false, 'motivo' => 'pago rechazado'];
        }

        // in_process, pending: se deja la reserva viva y se espera otro aviso.
        return ['acreditada' => false, 'motivo' => 'pago todavía en curso'];
    }

    /**
     * La fecha de acreditación, en el formato de la columna.
     *
     * Mercado Pago la manda como instante ISO con zona ("2026-09-16T10:00:00.000-04:00").
     * Guardar eso tal cual en un TIMESTAMP deja una fecha corrida o directamente
     * inválida, así que se convierte.
     */
    public static function fechaDeAcreditacion(array $detalle)
    {
        if (empty($detalle['acreditacion']) || !is_string($detalle['acreditacion'])) {
            return null;
        }

        $momento = date_create($detalle['acreditacion']);

        return $momento === false ? null : $momento->format('Y-m-d H:i:s');
    }

    /**
     * Cancela una compra y devuelve los lugares al cupo.
     *
     * No hace falta tocar ningún contador: `ocupadas()` sólo suma las pagadas y
     * las reservas vigentes, así que pasar a 'cancelada' libera los lugares
     * sola. La orden queda en la base con su estado nuevo, que es lo que
     * permite después explicar por qué el evento tiene lugar otra vez.
     *
     * Se cancela desde reservada o pagada. Una orden ya cancelada devuelve
     * false sin tocar nada: cancelar dos veces no puede liberar el doble.
     *
     * @return array{cancelada: bool, motivo: string}
     */
    public static function cancelar($db, $codigo)
    {
        $stmt = $db->prepare('SELECT estado, cantidad FROM ticket_orders WHERE codigo = ?');
        $stmt->execute([$codigo]);
        $orden = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($orden === false) {
            return ['cancelada' => false, 'motivo' => 'orden inexistente'];
        }

        if ($orden['estado'] === 'cancelada') {
            return ['cancelada' => false, 'motivo' => 'ya estaba cancelada'];
        }

        // La condición sobre el estado va en el UPDATE y no sólo en el if: dos
        // pedidos simultáneos leerían lo mismo, y el segundo no debe pisar.
        $upd = $db->prepare("
            UPDATE ticket_orders
            SET estado = 'cancelada', cancelada_en = NOW()
            WHERE codigo = ? AND estado IN ('reservada', 'pagada', 'vencida')
        ");
        $upd->execute([$codigo]);

        if ($upd->rowCount() === 0) {
            return ['cancelada' => false, 'motivo' => 'la orden no estaba en un estado cancelable'];
        }

        return ['cancelada' => true, 'motivo' => 'compra cancelada'];
    }

    /** Una orden por su código público, con los datos del evento. */
    public static function orden($db, $codigo)
    {
        $stmt = $db->prepare('
            SELECT o.*, l.text AS evento, l.event_date, l.event_time, l.event_address,
                   l.image_url AS evento_imagen,
                   p.title AS pagina, p.url_slug, p.email_contacto
            FROM ticket_orders o
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE o.codigo = ?
        ');
        $stmt->execute([$codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /**
     * Ventas de un evento, para el dueño.
     *
     * Las reservas vencidas se muestran como tales aunque en la base sigan
     * figurando 'reservada': no hay proceso que las marque, y mostrarlas como
     * vigentes daría una idea equivocada de cuánto se vendió.
     */
    public static function ventasDelEvento($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT id, codigo, nombre, email, telefono, cantidad,
                   precio_unitario, total, comision, comision_porcentaje,
                   moneda, estado, reserva_vence_en,
                   mp_payment_id, pagada_en, created_at,
                   mp_neto, mp_comisiones, acreditacion_en,
                   (estado = 'reservada' AND reserva_vence_en <= NOW()) AS vencida
            FROM ticket_orders
            WHERE link_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([(int) $linkId]);
        $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $vendidas = 0;
        $recaudado = 0.0;
        $comisiones = 0.0;
        $reservadas = 0;
        $porAcreditar = 0.0;
        $acreditado = 0.0;
        $proxima = null;
        $sinDato = 0;
        $ahora = date('Y-m-d H:i:s');

        foreach ($ordenes as &$orden) {
            if ($orden['vencida']) {
                $orden['estado'] = 'vencida';
            }
            unset($orden['vencida']);

            if ($orden['estado'] === 'pagada') {
                $vendidas += (int) $orden['cantidad'];
                $recaudado += (float) $orden['total'];
                $comisiones += (float) $orden['comision'];

                // Sin el dato de Mercado Pago no se suma nada: es preferible
                // avisar que faltan ventas por contar a mostrar un total que
                // parezca completo y no lo esté.
                $neto = isset($orden['mp_neto']) ? $orden['mp_neto'] : null;
                $cuando = isset($orden['acreditacion_en']) ? $orden['acreditacion_en'] : null;

                if ($neto === null) {
                    $sinDato++;
                } elseif ($cuando !== null && $cuando > $ahora) {
                    $porAcreditar += (float) $neto;

                    if ($proxima === null || $cuando < $proxima) {
                        $proxima = $cuando;
                    }
                } else {
                    $acreditado += (float) $neto;
                }
            } elseif ($orden['estado'] === 'reservada') {
                $reservadas += (int) $orden['cantidad'];
            }
        }
        unset($orden);

        return [
            'ordenes' => $ordenes,
            'resumen' => [
                'vendidas'   => $vendidas,
                'reservadas' => $reservadas,
                'recaudado'  => round($recaudado, 2),
                'comision'   => round($comisiones, 2),
                // Lo que realmente le queda al dueño después del split.
                'neto'       => round($recaudado - $comisiones, 2),
                // Y lo que dice Mercado Pago, que además descuenta su propia
                // comisión: es la plata que efectivamente entra a la cuenta.
                'acreditado'    => round($acreditado, 2),
                'por_acreditar' => round($porAcreditar, 2),
                'proxima_acreditacion' => $proxima,
                'ventas_sin_dato' => $sinDato,
            ],
        ];
    }

    /**
     * Eventos de una página que venden o vendieron entradas.
     *
     * Es el índice para llegar a las ventas sin pasar por el evento: quien
     * administra la página muchas veces sabe el nombre del show o la fecha,
     * pero no en qué grupo de contenido quedó cargado.
     *
     * Aparece un evento si tiene entradas configuradas o si alguna vez tuvo
     * una compra. Lo segundo no es redundante: si el dueño apaga las entradas
     * de un show que ya vendió, las ventas hechas tienen que seguir estando.
     *
     * Los totales son los mismos que muestra el panel de un evento —pagadas
     * suman, reservadas vencidas no— para que el listado y el detalle no se
     * contradigan.
     *
     * @param array $filtros texto (nombre del evento), desde y hasta (fechas ISO)
     */
    public static function eventosConEntradas($db, $pageId, array $filtros = [])
    {
        // Aparece si tiene entradas configuradas o si alguna vez tuvo una
        // compra: apagar las entradas de un show vendido no puede esconder lo
        // ya vendido.
        $where = [
            'lg.page_id = ?',
            "lg.type = 'eventos'",
            '(et.id IS NOT NULL OR v.link_id IS NOT NULL)',
        ];
        $params = [(int) $pageId];

        $texto = isset($filtros['texto']) ? trim((string) $filtros['texto']) : '';

        if ($texto !== '') {
            $where[] = 'l.text LIKE ?';
            // Escapamos los comodines: quien busca "100%" busca eso y no
            // cualquier cosa que empiece con 100.
            $params[] = '%' . addcslashes($texto, '%_\\') . '%';
        }

        foreach (['desde' => '>=', 'hasta' => '<='] as $clave => $comparador) {
            $fecha = isset($filtros[$clave]) ? trim((string) $filtros[$clave]) : '';

            if (self::esFechaIso($fecha)) {
                $where[] = "l.event_date $comparador ?";
                $params[] = $fecha;
            }
        }

        // Las ventas se resumen aparte y se pegan por link_id. Agrupar en la
        // consulta de afuera obligaría a listar cada columna del evento en el
        // GROUP BY o a depender de que el servidor no tenga ONLY_FULL_GROUP_BY,
        // que es una condición del entorno y no algo que podamos garantizar.
        $stmt = $db->prepare('
            SELECT l.id, l.text, l.event_date, l.event_time, l.event_address,
                   et.activo, et.capacidad, et.precio, et.moneda,
                   COALESCE(v.ordenes, 0)    AS ordenes,
                   COALESCE(v.vendidas, 0)   AS vendidas,
                   COALESCE(v.reservadas, 0) AS reservadas,
                   COALESCE(v.recaudado, 0)  AS recaudado
            FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            LEFT JOIN event_ticketing et ON et.link_id = l.id
            LEFT JOIN (
                SELECT link_id,
                       COUNT(*) AS ordenes,
                       SUM(CASE WHEN estado = \'pagada\' THEN cantidad END) AS vendidas,
                       SUM(CASE WHEN estado = \'reservada\'
                            AND (reserva_vence_en IS NULL OR reserva_vence_en > NOW())
                            THEN cantidad END) AS reservadas,
                       SUM(CASE WHEN estado = \'pagada\' THEN total END) AS recaudado
                FROM ticket_orders
                GROUP BY link_id
            ) v ON v.link_id = l.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.event_date DESC, l.id DESC
            LIMIT ' . self::LIMITE_EVENTOS . '
        ');
        $stmt->execute($params);

        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($eventos as $i => $evento) {
            $eventos[$i]['activo'] = !empty($evento['activo']);
            $eventos[$i]['capacidad'] = (int) $evento['capacidad'];
            $eventos[$i]['ordenes'] = (int) $evento['ordenes'];
            $eventos[$i]['vendidas'] = (int) $evento['vendidas'];
            $eventos[$i]['reservadas'] = (int) $evento['reservadas'];
            $eventos[$i]['recaudado'] = round((float) $evento['recaudado'], 2);
        }

        return $eventos;
    }

    /** Una fecha del filtro sirve sólo si es una fecha. */
    private static function esFechaIso($fecha)
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha);
    }

    // ------------------------------------------------------------ internos

    private static function validarComprador(array $datos)
    {
        $nombre = isset($datos['nombre']) ? trim($datos['nombre']) : '';
        $email = isset($datos['email']) ? trim($datos['email']) : '';
        $telefono = isset($datos['telefono']) ? trim($datos['telefono']) : '';
        $cantidad = isset($datos['cantidad']) ? (int) $datos['cantidad'] : 0;

        if ($nombre === '') {
            return 'Falta el nombre y apellido';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'El email no es válido';
        }

        // El teléfono es opcional: la entrada llega por mail y ahí termina el
        // circuito, así que exigirlo sólo espantaba compras. Pero si lo dejan,
        // tiene que servir para llamar. Sólo se exige que haya dígitos
        // suficientes: los formatos varían demasiado como para rechazar por
        // forma.
        if ($telefono !== '' && strlen(preg_replace('/\D/', '', $telefono)) < 6) {
            return 'El teléfono no es válido';
        }

        if ($cantidad < 1) {
            return 'Hay que pedir al menos una entrada';
        }

        return null;
    }

    /** Código público aleatorio, verificando que no exista. */
    private static function codigoLibre($db)
    {
        $stmt = $db->prepare('SELECT 1 FROM ticket_orders WHERE codigo = ?');

        for ($intento = 0; $intento < 5; $intento++) {
            $codigo = strtoupper(bin2hex(random_bytes(6)));
            $stmt->execute([$codigo]);

            if ($stmt->fetchColumn() === false) {
                return $codigo;
            }
        }

        throw new RuntimeException('No se pudo generar un código de orden');
    }
}
