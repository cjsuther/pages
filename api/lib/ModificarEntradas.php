<?php

/**
 * Modificar una compra de entradas ya confirmada: subir o bajar la cantidad,
 * cambiar cuántas de cada tipo o cambiar los lugares.
 *
 * La hace quien administra la página, nunca quien compró: el comprador tiene
 * el código de su orden, y con eso cualquiera podría sumarse entradas.
 *
 * No hay plata de por medio. El total, la comisión y lo que informó Mercado
 * Pago quedan como se pagaron: las entradas que se suman salen sin cargo y
 * las que se sacan no se devuelven. Así lo recaudado sigue coincidiendo con lo
 * que entró a la cuenta.
 *
 * El QR es uno por compra y apunta al código, que no cambia: la persona entra
 * con el mismo QR y en la puerta ve la cantidad nueva.
 *
 * Los controles son los de la venta —capacidad, cupo de cada tipo, promos
 * completas, lugares libres— con una diferencia: lo que ya tenía esta compra
 * cuenta como disponible para ella. Y sólo se mira la capacidad cuando la
 * compra crece: bajar una compra en un evento sobrevendido tiene que poder
 * hacerse.
 */
class ModificarEntradas
{
    /**
     * Lo que necesita la pantalla para editar una compra: la compra, el plano
     * con los lugares de los demás y cuántas quedan para ella.
     *
     * @return array{ok: bool, error: string|null, status?: int, datos?: array}
     */
    public static function datosParaEditar($db, $codigo)
    {
        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return self::mal(404, 'No encontramos esa compra');
        }

        $problema = self::problemaConLaOrden($orden);

        if ($problema !== null) {
            return self::mal(409, $problema);
        }

        $config = Entradas::configDelEvento($db, (int) $orden['link_id']);

        if ($config === null) {
            return self::mal(409, 'El evento ya no tiene entradas configuradas');
        }

        $linkId = (int) $orden['link_id'];
        $ordenId = (int) $orden['id'];
        $disponibles = max(0, (int) $config['capacidad'] - self::ocupadasPorOtras($db, $linkId, $ordenId));
        $tipos = null;

        if ($config['tipos'] !== null) {
            $ocupadasPorTipo = self::ocupadasPorTipoPorOtras($db, $linkId, $ordenId);

            $tipos = array_map(function ($tipo) use ($ocupadasPorTipo, $disponibles) {
                $personas = TiposDeEntrada::personas($tipo);
                $quedan = $disponibles;

                if ($tipo['cupo'] !== null) {
                    $tomadas = isset($ocupadasPorTipo[$tipo['id']]) ? $ocupadasPorTipo[$tipo['id']] : 0;
                    $quedan = min($quedan, max(0, (int) $tipo['cupo'] - $tomadas) * $personas);
                }

                return [
                    'id'          => (string) $tipo['id'],
                    'nombre'      => $tipo['nombre'],
                    'precio'      => (float) $tipo['precio'],
                    'personas'    => $personas,
                    'disponibles' => $quedan,
                ];
            }, $config['tipos']);
        }

        return [
            'ok' => true,
            'error' => null,
            'datos' => [
                'orden' => [
                    'codigo'     => $orden['codigo'],
                    'nombre'     => $orden['nombre'],
                    'email'      => $orden['email'],
                    'evento'     => $orden['evento'],
                    'cantidad'   => (int) $orden['cantidad'],
                    'ingresadas' => (int) $orden['ingresadas'],
                    'total'      => (float) $orden['total'],
                    'moneda'     => $orden['moneda'],
                    'lugares'    => $orden['lugares'],
                    'items'      => $orden['items'],
                ],
                // Lo que puede llegar a tener esta compra: lo que le queda al
                // evento contando como libre lo que ya es suyo.
                'disponibles' => $disponibles,
                'plano'       => $config['plano'],
                'ocupados'    => $config['plano'] === null ? [] : self::lugaresDeOtras($db, $linkId, $ordenId),
                'tipos'       => $tipos,
            ],
        ];
    }

    /**
     * Aplica el cambio.
     *
     * $pedido trae, según el evento: 'lugares' (con plano), 'tipos' (id =>
     * cantidad, con tipos y sin plano) o 'cantidad' (sin tipos ni plano).
     *
     * @return array{ok: bool, error: string|null, status?: int, antes?: array, despues?: array, modificacion_id?: int, ocupados?: string[]}
     */
    public static function modificar($db, $codigo, array $pedido, $userId)
    {
        $stmt = $db->prepare('SELECT id, link_id FROM ticket_orders WHERE codigo = ?');
        $stmt->execute([(string) $codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false || $fila['link_id'] === null) {
            return self::mal(404, 'No encontramos esa compra');
        }

        $ordenId = (int) $fila['id'];
        $linkId = (int) $fila['link_id'];

        $db->beginTransaction();

        try {
            // El mismo bloqueo que la venta: mientras se modifica, nadie más
            // compra en este evento. Después la orden, para que la puerta no
            // marque un ingreso entre que se lee y se escribe.
            $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ? FOR UPDATE');
            $stmt->execute([$linkId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($config === false) {
                $db->rollBack();
                return self::mal(409, 'El evento ya no tiene entradas configuradas');
            }

            $config = Entradas::decodificada($config);

            $stmt = $db->prepare('SELECT id, estado, cantidad, ingresadas FROM ticket_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$ordenId]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);

            $problema = self::problemaConLaOrden($orden);

            if ($problema !== null) {
                $db->rollBack();
                return self::mal(409, $problema);
            }

            $antes = [
                'cantidad' => (int) $orden['cantidad'],
                'lugares'  => Entradas::lugaresDeLaOrden($db, $ordenId),
                'items'    => Entradas::itemsDeLaOrden($db, $ordenId),
            ];

            $nuevo = self::loPedido($db, $linkId, $ordenId, $config, $pedido, $antes, (int) $orden['ingresadas']);

            if (!$nuevo['ok']) {
                $db->rollBack();
                return $nuevo;
            }

            $despues = $nuevo['despues'];

            if (self::iguales($antes, $despues)) {
                $db->rollBack();
                return self::mal(400, 'No hay cambios para guardar');
            }

            // La condición sobre ingresadas va también en el UPDATE: es la
            // garantía de no dejar una compra con más gente adentro que
            // entradas, aunque la puerta marque justo en este momento.
            $upd = $db->prepare("
                UPDATE ticket_orders
                SET cantidad = ?, modificada_en = NOW()
                WHERE id = ? AND estado = 'pagada' AND ingresadas <= ?
            ");
            $upd->execute([$despues['cantidad'], $ordenId, $despues['cantidad']]);

            if ($upd->rowCount() === 0) {
                $db->rollBack();
                return self::mal(409, 'La compra cambió mientras la editabas. Volvé a abrirla.');
            }

            $db->prepare('DELETE FROM ticket_order_lugares WHERE order_id = ?')->execute([$ordenId]);

            if ($config['plano'] !== null) {
                $conLugar = $db->prepare('INSERT INTO ticket_order_lugares (order_id, link_id, lugar) VALUES (?, ?, ?)');

                foreach ($despues['lugares'] as $lugar) {
                    $conLugar->execute([$ordenId, $linkId, $lugar]);
                }
            }

            $db->prepare('DELETE FROM ticket_order_items WHERE order_id = ?')->execute([$ordenId]);

            if ($despues['items'] !== []) {
                $conTipo = $db->prepare('
                    INSERT INTO ticket_order_items (order_id, tipo, nombre, precio_unitario, personas, cantidad)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');

                foreach ($despues['items'] as $item) {
                    $conTipo->execute([$ordenId, $item['tipo'], $item['nombre'], $item['precio'], $item['personas'], $item['cantidad']]);
                }
            }

            $historial = $db->prepare('
                INSERT INTO ticket_order_modificaciones (order_id, user_id, antes, despues)
                VALUES (?, ?, ?, ?)
            ');
            $historial->execute([
                $ordenId,
                $userId === null ? null : (int) $userId,
                json_encode($antes, JSON_UNESCAPED_UNICODE),
                json_encode($despues, JSON_UNESCAPED_UNICODE),
            ]);
            $modificacionId = (int) $db->lastInsertId();

            $db->commit();

            return [
                'ok' => true,
                'error' => null,
                'antes' => $antes,
                'despues' => $despues,
                'modificacion_id' => $modificacionId,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /** Anota en el historial cómo le fue al mail que avisa el cambio. */
    public static function anotarMail($db, $modificacionId, array $envio)
    {
        if ($envio['enviado']) {
            $stmt = $db->prepare('UPDATE ticket_order_modificaciones SET mail_enviado_en = NOW(), mail_error = NULL WHERE id = ?');
            $stmt->execute([(int) $modificacionId]);

            return;
        }

        $stmt = $db->prepare('UPDATE ticket_order_modificaciones SET mail_error = ? WHERE id = ?');
        $stmt->execute([mb_substr((string) $envio['motivo'], 0, 255), (int) $modificacionId]);
    }

    // --------------------------------------------------------------- internos

    /**
     * Por qué esta compra no se puede modificar, o null.
     *
     * Sólo las confirmadas: pagadas o reservas sin costo, que se guardan como
     * pagadas. Una que espera el pago no, porque Mercado Pago cobraría lo de
     * antes; una vencida o cancelada ya no tiene lugar que cambiar.
     */
    private static function problemaConLaOrden($orden)
    {
        if (!is_array($orden)) {
            return 'No encontramos esa compra';
        }

        if ($orden['estado'] !== 'pagada') {
            return 'Sólo se pueden modificar compras pagadas o reservas confirmadas';
        }

        return null;
    }

    /**
     * Cómo queda la compra con lo que se pidió, o por qué no puede quedar así.
     */
    private static function loPedido($db, $linkId, $ordenId, array $config, array $pedido, array $antes, $ingresadas)
    {
        $lugares = [];
        $porTipo = null;

        if ($config['plano'] !== null) {
            if (!isset($pedido['lugares']) || !is_array($pedido['lugares'])) {
                return self::mal(400, 'Elegí los lugares en el plano');
            }

            $lugares = array_values(array_unique(array_map('strval', $pedido['lugares'])));
            $cantidad = count($lugares);
        } elseif ($config['tipos'] !== null && isset($pedido['tipos'])) {
            $porTipo = Entradas::pedidoPorTipo($pedido['tipos']);

            if ($porTipo === null) {
                return self::mal(400, 'Los tipos de entrada no tienen el formato esperado');
            }

            // Una promo pedida a medias se completa, como en la venta.
            $porTipo = TiposDeEntrada::completarGrupos($porTipo, $config['tipos']);
            $cantidad = array_sum($porTipo);
        } else {
            if (!isset($pedido['cantidad']) || !is_numeric($pedido['cantidad'])) {
                return self::mal(400, 'Falta la cantidad');
            }

            $cantidad = (int) $pedido['cantidad'];

            if ($config['tipos'] !== null) {
                if (count($config['tipos']) > 1) {
                    return self::mal(400, 'Elegí cuántas entradas de cada tipo');
                }

                $porTipo = TiposDeEntrada::completarGrupos([(string) $config['tipos'][0]['id'] => $cantidad], $config['tipos']);
                $cantidad = array_sum($porTipo);
            }
        }

        if ($cantidad < 1) {
            return self::mal(400, 'La compra tiene que quedar con al menos una entrada. Para darla de baja, cancelala.');
        }

        if ($cantidad < $ingresadas) {
            return self::mal(409, $ingresadas === 1
                ? 'Ya entró 1 persona con esta compra: no puede quedar sin entradas'
                : "Ya entraron $ingresadas personas con esta compra: no puede quedar con menos de $ingresadas entradas");
        }

        if ($config['plano'] !== null) {
            $problema = self::problemaConLosLugares($db, $linkId, $ordenId, $config['plano'], $lugares);

            if ($problema !== null) {
                return $problema;
            }
        }

        // Sólo si la compra crece: bajar siempre se puede, aunque el evento
        // esté sobrevendido porque alguien achicó la capacidad.
        if ($cantidad > $antes['cantidad']) {
            $disponibles = (int) $config['capacidad'] - self::ocupadasPorOtras($db, $linkId, $ordenId);

            if ($cantidad > $disponibles) {
                return self::mal(409, $disponibles <= $antes['cantidad']
                    ? 'No quedan entradas: la compra no puede crecer'
                    : 'Sólo hay lugar para ' . $disponibles . ' entradas en esta compra');
            }
        }

        $items = [];

        if ($config['tipos'] !== null) {
            $items = self::items($db, $linkId, $ordenId, $config, $lugares, $porTipo, $antes['items']);

            if (is_string($items)) {
                return self::mal(409, $items);
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'despues' => ['cantidad' => $cantidad, 'lugares' => $lugares, 'items' => $items],
        ];
    }

    /**
     * Los renglones por tipo de la compra modificada, o por qué no pueden ser.
     *
     * El precio de un tipo que la compra ya tenía queda como se pagó; uno
     * nuevo toma el precio de hoy, que sirve sólo para el desglose del panel:
     * no se cobra.
     *
     * @return array|string
     */
    private static function items($db, $linkId, $ordenId, array $config, array $lugares, $porTipo, array $itemsAntes)
    {
        $tipos = $config['tipos'];
        $porId = TiposDeEntrada::porId($tipos);

        if ($config['plano'] !== null) {
            $tipoDe = TiposDeEntrada::tiposPorLugar($config['plano'], $tipos);
            $porTipo = [];

            foreach ($lugares as $lugar) {
                $porTipo[$tipoDe[$lugar]] = (isset($porTipo[$tipoDe[$lugar]]) ? $porTipo[$tipoDe[$lugar]] : 0) + 1;
            }
        }

        foreach (array_keys($porTipo) as $id) {
            if (!isset($porId[$id])) {
                return 'Uno de los tipos de entrada ya no existe. Volvé a abrir la compra.';
            }
        }

        $antesPorTipo = [];

        foreach ($itemsAntes as $item) {
            $antesPorTipo[$item['tipo']] = $item;
        }

        $ocupadas = self::ocupadasPorTipoPorOtras($db, $linkId, $ordenId);
        $items = [];

        foreach ($tipos as $tipo) {
            $id = (string) $tipo['id'];

            if (!isset($porTipo[$id])) {
                continue;
            }

            $personas = TiposDeEntrada::personas($tipo);
            $unidades = TiposDeEntrada::unidades($porTipo[$id], $personas);
            $unidadesAntes = isset($antesPorTipo[$id])
                ? TiposDeEntrada::unidades($antesPorTipo[$id]['cantidad'], $antesPorTipo[$id]['personas'])
                : 0;

            // Como la capacidad: el cupo sólo se mira si este tipo crece.
            if ($tipo['cupo'] !== null && $unidades > $unidadesAntes) {
                $quedan = (int) $tipo['cupo'] - (isset($ocupadas[$id]) ? $ocupadas[$id] : 0);

                if ($unidades > $quedan) {
                    return $quedan <= $unidadesAntes
                        ? "No quedan entradas \"{$tipo['nombre']}\""
                        : ($personas === 1
                            ? "Sólo quedan $quedan entradas \"{$tipo['nombre']}\" para esta compra"
                            : 'Sólo quedan ' . ($quedan * $personas) . " lugares en \"{$tipo['nombre']}\" para esta compra");
                }
            }

            $items[] = [
                'tipo'     => $id,
                'nombre'   => $tipo['nombre'],
                'precio'   => isset($antesPorTipo[$id]) ? $antesPorTipo[$id]['precio'] : round((float) $tipo['precio'], 2),
                'personas' => $personas,
                'cantidad' => $porTipo[$id],
            ];
        }

        return $items;
    }

    /** @return array|null */
    private static function problemaConLosLugares($db, $linkId, $ordenId, array $plano, array $lugares)
    {
        $inexistentes = array_diff($lugares, Plano::lugares($plano));

        if ($inexistentes !== []) {
            return self::mal(400, 'Ese lugar no existe en el plano. Volvé a abrir la compra.');
        }

        $deOtras = self::lugaresDeOtras($db, $linkId, $ordenId);
        $tomados = array_values(array_intersect($lugares, $deOtras));

        if ($tomados !== []) {
            return self::mal(409, (count($tomados) === 1 ? 'Ese lugar ya es de otra compra: ' : 'Estos lugares ya son de otra compra: ')
                . Plano::resumir($tomados) . '. Elegí otro.') + ['ocupados' => $deOtras];
        }

        return null;
    }

    /**
     * Entradas tomadas por las demás compras del evento: la regla de
     * Entradas::ocupadas() sin contar esta.
     */
    private static function ocupadasPorOtras($db, $linkId, $ordenId)
    {
        return (int) self::valor($db, "
            SELECT COALESCE(SUM(cantidad), 0)
            FROM ticket_orders
            WHERE link_id = ? AND id <> ?
              AND (estado = 'pagada'
                   OR (estado = 'reservada' AND reserva_vence_en > NOW()))
        ", [(int) $linkId, (int) $ordenId]);
    }

    /** Como Entradas::ocupadasPorTipo(), sin contar esta compra. */
    private static function ocupadasPorTipoPorOtras($db, $linkId, $ordenId)
    {
        $stmt = $db->prepare("
            SELECT i.tipo, COALESCE(SUM(CEIL(i.cantidad / i.personas)), 0) AS cantidad
            FROM ticket_order_items i
            INNER JOIN ticket_orders o ON o.id = i.order_id
            WHERE o.link_id = ? AND o.id <> ?
              AND (o.estado = 'pagada'
                   OR (o.estado = 'reservada' AND o.reserva_vence_en > NOW()))
            GROUP BY i.tipo
        ");
        $stmt->execute([(int) $linkId, (int) $ordenId]);

        $porTipo = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $porTipo[(string) $fila['tipo']] = (int) $fila['cantidad'];
        }

        return $porTipo;
    }

    /** Como Entradas::lugaresOcupados(), sin contar esta compra. */
    private static function lugaresDeOtras($db, $linkId, $ordenId)
    {
        $stmt = $db->prepare("
            SELECT tl.lugar
            FROM ticket_order_lugares tl
            INNER JOIN ticket_orders o ON o.id = tl.order_id
            WHERE tl.link_id = ? AND o.id <> ?
              AND (o.estado = 'pagada'
                   OR (o.estado = 'reservada' AND o.reserva_vence_en > NOW()))
        ");
        $stmt->execute([(int) $linkId, (int) $ordenId]);

        return array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** Si el cambio pedido deja la compra exactamente como estaba. */
    private static function iguales(array $antes, array $despues)
    {
        if ($antes['cantidad'] !== $despues['cantidad']) {
            return false;
        }

        $lugaresAntes = $antes['lugares'];
        $lugaresDespues = $despues['lugares'];
        sort($lugaresAntes);
        sort($lugaresDespues);

        if ($lugaresAntes !== $lugaresDespues) {
            return false;
        }

        $porTipo = function (array $items) {
            $mapa = [];

            foreach ($items as $item) {
                $mapa[$item['tipo']] = (int) $item['cantidad'];
            }

            ksort($mapa);

            return $mapa;
        };

        return $porTipo($antes['items']) === $porTipo($despues['items']);
    }

    private static function valor($db, $sql, array $params)
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private static function mal($status, $error)
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
