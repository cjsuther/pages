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

    /** Hasta cuántos días atrás se revisa una reserva sin pago registrado. */
    const DIAS_A_CONCILIAR = 60;

    /** Tope por corrida, para que una acumulación no estire el cron sin fin. */
    const LIMITE_A_CONCILIAR = 200;

    const MAX_POR_COMPRA = 50;

    /**
     * Configuración de venta de un evento, o null si no tiene.
     *
     * El plano vuelve ya decodificado: null si el evento vende sin lugares
     * asignados.
     */
    public static function configDelEvento($db, $linkId)
    {
        $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ?');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : self::conPlanoDecodificado($fila);
    }

    /**
     * Guarda la configuración de venta de un evento.
     *
     * El plano es opcional y se distingue "no vino" de "vino vacío": quien no
     * lo manda —el asistente, que sólo sabe de precio y cupo— no lo toca, y
     * quien manda null lo saca. Si no, cambiar el precio por otro camino
     * borraría el plano sin que nadie lo pidiera.
     *
     * Con plano, la capacidad es la cantidad de lugares del plano, y lo que
     * venga en 'capacidad' no se usa.
     *
     * @param array $datos ['activo', 'capacidad', 'precio', 'moneda', 'max_por_compra', 'plano'?]
     * @return array{ok: bool, error: string|null}
     */
    public static function guardarConfig($db, $linkId, array $datos)
    {
        $plano = array_key_exists('plano', $datos)
            ? $datos['plano']
            : self::planoGuardado($db, $linkId);

        if ($plano !== null) {
            $normalizado = Plano::normalizar($plano);

            if (!$normalizado['ok']) {
                return ['ok' => false, 'error' => $normalizado['error']];
            }

            $plano = $normalizado['plano'];
            $datos['capacidad'] = count(Plano::lugares($plano));
        }

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

        if ($plano !== null && $ocupadas > 0) {
            $problema = self::problemaConLoVendido($db, $linkId, $plano, $ocupadas);

            if ($problema !== null) {
                return ['ok' => false, 'error' => $problema];
            }
        }

        // Sacar el plano con lugares vendidos dejaría esas entradas con un
        // lugar que ya nadie más respeta: el siguiente compraría sin elegir y
        // podría terminar en la misma butaca.
        if ($plano === null && $ocupadas > 0 && self::lugaresOcupados($db, $linkId) !== []) {
            return ['ok' => false, 'error' => 'Ya hay lugares vendidos o reservados: el plano no se puede sacar'];
        }

        $stmt = $db->prepare('
            INSERT INTO event_ticketing (link_id, activo, capacidad, precio, moneda, max_por_compra, plano)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                activo = VALUES(activo),
                capacidad = VALUES(capacidad),
                precio = VALUES(precio),
                moneda = VALUES(moneda),
                max_por_compra = VALUES(max_por_compra),
                plano = VALUES(plano)
        ');
        $stmt->execute([
            (int) $linkId, $activo, $capacidad, $precio, $moneda, $maxPorCompra,
            $plano === null ? null : json_encode($plano, JSON_UNESCAPED_UNICODE),
        ]);

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
     * Lugares del plano que ya no están disponibles.
     *
     * Misma regla que ocupadas(): cuentan las pagadas y las reservas vigentes.
     * No hay un índice único que impida vender dos veces el mismo lugar —el
     * lugar se libera por vencimiento, que ningún índice puede ver—; lo que
     * lo impide es que las compras de un evento se hacen de a una, con la fila
     * de su configuración bloqueada.
     *
     * @return string[]
     */
    public static function lugaresOcupados($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT tl.lugar
            FROM ticket_order_lugares tl
            INNER JOIN ticket_orders o ON o.id = tl.order_id
            WHERE tl.link_id = ?
              AND (o.estado = 'pagada'
                   OR (o.estado = 'reservada' AND o.reserva_vence_en > NOW()))
        ");
        $stmt->execute([(int) $linkId]);

        return array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** Los lugares de una orden, en el orden en que se eligieron. */
    public static function lugaresDeLaOrden($db, $ordenId)
    {
        $stmt = $db->prepare('SELECT lugar FROM ticket_order_lugares WHERE order_id = ? ORDER BY id');
        $stmt->execute([(int) $ordenId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
        $plano = $config['plano'];

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
            // Con plano, el comprador elige dónde sentarse: necesita ver el
            // plano y qué lugares ya no están. Nunca quién los tiene.
            'plano'          => $plano,
            'ocupados'       => $plano === null ? [] : self::lugaresOcupados($db, $linkId),
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
     * En un evento con plano no se pide una cantidad sino lugares: la cantidad
     * es cuántos se eligieron, y cada uno se verifica libre dentro del mismo
     * bloqueo que el cupo.
     *
     * @param array $datos ['nombre', 'email', 'telefono', 'cantidad', 'lugares'?]
     * @return array{ok: bool, error: string|null, orden: array|null, ocupados?: string[]}
     */
    public static function crearOrden($db, $linkId, array $datos)
    {
        $lugares = null;

        if (isset($datos['lugares'])) {
            if (!is_array($datos['lugares'])) {
                return ['ok' => false, 'error' => 'Los lugares no tienen el formato esperado', 'orden' => null];
            }

            $lugares = array_values(array_unique(array_map('strval', $datos['lugares'])));
            $datos['cantidad'] = count($lugares);
        }

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

            $config = self::conPlanoDecodificado($config);
            $problemaDeLugares = self::problemaConLosLugares($db, $linkId, $config['plano'], $lugares);

            if ($problemaDeLugares !== null) {
                $db->rollBack();
                return ['ok' => false, 'orden' => null] + $problemaDeLugares;
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

            // La compra se ata también al registro del evento, que es lo que
            // queda si el evento se borra: sin él, quien compró se perdería.
            $recordId = Clientes::registrarEvento($db, $linkId);

            // Una reserva sin cobro se confirma en el acto: no hay pago que
            // esperar, así que dejarla vencer perdería la reserva sin motivo.
            $insert = $db->prepare('
                INSERT INTO ticket_orders
                    (codigo, link_id, record_id, nombre, email, telefono, cantidad,
                     precio_unitario, total, moneda, estado, reserva_vence_en, pagada_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                $codigo,
                (int) $linkId,
                $recordId,
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

            if ($config['plano'] !== null) {
                $conLugar = $db->prepare('INSERT INTO ticket_order_lugares (order_id, link_id, lugar) VALUES (?, ?, ?)');

                foreach ($lugares as $lugar) {
                    $conLugar->execute([$ordenId, (int) $linkId, $lugar]);
                }
            }

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
                    'lugares'   => $config['plano'] === null ? [] : $lugares,
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
    /**
     * Órdenes que quedaron reservadas y sin pago registrado.
     *
     * Son las candidatas a que se les haya perdido el aviso. Se miran desde las
     * más nuevas y con una ventana de días: una reserva de hace medio año que
     * nunca se pagó no se va a pagar, y preguntarle a Mercado Pago por cada una
     * para siempre sería gastar llamadas en nada.
     *
     * Las gratis quedan afuera: nacen pagadas y nunca pasan por Mercado Pago.
     *
     * @return string[] Códigos de orden.
     */
    public static function sinConciliar($db, $dias = self::DIAS_A_CONCILIAR, $limite = self::LIMITE_A_CONCILIAR)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM ticket_orders
            WHERE estado = 'reservada'
              AND mp_payment_id IS NULL
              AND total > 0
              AND link_id IS NOT NULL
              AND created_at >= (NOW() - INTERVAL ? DAY)
            ORDER BY created_at DESC
            LIMIT " . (int) $limite . "
        ");
        $stmt->execute([(int) $dias]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Ventas ya pagadas a las que les falta el desglose de comisiones.
     *
     * Son las anteriores a que se guardara la comisión de plataforma por
     * separado. Sin esto quedarían sin el dato para siempre, que es justo el
     * que dice si el split se aplicó o si Mercado Pago lo ignoró en silencio.
     *
     * @return array<array{codigo: string, mp_payment_id: string}>
     */
    public static function pagadasSinDesglose($db, $limite = self::LIMITE_A_CONCILIAR)
    {
        $stmt = $db->prepare("
            SELECT codigo, mp_payment_id
            FROM ticket_orders
            WHERE estado = 'pagada'
              AND mp_payment_id IS NOT NULL
              AND mp_comision_cobrada IS NULL
              AND total > 0
              AND link_id IS NOT NULL
            ORDER BY created_at DESC
            LIMIT " . (int) $limite . "
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Completa los números de una venta ya pagada, sin tocar su estado.
     *
     * Existe para las ventas anteriores a una columna nueva: acreditarPago sólo
     * escribe cuando la orden pasa de reservada a pagada, así que una venta vieja
     * se queda sin el dato para siempre. Acá sólo se rellenan los campos
     * informativos que estén vacíos —nunca se pisa lo que ya se sabía— y el
     * estado no se mira: esto no acredita ni desacredita nada.
     *
     * @return bool Si escribió algo.
     */
    public static function completarDetalleDePago($db, $codigo, array $detalle)
    {
        $campos = [
            'mp_neto'             => isset($detalle['neto']) ? $detalle['neto'] : null,
            'mp_comisiones'       => isset($detalle['comisiones']) ? $detalle['comisiones'] : null,
            'mp_comision_cobrada' => isset($detalle['comision_plataforma']) ? $detalle['comision_plataforma'] : null,
            'acreditacion_en'     => self::fechaDeAcreditacion($detalle),
        ];

        $sets = [];
        $valores = [];

        foreach ($campos as $campo => $valor) {
            if ($valor === null) {
                continue;
            }

            // Sólo si está vacío: lo que Mercado Pago dijo el día de la venta
            // manda sobre lo que diga hoy.
            $sets[] = "$campo = COALESCE($campo, ?)";
            $valores[] = $valor;
        }

        if ($sets === []) {
            return false;
        }

        $valores[] = $codigo;
        $stmt = $db->prepare('UPDATE ticket_orders SET ' . implode(', ', $sets) . " WHERE codigo = ? AND estado = 'pagada'");
        $stmt->execute($valores);

        return $stmt->rowCount() > 0;
    }

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
                    mp_comision_cobrada = COALESCE(?, mp_comision_cobrada),
                    acreditacion_en = COALESCE(?, acreditacion_en)
                WHERE codigo = ? AND estado IN ('reservada', 'vencida')
            ");
            $upd->execute([
                $pagoId,
                isset($detalle['neto']) ? $detalle['neto'] : null,
                isset($detalle['comisiones']) ? $detalle['comisiones'] : null,
                isset($detalle['comision_plataforma']) ? $detalle['comision_plataforma'] : null,
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
                   p.title AS pagina, p.url_slug, p.email_contacto, p.meta_pixel_id
            FROM ticket_orders o
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE o.codigo = ?
        ');
        $stmt->execute([$codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        $fila['lugares'] = self::lugaresDeLaOrden($db, $fila['id']);

        return $fila;
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
            SELECT id, codigo, nombre, email, telefono, cantidad, ingresadas, ingreso_en,
                   precio_unitario, total, comision, comision_porcentaje,
                   moneda, estado, reserva_vence_en,
                   mp_payment_id, pagada_en, created_at,
                   mp_neto, mp_comisiones, mp_comision_cobrada, acreditacion_en,
                   (estado = 'reservada' AND reserva_vence_en <= NOW()) AS vencida
            FROM ticket_orders
            WHERE link_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([(int) $linkId]);
        $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lugaresPorOrden = self::lugaresPorOrden($db, $linkId);
        $conflictos = self::lugaresVendidosDosVeces($ordenes, $lugaresPorOrden);

        $vendidas = 0;
        $ingresadas = 0;
        $recaudado = 0.0;
        $comisiones = 0.0;
        // Lo que Mercado Pago dice que cobró de comisión de plataforma. Se
        // acumula aparte de $comisiones —que es lo que pedimos— porque la
        // diferencia entre las dos es el dato: si pedimos y no se cobró, el
        // split no está funcionando y la venta se hizo igual.
        $comisionCobrada = 0.0;
        $comisionSinDato = 0;
        // Lo que de verdad entra a la cuenta: se acumula del neto que informa
        // Mercado Pago, no de una resta nuestra. Cuando una venta todavía no
        // tiene ese dato se usa la estimación vieja para no dejar un hueco, y
        // $sinDato avisa que el total no está cerrado.
        $netoReal = 0.0;
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

            $orden['lugares'] = isset($lugaresPorOrden[$orden['id']]) ? $lugaresPorOrden[$orden['id']] : [];
            $orden['lugares_en_conflicto'] = $orden['estado'] === 'pagada'
                ? array_values(array_intersect($orden['lugares'], $conflictos))
                : [];

            if ($orden['estado'] === 'pagada') {
                $vendidas += (int) $orden['cantidad'];
                $ingresadas += isset($orden['ingresadas']) ? (int) $orden['ingresadas'] : 0;
                $recaudado += (float) $orden['total'];
                $comisiones += (float) $orden['comision'];

                // null es "no lo sabemos" —una venta anterior a que se guardara
                // el desglose— y no es lo mismo que un cero, que sí sería una
                // respuesta: Mercado Pago no cobró nada.
                $cobrada = isset($orden['mp_comision_cobrada']) ? $orden['mp_comision_cobrada'] : null;

                // Lo cobrado sale de Mercado Pago. Cuando todavía no lo sabemos
                // se usa lo que pedimos —no hay evidencia de que no se haya
                // cobrado— y $comisionSinDato avisa que el número no está
                // confirmado.
                if ($cobrada === null) {
                    $comisionSinDato++;
                    $comisionCobrada += (float) $orden['comision'];
                } else {
                    $comisionCobrada += (float) $cobrada;
                }

                // Sin el dato de Mercado Pago no se suma nada: es preferible
                // avisar que faltan ventas por contar a mostrar un total que
                // parezca completo y no lo esté.
                $neto = isset($orden['mp_neto']) ? $orden['mp_neto'] : null;
                $cuando = isset($orden['acreditacion_en']) ? $orden['acreditacion_en'] : null;

                // Sin dato de Mercado Pago se cae a la estimación de antes
                // —total menos nuestra comisión—, que se queda corta en la de
                // Mercado Pago pero es mejor que no contar la venta.
                $netoReal += $neto === null
                    ? (float) $orden['total'] - (float) $orden['comision']
                    : (float) $neto;

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
                // Cuántas de las vendidas ya pasaron por la puerta.
                'ingresadas' => $ingresadas,
                'reservadas' => $reservadas,
                'recaudado'  => round($recaudado, 2),
                // Lo que pedimos que se nos descuente. Sólo sirve para
                // compararlo contra lo cobrado: si no coinciden, el
                // marketplace_fee se está mandando y Mercado Pago lo ignora.
                'comision'   => round($comisiones, 2),
                // Lo que Mercado Pago efectivamente cobró. Es el que va en el
                // desglose de la pantalla: mostrar lo pedido ahí sería mostrar
                // una intención donde va un hecho.
                'comision_cobrada'  => round($comisionCobrada, 2),
                'comision_sin_dato' => $comisionSinDato,
                // La de Mercado Pago es lo que falta para que la cuenta cierre:
                // de lo recaudado salió lo nuestro, salió lo suyo, y lo que
                // queda es el neto que él mismo informa. Se despeja en vez de
                // leerse porque su campo de comisiones no es consistente entre
                // endpoints —el detalle de un pago incluye la comisión de
                // plataforma en el desglose y la búsqueda no—, y despejando la
                // resta de la pantalla cierra siempre.
                'comision_mercadopago' => round($recaudado - $netoReal - $comisionCobrada, 2),
                // Lo que efectivamente entra a la cuenta. Antes era
                // recaudado - nuestra comisión, que ignoraba la de Mercado
                // Pago: prometía varios miles de más en una venta chica.
                'neto'       => round($netoReal, 2),
                // Y lo que dice Mercado Pago, que además descuenta su propia
                // comisión: es la plata que efectivamente entra a la cuenta.
                'acreditado'    => round($acreditado, 2),
                'por_acreditar' => round($porAcreditar, 2),
                'proxima_acreditacion' => $proxima,
                'ventas_sin_dato' => $sinDato,
                // Lugares con más de una compra pagada. Pasa sólo si alguien
                // pagó después de que su reserva venciera y otro ya había
                // tomado el lugar: el pago se acredita igual, y quien organiza
                // tiene que enterarse para reubicar a uno de los dos.
                'lugares_en_conflicto' => $conflictos,
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
    /** Estados del filtro de la lista de eventos con entradas. */
    const ESTADOS_EVENTOS = ['vigentes', 'vencidos', 'todos'];
    const ESTADO_POR_DEFECTO = 'vigentes';

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

        // Vencido o no. Por defecto se muestran los que todavía no pasaron:
        // la lista se usa para mirar cómo va la venta de lo que viene, y con
        // el histórico entero adelante hay que buscar el show de esta semana
        // entre todos los del año pasado.
        //
        // Un evento sin fecha no está vencido: no hay contra qué compararlo, y
        // esconderlo lo dejaría fuera de las dos listas.
        $estado = isset($filtros['estado']) ? $filtros['estado'] : self::ESTADO_POR_DEFECTO;

        if ($estado === 'vigentes') {
            $where[] = '(l.event_date IS NULL OR l.event_date >= ?)';
            $params[] = Fechas::hoy();
        } elseif ($estado === 'vencidos') {
            $where[] = 'l.event_date < ?';
            $params[] = Fechas::hoy();
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

    /** Tope de planos para copiar: los de los shows más recientes alcanzan. */
    const LIMITE_PLANOS = 50;

    /**
     * Planos de los otros eventos de una página, para copiar uno.
     *
     * Un lugar arma su plano una vez y lo usa en cada show: volver a dibujarlo
     * fila por fila en cada evento es tedioso y termina en planos que no
     * coinciden entre sí. Se copia sólo la forma, nunca lo vendido.
     *
     * Van primero los más recientes, que son los que más probablemente
     * tengan el plano vigente del lugar.
     *
     * @return array<array{id: int, text: string, event_date: string|null, lugares: int, plano: array}>
     */
    public static function planosDeLaPagina($db, $pageId, $exceptoLinkId = 0)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.text, l.event_date, et.plano
            FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            JOIN event_ticketing et ON et.link_id = l.id
            WHERE lg.page_id = ?
              AND l.id <> ?
              AND et.plano IS NOT NULL
            ORDER BY l.event_date DESC, l.id DESC
            LIMIT ' . self::LIMITE_PLANOS . '
        ');
        $stmt->execute([(int) $pageId, (int) $exceptoLinkId]);

        $planos = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $plano = json_decode((string) $fila['plano'], true);

            // Un plano que no se puede leer no se ofrece: copiarlo daría un
            // plano roto que recién falla al guardar.
            if (!is_array($plano) || !Plano::normalizar($plano)['ok']) {
                continue;
            }

            $planos[] = [
                'id'         => (int) $fila['id'],
                'text'       => $fila['text'],
                'event_date' => $fila['event_date'],
                'lugares'    => count(Plano::lugares($plano)),
                'plano'      => $plano,
            ];
        }

        return $planos;
    }

    /** Una fecha del filtro sirve sólo si es una fecha. */
    private static function esFechaIso($fecha)
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha);
    }

    // ------------------------------------------------------------ internos

    private static function conPlanoDecodificado(array $config)
    {
        $plano = isset($config['plano']) && $config['plano'] !== '' ? json_decode($config['plano'], true) : null;
        $config['plano'] = is_array($plano) ? $plano : null;

        return $config;
    }

    /** El plano que ya tiene guardado el evento, o null. */
    private static function planoGuardado($db, $linkId)
    {
        $config = self::configDelEvento($db, $linkId);

        return $config === null ? null : $config['plano'];
    }

    /**
     * Por qué el plano nuevo no puede reemplazar al anterior, o null.
     *
     * Un lugar vendido no puede desaparecer del plano: la persona llega con
     * "Fila C, butaca 4" y esa butaca tiene que existir. Y un evento que ya
     * vendió entradas sin lugar no puede pasar a un plano, porque esa gente
     * no tiene dónde sentarse.
     */
    private static function problemaConLoVendido($db, $linkId, array $plano, $ocupadas)
    {
        $tomados = self::lugaresOcupados($db, $linkId);
        $sinLugar = $ocupadas - count($tomados);

        if ($sinLugar > 0) {
            return "Ya hay $sinLugar entradas vendidas sin lugar asignado: el evento no puede pasar a un plano";
        }

        $perdidos = array_values(array_diff($tomados, Plano::lugares($plano)));

        if ($perdidos !== []) {
            return 'Estos lugares ya están vendidos o reservados y no se pueden sacar del plano: '
                . Plano::resumir(array_slice($perdidos, 0, 10));
        }

        return null;
    }

    /**
     * Por qué no se pueden tomar estos lugares, o null.
     *
     * @return array{error: string, ocupados?: string[]}|null
     */
    private static function problemaConLosLugares($db, $linkId, $plano, $lugares)
    {
        if ($plano === null) {
            return $lugares === null || $lugares === []
                ? null
                : ['error' => 'Este evento no tiene lugares numerados'];
        }

        if ($lugares === null || $lugares === []) {
            return ['error' => 'Elegí tus lugares en el plano'];
        }

        $inexistentes = array_diff($lugares, Plano::lugares($plano));

        if ($inexistentes !== []) {
            return ['error' => 'Ese lugar no existe en el plano. Volvé a cargar la página.'];
        }

        $ocupados = self::lugaresOcupados($db, $linkId);
        $tomados = array_values(array_intersect($lugares, $ocupados));

        if ($tomados !== []) {
            // Se devuelven los ocupados para que el plano del comprador se
            // actualice sin recargar: si no, vuelve a elegir sobre una foto vieja.
            return [
                'error' => (count($tomados) === 1 ? 'Alguien acaba de tomar ' : 'Alguien acaba de tomar estos lugares: ')
                    . Plano::resumir($tomados) . '. Elegí otro.',
                'ocupados' => $ocupados,
            ];
        }

        return null;
    }

    /** @return array<int, string[]> Lugares de cada orden del evento, por id de orden. */
    private static function lugaresPorOrden($db, $linkId)
    {
        $stmt = $db->prepare('SELECT order_id, lugar FROM ticket_order_lugares WHERE link_id = ? ORDER BY id');
        $stmt->execute([(int) $linkId]);

        $porOrden = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $porOrden[(int) $fila['order_id']][] = (string) $fila['lugar'];
        }

        return $porOrden;
    }

    /** @return string[] Lugares que figuran en más de una orden pagada. */
    private static function lugaresVendidosDosVeces(array $ordenes, array $lugaresPorOrden)
    {
        $veces = [];

        foreach ($ordenes as $orden) {
            if ($orden['estado'] !== 'pagada' || !isset($lugaresPorOrden[$orden['id']])) {
                continue;
            }

            foreach ($lugaresPorOrden[$orden['id']] as $lugar) {
                $veces[$lugar] = isset($veces[$lugar]) ? $veces[$lugar] + 1 : 1;
            }
        }

        return array_keys(array_filter($veces, function ($n) { return $n > 1; }));
    }

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
