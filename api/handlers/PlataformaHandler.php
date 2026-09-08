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

        return Response::ok([
            'desde'   => $req->param('desde', ''),
            'hasta'   => $req->param('hasta', ''),
            'resumen' => self::resumen($db, $where, $params),
            'meses'   => self::porMes($db, $where, $params),
            'paginas' => self::porPagina($db, $where, $params),
            'revisar' => self::sinCobrar($db, $where, $params),
        ]);
    }

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

    private static function resumen($db, $where, array $params)
    {
        $stmt = $db->prepare('SELECT ' . self::SUMAS() . " FROM ticket_orders o WHERE $where");
        $stmt->execute($params);

        return self::numeros($stmt->fetch(PDO::FETCH_ASSOC));
    }

    private static function porMes($db, $where, array $params)
    {
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(o.pagada_en, '%Y-%m') AS mes, " . self::SUMAS() . "
            FROM ticket_orders o
            WHERE $where AND o.pagada_en IS NOT NULL
            GROUP BY mes
            ORDER BY mes DESC
        ");
        $stmt->execute($params);

        return array_map([self::class, 'numeros'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function porPagina($db, $where, array $params)
    {
        $stmt = $db->prepare('
            SELECT p.id, p.title, p.url_slug, ' . self::SUMAS() . "
            FROM ticket_orders o
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
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
    private static function sinCobrar($db, $where, array $params)
    {
        $stmt = $db->prepare("
            SELECT o.codigo, o.pagada_en, o.total, o.comision AS pedida,
                   o.mp_comision_cobrada AS cobrada,
                   l.text AS evento, p.title AS pagina, p.url_slug
            FROM ticket_orders o
            INNER JOIN links l ON l.id = o.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
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
