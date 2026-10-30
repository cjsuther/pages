<?php

/**
 * Lo que ve quien administra la plataforma y nadie más.
 *
 * Son datos de todas las páginas juntas —cuánto vendió cada una, cuánta
 * comisión dejó—, así que el control de acceso no es una formalidad: acá se
 * cruzan las ventas de gente que no se conoce entre sí.
 */
class PlataformaHandler
{
    /** Tope de la lista de ventas con problema. Es para revisar, no para exportar. */
    const LIMITE_REVISAR = 100;

    /**
     * Reporte de comisiones cobradas.
     *
     * La pregunta que contesta es doble: cuánto se cobró, y si lo cobrado
     * coincide con lo que se pidió cobrar. Lo segundo importa porque mandar el
     * marketplace_fee no garantiza nada: si la cuenta de la página no está
     * conectada por OAuth desde nuestra aplicación, Mercado Pago lo ignora sin
     * devolver ningún error y la venta se hace igual.
     */
    public static function comisiones($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        if (!Plataforma::esAdmin($db, $req->userId())) {
            // 404 y no 403: para quien no administra la plataforma este reporte
            // no existe, y un 403 confirmaría que hay algo detrás de esta URL.
            return Response::notFound('No encontramos esa página');
        }

        list($where, $params) = self::rango($req);

        // Entradas, productos digitales y físicos se cobran igual (misma
        // comisión, mismo Mercado Pago) pero se cuentan por separado. Se
        // consulta cada tipo por su lado y se suman acá: las consultas de
        // entradas quedan como estaban, y sus números no pueden cambiar por
        // sumar productos.
        $porTipo = [];
        $meses = [];
        $paginas = [];
        $revisar = [];

        foreach (self::FUENTES as $tipo => $fuente) {
            $porTipo[$tipo] = self::resumen($db, $fuente, $where, $params);
            $meses[$tipo] = self::porMes($db, $fuente, $where, $params);
            $paginas[$tipo] = self::porPagina($db, $fuente, $where, $params);
            $revisar[$tipo] = self::sinCobrar($db, $fuente, $where, $params);
        }

        return Response::ok([
            'desde'    => $req->param('desde', ''),
            'hasta'    => $req->param('hasta', ''),
            'resumen'  => self::numeros(self::sumar(array_values($porTipo))),
            'por_tipo' => $porTipo,
            'meses'    => self::juntar($meses, 'mes', 'mes'),
            'paginas'  => self::juntar($paginas, 'id', 'cobrada'),
            'revisar'  => self::juntarRevisar($revisar),
        ]);
    }

    /**
     * De dónde sale cada tipo de venta. Las claves son las de por_tipo.
     *
     * `tipo` es lo que separa una fuente de otra en la misma tabla: digitales
     * y físicos viven los dos en product_orders, y se distinguen por el tipo
     * del producto, que no cambia después de crearlo. Va en todas las
     * consultas, también en las que no necesitan la página.
     *
     * Un producto borrado ya no tiene link: su página sale de la foto que
     * guarda digital_products. Lo mismo su nombre; el de un físico sale de la
     * foto del pedido.
     */
    const FUENTES = [
        'entradas' => [
            'tabla' => 'ticket_orders',
            'tipo'  => '',
            'item'  => 'l.text',
            'joins' => '
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id',
        ],
        'productos' => [
            'tabla' => 'product_orders',
            'tipo'  => "INNER JOIN digital_products dp ON dp.id = o.product_id AND dp.tipo = 'digital'",
            'item'  => 'COALESCE(l.text, dp.titulo)',
            'joins' => self::JOINS_PRODUCTO,
        ],
        'fisicos' => [
            'tabla' => 'product_orders',
            'tipo'  => "INNER JOIN digital_products dp ON dp.id = o.product_id AND dp.tipo = 'fisico'",
            'item'  => 'COALESCE(o.producto_titulo, l.text, dp.titulo)',
            'joins' => self::JOINS_PRODUCTO,
        ],
    ];

    /** La página de un producto, digital o físico, con o sin link. */
    const JOINS_PRODUCTO = '
            LEFT JOIN links l ON l.id = dp.link_id
            LEFT JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = COALESCE(lg.page_id, dp.page_id)';

    /** Cómo se nombra una venta de cada tipo en la lista a revisar. */
    const SINGULAR = ['entradas' => 'entrada', 'productos' => 'producto', 'fisicos' => 'fisico'];

    /** Lo que se suma de una fila. */
    const SUMABLES = ['ventas', 'recaudado', 'pedida', 'cobrada', 'sin_dato'];

    // ------------------------------------------------------------- internos

    /**
     * Sólo ventas pagadas y con precio. Las gratis no pasan por Mercado Pago,
     * así que no tienen comisión ni nada que revisar.
     *
     * El rango se filtra por pagada_en: la comisión se cobra cuando se cobra la
     * venta, no cuando se reservó.
     */
    private static function rango(Request $req)
    {
        $where = ["o.estado = 'pagada'", 'o.total > 0'];
        $params = [];

        foreach (['desde' => '>=', 'hasta' => '<='] as $clave => $comparador) {
            $fecha = trim((string) $req->param($clave, ''));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                $where[] = "DATE(o.pagada_en) $comparador ?";
                $params[] = $fecha;
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Las columnas que se suman, siempre iguales.
     *
     * `cobrada` cae en lo pedido cuando todavía no sabemos lo cobrado: es lo
     * mejor que hay, y `sin_dato` dice cuántas de esas hay para que el total no
     * se lea como confirmado.
     */
    private static function SUMAS()
    {
        return '
            COUNT(*) AS ventas,
            COALESCE(SUM(o.total), 0) AS recaudado,
            COALESCE(SUM(o.comision), 0) AS pedida,
            COALESCE(SUM(COALESCE(o.mp_comision_cobrada, o.comision)), 0) AS cobrada,
            COALESCE(SUM(CASE WHEN o.mp_comision_cobrada IS NULL THEN 1 ELSE 0 END), 0) AS sin_dato';
    }

    private static function resumen($db, array $fuente, $where, array $params)
    {
        $stmt = $db->prepare('SELECT ' . self::SUMAS() . " FROM {$fuente['tabla']} o {$fuente['tipo']} WHERE $where");
        $stmt->execute($params);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return self::numeros($fila ?: self::ceros());
    }

    private static function porMes($db, array $fuente, $where, array $params)
    {
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(o.pagada_en, '%Y-%m') AS mes, " . self::SUMAS() . "
            FROM {$fuente['tabla']} o {$fuente['tipo']}
            WHERE $where AND o.pagada_en IS NOT NULL
            GROUP BY mes
            ORDER BY mes DESC
        ");
        $stmt->execute($params);

        return array_map([self::class, 'numeros'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function porPagina($db, array $fuente, $where, array $params)
    {
        $stmt = $db->prepare('
            SELECT p.id, p.title, p.url_slug, ' . self::SUMAS() . "
            FROM {$fuente['tabla']} o {$fuente['tipo']}
            {$fuente['joins']}
            WHERE $where
            GROUP BY p.id, p.title, p.url_slug
            ORDER BY cobrada DESC
        ");
        $stmt->execute($params);

        return array_map([self::class, 'numeros'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Ventas donde Mercado Pago cobró menos comisión de la que se pidió.
     *
     * Es la parte accionable del reporte: cada fila acá es plata que se pidió
     * y no entró, y casi siempre significa que esa página no conectó su cuenta
     * por OAuth.
     */
    private static function sinCobrar($db, array $fuente, $where, array $params)
    {
        $stmt = $db->prepare("
            SELECT o.codigo, o.pagada_en, o.total, o.comision AS pedida,
                   o.mp_comision_cobrada AS cobrada,
                   {$fuente['item']} AS evento, p.title AS pagina, p.url_slug
            FROM {$fuente['tabla']} o {$fuente['tipo']}
            {$fuente['joins']}
            WHERE $where
              AND o.mp_comision_cobrada IS NOT NULL
              AND o.mp_comision_cobrada < o.comision
            ORDER BY o.pagada_en DESC
            LIMIT " . self::LIMITE_REVISAR
        );
        $stmt->execute($params);

        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filas as $i => $fila) {
            $filas[$i]['total'] = (float) $fila['total'];
            $filas[$i]['pedida'] = (float) $fila['pedida'];
            $filas[$i]['cobrada'] = (float) $fila['cobrada'];
        }

        return $filas;
    }

    private static function ceros()
    {
        return array_fill_keys(self::SUMABLES, 0);
    }

    /** Suma las columnas sumables de varias filas. */
    private static function sumar(array $filas)
    {
        $total = self::ceros();

        foreach ($filas as $fila) {
            foreach (self::SUMABLES as $columna) {
                $total[$columna] += isset($fila[$columna]) ? $fila[$columna] : 0;
            }
        }

        return $total;
    }

    /**
     * Junta las filas de cada tipo que comparten clave (el mes, la página),
     * sumándolas. Cada fila lleva también cuántas de sus ventas son de
     * productos digitales (ventas_productos) y de físicos (ventas_fisicos),
     * para distinguirlas en el detalle.
     *
     * @param array  $porTipo tipo => filas
     * @param string $clave   la columna que identifica la fila
     * @param string $orden   la columna por la que se ordena, de mayor a menor
     */
    private static function juntar(array $porTipo, $clave, $orden)
    {
        $juntas = [];

        foreach ($porTipo as $tipo => $filas) {
            foreach ($filas as $fila) {
                $k = (string) $fila[$clave];

                if (!isset($juntas[$k])) {
                    $juntas[$k] = array_merge($fila, self::ceros(), ['ventas_productos' => 0, 'ventas_fisicos' => 0]);
                }

                foreach (self::SUMABLES as $columna) {
                    $juntas[$k][$columna] += $fila[$columna];
                }

                if ($tipo !== 'entradas') {
                    $juntas[$k]['ventas_' . $tipo] += $fila['ventas'];
                }
            }
        }

        $juntas = array_map([self::class, 'numeros'], array_values($juntas));

        usort($juntas, function ($a, $b) use ($orden) {
            return $b[$orden] <=> $a[$orden];
        });

        return $juntas;
    }

    /** La lista a revisar de todos los tipos, la más reciente primero, con el mismo tope. */
    private static function juntarRevisar(array $porTipo)
    {
        $filas = [];

        foreach ($porTipo as $tipo => $deEseTipo) {
            foreach ($deEseTipo as $fila) {
                $filas[] = $fila + ['tipo' => self::SINGULAR[$tipo]];
            }
        }

        usort($filas, function ($a, $b) {
            return strcmp((string) $b['pagada_en'], (string) $a['pagada_en']);
        });

        return array_slice($filas, 0, self::LIMITE_REVISAR);
    }

    /** MySQL devuelve todo como texto; el frontend hace cuentas con esto. */
    private static function numeros(array $fila)
    {
        foreach (['ventas', 'sin_dato'] as $entero) {
            if (isset($fila[$entero])) {
                $fila[$entero] = (int) $fila[$entero];
            }
        }

        foreach (['recaudado', 'pedida', 'cobrada'] as $decimal) {
            if (isset($fila[$decimal])) {
                $fila[$decimal] = round((float) $fila[$decimal], 2);
            }
        }

        // Lo que se pidió y no entró. Se calcula acá y no en el frontend para
        // que la definición viva en un solo lugar.
        $fila['diferencia'] = round($fila['pedida'] - $fila['cobrada'], 2);

        return $fila;
    }
}
