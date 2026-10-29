<?php

/**
 * Pedidos de productos físicos (#43): lo que se le pide a quien compra una
 * remera o un disco además de lo de siempre —cantidad, opciones, dirección y
 * horarios de entrega—, el total con envío y el stock.
 *
 * El cobro no es de acá: es el de los digitales (CompraProductoHandler,
 * OrdenesDeProductos, Cobros, Comision, MercadoPago). Esto valida el pedido,
 * arma su foto y descuenta el stock cuando se acredita.
 */
class PedidosFisicos
{
    const CANTIDAD_MAXIMA = 10;

    /** Las 23 provincias y la Ciudad de Buenos Aires, como se muestran. */
    const PROVINCIAS = [
        'Buenos Aires', 'Ciudad Autónoma de Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
        'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
        'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe',
        'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
    ];

    /** Lo que se guarda => cómo se lee. En este orden. */
    const DIAS = [
        'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles', 'jueves' => 'Jueves',
        'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
    ];

    const FRANJAS = ['manana' => 'Mañana', 'tarde' => 'Tarde', 'noche' => 'Noche'];

    /** Los largos de las columnas de product_order_envios. */
    const LARGOS = [
        'calle' => 150, 'piso_depto' => 50, 'localidad' => 100, 'codigo_postal' => 10,
        'indicaciones' => 255, 'comentario' => 255,
    ];

    const MENSAJE_SIN_STOCK = 'No queda stock suficiente para esa cantidad. Probá con menos unidades o con otra opción.';

    const MENSAJE_AGOTADO = 'Este producto está agotado.';

    // ------------------------------------------------------------ validación

    /**
     * La cantidad pedida, de 1 a CANTIDAD_MAXIMA, o null si no sirve.
     *
     * @return int|null
     */
    public static function cantidad($valor)
    {
        if (is_int($valor)) {
            $n = $valor;
        } elseif (is_string($valor) && preg_match('/^\s*\d{1,3}\s*$/', $valor) === 1) {
            $n = (int) trim($valor);
        } else {
            return null;
        }

        return $n >= 1 && $n <= self::CANTIDAD_MAXIMA ? $n : null;
    }

    /**
     * Para entregar hace falta poder llamar: el teléfono es obligatorio y
     * tiene que tener al menos 6 dígitos.
     *
     * @return string|null el problema, o null
     */
    public static function problemaDeTelefono($telefono)
    {
        $telefono = is_string($telefono) ? trim($telefono) : '';

        if ($telefono === '') {
            return 'Falta el teléfono: lo necesitamos para coordinar la entrega';
        }

        return strlen(preg_replace('/\D/', '', $telefono)) < 6 ? 'El teléfono no es válido' : null;
    }

    /**
     * La dirección de envío que llega del formulario, validada.
     *
     * @return array [['calle' => ..., 'piso_depto' => ?, 'localidad' => ..., 'provincia' => ...,
     *                 'codigo_postal' => ..., 'indicaciones' => ?], null] o [null, error]
     */
    public static function envio($envio)
    {
        if (!is_array($envio)) {
            return [null, 'Falta la dirección de envío'];
        }

        $campos = [];

        foreach (['calle', 'piso_depto', 'localidad', 'provincia', 'codigo_postal', 'indicaciones'] as $campo) {
            $campos[$campo] = self::texto(isset($envio[$campo]) ? $envio[$campo] : null);
        }

        $obligatorios = [
            'calle'         => 'Falta la calle y el número',
            'localidad'     => 'Falta la localidad',
            'provincia'     => 'Falta la provincia',
            'codigo_postal' => 'Falta el código postal',
        ];

        foreach ($obligatorios as $campo => $mensaje) {
            if ($campos[$campo] === '') {
                return [null, $mensaje];
            }
        }

        $nombres = [
            'calle' => 'La calle y el número', 'piso_depto' => 'El piso y depto', 'localidad' => 'La localidad',
            'codigo_postal' => 'El código postal', 'indicaciones' => 'Las indicaciones',
        ];

        foreach ($nombres as $campo => $nombre) {
            if (mb_strlen($campos[$campo], 'UTF-8') > self::LARGOS[$campo]) {
                return [null, $nombre . ' puede tener hasta ' . self::LARGOS[$campo] . ' caracteres'];
            }
        }

        if (!in_array($campos['provincia'], self::PROVINCIAS, true)) {
            return [null, 'Elegí una provincia de la lista'];
        }

        // Los 4 números de siempre o el CPA de 8 caracteres (C1043AAZ).
        $cp = strtoupper(str_replace(' ', '', $campos['codigo_postal']));

        if (preg_match('/^(\d{4}|[A-Z]\d{4}[A-Z]{3})$/', $cp) !== 1) {
            return [null, 'El código postal no es válido: son 4 números (1414) o 8 caracteres (C1414BAD)'];
        }

        $campos['codigo_postal'] = $cp;

        foreach (['piso_depto', 'indicaciones'] as $opcional) {
            if ($campos[$opcional] === '') {
                $campos[$opcional] = null;
            }
        }

        return [$campos, null];
    }

    /**
     * Los horarios en que puede recibir: al menos un día y una franja.
     *
     * @return array [['dias' => [...], 'franjas' => [...], 'comentario' => ?], null] o [null, error]
     */
    public static function horarios($horarios)
    {
        if (!is_array($horarios)) {
            return [null, 'Elegí al menos un día para recibir el pedido'];
        }

        $dias = self::elegidos(isset($horarios['dias']) ? $horarios['dias'] : null, self::DIAS);

        if ($dias === null) {
            return [null, 'Los días no tienen el formato esperado'];
        }

        if (empty($dias)) {
            return [null, 'Elegí al menos un día para recibir el pedido'];
        }

        $franjas = self::elegidos(isset($horarios['franjas']) ? $horarios['franjas'] : null, self::FRANJAS);

        if ($franjas === null) {
            return [null, 'Las franjas no tienen el formato esperado'];
        }

        if (empty($franjas)) {
            return [null, 'Elegí al menos una franja: mañana, tarde o noche'];
        }

        $comentario = self::texto(isset($horarios['comentario']) ? $horarios['comentario'] : null);

        if (mb_strlen($comentario, 'UTF-8') > self::LARGOS['comentario']) {
            return [null, 'El comentario de horario puede tener hasta ' . self::LARGOS['comentario'] . ' caracteres'];
        }

        return [['dias' => $dias, 'franjas' => $franjas, 'comentario' => $comentario === '' ? null : $comentario], null];
    }

    /**
     * Las claves elegidas de una lista cerrada, sin repetir y en el orden de
     * la lista. null si llega algo que no es de la lista.
     */
    private static function elegidos($valor, array $validos)
    {
        if ($valor === null || $valor === '') {
            return [];
        }

        if (!is_array($valor)) {
            return null;
        }

        foreach ($valor as $v) {
            if (!is_string($v) || !array_key_exists($v, $validos)) {
                return null;
            }
        }

        return array_values(array_filter(array_keys($validos), function ($clave) use ($valor) {
            return in_array($clave, $valor, true);
        }));
    }

    /**
     * Las opciones elegidas contra las características activas del producto:
     * exactamente una de cada una. Sin características no se elige nada.
     *
     * @param array $caracteristicas las de ProductosFisicos::completar()
     * @param mixed $elegidas ids de opciones
     * @return array [['ids' => [...], 'texto' => 'Talle M · Color Negro' o null], null] o [null, error]
     */
    public static function opciones(array $caracteristicas, $elegidas)
    {
        if (empty($caracteristicas)) {
            return [['ids' => [], 'texto' => null], null];
        }

        $elegidas = is_array($elegidas) ? array_map('intval', array_values($elegidas)) : [];
        $ids = [];
        $partes = [];

        foreach ($caracteristicas as $c) {
            $encontrada = null;

            foreach ($c['opciones'] as $o) {
                if (in_array((int) $o['id'], $elegidas, true)) {
                    if ($encontrada !== null) {
                        return [null, 'Elegí una sola opción de ' . mb_strtolower($c['nombre'], 'UTF-8')];
                    }

                    $encontrada = $o;
                }
            }

            if ($encontrada === null) {
                return [null, 'Elegí ' . mb_strtolower($c['nombre'], 'UTF-8')];
            }

            $ids[] = (int) $encontrada['id'];
            $partes[] = $c['nombre'] . ' ' . $encontrada['nombre'];
        }

        if (count($elegidas) !== count($ids)) {
            return [null, 'Alguna de las opciones elegidas ya no existe'];
        }

        return [['ids' => $ids, 'texto' => implode(' · ', $partes)], null];
    }

    /**
     * Cuánto queda de lo elegido, o null si no tiene límite. Con
     * características, el de la combinación; sin, el del producto.
     *
     * @param array $producto completado por ProductosFisicos::completar()
     * @return int|null
     */
    public static function stockDisponible(array $producto, array $opcionIds)
    {
        if (empty($producto['caracteristicas'])) {
            return isset($producto['stock']) && $producto['stock'] !== null ? (int) $producto['stock'] : null;
        }

        $clave = ProductosFisicos::clave($opcionIds);

        foreach (isset($producto['variantes']) ? $producto['variantes'] : [] as $v) {
            if ($v['clave'] === $clave) {
                return $v['stock'] === null ? null : (int) $v['stock'];
            }
        }

        return null;
    }

    /** Precio × cantidad + envío, en pesos con dos decimales. */
    public static function total($precioUnitario, $cantidad, $costoEnvio)
    {
        return round(round((float) $precioUnitario, 2) * (int) $cantidad + round((float) $costoEnvio, 2), 2);
    }

    // ------------------------------------------------------------- stock

    /**
     * Descuenta el stock de un pedido que se acaba de acreditar. Tiene que
     * correr dentro de la transacción que marca la orden pagada.
     *
     * La fila de stock se lee con FOR UPDATE: si dos acreditan la última
     * unidad a la vez, el segundo espera al primero y ve lo que quedó. Al que
     * ya no le alcanza se le marca sin_stock: la orden queda pagada igual, y
     * lo resuelve quien vende. Nada se cancela ni se devuelve solo.
     *
     * @return bool true si había stock (o no tiene límite)
     */
    public static function descontarStock($db, array $orden)
    {
        $cantidad = max(1, (int) $orden['cantidad']);
        $productoId = (int) $orden['product_id'];

        $stmt = $db->prepare('SELECT option_id FROM product_order_options WHERE order_id = ?');
        $stmt->execute([(int) $orden['id']]);
        $opciones = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $clave = empty($opciones) ? null : ProductosFisicos::clave($opciones);

        if ($clave === null) {
            $stmt = $db->prepare('SELECT stock FROM digital_products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productoId]);
        } else {
            $stmt = $db->prepare('SELECT stock FROM product_variants WHERE product_id = ? AND clave = ? FOR UPDATE');
            $stmt->execute([$productoId, $clave]);
        }

        $stock = $stmt->fetchColumn();

        // Sin fila o con NULL: sin límite.
        if ($stock === false || $stock === null) {
            return true;
        }

        if ((int) $stock < $cantidad) {
            $upd = $db->prepare('UPDATE product_orders SET sin_stock = 1 WHERE id = ?');
            $upd->execute([(int) $orden['id']]);

            return false;
        }

        if ($clave === null) {
            $upd = $db->prepare('UPDATE digital_products SET stock = stock - ? WHERE id = ?');
            $upd->execute([$cantidad, $productoId]);
        } else {
            $upd = $db->prepare('UPDATE product_variants SET stock = stock - ? WHERE product_id = ? AND clave = ?');
            $upd->execute([$cantidad, $productoId, $clave]);
        }

        return true;
    }

    // -------------------------------------------------------------- lectura

    /** La dirección y los horarios de un pedido, o null si no es de un físico. */
    public static function envioDe($db, $ordenId)
    {
        $stmt = $db->prepare('SELECT * FROM product_order_envios WHERE order_id = ?');
        $stmt->execute([(int) $ordenId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        return [
            'calle'         => $fila['calle'],
            'piso_depto'    => $fila['piso_depto'],
            'localidad'     => $fila['localidad'],
            'provincia'     => $fila['provincia'],
            'codigo_postal' => $fila['codigo_postal'],
            'indicaciones'  => $fila['indicaciones'],
            'dias'          => self::lista($fila['dias']),
            'franjas'       => self::lista($fila['franjas']),
            'comentario'    => $fila['comentario_horario'],
        ];
    }

    /**
     * La calle y el número a medias, para la pantalla pública del pedido:
     * hasta el primer dígito del número ("Av. Corrientes 1…"). Sin número,
     * la primera mitad. Así un código filtrado no expone el domicilio.
     */
    public static function calleParcial($calle)
    {
        $calle = trim((string) $calle);

        if ($calle === '') {
            return '';
        }

        if (preg_match('/^(\D*\d)/u', $calle, $m) === 1) {
            $parcial = $m[1];
        } else {
            $parcial = mb_substr($calle, 0, (int) ceil(mb_strlen($calle, 'UTF-8') / 2), 'UTF-8');
        }

        return rtrim($parcial) . '…';
    }

    /** "Lunes, Miércoles y Sábado". */
    public static function describirDias(array $dias)
    {
        return self::enumerar(array_map(function ($d) {
            return isset(self::DIAS[$d]) ? self::DIAS[$d] : $d;
        }, $dias));
    }

    /** "Mañana y Tarde". */
    public static function describirFranjas(array $franjas)
    {
        return self::enumerar(array_map(function ($f) {
            return isset(self::FRANJAS[$f]) ? self::FRANJAS[$f] : $f;
        }, $franjas));
    }

    /** "Av. Corrientes 1234, 3° B — CABA, Ciudad Autónoma de Buenos Aires (C1043)". */
    public static function describirDireccion(array $envio)
    {
        $calle = $envio['calle'] . (empty($envio['piso_depto']) ? '' : ', ' . $envio['piso_depto']);

        return $calle . ' — ' . $envio['localidad'] . ', ' . $envio['provincia'] . ' (' . $envio['codigo_postal'] . ')';
    }

    private static function enumerar(array $partes)
    {
        if (count($partes) <= 1) {
            return implode('', $partes);
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }

    private static function lista($valor)
    {
        $valor = trim((string) $valor);

        return $valor === '' ? [] : explode(',', $valor);
    }

    /** Un campo de texto del cuerpo, sin espacios de más; cualquier otra cosa es vacío. */
    private static function texto($valor)
    {
        return is_string($valor) || is_int($valor) ? trim((string) $valor) : '';
    }
}
