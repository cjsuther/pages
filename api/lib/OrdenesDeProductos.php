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
     * El producto de ese link, o null si no hay nada que vender ahí.
     *
     * Tiene que ser un link de un grupo "productos". Un digital tiene que
     * tener el archivo subido: sin archivo no hay nada que entregar. Un
     * físico (#43) vuelve con sus características y su stock
     * (ProductosFisicos::completar). Vuelve también si está oculto: quien
     * llama decide con ProductosDigitales::aceptaPedidos().
     */
    public static function productoEnVenta($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.text, l.image_url, lg.page_id,
                   dp.id AS producto_id, dp.tipo, dp.oculto, dp.borrado_en,
                   dp.precio, dp.costo_envio, dp.zona_envio, dp.stock,
                   dp.archivo_ruta, dp.archivo_nombre, dp.archivo_bytes
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN digital_products dp ON dp.link_id = l.id
            WHERE l.id = ? AND lg.type = "productos"
              AND (dp.tipo = "fisico" OR (dp.tipo = "digital" AND dp.archivo_ruta IS NOT NULL))
        ');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        return ProductosFisicos::esFisico($fila) ? ProductosFisicos::completar($db, [$fila])[0] : $fila;
    }

    // ------------------------------------------------------- pedidos gratis

    /** Pedidos gratis por hora desde una misma IP, entre todos los productos. */
    const GRATIS_POR_HORA_POR_IP = 5;

    /** Pedidos gratis por hora para un mismo email, entre todos los productos. */
    const GRATIS_POR_HORA_POR_EMAIL = 3;

    const MENSAJE_DEMASIADOS_PEDIDOS = 'Hiciste demasiados pedidos en poco tiempo. Probá de nuevo en una hora.';

    /**
     * true si ese email o esa IP ya pidieron demasiados productos gratis en la
     * última hora. Cuenta las órdenes gratis creadas, de cualquier producto.
     *
     * Sin esto el formulario de un producto gratis serviría para mandar mails
     * a cualquier dirección: cada email distinto crea una orden y sale un mail.
     */
    public static function superaLimiteGratis($db, $email, $ip)
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM product_orders
            WHERE email = ? AND total = 0 AND creado_en >= (NOW() - INTERVAL 1 HOUR)
        ");
        $stmt->execute([self::normalizarEmail($email)]);

        if ((int) $stmt->fetchColumn() >= self::GRATIS_POR_HORA_POR_EMAIL) {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM product_orders
            WHERE ip = ? AND total = 0 AND creado_en >= (NOW() - INTERVAL 1 HOUR)
        ");
        $stmt->execute([(string) $ip]);

        return (int) $stmt->fetchColumn() >= self::GRATIS_POR_HORA_POR_IP;
    }

    /**
     * El email como se guarda y se compara: sin espacios y en minúsculas.
     *
     * La columna ya compara sin distinguir mayúsculas; guardarlo así evita
     * depender de eso.
     */
    public static function normalizarEmail($email)
    {
        return mb_strtolower(trim((string) $email), 'UTF-8');
    }

    // ------------------------------------------------------------- órdenes

    /**
     * Crea la orden con su token y devuelve el token en claro, que sólo
     * existe acá y en el mail.
     *
     * Un producto gratis nace pagado: no hay pago que esperar.
     *
     * Un físico (#43) llega con $pedido: la foto (titulo, opciones_texto,
     * opcion_ids, variante_id, precio_unitario, cantidad, costo_envio) y la
     * entrega (envio, horarios). Todo se escribe en una transacción; si nace
     * pagado, ahí mismo se descuenta el stock, como al acreditar.
     *
     * @return array{id: int, codigo: string, token: string, estado: string}
     */
    public static function crear($db, $productoId, array $comprador, $total, $ip = null, ?array $pedido = null)
    {
        $total = round((float) $total, 2);
        $esGratis = $total <= 0;
        $token = bin2hex(random_bytes(32));
        $codigo = Entradas::codigoLibre($db, 'product_orders');
        $estado = $esGratis ? 'pagada' : 'pendiente';
        $telefono = isset($comprador['telefono']) ? trim((string) $comprador['telefono']) : '';

        $columnas = ['codigo', 'product_id', 'nombre', 'email', 'telefono', 'total', 'estado', 'pagada_en',
                     'token_hash', 'token_cifrado', 'ip'];
        $valores = [
            $codigo,
            (int) $productoId,
            trim((string) $comprador['nombre']),
            self::normalizarEmail($comprador['email']),
            $telefono === '' ? null : $telefono,
            $esGratis ? 0 : $total,
            $estado,
            $esGratis ? date('Y-m-d H:i:s') : null,
            self::hash($token),
            Cripto::cifrar($token),
            $ip === null || $ip === '' ? null : (string) $ip,
        ];

        if ($pedido !== null) {
            $columnas = array_merge($columnas, ['producto_titulo', 'variante_id', 'opciones_texto', 'cantidad',
                                                'precio_unitario', 'costo_envio']);
            $valores = array_merge($valores, [
                (string) $pedido['titulo'],
                empty($pedido['variante_id']) ? null : (int) $pedido['variante_id'],
                $pedido['opciones_texto'],
                (int) $pedido['cantidad'],
                round((float) $pedido['precio_unitario'], 2),
                round((float) $pedido['costo_envio'], 2),
            ]);

            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare('
                INSERT INTO product_orders (' . implode(', ', $columnas) . ')
                VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')
            ');
            $stmt->execute($valores);
            $id = (int) $db->lastInsertId();

            if ($pedido !== null) {
                self::guardarEntrega($db, $id, $pedido);

                if ($esGratis) {
                    PedidosFisicos::descontarStock($db, [
                        'id' => $id, 'product_id' => (int) $productoId, 'cantidad' => (int) $pedido['cantidad'],
                    ]);
                }

                $db->commit();
            }
        } catch (Throwable $e) {
            if ($pedido !== null && $db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return [
            'id'     => $id,
            'codigo' => $codigo,
            'token'  => $token,
            'estado' => $estado,
        ];
    }

    /** Las opciones elegidas, la dirección y los horarios de un pedido de un físico. */
    private static function guardarEntrega($db, $ordenId, array $pedido)
    {
        foreach ($pedido['opcion_ids'] as $opcionId) {
            $stmt = $db->prepare('INSERT INTO product_order_options (order_id, option_id) VALUES (?, ?)');
            $stmt->execute([$ordenId, (int) $opcionId]);
        }

        $envio = $pedido['envio'];
        $horarios = $pedido['horarios'];

        $stmt = $db->prepare('
            INSERT INTO product_order_envios
                (order_id, calle, piso_depto, localidad, provincia, codigo_postal, indicaciones,
                 dias, franjas, comentario_horario)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $ordenId,
            $envio['calle'],
            $envio['piso_depto'],
            $envio['localidad'],
            $envio['provincia'],
            $envio['codigo_postal'],
            $envio['indicaciones'],
            implode(',', $horarios['dias']),
            implode(',', $horarios['franjas']),
            $horarios['comentario'],
        ]);
    }

    /**
     * La orden que ya tiene este email para un producto gratis, o null.
     *
     * Pedirlo dos veces no crea otra orden: se le reenvía el link que ya tiene.
     */
    public static function gratisDelEmail($db, $productoId, $email)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM product_orders
            WHERE product_id = ? AND email = ? AND estado = 'pagada' AND total = 0
            ORDER BY id
            LIMIT 1
        ");
        $stmt->execute([(int) $productoId, self::normalizarEmail($email)]);
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

    /**
     * Lo que se lee del producto y de la página junto con una orden.
     *
     * El producto puede estar borrado (link_id null) y hasta la página: lo que
     * necesita el comprador sale de la foto de digital_products, y lo que
     * falte (la imagen, la página) llega null.
     *
     * Un pedido de un físico tiene su propia foto del título
     * (o.producto_titulo): si el producto se renombra, el pedido no cambia.
     */
    const DATOS_DEL_PRODUCTO = '
        dp.link_id, dp.tipo, COALESCE(o.producto_titulo, l.text, dp.titulo) AS producto, l.image_url AS producto_imagen,
        dp.archivo_ruta, dp.archivo_nombre, dp.archivo_bytes,
        COALESCE(lg.page_id, dp.page_id) AS pagina_id,
        p.title AS pagina, p.url_slug';

    const JOINS_DEL_PRODUCTO = '
        INNER JOIN digital_products dp ON dp.id = o.product_id
        LEFT JOIN links l ON l.id = dp.link_id
        LEFT JOIN link_groups lg ON lg.id = l.group_id
        LEFT JOIN pages p ON p.id = COALESCE(lg.page_id, dp.page_id)';

    /**
     * Una orden por su código, con los datos del producto y de la página.
     * duena_email es adonde va el "Pedido nuevo" de un físico si la página
     * no tiene email de contacto.
     */
    public static function orden($db, $codigo)
    {
        $stmt = $db->prepare('
            SELECT o.*, ' . self::DATOS_DEL_PRODUCTO . ', p.email_contacto, p.meta_pixel_id, u.email AS duena_email
            FROM product_orders o
            ' . self::JOINS_DEL_PRODUCTO . '
            LEFT JOIN users u ON u.id = p.user_id
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
     * el actual. El token de un pedido de un físico no sirve: no hay nada que
     * bajar, y nunca sale de la base.
     */
    public static function pagadaPorToken($db, $token)
    {
        // Del cuerpo puede llegar cualquier cosa, también un array.
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $db->prepare('
            SELECT o.id, o.codigo, o.estado, o.descargas, ' . self::DATOS_DEL_PRODUCTO . '
            FROM product_orders o
            ' . self::JOINS_DEL_PRODUCTO . '
            WHERE o.token_hash = ?
        ');
        $stmt->execute([self::hash($token)]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false || $fila['estado'] !== 'pagada' || ProductosFisicos::esFisico($fila)) {
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

    /** true si la orden se creó dentro de la ventana de la conciliación. */
    public static function estaEnVentanaDeConciliacion(array $orden)
    {
        return !empty($orden['creado_en'])
            && strtotime($orden['creado_en']) >= time() - self::DIAS_A_CONCILIAR * 86400;
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

    // ---------------------------------------------------------------- ventas

    /**
     * Las ventas de un producto (por su link) o de todos los productos de una
     * página, los borrados incluidos, con los totales de arriba.
     *
     * Las de una página se buscan por la foto de digital_products (page_id):
     * el link de un producto borrado ya no existe.
     *
     * Lo que sale es para quien administra la página: datos de contacto sí,
     * pero nunca el token ni nada con lo que se pueda descargar.
     *
     * @param string $alcance 'link' o 'pagina'
     * @return array{ordenes: array, resumen: array}
     */
    public static function ventas($db, $alcance, $id)
    {
        $filtros = [
            'link'   => 'dp.link_id = ?',
            'pagina' => 'dp.page_id = ?',
        ];

        if (!isset($filtros[$alcance])) {
            throw new InvalidArgumentException('Alcance de ventas desconocido');
        }

        $stmt = $db->prepare('
            SELECT o.id, o.codigo, o.nombre, o.email, o.telefono, o.total, o.estado,
                   o.creado_en, o.pagada_en, o.descargas, o.primera_descarga_en, o.ultima_descarga_en,
                   dp.id AS producto_id, dp.link_id, COALESCE(o.producto_titulo, l.text, dp.titulo) AS producto,
                   dp.borrado_en AS producto_borrado_en
            FROM product_orders o
            INNER JOIN digital_products dp ON dp.id = o.product_id
            LEFT JOIN links l ON l.id = dp.link_id
            WHERE ' . $filtros[$alcance] . '
            ORDER BY o.id DESC
        ');
        $stmt->execute([(int) $id]);

        $ordenes = array_map([self::class, 'venta'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['ordenes' => $ordenes, 'resumen' => self::resumenDeVentas($ordenes)];
    }

    /** Una fila de la lista de ventas, con los tipos que espera el frontend. */
    private static function venta(array $fila)
    {
        return [
            'id'                  => (int) $fila['id'],
            'codigo'              => $fila['codigo'],
            'nombre'              => $fila['nombre'],
            'email'               => $fila['email'],
            'telefono'            => $fila['telefono'],
            'total'               => round((float) $fila['total'], 2),
            'moneda'              => 'ARS',
            'estado'              => self::estaVencida($fila) ? 'vencida' : $fila['estado'],
            'creado_en'           => $fila['creado_en'],
            'pagada_en'           => $fila['pagada_en'],
            'descargas'           => (int) $fila['descargas'],
            'primera_descarga_en' => $fila['primera_descarga_en'],
            'ultima_descarga_en'  => $fila['ultima_descarga_en'],
            'producto_id'         => (int) $fila['producto_id'],
            'link_id'             => $fila['link_id'] === null ? null : (int) $fila['link_id'],
            'producto'            => $fila['producto'],
            'producto_borrado'    => !empty($fila['producto_borrado_en']),
        ];
    }

    /** Ventas pagadas, lo recaudado con ellas y cuántas ya se descargaron. */
    private static function resumenDeVentas(array $ordenes)
    {
        $resumen = ['pagadas' => 0, 'recaudado' => 0.0, 'descargaron' => 0];

        foreach ($ordenes as $o) {
            if ($o['estado'] !== 'pagada') {
                continue;
            }

            $resumen['pagadas']++;
            $resumen['recaudado'] += $o['total'];

            if ($o['descargas'] > 0) {
                $resumen['descargaron']++;
            }
        }

        $resumen['recaudado'] = round($resumen['recaudado'], 2);

        return $resumen;
    }

    /**
     * Las ventas en un archivo de Excel, con la columna de descargas.
     *
     * @return string el contenido del .xlsx
     */
    public static function excel(array $ordenes)
    {
        $filas = [];

        foreach ($ordenes as $o) {
            $filas[] = [
                $o['codigo'],
                $o['producto'] . ($o['producto_borrado'] ? ' (borrado)' : ''),
                $o['nombre'],
                $o['email'],
                $o['telefono'],
                (float) $o['total'],
                $o['estado'],
                $o['creado_en'],
                self::descripcionDeDescargas($o),
                (int) $o['descargas'],
                $o['primera_descarga_en'],
            ];
        }

        return Excel::tabla(
            ['Código', 'Producto', 'Nombre', 'Email', 'Teléfono', 'Total', 'Estado', 'Fecha',
             'Descargó', 'Descargas', 'Primera descarga'],
            $filas,
            'Ventas'
        );
    }

    /** "No", o "Sí · 1 vez" / "Sí · 3 veces". */
    public static function descripcionDeDescargas(array $orden)
    {
        $veces = (int) $orden['descargas'];

        if ($veces <= 0) {
            return 'No';
        }

        return 'Sí · ' . $veces . ($veces === 1 ? ' vez' : ' veces');
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
     * Un físico (#43) descuenta su stock en la misma transacción que marca la
     * orden pagada (PedidosFisicos::descontarStock). Si ya no alcanza, la
     * orden queda pagada igual y marcada sin_stock.
     *
     * @return array{acreditada: bool, motivo: string}
     */
    public static function acreditarPago($db, $codigo, $pagoId, $estadoMp, array $detalle = [])
    {
        if ($estadoMp !== 'approved') {
            return self::registrarPago($db, $codigo, $pagoId, $estadoMp, $detalle);
        }

        $db->beginTransaction();

        try {
            $resultado = self::registrarPago($db, $codigo, $pagoId, $estadoMp, $detalle);

            if ($resultado['acreditada']) {
                $stmt = $db->prepare("
                    SELECT o.id, o.product_id, o.cantidad
                    FROM product_orders o
                    INNER JOIN digital_products dp ON dp.id = o.product_id
                    WHERE dp.tipo = 'fisico' AND o.codigo = ?
                ");
                $stmt->execute([$codigo]);
                $fisico = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($fisico !== false && !PedidosFisicos::descontarStock($db, $fisico)) {
                    $resultado['sin_stock'] = true;
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return $resultado;
    }

    private static function registrarPago($db, $codigo, $pagoId, $estadoMp, array $detalle)
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
     * Órdenes que pueden tener un pago aprobado que no llegó por aviso:
     *
     * - pendientes sin pago registrado (se perdió el aviso);
     * - rechazadas: tienen el id del pago rechazado, pero en el checkout de
     *   Mercado Pago se puede reintentar con otra tarjeta, y ese segundo pago
     *   puede no avisar. La búsqueda por referencia prefiere el aprobado.
     *
     * Siempre dentro de la ventana de días. Las gratis no: nacen pagadas.
     *
     * @return string[] Códigos de orden.
     */
    public static function sinConciliar($db, $dias = self::DIAS_A_CONCILIAR, $limite = self::LIMITE_A_CONCILIAR)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM product_orders
            WHERE ((estado = 'pendiente' AND mp_payment_id IS NULL) OR estado = 'rechazada')
              AND total > 0
              AND creado_en >= (NOW() - INTERVAL ? DAY)
            ORDER BY creado_en DESC
            LIMIT " . (int) $limite . "
        ");
        $stmt->execute([(int) $dias]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
