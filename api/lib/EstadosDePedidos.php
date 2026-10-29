<?php

/**
 * Los estados de un pedido de un físico (#44), una vez pagado:
 *
 *   Ingresado → Con fecha de entrega → Enviado → Entregado
 *
 * y Cancelado desde cualquiera salvo Entregado. Se puede saltar hacia
 * adelante (de Ingresado directo a Enviado o a Entregado), nunca volver: lo
 * único que se repite es Con fecha de entrega, para corregir la fecha.
 *
 * Es independiente del estado del pago (product_orders.estado): un pedido
 * entra acá recién cuando el pago se acredita o el pedido gratis se confirma
 * (ingresar), y desde ahí lo mueve quien vende (cambiar). Cancelar no
 * devuelve el pago —eso se hace desde Mercado Pago—; sí devuelve el stock, si
 * el pedido lo había descontado.
 *
 * Cada cambio queda en product_order_historial con quién lo hizo y sus datos.
 */
class EstadosDePedidos
{
    /** Lo que se guarda => cómo se lee. En el orden del recorrido. */
    const ESTADOS = [
        'ingresado' => 'Ingresado',
        'con_fecha' => 'Con fecha de entrega',
        'enviado'   => 'Enviado',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];

    /**
     * Los mismos estados con otro nombre según la forma de entrega (#53). Lo
     * que no está acá se llama como en ESTADOS: envío y coordinar usan esos.
     */
    const ESTADOS_DEL_RETIRO = [
        'con_fecha' => 'Con fecha de retiro',
        'enviado'   => 'Listo para retirar',
        'entregado' => 'Retirado',
    ];

    /** Cómo se llama un estado en un pedido de esa forma de entrega. */
    public static function etiqueta($estado, $forma = 'envio')
    {
        if ($forma === 'retiro' && isset(self::ESTADOS_DEL_RETIRO[$estado])) {
            return self::ESTADOS_DEL_RETIRO[$estado];
        }

        return isset(self::ESTADOS[$estado]) ? self::ESTADOS[$estado] : (string) $estado;
    }

    /** A dónde se puede pasar desde cada estado. */
    const SIGUIENTES = [
        'ingresado' => ['con_fecha', 'enviado', 'entregado', 'cancelado'],
        // Con fecha de entrega se repite: es corregir la fecha.
        'con_fecha' => ['con_fecha', 'enviado', 'entregado', 'cancelado'],
        'enviado'   => ['entregado', 'cancelado'],
        'entregado' => [],
        'cancelado' => [],
    ];

    /** Los largos de las columnas de product_orders. */
    const LARGOS = ['empresa' => 100, 'seguimiento' => 255, 'motivo' => 255];

    /** Hasta cuántos días adelante se puede poner una fecha de entrega. */
    const DIAS_HACIA_ADELANTE = 365;

    // ------------------------------------------------------------ transiciones

    public static function puedePasar($desde, $hacia)
    {
        return in_array($hacia, self::siguientes($desde), true);
    }

    /** Los estados a los que se puede pasar desde $desde, para la pantalla. */
    public static function siguientes($desde)
    {
        return is_string($desde) && isset(self::SIGUIENTES[$desde]) ? self::SIGUIENTES[$desde] : [];
    }

    /**
     * Los datos de un paso, validados.
     *
     * - con_fecha: fecha (AAAA-MM-DD, obligatoria) y franja (opcional, de
     *   PedidosFisicos::FRANJAS). La fecha puede ser de ayer en adelante: el
     *   servidor no está en la hora de Argentina, y a la noche "hoy" allá
     *   puede ser "ayer" acá.
     * - enviado: empresa y seguimiento, los dos opcionales.
     * - cancelado: motivo, obligatorio.
     * - entregado: nada.
     *
     * @param string|null $hoy AAAA-MM-DD, para los tests
     * @return array [datos, null] o [null, error]
     */
    public static function datos($hacia, array $entrada, $hoy = null)
    {
        if ($hacia === 'con_fecha') {
            return self::datosDeFecha($entrada, $hoy === null ? date('Y-m-d') : $hoy);
        }

        if ($hacia === 'enviado') {
            $datos = [
                'empresa'     => self::texto(isset($entrada['empresa']) ? $entrada['empresa'] : null),
                'seguimiento' => self::texto(isset($entrada['seguimiento']) ? $entrada['seguimiento'] : null),
            ];

            foreach (['empresa' => 'La empresa o el cadete', 'seguimiento' => 'El seguimiento'] as $campo => $nombre) {
                if (mb_strlen($datos[$campo], 'UTF-8') > self::LARGOS[$campo]) {
                    return [null, $nombre . ' puede tener hasta ' . self::LARGOS[$campo] . ' caracteres'];
                }

                if ($datos[$campo] === '') {
                    $datos[$campo] = null;
                }
            }

            return [$datos, null];
        }

        if ($hacia === 'cancelado') {
            $motivo = self::texto(isset($entrada['motivo']) ? $entrada['motivo'] : null);

            if ($motivo === '') {
                return [null, 'Contá por qué se cancela: se lo mandamos a quien compró'];
            }

            if (mb_strlen($motivo, 'UTF-8') > self::LARGOS['motivo']) {
                return [null, 'El motivo puede tener hasta ' . self::LARGOS['motivo'] . ' caracteres'];
            }

            return [['motivo' => $motivo], null];
        }

        if ($hacia === 'entregado') {
            return [[], null];
        }

        return [null, 'Estado desconocido'];
    }

    private static function datosDeFecha(array $entrada, $hoy)
    {
        $fecha = self::texto(isset($entrada['fecha']) ? $entrada['fecha'] : null);

        if ($fecha === '') {
            return [null, 'Elegí la fecha de entrega'];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [null, 'La fecha de entrega no es válida'];
        }

        $base = strtotime($hoy . ' 00:00:00');

        if ($fecha < date('Y-m-d', $base - 86400)) {
            return [null, 'La fecha de entrega ya pasó'];
        }

        if ($fecha > date('Y-m-d', $base + self::DIAS_HACIA_ADELANTE * 86400)) {
            return [null, 'La fecha de entrega tiene que ser dentro del próximo año'];
        }

        $franja = self::texto(isset($entrada['franja']) ? $entrada['franja'] : null);

        if ($franja !== '' && !array_key_exists($franja, PedidosFisicos::FRANJAS)) {
            return [null, 'La franja tiene que ser mañana, tarde o noche'];
        }

        return [['fecha' => $fecha, 'franja' => $franja === '' ? null : $franja], null];
    }

    // ------------------------------------------------------------- escritura

    /**
     * El primer estado de un pedido de un físico: Ingresado, cuando se
     * acredita el pago o se confirma el pedido gratis. Lo hace el sistema
     * (user_id NULL). Tiene que correr dentro de la transacción que marca la
     * orden pagada. Si ya tenía estado, no hace nada.
     */
    public static function ingresar($db, $ordenId)
    {
        $stmt = $db->prepare("UPDATE product_orders SET estado_pedido = 'ingresado' WHERE id = ? AND estado_pedido IS NULL");
        $stmt->execute([(int) $ordenId]);

        if ($stmt->rowCount() > 0) {
            self::anotar($db, $ordenId, 'ingresado', [], null);
        }
    }

    /**
     * Pasa un pedido a otro estado. Todo en una transacción: el estado, sus
     * datos, el stock devuelto si se cancela y la línea del historial.
     *
     * La orden se vuelve a leer con FOR UPDATE: si dos personas lo cambian a
     * la vez, la segunda ve lo que hizo la primera y la transición se valida
     * contra eso, no contra lo que tenía en pantalla.
     *
     * @param array $datos los de datos(), ya validados
     * @return array{ok: bool, status?: int, error?: string, desde?: string, stock_devuelto?: bool}
     */
    public static function cambiar($db, $ordenId, $hacia, array $datos, $userId)
    {
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('
                SELECT id, product_id, cantidad, estado, estado_pedido, sin_stock, entrega_fecha, entrega_franja
                FROM product_orders
                WHERE id = ?
                FOR UPDATE
            ');
            $stmt->execute([(int) $ordenId]);
            $orden = $stmt->fetch(PDO::FETCH_ASSOC);

            $problema = self::problemaDeTransicion($orden, $hacia, $datos);

            if ($problema !== null) {
                $db->rollBack();

                return ['ok' => false, 'status' => $problema[0], 'error' => $problema[1]];
            }

            $desde = $orden['estado_pedido'];

            list($columnas, $valores) = self::columnas($hacia, $datos);
            $valores[] = (int) $ordenId;
            $valores[] = $desde;

            $upd = $db->prepare('
                UPDATE product_orders
                SET ' . implode(', ', $columnas) . '
                WHERE id = ? AND estado_pedido = ?
            ');
            $upd->execute($valores);

            $stockDevuelto = false;

            if ($hacia === 'cancelado' && empty($orden['sin_stock'])) {
                $stockDevuelto = self::devolverStock($db, $orden);
            }

            self::anotar($db, $ordenId, $hacia, $datos, $userId);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        return ['ok' => true, 'desde' => $desde, 'stock_devuelto' => $stockDevuelto];
    }

    /** [status, mensaje] si no se puede, o null. */
    private static function problemaDeTransicion($orden, $hacia, array $datos)
    {
        if (!isset(self::ESTADOS[$hacia])) {
            return [400, 'Estado desconocido'];
        }

        if ($orden === false || $orden === null) {
            return [404, 'No encontramos ese pedido'];
        }

        if ($orden['estado'] !== 'pagada' || empty($orden['estado_pedido'])) {
            return [409, 'Este pedido todavía no está pagado: no se puede gestionar'];
        }

        $desde = $orden['estado_pedido'];

        if (!self::puedePasar($desde, $hacia)) {
            return [409, empty(self::SIGUIENTES[$desde])
                ? 'El pedido ya está ' . mb_strtolower(self::ESTADOS[$desde], 'UTF-8') . ': no se puede cambiar'
                : 'Un pedido ' . mb_strtolower(self::ESTADOS[$desde], 'UTF-8') . ' no puede pasar a '
                    . mb_strtolower(self::ESTADOS[$hacia], 'UTF-8')];
        }

        if ($desde === 'con_fecha' && $hacia === 'con_fecha'
            && (string) $orden['entrega_fecha'] === $datos['fecha']
            && (string) $orden['entrega_franja'] === (string) $datos['franja']) {
            return [409, 'El pedido ya tiene esa fecha y esa franja'];
        }

        return null;
    }

    /** Las columnas que cambia cada paso, para el UPDATE. */
    private static function columnas($hacia, array $datos)
    {
        $columnas = ['estado_pedido = ?'];
        $valores = [$hacia];

        if ($hacia === 'con_fecha') {
            $columnas[] = 'entrega_fecha = ?';
            $columnas[] = 'entrega_franja = ?';
            $valores[] = $datos['fecha'];
            $valores[] = $datos['franja'];
        } elseif ($hacia === 'enviado') {
            $columnas[] = 'envio_empresa = ?';
            $columnas[] = 'envio_seguimiento = ?';
            $valores[] = $datos['empresa'];
            $valores[] = $datos['seguimiento'];
        } elseif ($hacia === 'cancelado') {
            $columnas[] = 'cancelado_motivo = ?';
            $valores[] = $datos['motivo'];
        }

        return [$columnas, $valores];
    }

    /**
     * Devuelve al stock lo que el pedido descontó al acreditarse
     * (PedidosFisicos::descontarStock), por la misma combinación. Sin límite
     * (NULL), o si la combinación ya no existe, no hay nada que devolver.
     *
     * @return bool true si sumó algo
     */
    public static function devolverStock($db, array $orden)
    {
        $cantidad = max(1, (int) $orden['cantidad']);
        $productoId = (int) $orden['product_id'];

        $stmt = $db->prepare('SELECT option_id FROM product_order_options WHERE order_id = ?');
        $stmt->execute([(int) $orden['id']]);
        $opciones = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($opciones)) {
            $upd = $db->prepare('UPDATE digital_products SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL');
            $upd->execute([$cantidad, $productoId]);
        } else {
            $upd = $db->prepare('
                UPDATE product_variants SET stock = stock + ?
                WHERE product_id = ? AND clave = ? AND stock IS NOT NULL
            ');
            $upd->execute([$cantidad, $productoId, ProductosFisicos::clave($opciones)]);
        }

        return $upd->rowCount() > 0;
    }

    private static function anotar($db, $ordenId, $estado, array $datos, $userId)
    {
        $stmt = $db->prepare('
            INSERT INTO product_order_historial (order_id, estado, datos, user_id)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            (int) $ordenId,
            $estado,
            empty($datos) ? null : json_encode($datos, JSON_UNESCAPED_UNICODE),
            $userId === null ? null : (int) $userId,
        ]);
    }

    // --------------------------------------------------------------- lectura

    /**
     * El historial de un pedido, del primero al último, con quién hizo cada
     * cambio (su nombre o, si no tiene, su email). "Sistema" cuando entró
     * solo, al acreditarse el pago. Los nombres, los de la forma de entrega.
     */
    public static function historial($db, $ordenId, $forma = 'envio')
    {
        $stmt = $db->prepare('
            SELECT h.id, h.estado, h.datos, h.creado_en, h.user_id,
                   COALESCE(NULLIF(u.name, ""), u.email) AS quien
            FROM product_order_historial h
            LEFT JOIN users u ON u.id = h.user_id
            WHERE h.order_id = ?
            ORDER BY h.id
        ');
        $stmt->execute([(int) $ordenId]);

        return array_map(function ($fila) use ($forma) {
            $datos = $fila['datos'] === null ? [] : json_decode($fila['datos'], true);

            return [
                'estado'    => $fila['estado'],
                'etiqueta'  => self::etiqueta($fila['estado'], $forma),
                'datos'     => is_array($datos) ? $datos : [],
                'creado_en' => $fila['creado_en'],
                'quien'     => $fila['user_id'] === null ? 'Sistema' : $fila['quien'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * La línea de tiempo que ve quien compró en /compra/:codigo: los pasos
     * del recorrido con la fecha en que se llegó a cada uno (la última vez,
     * si la fecha se corrigió). Sin quién lo hizo ni los datos del historial:
     * el código del pedido no es una credencial. Un cancelado corta el
     * recorrido donde estaba.
     *
     * @param string $forma la forma de entrega, que le pone nombre a los pasos (#53)
     * @return array [['estado' => ..., 'etiqueta' => ..., 'fecha' => ?], ...]
     */
    public static function lineaDeTiempo($estadoActual, array $historial, $forma = 'envio')
    {
        $cuando = [];

        foreach ($historial as $paso) {
            $cuando[$paso['estado']] = $paso['creado_en'];
        }

        $recorrido = ['ingresado', 'con_fecha', 'enviado', 'entregado'];

        if ($estadoActual === 'cancelado') {
            // Hasta donde llegó, y el cancelado al final.
            $recorrido = array_values(array_filter($recorrido, function ($e) use ($cuando) {
                return isset($cuando[$e]);
            }));
            $recorrido[] = 'cancelado';
        }

        return array_map(function ($estado) use ($cuando, $forma) {
            return [
                'estado'   => $estado,
                'etiqueta' => self::etiqueta($estado, $forma),
                'fecha'    => isset($cuando[$estado]) ? $cuando[$estado] : null,
            ];
        }, $recorrido);
    }

    /** Un campo de texto del cuerpo, sin espacios de más; cualquier otra cosa es vacío. */
    private static function texto($valor)
    {
        return is_string($valor) || is_int($valor) ? trim((string) $valor) : '';
    }
}
