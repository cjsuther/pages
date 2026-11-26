<?php

/**
 * Devoluciones de dinero a quienes compraron entradas.
 *
 * --- Qué se devuelve ---
 *
 * Sólo lo que le llegó al organizador: el neto que informa Mercado Pago
 * (net_received_amount, guardado en ticket_orders.mp_neto) menos lo que ya se
 * devolvió. Lo que se quedaron Mercado Pago y Rezonar no se devuelve. Con
 * cargos al comprador, eso es en la práctica el precio de las entradas.
 *
 * Se devuelve la compra completa (todo lo que queda, y la compra se anula) o
 * algunas entradas (la parte proporcional, según lo que valen, y las entradas
 * se sacan de la compra). La última entrada se lleva el resto, así que
 * devolver de a una termina devolviendo exactamente el neto.
 *
 * --- Cómo ---
 *
 * Es un reembolso de Mercado Pago (POST /v1/payments/{id}/refunds, parcial con
 * amount) hecho con el token OAuth del vendedor: se ve como devolución en su
 * cuenta y en la de quien compró. Nada de transferencias por fuera.
 *
 * --- RIESGO conocido, a verificar en sandbox ---
 *
 * La documentación del split de Mercado Pago dice que en un reembolso "o valor
 * devido ao cliente final será dividido e subtraído da conta do vendedor e da
 * conta do Marketplace, sendo proporcional". Si es así, un reembolso parcial
 * también le descuenta una parte a la comisión de Rezonar, y no sólo al
 * organizador. Por eso, después de cada reembolso se consulta el pago y se
 * guarda en `reparto` lo que informa Mercado Pago (fee_details, neto,
 * application_fee). docs/PRUEBA-DEVOLUCIONES-SANDBOX.md explica cómo medirlo.
 *
 * --- Seguridad de la plata ---
 *
 *  - Se anota el intento (estado 'pendiente', con su clave de idempotencia)
 *    ANTES de llamar a Mercado Pago, con la orden bloqueada. Mientras haya uno
 *    pendiente o incierto, no se puede pedir otro.
 *  - Tope: lo devuelto (lo nuestro y lo que diga Mercado Pago, lo que sea
 *    mayor) nunca supera el neto.
 *  - Si no sabemos cómo terminó (timeout, 5xx), queda 'incierta' y el cron le
 *    pregunta a Mercado Pago. Nunca se reintenta solo: el dinero lo mueve sólo
 *    un pedido explícito de quien organiza.
 *  - Todo detrás de DEVOLUCIONES_HABILITADAS (apagado si no está definido).
 */
class Devoluciones
{
    /** Mercado Pago permite devolver hasta 180 días después de aprobado. */
    const DIAS_MAXIMOS = 180;

    /**
     * Una 'incierta' que Mercado Pago no tiene registrada después de este
     * tiempo no se hizo. Antes se espera: el listado puede tardar en verla.
     */
    const MINUTOS_PARA_DESCARTAR = 30;

    /** Una 'pendiente' más vieja que esto es un proceso que se murió en el medio. */
    const MINUTOS_PENDIENTE = 2;

    /** Un mismo bloqueo repetido en este lapso es un doble clic: no se anota dos veces. */
    const SEGUNDOS_REPETIDO = 60;

    const LIMITE_A_CONCILIAR = 50;

    /** Sólo para tests: true/false impuesto, o null para leer config.php. */
    private static $forzada = null;

    public static function habilitadas()
    {
        if (self::$forzada !== null) {
            return self::$forzada;
        }

        return defined('DEVOLUCIONES_HABILITADAS') && DEVOLUCIONES_HABILITADAS === true;
    }

    /** Sólo para tests. */
    public static function forzar($valor)
    {
        self::$forzada = $valor === null ? null : (bool) $valor;
    }

    // ------------------------------------------------------------- motivos

    /** Los motivos de nuestros bloqueos, en castellano. */
    const MOTIVOS = [
        'apagadas'          => 'Las devoluciones no están habilitadas en la plataforma.',
        'invitado'          => 'Es un invitado: no hubo pago, no hay nada para devolver.',
        'sin_costo'         => 'La compra no tuvo costo: no hay nada para devolver.',
        'no_pagada'         => 'La compra no está pagada: no hay nada para devolver.',
        'sin_pago'          => 'La compra no tiene un pago de Mercado Pago asociado.',
        'vieja'             => 'El pago tiene más de 180 días: Mercado Pago ya no permite devolverlo.',
        'usadas'            => 'Ya entró gente con esta compra: las entradas usadas no se devuelven.',
        'sin_credenciales'  => 'La página no tiene una cuenta de Mercado Pago conectada.',
        'consulta_fallida'  => 'No pudimos consultar el pago en Mercado Pago. Probá de nuevo en unos minutos.',
        'pago_no_aprobado'  => 'Mercado Pago dice que el pago no está aprobado: no se puede devolver.',
        'sin_neto'          => 'Mercado Pago no informó cuánto le llegó a la cuenta por esta compra: no se puede calcular la devolución.',
        'nada'              => 'Ya se devolvió todo lo que llegó a la cuenta por esta compra.',
        'monto_minimo'      => 'Lo que corresponde devolver por esas entradas es menos de un centavo.',
    ];

    /**
     * Por qué Mercado Pago no hizo el reembolso, en castellano.
     *
     * Los códigos salen de la referencia de la API (create refund). Saldo
     * insuficiente no tiene un código publicado: se reconoce por el texto.
     */
    public static function motivoDeMercadoPago($statusHttp, $codigo, $mensaje)
    {
        $codigo = (string) $codigo;
        $texto = mb_strtolower((string) $mensaje);

        if (strpos($texto, 'insufficient') !== false || strpos($texto, 'balance') !== false
            || strpos($texto, 'saldo') !== false || strpos($texto, 'not enough') !== false) {
            return 'No hay saldo suficiente en la cuenta de Mercado Pago del organizador para hacer la devolución.';
        }

        if (in_array($codigo, ['3024', '4295'], true) || strpos($texto, 'partial') !== false) {
            return 'Mercado Pago no permite devoluciones parciales para este pago (depende del medio con el que se pagó).';
        }

        if (in_array($codigo, ['2024', '15016'], true) || strpos($texto, 'too old') !== false) {
            return 'El pago tiene más de 180 días: Mercado Pago ya no permite devolverlo.';
        }

        if (in_array($codigo, ['2063', '4293'], true)) {
            return 'El pago no está en un estado que permita devolverlo (puede estar en disputa o ya devuelto).';
        }

        if ($codigo === '4296') {
            return 'Mercado Pago dice que ese pago ya fue devuelto.';
        }

        if (in_array($codigo, ['4040', '4041'], true)) {
            return 'Mercado Pago rechazó el monto de la devolución.';
        }

        if ($codigo === '2000') {
            return 'Mercado Pago no encuentra el pago.';
        }

        if ((int) $statusHttp === 401 || (int) $statusHttp === 403 || $codigo === '401'
            || $codigo === 'unauthorized' || $codigo === 'forbidden') {
            return 'Mercado Pago rechazó la credencial de la cuenta conectada. Hay que volver a conectar Mercado Pago.';
        }

        if ((int) $statusHttp === 429) {
            return 'Mercado Pago está recibiendo demasiados pedidos. Probá de nuevo en unos minutos.';
        }

        if ($codigo === 'rejected' || $codigo === 'cancelled') {
            return 'Mercado Pago rechazó la devolución.';
        }

        return 'Mercado Pago rechazó la devolución (código ' . ($codigo === '' ? $statusHttp : $codigo) . ').';
    }

    // ----------------------------------------------------------- consultas

    /**
     * Lo que necesita la pantalla antes de devolver: cuánto llegó, cuánto se
     * devolvió, cuánto queda, qué entradas tiene y los intentos anteriores.
     *
     * Le pregunta a Mercado Pago el neto de verdad. No anota nada.
     *
     * @return array{ok: bool, status?: int, error?: string, datos?: array}
     */
    public static function preparar($db, $codigo, $http = null)
    {
        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return self::mal(404, 'No encontramos esa compra');
        }

        $aviso = self::bloqueoSinMercadoPago($orden);
        $neto = $orden['mp_neto'] === null ? null : (float) $orden['mp_neto'];
        $devueltoMp = 0.0;

        if ($aviso === null) {
            $token = Cobros::tokenDelEvento($db, (int) $orden['link_id'], $http);

            if ($token === null) {
                $aviso = 'sin_credenciales';
            } else {
                $pago = (new MercadoPago($token, $http))->consultarPago($orden['mp_payment_id']);

                if (!$pago['ok']) {
                    $aviso = 'consulta_fallida';
                } else {
                    $neto = $pago['neto'] === null ? $neto : (float) $pago['neto'];
                    $devueltoMp = (float) $pago['devuelto'];

                    if ($pago['estado'] !== 'approved') {
                        $aviso = 'pago_no_aprobado';
                    } elseif ($neto === null) {
                        $aviso = 'sin_neto';
                    }
                }
            }
        }

        $devuelto = self::devueltoSegunNosotros($db, (int) $orden['id']);
        $devolvible = $neto === null ? 0.0 : self::devolvible($neto, max($devuelto, $devueltoMp));

        if ($aviso === null && $devolvible <= 0) {
            $aviso = 'nada';
        }

        $total = (float) $orden['total'];
        $comisionPlataforma = $orden['mp_comision_cobrada'] !== null
            ? (float) $orden['mp_comision_cobrada']
            : (float) $orden['comision'];

        return [
            'ok' => true,
            'datos' => [
                'orden' => [
                    'codigo'     => $orden['codigo'],
                    'nombre'     => $orden['nombre'],
                    'email'      => $orden['email'],
                    'evento'     => $orden['evento'],
                    'estado'     => $orden['estado'],
                    'cantidad'   => (int) $orden['cantidad'],
                    'ingresadas' => (int) $orden['ingresadas'],
                    'moneda'     => $orden['moneda'],
                    'lugares'    => $orden['lugares'],
                    'items'      => $orden['items'],
                    'pagada_en'  => $orden['pagada_en'],
                ],
                'desglose' => [
                    'cobrado'              => round($total, 2),
                    'comision_plataforma'  => round($comisionPlataforma, 2),
                    'comision_mercadopago' => $neto === null ? null : round($total - $neto - $comisionPlataforma, 2),
                    'neto'                 => $neto === null ? null : round($neto, 2),
                    'devuelto'             => round(max($devuelto, $devueltoMp), 2),
                    'devolvible'           => $devolvible,
                ],
                // Cómo se eligen las entradas a devolver en este evento.
                'por_entrada' => self::formaDeElegir($db, $orden),
                'aviso'       => $aviso === null ? null : self::MOTIVOS[$aviso],
                'intentos'    => self::intentos($db, (int) $orden['id']),
            ],
        ];
    }

    /** El historial de intentos de una compra, del más nuevo al más viejo. */
    public static function intentos($db, $ordenId)
    {
        $stmt = $db->prepare('
            SELECT id, modo, monto, estado, origen_rechazo, motivo_rechazo, mp_error_codigo,
                   mp_refund_id, entradas_error, mail_enviado_en, created_at, resuelta_en
            FROM ticket_order_devoluciones
            WHERE order_id = ?
            ORDER BY id DESC
        ');
        $stmt->execute([(int) $ordenId]);

        return array_map(function ($fila) {
            $fila['id'] = (int) $fila['id'];
            $fila['monto'] = (float) $fila['monto'];

            return $fila;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------ devolver

    /**
     * Pide la devolución.
     *
     * $pedido: modo ('completa'|'entradas'); para 'entradas', cantidad, tipos
     * o lugares (lo que se saca); confirmacion (el monto que vio quien la
     * pide); simular (true = sólo calcular, sin tocar a Mercado Pago).
     *
     * Los bloqueos (entradas usadas, invitado, nada para devolver...) se anotan
     * como intento rechazado, con su motivo. Un pedido mal formado, uno que
     * choca con otro en curso o un monto que cambió, no.
     *
     * @return array{ok: bool, status: int, error?: string, ...}
     */
    public static function devolver($db, $codigo, array $pedido, $userId, $http = null, $mailer = null)
    {
        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return self::mal(404, 'No encontramos esa compra');
        }

        $ordenId = (int) $orden['id'];
        $modo = isset($pedido['modo']) ? $pedido['modo'] : null;
        $simular = !empty($pedido['simular']);

        if (!in_array($modo, ['completa', 'entradas'], true)) {
            return self::mal(400, 'modo tiene que ser completa o entradas');
        }

        $confirmacion = null;

        if (!$simular) {
            if (!isset($pedido['confirmacion']) || !is_numeric($pedido['confirmacion'])) {
                return self::mal(400, 'Falta confirmar el monto a devolver');
            }

            $confirmacion = round((float) $pedido['confirmacion'], 2);
        }

        $quitar = [];

        if ($modo === 'entradas') {
            foreach (['cantidad', 'tipos', 'lugares'] as $campo) {
                if (array_key_exists($campo, $pedido)) {
                    $quitar[$campo] = $pedido[$campo];
                }
            }
        }

        $intento = ['modo' => $modo, 'user_id' => $userId, 'pedido' => $modo === 'entradas' ? ['quitar' => $quitar] : null];

        // 1. Lo que se sabe sin preguntarle a nadie.
        $bloqueo = self::habilitadas() ? self::bloqueoSinMercadoPago($orden) : 'apagadas';

        if ($bloqueo === null && $modo === 'completa' && (int) $orden['ingresadas'] > 0) {
            $bloqueo = 'usadas';
        }

        if ($bloqueo !== null) {
            return self::bloquear($db, $ordenId, $intento, $bloqueo, $bloqueo === 'apagadas' ? 403 : 409);
        }

        // 2. El neto de verdad, preguntado ahora.
        $token = Cobros::tokenDelEvento($db, (int) $orden['link_id'], $http);

        if ($token === null) {
            return self::bloquear($db, $ordenId, $intento, 'sin_credenciales', 409);
        }

        $mp = new MercadoPago($token, $http);
        $pago = $mp->consultarPago($orden['mp_payment_id']);

        if (!$pago['ok']) {
            return self::bloquear($db, $ordenId, $intento, 'consulta_fallida', 502, 'mercadopago');
        }

        if ($pago['estado'] !== 'approved') {
            return self::bloquear($db, $ordenId, $intento, 'pago_no_aprobado', 409, 'mercadopago');
        }

        $neto = $pago['neto'] !== null ? (float) $pago['neto'] : ($orden['mp_neto'] === null ? null : (float) $orden['mp_neto']);

        if ($neto === null) {
            return self::bloquear($db, $ordenId, $intento, 'sin_neto', 409, 'mercadopago');
        }

        // Ya que se preguntó: si el neto no estaba guardado, se guarda.
        if ($orden['mp_neto'] === null) {
            Entradas::completarDetalleDePago($db, $codigo, $pago);
        }

        // 3. Con la orden bloqueada: lo que queda, el monto, y el intento.
        $db->beginTransaction();

        try {
            $linkId = (int) $orden['link_id'];
            $config = null;

            if ($modo === 'entradas') {
                // El mismo orden de bloqueo que modificar(): evento y orden.
                $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ? FOR UPDATE');
                $stmt->execute([$linkId]);
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
                $config = $config === false ? null : Entradas::decodificada($config);
            }

            $stmt = $db->prepare('SELECT id, estado, cantidad, ingresadas, devuelto FROM ticket_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$ordenId]);
            $actual = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($actual === false || $actual['estado'] !== 'pagada') {
                $db->rollBack();
                return self::bloquear($db, $ordenId, $intento, 'no_pagada', 409);
            }

            if (self::hayEnCurso($db, $ordenId)) {
                $db->rollBack();
                return self::mal(409, 'Ya hay una devolución en curso para esta compra. Esperá a que termine.');
            }

            if ($modo === 'completa' && (int) $actual['ingresadas'] > 0) {
                $db->rollBack();
                return self::bloquear($db, $ordenId, $intento, 'usadas', 409);
            }

            $devuelto = max(self::devueltoSegunNosotros($db, $ordenId), (float) $pago['devuelto']);
            $devolvible = self::devolvible($neto, $devuelto);

            if ($devolvible <= 0) {
                $db->rollBack();
                return self::bloquear($db, $ordenId, $intento, 'nada', 409);
            }

            $todas = $modo === 'completa';
            $despues = null;
            $monto = $devolvible;

            if ($modo === 'entradas') {
                if ($config === null) {
                    $db->rollBack();
                    return self::mal(409, 'El evento ya no tiene entradas configuradas: devolvé la compra completa');
                }

                $nuevo = ModificarEntradas::sinEntradas($db, $ordenId, $linkId, $config, $quitar, (int) $actual['ingresadas']);

                if (!$nuevo['ok']) {
                    $db->rollBack();

                    // Las entradas usadas son un motivo de verdad; un pedido
                    // que no tiene sentido (400) es un error de la pantalla.
                    return $nuevo['status'] === 409
                        ? self::bloquear($db, $ordenId, $intento, null, 409, 'validacion', $nuevo['error'])
                        : $nuevo + ['status' => 400];
                }

                $todas = $nuevo['todas'];

                if (!$todas) {
                    $despues = $nuevo['despues'];
                    $monto = self::parteDe($devolvible, $nuevo['antes'], $despues);
                    $intento['pedido'] = ['quitar' => $quitar, 'antes' => $nuevo['antes'], 'despues' => $despues];
                }
            }

            if ($todas) {
                if ((int) $actual['ingresadas'] > 0) {
                    $db->rollBack();
                    return self::bloquear($db, $ordenId, $intento, 'usadas', 409);
                }

                $intento['modo'] = 'completa';
                $intento['pedido'] = $modo === 'entradas' ? ['quitar' => $quitar, 'todas' => true] : null;
            }

            if ($monto < 0.01) {
                $db->rollBack();
                return self::bloquear($db, $ordenId, $intento, 'monto_minimo', 409);
            }

            $calculo = [
                'monto'      => $monto,
                'neto'       => round($neto, 2),
                'devuelto'   => round($devuelto, 2),
                'devolvible' => $devolvible,
                'modo'       => $intento['modo'],
                'despues'    => $despues,
            ];

            if ($simular) {
                $db->rollBack();
                return ['ok' => true, 'status' => 200, 'simulada' => true] + $calculo;
            }

            // Quien confirmó vio otro número: algo cambió en el medio (otra
            // devolución, el neto). Se le muestra el nuevo y se le pide de vuelta.
            if (abs($confirmacion - $monto) > 0.005) {
                $db->rollBack();
                return self::mal(409, 'El monto a devolver cambió: ahora es ' . self::pesos($monto) . '. Revisalo y confirmá de nuevo.')
                    + ['monto' => $monto];
            }

            $clave = 'rz-dev-' . $ordenId . '-' . bin2hex(random_bytes(12));

            $ins = $db->prepare("
                INSERT INTO ticket_order_devoluciones
                    (order_id, user_id, modo, monto, neto_al_pedir, devuelto_antes, pedido,
                     estado, mp_payment_id, idempotency_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)
            ");
            $ins->execute([
                $ordenId,
                $userId === null ? null : (int) $userId,
                $intento['modo'],
                $monto,
                round($neto, 2),
                round($devuelto, 2),
                $intento['pedido'] === null ? null : json_encode($intento['pedido'], JSON_UNESCAPED_UNICODE),
                (string) $orden['mp_payment_id'],
                $clave,
            ]);
            $devolucionId = (int) $db->lastInsertId();

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        // 4. Mercado Pago. Fuera de la transacción: la llamada puede tardar y
        //    el intento ya quedó anotado (y bloquea uno nuevo).
        $respuesta = $mp->devolver($orden['mp_payment_id'], $monto, $clave);

        return self::resolver($db, $devolucionId, $respuesta, $mp, $mailer);
    }

    /**
     * Aplica lo que contestó Mercado Pago a un intento.
     *
     * @return array respuesta para el handler
     */
    private static function resolver($db, $devolucionId, array $respuesta, MercadoPago $mp, $mailer)
    {
        if ($respuesta['resultado'] === 'aprobada') {
            $aplicada = self::aprobar($db, $devolucionId, $respuesta);
            $fila = self::fila($db, $devolucionId);
            $envio = null;

            // Si ya estaba aprobada (otra corrida llegó antes), no se repite
            // ni la consulta del reparto ni el mail.
            if ($aplicada['aplicada']) {
                self::guardarReparto($db, $devolucionId, $mp, $fila['mp_payment_id'], $respuesta['respuesta']);
                $envio = self::avisar($db, $devolucionId, $mailer);
            }

            return [
                'ok'           => true,
                'status'       => 200,
                'estado'       => 'aprobada',
                'monto'        => (float) $fila['monto'],
                'entradas_error' => $aplicada['entradas_error'],
                'mail'         => $envio,
            ];
        }

        if ($respuesta['resultado'] === 'rechazada') {
            $motivo = self::motivoDeMercadoPago($respuesta['status_http'], $respuesta['error_codigo'], $respuesta['error_mensaje']);
            self::rechazar($db, $devolucionId, 'mercadopago', $motivo, $respuesta);

            return self::mal(409, $motivo) + ['estado' => 'rechazada'];
        }

        $upd = $db->prepare("
            UPDATE ticket_order_devoluciones
            SET estado = 'incierta', mp_status_http = ?, mp_refund_id = COALESCE(?, mp_refund_id)
            WHERE id = ? AND estado = 'pendiente'
        ");
        $upd->execute([$respuesta['status_http'], $respuesta['id'], $devolucionId]);

        return [
            'ok'     => true,
            'status' => 202,
            'estado' => 'incierta',
            'mensaje' => 'Mercado Pago no confirmó la devolución todavía. La estamos verificando: '
                . 'no la vuelvas a pedir. En unos minutos aparece como hecha o como no realizada.',
        ];
    }

    /**
     * Marca el intento como hecho, suma lo devuelto y saca las entradas.
     *
     * Idempotente: si el intento ya estaba aprobado (dos corridas de la
     * conciliación), no suma dos veces.
     *
     * @return array{aplicada: bool, entradas_error: string|null}
     */
    private static function aprobar($db, $devolucionId, array $respuesta)
    {
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('SELECT * FROM ticket_order_devoluciones WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $devolucionId]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fila === false || !in_array($fila['estado'], ['pendiente', 'incierta'], true)) {
                $db->rollBack();
                return ['aplicada' => false, 'entradas_error' => null];
            }

            $ordenId = (int) $fila['order_id'];
            $pedido = $fila['pedido'] === null ? null : json_decode($fila['pedido'], true);

            $stmt = $db->prepare('SELECT link_id FROM ticket_orders WHERE id = ?');
            $stmt->execute([$ordenId]);
            $linkId = $stmt->fetchColumn();
            $linkId = $linkId === false || $linkId === null ? null : (int) $linkId;

            $config = null;

            if ($fila['modo'] === 'entradas' && $linkId !== null) {
                $stmt = $db->prepare('SELECT * FROM event_ticketing WHERE link_id = ? FOR UPDATE');
                $stmt->execute([$linkId]);
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
                $config = $config === false ? null : Entradas::decodificada($config);
            }

            $stmt = $db->prepare('SELECT id, estado, cantidad, ingresadas FROM ticket_orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$ordenId]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);

            $upd = $db->prepare("
                UPDATE ticket_order_devoluciones
                SET estado = 'aprobada', mp_refund_id = ?, mp_status_http = ?, resuelta_en = NOW()
                WHERE id = ? AND estado IN ('pendiente', 'incierta')
            ");
            $upd->execute([$respuesta['id'], $respuesta['status_http'], (int) $devolucionId]);

            $upd = $db->prepare('
                UPDATE ticket_orders
                SET devuelto = devuelto + ?, devuelta_en = NOW(), devolucion_error = NULL, devolucion_error_en = NULL
                WHERE id = ?
            ');
            $upd->execute([(float) $fila['monto'], $ordenId]);

            // La plata ya salió: si las entradas no se pueden sacar (la compra
            // cambió mientras tanto) igual se registra la devolución, y queda
            // anotado para resolverlo a mano.
            $error = null;

            if ($orden === false || $orden['estado'] !== 'pagada') {
                $error = 'La compra ya no estaba pagada: las entradas no se tocaron.';
            } elseif ($fila['modo'] === 'completa') {
                if ((int) $orden['ingresadas'] > 0) {
                    $error = 'Entró gente con la compra mientras se devolvía: no se anuló. Revisala.';
                } else {
                    $upd = $db->prepare("
                        UPDATE ticket_orders SET estado = 'cancelada', cancelada_en = NOW()
                        WHERE id = ? AND estado = 'pagada' AND ingresadas = 0
                    ");
                    $upd->execute([$ordenId]);

                    if ($upd->rowCount() === 0) {
                        $error = 'No se pudo anular la compra: cambió mientras se devolvía. Revisala.';
                    }
                }
            } else {
                $error = self::sacarEntradas($db, $ordenId, $linkId, $config, $orden, $pedido, $fila['user_id']);
            }

            if ($error !== null) {
                $db->prepare('UPDATE ticket_order_devoluciones SET entradas_error = ? WHERE id = ?')
                    ->execute([mb_substr($error, 0, 255), (int) $devolucionId]);
            }

            $db->commit();

            return ['aplicada' => true, 'entradas_error' => $error];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /** @return string|null por qué no se pudieron sacar, o null si salió bien */
    private static function sacarEntradas($db, $ordenId, $linkId, $config, array $orden, $pedido, $userId)
    {
        if ($linkId === null || $config === null || !is_array($pedido) || !isset($pedido['quitar'])) {
            return 'No se pudieron sacar las entradas de la compra: hacelo desde Modificar.';
        }

        // Se recalcula sobre la compra de ahora, no sobre la foto del pedido.
        $nuevo = ModificarEntradas::sinEntradas($db, $ordenId, $linkId, $config, $pedido['quitar'], (int) $orden['ingresadas']);

        if (!$nuevo['ok']) {
            return 'No se pudieron sacar las entradas (' . $nuevo['error'] . '): hacelo desde Modificar.';
        }

        if ($nuevo['todas']) {
            $upd = $db->prepare("
                UPDATE ticket_orders SET estado = 'cancelada', cancelada_en = NOW()
                WHERE id = ? AND estado = 'pagada' AND ingresadas = 0
            ");
            $upd->execute([$ordenId]);

            return $upd->rowCount() > 0 ? null : 'No se pudo anular la compra: revisala.';
        }

        $id = ModificarEntradas::aplicar($db, $ordenId, $linkId, $config, $nuevo['antes'], $nuevo['despues'], $userId);

        return $id === null ? 'No se pudieron sacar las entradas: la compra cambió. Hacelo desde Modificar.' : null;
    }

    /** Anota el rechazo de Mercado Pago en el intento y en la compra. */
    private static function rechazar($db, $devolucionId, $origen, $motivo, array $respuesta = [])
    {
        $upd = $db->prepare("
            UPDATE ticket_order_devoluciones
            SET estado = 'rechazada', origen_rechazo = ?, motivo_rechazo = ?,
                mp_status_http = ?, mp_error_codigo = ?, mp_error_mensaje = ?, resuelta_en = NOW()
            WHERE id = ? AND estado IN ('pendiente', 'incierta')
        ");
        $upd->execute([
            $origen,
            mb_substr($motivo, 0, 255),
            isset($respuesta['status_http']) ? $respuesta['status_http'] : null,
            isset($respuesta['error_codigo']) ? $respuesta['error_codigo'] : null,
            isset($respuesta['error_mensaje']) ? mb_substr((string) $respuesta['error_mensaje'], 0, 255) : null,
            (int) $devolucionId,
        ]);

        $fila = self::fila($db, $devolucionId);

        if ($fila !== null) {
            self::anotarErrorEnLaCompra($db, (int) $fila['order_id'], $motivo);
        }
    }

    /**
     * Un bloqueo nuestro: se anota como intento rechazado, salvo que sea el
     * mismo motivo de hace un instante (un doble clic).
     */
    private static function bloquear($db, $ordenId, array $intento, $clave, $status, $origen = 'validacion', $texto = null)
    {
        $motivo = $texto !== null ? $texto : self::MOTIVOS[$clave];

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM ticket_order_devoluciones
            WHERE order_id = ? AND estado = 'rechazada' AND motivo_rechazo = ?
              AND created_at > (NOW() - INTERVAL " . (int) self::SEGUNDOS_REPETIDO . ' SECOND)
        ');
        $stmt->execute([(int) $ordenId, $motivo]);

        if ((int) $stmt->fetchColumn() === 0) {
            $ins = $db->prepare("
                INSERT INTO ticket_order_devoluciones
                    (order_id, user_id, modo, monto, pedido, estado, origen_rechazo, motivo_rechazo, resuelta_en)
                VALUES (?, ?, ?, 0, ?, 'rechazada', ?, ?, NOW())
            ");
            $ins->execute([
                (int) $ordenId,
                $intento['user_id'] === null ? null : (int) $intento['user_id'],
                $intento['modo'],
                $intento['pedido'] === null ? null : json_encode($intento['pedido'], JSON_UNESCAPED_UNICODE),
                $origen,
                mb_substr($motivo, 0, 255),
            ]);

            self::anotarErrorEnLaCompra($db, $ordenId, $motivo);
        }

        return self::mal($status, $motivo) + ['estado' => 'rechazada'];
    }

    private static function anotarErrorEnLaCompra($db, $ordenId, $motivo)
    {
        $db->prepare('UPDATE ticket_orders SET devolucion_error = ?, devolucion_error_en = NOW() WHERE id = ?')
            ->execute([mb_substr($motivo, 0, 255), (int) $ordenId]);
    }

    /**
     * Lo que Mercado Pago dice del pago después de devolver.
     *
     * Es el dato que responde la pregunta abierta: si application_fee bajó (o
     * el reembolso trae un reparto con la plataforma), el reembolso le sacó
     * una parte a la comisión de Rezonar.
     */
    private static function guardarReparto($db, $devolucionId, MercadoPago $mp, $pagoId, $respuestaDelReembolso)
    {
        try {
            $reparto = $mp->repartoDelPago($pagoId);
        } catch (Throwable $e) {
            $reparto = null;
        }

        $fila = self::fila($db, $devolucionId);
        $stmt = $db->prepare('SELECT mp_comision_cobrada FROM ticket_orders WHERE id = ?');
        $stmt->execute([(int) $fila['order_id']]);
        $comisionAntes = $stmt->fetchColumn();

        $datos = [
            'consultado_en'          => date('c'),
            'reembolso'              => $respuestaDelReembolso,
            'pago'                   => $reparto,
            'comision_plataforma_antes' => $comisionAntes === false || $comisionAntes === null ? null : (float) $comisionAntes,
            // null = no se pudo saber. true/false sólo comparando datos de
            // Mercado Pago: la comisión de plataforma del pago, antes y ahora.
            'toco_comision_plataforma' => null,
        ];

        if ($reparto !== null && $reparto['application_fee'] !== null && $datos['comision_plataforma_antes'] !== null) {
            $datos['toco_comision_plataforma'] = abs($reparto['application_fee'] - $datos['comision_plataforma_antes']) > 0.005;
        }

        $db->prepare('UPDATE ticket_order_devoluciones SET reparto = ? WHERE id = ?')
            ->execute([json_encode($datos, JSON_UNESCAPED_UNICODE), (int) $devolucionId]);
    }

    /** El mail a quien compró. No voltea la devolución si falla. */
    private static function avisar($db, $devolucionId, $mailer)
    {
        try {
            $envio = CorreoDevoluciones::enviar($db, $devolucionId, $mailer);
        } catch (Throwable $e) {
            $envio = ['enviado' => false, 'motivo' => 'no se pudo enviar el mail'];
        }

        if ($envio['enviado']) {
            $db->prepare('UPDATE ticket_order_devoluciones SET mail_enviado_en = NOW(), mail_error = NULL WHERE id = ?')
                ->execute([(int) $devolucionId]);
        } else {
            $db->prepare('UPDATE ticket_order_devoluciones SET mail_error = ? WHERE id = ?')
                ->execute([mb_substr((string) $envio['motivo'], 0, 255), (int) $devolucionId]);
        }

        return $envio;
    }

    // ---------------------------------------------------------- conciliar

    /**
     * Resuelve los intentos que quedaron sin saber cómo terminaron.
     *
     * Sólo pregunta (GET de los reembolsos del pago): nunca vuelve a pedir un
     * reembolso. Si Mercado Pago tiene uno con ese monto que todavía no es de
     * ningún intento nuestro, es éste: se aprueba. Si pasado un rato no lo
     * tiene, no se hizo: se marca rechazado y se puede volver a pedir.
     *
     * @return array{revisadas: int, aprobadas: int, rechazadas: int}
     */
    public static function conciliar($db, $http = null, $mailer = null)
    {
        $stmt = $db->prepare("
            SELECT d.id, d.order_id, d.monto, d.estado, d.mp_payment_id, d.mp_refund_id, d.created_at, o.link_id,
                   (d.created_at < (NOW() - INTERVAL " . (int) self::MINUTOS_PARA_DESCARTAR . " MINUTE)) AS vieja
            FROM ticket_order_devoluciones d
            INNER JOIN ticket_orders o ON o.id = d.order_id
            WHERE (d.estado = 'incierta'
                   OR (d.estado = 'pendiente' AND d.created_at < (NOW() - INTERVAL " . (int) self::MINUTOS_PENDIENTE . " MINUTE)))
              AND d.mp_payment_id IS NOT NULL
              AND o.link_id IS NOT NULL
            ORDER BY d.id
            LIMIT " . (int) self::LIMITE_A_CONCILIAR
        );
        $stmt->execute();
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resumen = ['revisadas' => 0, 'aprobadas' => 0, 'rechazadas' => 0];

        foreach ($filas as $fila) {
            $token = Cobros::tokenDelEvento($db, (int) $fila['link_id'], $http);

            if ($token === null) {
                continue;
            }

            $mp = new MercadoPago($token, $http);
            $lista = $mp->devoluciones($fila['mp_payment_id']);

            if (!$lista['ok']) {
                continue;
            }

            $resumen['revisadas']++;
            $conocidos = self::reembolsosConocidos($db, $fila['mp_payment_id']);
            $encontrado = null;

            foreach ($lista['devoluciones'] as $reembolso) {
                // Si Mercado Pago ya nos había dado el id (quedó "en proceso"),
                // se busca por id; si no, por monto entre los que no son de nadie.
                if (!empty($fila['mp_refund_id'])) {
                    if ($reembolso['id'] === (string) $fila['mp_refund_id']) {
                        $encontrado = $reembolso;
                        break;
                    }

                    continue;
                }

                if (in_array($reembolso['id'], $conocidos, true)) {
                    continue;
                }

                if ($reembolso['monto'] !== null && abs($reembolso['monto'] - (float) $fila['monto']) < 0.005) {
                    $encontrado = $reembolso;
                    break;
                }
            }

            if ($encontrado !== null && in_array($encontrado['estado'], [null, 'approved'], true)) {
                self::resolver($db, (int) $fila['id'], [
                    'resultado'   => 'aprobada',
                    'status_http' => 200,
                    'id'          => $encontrado['id'],
                    'monto'       => $encontrado['monto'],
                    'respuesta'   => ['id' => $encontrado['id'], 'amount' => $encontrado['monto'], 'status' => $encontrado['estado']],
                ], $mp, $mailer);
                $resumen['aprobadas']++;
                continue;
            }

            if ($encontrado !== null && in_array($encontrado['estado'], ['rejected', 'cancelled'], true)) {
                self::rechazar($db, (int) $fila['id'], 'mercadopago', 'Mercado Pago rechazó la devolución.', [
                    'error_codigo' => $encontrado['estado'], 'error_mensaje' => 'refund ' . $encontrado['estado'],
                ]);
                $resumen['rechazadas']++;
                continue;
            }

            // En proceso: se espera a la próxima corrida.
            if ($encontrado !== null) {
                continue;
            }

            if ((int) $fila['vieja'] === 1) {
                self::rechazar($db, (int) $fila['id'], 'mercadopago',
                    'Mercado Pago no registró la devolución: no se hizo. Podés volver a pedirla.');
                $resumen['rechazadas']++;
            }
        }

        return $resumen;
    }

    private static function reembolsosConocidos($db, $pagoId)
    {
        $stmt = $db->prepare('
            SELECT mp_refund_id FROM ticket_order_devoluciones
            WHERE mp_payment_id = ? AND mp_refund_id IS NOT NULL AND estado = \'aprobada\'
        ');
        $stmt->execute([(string) $pagoId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------ cuentas

    /** Lo que queda por devolver, al centavo y hacia abajo. Nunca negativo. */
    public static function devolvible($neto, $devuelto)
    {
        $centavos = (int) floor(round(((float) $neto - (float) $devuelto) * 100, 4));

        return max(0, $centavos) / 100.0;
    }

    /**
     * La parte del devolvible que corresponde a las entradas que se sacan.
     *
     * Proporcional a lo que valen (el precio de cada renglón, como en el
     * desglose por tipo). Sin tipos, todas valen lo mismo. Hacia abajo, al
     * centavo: el redondeo queda para la última, que se lleva el resto.
     */
    public static function parteDe($devolvible, array $antes, array $despues)
    {
        $valor = function (array $estado) {
            return array_sum(array_map([TiposDeEntrada::class, 'subtotal'], $estado['items']));
        };

        $valorAntes = $antes['items'] !== [] ? $valor($antes) : 0.0;
        $valorDespues = $despues['items'] !== [] ? $valor($despues) : 0.0;

        if ($antes['items'] === [] || $despues['items'] === [] || $valorAntes <= 0) {
            $valorAntes = (float) $antes['cantidad'];
            $valorDespues = (float) $despues['cantidad'];
        }

        if ($valorAntes <= 0) {
            return 0.0;
        }

        $proporcion = max(0.0, min(1.0, ($valorAntes - $valorDespues) / $valorAntes));
        $centavos = (int) floor(round($devolvible * 100 * $proporcion, 4));

        return $centavos / 100.0;
    }

    // ----------------------------------------------------------- internos

    /** Lo que la frena sin necesidad de preguntarle a Mercado Pago. */
    private static function bloqueoSinMercadoPago(array $orden)
    {
        if (isset($orden['origen']) && $orden['origen'] === 'invitacion') {
            return 'invitado';
        }

        if ((float) $orden['total'] <= 0) {
            return 'sin_costo';
        }

        if ($orden['estado'] !== 'pagada') {
            return 'no_pagada';
        }

        if (empty($orden['mp_payment_id'])) {
            return 'sin_pago';
        }

        if (!empty($orden['pagada_en']) && strtotime($orden['pagada_en']) < time() - self::DIAS_MAXIMOS * 86400) {
            return 'vieja';
        }

        return null;
    }

    /** Lo devuelto según nuestro registro: lo aprobado más lo que está en curso. */
    private static function devueltoSegunNosotros($db, $ordenId)
    {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(monto), 0) FROM ticket_order_devoluciones
            WHERE order_id = ? AND estado IN ('aprobada', 'pendiente', 'incierta')
        ");
        $stmt->execute([(int) $ordenId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    private static function hayEnCurso($db, $ordenId)
    {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM ticket_order_devoluciones
            WHERE order_id = ? AND estado IN ('pendiente', 'incierta')
        ");
        $stmt->execute([(int) $ordenId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Qué tiene que elegir la pantalla para devolver algunas entradas. */
    private static function formaDeElegir($db, array $orden)
    {
        $config = $orden['link_id'] === null ? null : Entradas::configDelEvento($db, (int) $orden['link_id']);

        if ($config !== null && $config['plano'] !== null) {
            return ['forma' => 'lugares', 'lugares' => $orden['lugares']];
        }

        if ($config !== null && $config['tipos'] !== null && $orden['items'] !== []) {
            return ['forma' => 'tipos', 'items' => $orden['items']];
        }

        return ['forma' => 'cantidad', 'cantidad' => (int) $orden['cantidad']];
    }

    private static function fila($db, $devolucionId)
    {
        $stmt = $db->prepare('SELECT * FROM ticket_order_devoluciones WHERE id = ?');
        $stmt->execute([(int) $devolucionId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    private static function pesos($monto)
    {
        return '$' . number_format((float) $monto, 2, ',', '.');
    }

    private static function mal($status, $error)
    {
        return ['ok' => false, 'status' => $status, 'error' => $error];
    }
}
