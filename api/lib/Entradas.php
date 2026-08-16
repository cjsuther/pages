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
                trim($datos['telefono']),
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
    public static function acreditarPago($db, $codigo, $pagoId, $estadoMp)
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
            $upd = $db->prepare("
                UPDATE ticket_orders
                SET estado = 'pagada', mp_payment_id = ?, pagada_en = NOW()
                WHERE codigo = ? AND estado IN ('reservada', 'vencida')
            ");
            $upd->execute([$pagoId, $codigo]);

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

    /** Una orden por su código público, con los datos del evento. */
    public static function orden($db, $codigo)
    {
        $stmt = $db->prepare('
            SELECT o.*, l.text AS evento, l.event_date, l.event_time, l.event_address,
                   p.title AS pagina, p.url_slug
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
                   precio_unitario, total, moneda, estado, reserva_vence_en,
                   mp_payment_id, pagada_en, created_at,
                   (estado = 'reservada' AND reserva_vence_en <= NOW()) AS vencida
            FROM ticket_orders
            WHERE link_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([(int) $linkId]);
        $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $vendidas = 0;
        $recaudado = 0.0;
        $reservadas = 0;

        foreach ($ordenes as &$orden) {
            if ($orden['vencida']) {
                $orden['estado'] = 'vencida';
            }
            unset($orden['vencida']);

            if ($orden['estado'] === 'pagada') {
                $vendidas += (int) $orden['cantidad'];
                $recaudado += (float) $orden['total'];
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
            ],
        ];
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

        // Sólo se exige que haya dígitos suficientes: los formatos de teléfono
        // varían demasiado como para rechazar por forma.
        if (strlen(preg_replace('/\D/', '', $telefono)) < 6) {
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
