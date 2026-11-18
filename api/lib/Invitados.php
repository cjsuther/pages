<?php

/**
 * Invitados sin costo de un evento con entradas.
 *
 * Un invitado es una orden de ticket_orders con origen 'invitacion': tiene
 * código, QR y página pública como cualquier entrada, y en la puerta se
 * controla igual —una sola vez por lugar—. Nace 'pagada' con total 0, así
 * que no pasa por Mercado Pago, no genera comisión ni cargos al comprador.
 *
 * Lo que sí hace es ocupar lugar: cuenta para la capacidad del evento, para
 * el cupo de su tipo y para el plano, igual que una entrada vendida. Regalar
 * entradas por encima de la capacidad es sobrevender.
 *
 * Anular es cancelar la orden: la puerta ya rechaza las canceladas, y el
 * lugar vuelve al cupo solo (ver Entradas::ocupadas()).
 *
 * El checkout no pasa por acá ni comparte código nuevo con esto: las
 * comprobaciones de cupo, tipos y lugares son las mismas funciones de
 * Entradas, llamadas con la fila del evento bloqueada como en la venta.
 */
class Invitados
{
    /** Lugares por invitado: la persona y sus acompañantes (+1, +2…). */
    const MAX_LUGARES = 20;

    /** Tope razonable para el límite de invitados de un evento. */
    const MAX_LIMITE = 100000;

    /**
     * Agrega un invitado y le toma los lugares.
     *
     * @param array $datos ['nombre', 'email'?, 'telefono'?, 'cantidad'?, 'tipo'?, 'lugares'?]
     * @return array{ok: bool, error: string|null, status?: int, orden?: array, ocupados?: string[]}
     */
    public static function agregar($db, $linkId, array $datos, $userId)
    {
        $nombre = isset($datos['nombre']) ? trim((string) $datos['nombre']) : '';
        $email = isset($datos['email']) ? trim((string) $datos['email']) : '';
        $telefono = isset($datos['telefono']) ? trim((string) $datos['telefono']) : '';

        if ($nombre === '') {
            return self::mal(400, 'Falta el nombre del invitado');
        }

        if (mb_strlen($nombre) > 150) {
            return self::mal(400, 'El nombre es demasiado largo');
        }

        // El email es opcional: la entrada también se puede pasar por link o
        // por WhatsApp. Pero si lo cargan, tiene que servir para mandarla.
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
            return self::mal(400, 'El email no es válido');
        }

        if ($telefono !== '' && (strlen(preg_replace('/\D/', '', $telefono)) < 6 || mb_strlen($telefono) > 50)) {
            return self::mal(400, 'El teléfono no es válido');
        }

        $lugares = null;

        if (isset($datos['lugares']) && $datos['lugares'] !== []) {
            if (!is_array($datos['lugares'])) {
                return self::mal(400, 'Los lugares no tienen el formato esperado');
            }

            $lugares = array_values(array_unique(array_map('strval', $datos['lugares'])));
            $cantidad = count($lugares);
        } else {
            $cantidad = isset($datos['cantidad']) && is_numeric($datos['cantidad']) ? (int) $datos['cantidad'] : 1;
        }

        if ($cantidad < 1 || $cantidad > self::MAX_LUGARES) {
            return self::mal(400, 'Cada invitado puede tener entre 1 y ' . self::MAX_LUGARES . ' lugares');
        }

        $tipo = isset($datos['tipo']) && $datos['tipo'] !== '' && $datos['tipo'] !== null ? (string) $datos['tipo'] : null;

        if ($tipo !== null && !preg_match(TiposDeEntrada::PATRON_ID, $tipo)) {
            return self::mal(400, 'El tipo de entrada no tiene el formato esperado');
        }

        $db->beginTransaction();

        try {
            // La misma fila que bloquea la venta: un invitado y una compra no
            // pueden llevarse a la vez el último lugar.
            $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ? FOR UPDATE');
            $stmt->execute([(int) $linkId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            // No hace falta que la venta esté activa: se puede invitar con la
            // venta cerrada. Pero sí que el evento tenga entradas, que es lo
            // que define la capacidad.
            if ($config === false) {
                $db->rollBack();
                return self::mal(409, 'Este evento todavía no tiene entradas configuradas: definí la capacidad en la solapa Entradas');
            }

            $config = Entradas::decodificada($config);

            $problemaDeLugares = Entradas::problemaConLosLugares($db, $linkId, $config['plano'], $lugares);

            if ($problemaDeLugares !== null) {
                $db->rollBack();
                return ['ok' => false, 'status' => 409] + $problemaDeLugares;
            }

            $disponibles = (int) $config['capacidad'] - Entradas::ocupadas($db, $linkId);

            if ($cantidad > $disponibles) {
                $db->rollBack();

                return self::mal(409, $disponibles < 1
                    ? 'No quedan lugares: el evento está completo'
                    : "Sólo quedan $disponibles lugares en el evento");
            }

            $limite = isset($config['max_invitados']) && $config['max_invitados'] !== null ? (int) $config['max_invitados'] : null;

            if ($limite !== null) {
                $quedanDelLimite = $limite - self::lugaresDeInvitados($db, $linkId);

                if ($cantidad > $quedanDelLimite) {
                    $db->rollBack();

                    return self::mal(409, $quedanDelLimite < 1
                        ? "Ya se usaron los $limite lugares para invitados de este evento"
                        : "Quedan $quedanDelLimite lugares para invitados de este evento");
                }
            }

            $items = null;

            if ($config['tipos'] !== null) {
                $pedido = null;

                if ($config['plano'] === null) {
                    if ($tipo === null && count($config['tipos']) > 1) {
                        $db->rollBack();
                        return self::mal(400, 'Elegí el tipo de entrada del invitado');
                    }

                    $pedido = [$tipo === null ? (string) $config['tipos'][0]['id'] : $tipo => $cantidad];
                }

                $items = Entradas::itemsDelPedido($db, $linkId, $config, $lugares, $pedido, $cantidad);

                if (is_string($items)) {
                    $db->rollBack();
                    return self::mal(409, $items);
                }

                // El tipo dice qué lugar le toca y cuenta para su cupo; el
                // precio es cero porque no se cobra.
                foreach ($items as $i => $item) {
                    $items[$i]['precio'] = 0.0;
                }
            }

            $codigo = Entradas::codigoLibre($db);
            $recordId = Clientes::registrarEvento($db, $linkId);

            $insert = $db->prepare("
                INSERT INTO ticket_orders
                    (codigo, link_id, record_id, origen, invitado_por, nombre, email, telefono, cantidad,
                     precio_unitario, total, cargo_mercadopago, cargo_plataforma, comision, comision_porcentaje,
                     moneda, estado, reserva_vence_en, pagada_en)
                VALUES (?, ?, ?, 'invitacion', ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, ?, 'pagada', NULL, ?)
            ");
            $insert->execute([
                $codigo,
                (int) $linkId,
                $recordId,
                $userId === null ? null : (int) $userId,
                $nombre,
                // La columna no acepta null: sin email se guarda vacío.
                $email,
                $telefono,
                $cantidad,
                $config['moneda'],
                date('Y-m-d H:i:s'),
            ]);

            $ordenId = (int) $db->lastInsertId();

            if ($config['plano'] !== null) {
                $conLugar = $db->prepare('INSERT INTO ticket_order_lugares (order_id, link_id, lugar) VALUES (?, ?, ?)');

                foreach ($lugares as $lugar) {
                    $conLugar->execute([$ordenId, (int) $linkId, $lugar]);
                }
            }

            if ($items !== null) {
                $conTipo = $db->prepare('
                    INSERT INTO ticket_order_items (order_id, tipo, nombre, precio_unitario, personas, cantidad)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');

                foreach ($items as $item) {
                    $conTipo->execute([$ordenId, $item['tipo'], $item['nombre'], 0, $item['personas'], $item['cantidad']]);
                }
            }

            $db->commit();

            return [
                'ok' => true,
                'error' => null,
                'orden' => [
                    'id'       => $ordenId,
                    'codigo'   => $codigo,
                    'nombre'   => $nombre,
                    'email'    => $email,
                    'cantidad' => $cantidad,
                    'lugares'  => $config['plano'] === null ? [] : $lugares,
                    'items'    => $items === null ? [] : $items,
                ],
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Los invitados del evento, con quién entró, y cuánto lugar queda.
     *
     * @return array{invitados: array[], resumen: array}
     */
    public static function lista($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT o.id, o.codigo, o.nombre, o.email, o.telefono, o.cantidad, o.ingresadas, o.ingreso_en,
                   o.estado, o.created_at, o.cancelada_en, o.mail_enviado_en, o.mail_error,
                   u.name AS invitado_por
            FROM ticket_orders o
            LEFT JOIN users u ON u.id = o.invitado_por
            WHERE o.link_id = ? AND o.origen = 'invitacion'
            ORDER BY o.created_at DESC, o.id DESC
        ");
        $stmt->execute([(int) $linkId]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = Entradas::itemsPorOrden($db, $linkId);
        $config = Entradas::configDelEvento($db, $linkId);

        $lugares = 0;
        $ingresaron = 0;
        $invitados = [];

        foreach ($filas as $fila) {
            $vigente = $fila['estado'] === 'pagada';

            if ($vigente) {
                $lugares += (int) $fila['cantidad'];
                $ingresaron += (int) $fila['ingresadas'];
            }

            $invitados[] = [
                'id'           => (int) $fila['id'],
                'codigo'       => $fila['codigo'],
                'nombre'       => $fila['nombre'],
                'email'        => $fila['email'],
                'telefono'     => $fila['telefono'],
                'cantidad'     => (int) $fila['cantidad'],
                'ingresadas'   => (int) $fila['ingresadas'],
                'ingreso_en'   => $fila['ingreso_en'],
                // 'vigente' o 'anulado': para quien invita no hay más estados.
                'estado'       => $vigente ? 'vigente' : 'anulado',
                'anulado_en'   => $vigente ? null : $fila['cancelada_en'],
                'creado_en'    => $fila['created_at'],
                'mail_enviado' => !empty($fila['mail_enviado_en']),
                'mail_error'   => $fila['mail_error'],
                'invitado_por' => $fila['invitado_por'],
                'lugares'      => Entradas::lugaresDeLaOrden($db, $fila['id']),
                'tipos'        => TiposDeEntrada::resumir(isset($items[$fila['id']]) ? $items[$fila['id']] : []),
                'url'          => CodigoQR::urlDeLaOrden($fila['codigo']),
            ];
        }

        $capacidad = $config === null ? 0 : (int) $config['capacidad'];
        $limite = $config === null || !isset($config['max_invitados']) || $config['max_invitados'] === null
            ? null
            : (int) $config['max_invitados'];

        return [
            'invitados' => $invitados,
            'resumen' => [
                'configurado' => $config !== null,
                'lugares'     => $lugares,
                'ingresaron'  => $ingresaron,
                'capacidad'   => $capacidad,
                // Lo que queda en el evento para cualquiera: vendidas,
                // reservas vigentes e invitados ya se descontaron.
                'disponibles' => $config === null ? 0 : max(0, $capacidad - Entradas::ocupadas($db, $linkId)),
                'max_invitados' => $limite,
                'quedan_del_limite' => $limite === null ? null : max(0, $limite - $lugares),
                'plano'       => $config === null ? null : $config['plano'],
                'ocupados'    => $config === null || $config['plano'] === null ? [] : Entradas::lugaresOcupados($db, $linkId),
                'tipos'       => $config === null || $config['tipos'] === null ? null : array_map(function ($t) {
                    return ['id' => (string) $t['id'], 'nombre' => $t['nombre']];
                }, $config['tipos']),
            ],
        ];
    }

    /**
     * Fija o saca el límite de lugares para invitados del evento.
     *
     * @param int|null $limite null = sin límite
     * @return array{ok: bool, error: string|null, status?: int}
     */
    public static function guardarLimite($db, $linkId, $limite)
    {
        if (Entradas::configDelEvento($db, $linkId) === null) {
            return self::mal(409, 'Este evento todavía no tiene entradas configuradas');
        }

        if ($limite !== null) {
            $limite = (int) $limite;

            if ($limite < 0 || $limite > self::MAX_LIMITE) {
                return self::mal(400, 'El límite de invitados no es válido');
            }

            // Como la capacidad: no puede quedar por debajo de lo ya dado.
            $usados = self::lugaresDeInvitados($db, $linkId);

            if ($limite < $usados) {
                return self::mal(409, "Ya hay $usados lugares de invitados: el límite no puede ser menor");
            }
        }

        $stmt = $db->prepare('UPDATE event_ticketing SET max_invitados = ? WHERE link_id = ?');
        $stmt->execute([$limite, (int) $linkId]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * El invitado de un evento por su código, o null si no es un invitado.
     *
     * Las acciones de invitados sólo valen sobre invitados: anular por acá
     * una compra pagada sería cancelarla por la puerta de atrás.
     */
    public static function delEvento($db, $codigo)
    {
        $orden = Entradas::orden($db, $codigo);

        if ($orden === null || !self::esInvitacion($orden)) {
            return null;
        }

        return $orden;
    }

    /** Anula un invitado: su QR deja de valer y el lugar vuelve al cupo. */
    public static function anular($db, $codigo)
    {
        $resultado = Entradas::cancelar($db, $codigo);

        if (!$resultado['cancelada']) {
            return self::mal(409, $resultado['motivo'] === 'ya estaba cancelada'
                ? 'Ese invitado ya estaba anulado'
                : 'No se pudo anular el invitado');
        }

        return ['ok' => true, 'error' => null];
    }

    /** Si una orden (con su columna origen) es de un invitado. */
    public static function esInvitacion(array $orden)
    {
        return isset($orden['origen']) && $orden['origen'] === 'invitacion';
    }

    /** Lugares tomados por invitados vigentes del evento. */
    public static function lugaresDeInvitados($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(cantidad), 0)
            FROM ticket_orders
            WHERE link_id = ? AND origen = 'invitacion' AND estado = 'pagada'
        ");
        $stmt->execute([(int) $linkId]);

        return (int) $stmt->fetchColumn();
    }

    private static function mal($status, $error)
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
