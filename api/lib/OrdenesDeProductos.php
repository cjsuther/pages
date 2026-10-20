<?php

/**
 * Órdenes de productos digitales: la compra y el link de descarga.
 *
 * Una orden es una unidad de un producto. El cobro reutiliza lo de entradas
 * (Cobros, Comision, MercadoPago); lo que cambia es lo que se entrega: no hay
 * cupo que tomar, hay un archivo que bajar.
 *
 * El link de descarga es la credencial, con las reglas de ClaveDeEvento y
 * SubidasConToken:
 *
 * - 256 bits al azar, generados al crear la orden.
 * - En la base queda el hash —se busca por él, que es único— y una copia
 *   cifrada para poder reenviar el mail. Quien lea la base no puede bajar nada
 *   con lo que ve.
 * - Viaja después del # (FRONTEND_URL/descarga#token): el navegador no lo
 *   manda al pedir la página, así que no queda en los registros del servidor
 *   ni en el Referer. A la API llega siempre en el cuerpo de un POST.
 * - El código de la orden nunca sirve para descargar: son 12 hex y se ve en la
 *   URL a la que vuelve Mercado Pago.
 */
class OrdenesDeProductos
{
    /** Lo mismo que una reserva de entradas: vence a los mismos minutos. */
    const MINUTOS_PARA_PAGAR = Entradas::MINUTOS_DE_RESERVA;

    /** Ventana de la conciliación, igual que en entradas. */
    const DIAS_A_CONCILIAR = Entradas::DIAS_A_CONCILIAR;

    const LIMITE_A_CONCILIAR = Entradas::LIMITE_A_CONCILIAR;

    /** Un pago aprobado acredita desde cualquiera de estos estados. */
    const ACREDITABLES = ['pendiente', 'vencida', 'rechazada'];

    // ------------------------------------------------------------ producto

    /**
     * El producto que se puede comprar en ese link, o null.
     *
     * Tiene que ser un link de un grupo "productos" y tener el archivo subido:
     * sin archivo no hay nada que entregar.
     */
    public static function productoEnVenta($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.text, l.image_url, lg.page_id,
                   dp.precio, dp.archivo_ruta, dp.archivo_nombre, dp.archivo_bytes
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN digital_products dp ON dp.link_id = l.id
            WHERE l.id = ? AND lg.type = "productos" AND dp.archivo_ruta IS NOT NULL
        ');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    // ------------------------------------------------------------- órdenes

    /**
     * Crea la orden con su token y devuelve el token en claro, que sólo
     * existe acá y en el mail.
     *
     * Un producto gratis nace pagado: no hay pago que esperar.
     *
     * @return array{id: int, codigo: string, token: string, estado: string}
     */
    public static function crear($db, $linkId, array $comprador, $total)
    {
        $total = round((float) $total, 2);
        $esGratis = $total <= 0;
        $token = bin2hex(random_bytes(32));
        $codigo = Entradas::codigoLibre($db, 'product_orders');
        $estado = $esGratis ? 'pagada' : 'pendiente';
        $telefono = isset($comprador['telefono']) ? trim((string) $comprador['telefono']) : '';

        $stmt = $db->prepare('
            INSERT INTO product_orders
                (codigo, link_id, nombre, email, telefono, total, estado, pagada_en, token_hash, token_cifrado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $codigo,
            (int) $linkId,
            trim((string) $comprador['nombre']),
            trim((string) $comprador['email']),
            $telefono === '' ? null : $telefono,
            $esGratis ? 0 : $total,
            $estado,
            $esGratis ? date('Y-m-d H:i:s') : null,
            self::hash($token),
            Cripto::cifrar($token),
        ]);

        return [
            'id'     => (int) $db->lastInsertId(),
            'codigo' => $codigo,
            'token'  => $token,
            'estado' => $estado,
        ];
    }

    /**
     * La orden que ya tiene este email para un producto gratis, o null.
     *
     * Pedirlo dos veces no crea otra orden: se le reenvía el link que ya tiene.
     */
    public static function gratisDelEmail($db, $linkId, $email)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM product_orders
            WHERE link_id = ? AND email = ? AND estado = 'pagada' AND total = 0
            ORDER BY id
            LIMIT 1
        ");
        $stmt->execute([(int) $linkId, trim((string) $email)]);
        $codigo = $stmt->fetchColumn();

        return $codigo === false ? null : (string) $codigo;
    }

    public static function guardarPreferencia($db, $ordenId, $preferenciaId)
    {
        $stmt = $db->prepare('UPDATE product_orders SET mp_preference_id = ? WHERE id = ?');
        $stmt->execute([$preferenciaId, (int) $ordenId]);
    }

    /** Congela la comisión con la que se creó el cobro, como Entradas::guardarComision. */
    public static function guardarComision($db, $ordenId, $comision, $porcentaje)
    {
        $stmt = $db->prepare('UPDATE product_orders SET comision = ?, comision_porcentaje = ? WHERE id = ?');
        $stmt->execute([round((float) $comision, 2), round((float) $porcentaje, 2), (int) $ordenId]);
    }

    /** Una orden que no llegó a tener link de pago. */
    public static function cancelar($db, $codigo)
    {
        $stmt = $db->prepare("UPDATE product_orders SET estado = 'cancelada' WHERE codigo = ? AND estado = 'pendiente'");
        $stmt->execute([$codigo]);
    }

    /** Una orden por su código, con los datos del producto y de la página. */
    public static function orden($db, $codigo)
    {
        $stmt = $db->prepare('
            SELECT o.*, l.text AS producto, l.image_url AS producto_imagen,
                   dp.archivo_ruta, dp.archivo_nombre, dp.archivo_bytes,
                   p.title AS pagina, p.url_slug, p.email_contacto, p.meta_pixel_id
            FROM product_orders o
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            LEFT JOIN digital_products dp ON dp.link_id = l.id
            WHERE o.codigo = ?
        ');
        $stmt->execute([(string) $codigo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /**
     * La orden pagada a la que corresponde un token, o null.
     *
     * Se busca por el hash, que es una columna única: no hay comparación de
     * texto que pueda filtrar por tiempo cuánto del token se acertó. Un token
     * que no existe, el de una orden sin pagar y uno con otra forma dan lo
     * mismo, para no revelar nada a quien prueba.
     *
     * El archivo se lee del producto en el momento: si se reemplazó, se baja
     * el actual.
     */
    public static function pagadaPorToken($db, $token)
    {
        // Del cuerpo puede llegar cualquier cosa, también un array.
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $db->prepare('
            SELECT o.id, o.codigo, o.estado, o.descargas,
                   l.text AS producto, l.image_url AS producto_imagen,
                   dp.archivo_ruta, dp.archivo_nombre, dp.archivo_bytes,
                   p.title AS pagina, p.url_slug
            FROM product_orders o
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            LEFT JOIN digital_products dp ON dp.link_id = l.id
            WHERE o.token_hash = ?
        ');
        $stmt->execute([self::hash($token)]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false || $fila['estado'] !== 'pagada') {
            return null;
        }

        return $fila;
    }

    /** Suma una descarga. Sin tope: el link sirve para siempre. */
    public static function registrarDescarga($db, $ordenId)
    {
        $stmt = $db->prepare('
            UPDATE product_orders
            SET descargas = descargas + 1,
                primera_descarga_en = COALESCE(primera_descarga_en, NOW()),
                ultima_descarga_en = NOW()
            WHERE id = ?
        ');
        $stmt->execute([(int) $ordenId]);
    }

    /** El token en claro de una orden, para reenviar el mail. */
    public static function token(array $orden)
    {
        if (empty($orden['token_cifrado'])) {
            return null;
        }

        $token = Cripto::descifrar($orden['token_cifrado']);

        return $token === null || $token === '' ? null : $token;
    }

    /** El link que recibe el comprador. El token va después del #. */
    public static function urlDeDescarga($token)
    {
        return rtrim(FRONTEND_URL, '/') . '/descarga#' . $token;
    }

    /** Una pendiente cuyo plazo pasó. Como en entradas, se calcula: nadie la marca. */
    public static function estaVencida(array $orden)
    {
        return $orden['estado'] === 'pendiente'
            && !empty($orden['creado_en'])
            && strtotime($orden['creado_en']) + self::MINUTOS_PARA_PAGAR * 60 <= time();
    }

    public static function hash($token)
    {
        return hash('sha256', (string) $token);
    }

    // ---------------------------------------------------------------- pagos

    /**
     * Acredita un pago sobre su orden. Mismo contrato que Entradas::acreditarPago.
     *
     * Mercado Pago reintenta los avisos: esto tiene que dar lo mismo las veces
     * que se llame con el mismo pago. Lo garantiza la condición sobre el estado
     * en el UPDATE, junto al índice único sobre mp_payment_id.
     *
     * A diferencia de una entrada, un aprobado acredita también una orden
     * rechazada o vencida: no hay cupo que cuidar, y en el checkout de Mercado
     * Pago se puede reintentar con otra tarjeta después de un rechazo. Dejar
     * afuera a quien pagó en el segundo intento sería quedarse con su plata.
     *
     * @return array{acreditada: bool, motivo: string}
     */
    public static function acreditarPago($db, $codigo, $pagoId, $estadoMp, array $detalle = [])
    {
        $stmt = $db->prepare('SELECT estado FROM product_orders WHERE codigo = ?');
        $stmt->execute([$codigo]);
        $estado = $stmt->fetchColumn();

        if ($estado === false) {
            return ['acreditada' => false, 'motivo' => 'orden inexistente'];
        }

        if ($estado === 'pagada') {
            return ['acreditada' => false, 'motivo' => 'ya estaba pagada'];
        }

        if ($estadoMp === 'approved') {
            $upd = $db->prepare("
                UPDATE product_orders
                SET estado = 'pagada', mp_payment_id = ?, pagada_en = NOW(),
                    mp_comision_cobrada = COALESCE(?, mp_comision_cobrada)
                WHERE codigo = ? AND estado IN ('pendiente', 'vencida', 'rechazada')
            ");
            $upd->execute([
                $pagoId,
                isset($detalle['comision_plataforma']) ? $detalle['comision_plataforma'] : null,
                $codigo,
            ]);

            return $upd->rowCount() > 0
                ? ['acreditada' => true, 'motivo' => 'pago acreditado']
                : ['acreditada' => false, 'motivo' => 'la orden no estaba en un estado acreditable'];
        }

        if (in_array($estadoMp, ['rejected', 'cancelled'], true)) {
            $upd = $db->prepare("
                UPDATE product_orders
                SET estado = 'rechazada', mp_payment_id = ?
                WHERE codigo = ? AND estado IN ('pendiente', 'vencida')
            ");
            $upd->execute([$pagoId, $codigo]);

            return ['acreditada' => false, 'motivo' => 'pago rechazado'];
        }

        return ['acreditada' => false, 'motivo' => 'pago todavía en curso'];
    }

    /**
     * Órdenes pendientes sin pago registrado, candidatas a que se les haya
     * perdido el aviso. Las gratis no: nacen pagadas.
     *
     * @return string[] Códigos de orden.
     */
    public static function sinConciliar($db, $dias = self::DIAS_A_CONCILIAR, $limite = self::LIMITE_A_CONCILIAR)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM product_orders
            WHERE estado = 'pendiente'
              AND mp_payment_id IS NULL
              AND total > 0
              AND creado_en >= (NOW() - INTERVAL ? DAY)
            ORDER BY creado_en DESC
            LIMIT " . (int) $limite . "
        ");
        $stmt->execute([(int) $dias]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
