<?php

/**
 * Control de ingreso en la puerta de un evento.
 *
 * Quien está en la puerta no tiene cuenta: tiene un link con una clave, que
 * sirve para un solo evento. Con eso ve la lista de compras pagadas y marca
 * quién entró, escaneando el QR de la entrada o buscando por nombre.
 *
 * La regla que ordena todo: una entrada sólo vale para su evento. El QR de
 * otro show, o de una compra cancelada, tiene que dar un "no" claro, porque en
 * la puerta nadie tiene tiempo de leer la letra chica.
 */
class Puerta
{
    // Resultado de mirar una entrada en la puerta.
    const VALIDA = 'valida';
    const YA_ENTRO = 'ya_entro';
    const NO_PAGADA = 'no_pagada';
    const OTRO_EVENTO = 'otro_evento';
    const NO_EXISTE = 'no_existe';

    // ------------------------------------------------------------- el link

    /**
     * Genera la clave de puerta del evento y la devuelve en claro.
     *
     * Si ya había una, la reemplaza: es la forma de dejar afuera a quien
     * tenía el link anterior.
     */
    public static function generarClave($db, $linkId)
    {
        return ClaveDeEvento::generar($db, $linkId, ClaveDeEvento::PUERTA);
    }

    /** La clave vigente del evento, en claro, o null si no tiene link. */
    public static function claveDelEvento($db, $linkId)
    {
        return ClaveDeEvento::clave($db, $linkId, ClaveDeEvento::PUERTA);
    }

    public static function revocar($db, $linkId)
    {
        ClaveDeEvento::revocar($db, $linkId, ClaveDeEvento::PUERTA);
    }

    /** La dirección que se le pasa a quien está en la puerta. */
    public static function url($clave)
    {
        return ClaveDeEvento::url($clave, ClaveDeEvento::PUERTA);
    }

    /**
     * El evento al que da acceso una clave de puerta, o null.
     *
     * Exige que sea de puerta: la clave del link de ventas no puede marcar
     * gente entrando, aunque las dos salgan de la misma tabla.
     *
     * @return array|null ['id', 'text', 'event_date', 'event_time', 'event_address', 'pagina']
     */
    public static function eventoDeLaClave($db, $clave)
    {
        return ClaveDeEvento::evento($db, $clave, ClaveDeEvento::PUERTA);
    }

    // ------------------------------------------------------------ la lista

    /**
     * Las compras pagadas del evento y cuánta gente entró.
     *
     * Sólo lo que la puerta necesita para reconocer a alguien: nombre, código
     * y lugares. El email y el teléfono no salen de acá, porque este link se
     * le da a gente que no administra la página.
     */
    public static function lista($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT id, codigo, nombre, cantidad, ingresadas, ingreso_en
            FROM ticket_orders
            WHERE link_id = ? AND estado = 'pagada'
            ORDER BY nombre
        ");
        $stmt->execute([(int) $linkId]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lugares = self::lugaresPorOrden($db, $linkId);
        $entradas = 0;
        $ingresadas = 0;
        $ordenes = [];

        foreach ($filas as $fila) {
            $entradas += (int) $fila['cantidad'];
            $ingresadas += (int) $fila['ingresadas'];
            $ordenes[] = self::publica($fila, isset($lugares[$fila['id']]) ? $lugares[$fila['id']] : []);
        }

        return [
            'ordenes' => $ordenes,
            'resumen' => ['entradas' => $entradas, 'ingresadas' => $ingresadas, 'compras' => count($ordenes)],
        ];
    }

    // --------------------------------------------------------- una entrada

    /**
     * Qué pasa con una entrada en la puerta de este evento.
     *
     * @return array{resultado: string, orden: array|null, evento?: string}
     */
    public static function mirar($db, $linkId, $codigo)
    {
        $codigo = self::codigoDesdeQr($codigo);

        if ($codigo === null) {
            return ['resultado' => self::NO_EXISTE, 'orden' => null];
        }

        $stmt = $db->prepare('
            SELECT o.id, o.codigo, o.link_id, o.nombre, o.cantidad, o.ingresadas, o.ingreso_en, o.estado,
                   o.reserva_vence_en, l.text AS evento
            FROM ticket_orders o
            INNER JOIN links l ON l.id = o.link_id
            WHERE o.codigo = ?
        ');
        $stmt->execute([$codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return ['resultado' => self::NO_EXISTE, 'orden' => null];
        }

        // Una entrada de otro show no dice nada de la gente de este, así que
        // no se devuelve la compra: sólo de qué evento es.
        if ((int) $fila['link_id'] !== (int) $linkId) {
            return ['resultado' => self::OTRO_EVENTO, 'orden' => null, 'evento' => $fila['evento']];
        }

        $orden = self::publica($fila, Entradas::lugaresDeLaOrden($db, $fila['id']));

        if ($fila['estado'] !== 'pagada') {
            $vencida = $fila['estado'] === 'reservada'
                && $fila['reserva_vence_en'] !== null
                && strtotime($fila['reserva_vence_en']) <= time();

            $orden['estado'] = $vencida ? 'vencida' : $fila['estado'];

            return ['resultado' => self::NO_PAGADA, 'orden' => $orden];
        }

        if ($orden['restantes'] < 1) {
            return ['resultado' => self::YA_ENTRO, 'orden' => $orden];
        }

        return ['resultado' => self::VALIDA, 'orden' => $orden];
    }

    /**
     * Marca que entraron $cantidad personas de una compra.
     *
     * Dos personas en dos puertas pueden escanear el mismo QR a la vez. La
     * condición sobre lo que queda va en el UPDATE y no sólo en la lectura:
     * así una sola de las dos pasa, y la otra ve "ya entró".
     *
     * @return array{ok: bool, resultado: string, orden: array|null}
     */
    public static function ingresar($db, $linkId, $codigo, $cantidad)
    {
        $cantidad = max(1, (int) $cantidad);
        $codigo = self::codigoDesdeQr($codigo);

        $upd = $db->prepare("
            UPDATE ticket_orders
            SET ingresadas = ingresadas + ?, ingreso_en = NOW()
            WHERE codigo = ? AND link_id = ? AND estado = 'pagada'
              AND ingresadas + ? <= cantidad
        ");
        $upd->execute([$cantidad, (string) $codigo, (int) $linkId, $cantidad]);

        $ahora = self::mirar($db, $linkId, $codigo);

        return ['ok' => $upd->rowCount() > 0] + $ahora;
    }

    /**
     * Deshace un ingreso marcado por error.
     *
     * En la puerta se toca el botón equivocado, y sin esto la persona que sí
     * tenía que entrar quedaría como "ya entró".
     */
    public static function deshacer($db, $linkId, $codigo, $cantidad)
    {
        $cantidad = max(1, (int) $cantidad);
        $codigo = self::codigoDesdeQr($codigo);

        // ingreso_en va primero: MySQL aplica las asignaciones en orden, y si
        // fuera después ya vería ingresadas con la resta hecha.
        $upd = $db->prepare('
            UPDATE ticket_orders
            SET ingreso_en = CASE WHEN ingresadas - ? > 0 THEN ingreso_en ELSE NULL END,
                ingresadas = ingresadas - ?
            WHERE codigo = ? AND link_id = ? AND ingresadas >= ?
        ');
        $upd->execute([$cantidad, $cantidad, (string) $codigo, (int) $linkId, $cantidad]);

        return ['ok' => $upd->rowCount() > 0] + self::mirar($db, $linkId, $codigo);
    }

    /**
     * El código de una orden a partir de lo que leyó la cámara.
     *
     * El QR de la entrada tiene la dirección de la orden
     * (https://rezon.ar/entrada/ABC123DEF456), pero también se puede tipear el
     * código a mano, con minúsculas o espacios.
     */
    public static function codigoDesdeQr($texto)
    {
        $texto = trim((string) $texto);

        if (preg_match('#/entrada/([A-Za-z0-9]{12})(?:[/?\#]|$)#', $texto, $m)) {
            return strtoupper($m[1]);
        }

        $limpio = strtoupper(preg_replace('/\s+/', '', $texto));

        return preg_match('/^[A-F0-9]{12}$/', $limpio) ? $limpio : null;
    }

    public static function hash($clave)
    {
        return ClaveDeEvento::hash($clave);
    }

    // ------------------------------------------------------------ internos

    private static function publica(array $fila, array $lugares)
    {
        $cantidad = (int) $fila['cantidad'];
        $ingresadas = (int) $fila['ingresadas'];

        return [
            'codigo'     => $fila['codigo'],
            'nombre'     => $fila['nombre'],
            'cantidad'   => $cantidad,
            'ingresadas' => $ingresadas,
            'restantes'  => max(0, $cantidad - $ingresadas),
            'ingreso_en' => $fila['ingreso_en'],
            'lugares'    => $lugares,
        ];
    }

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
}
