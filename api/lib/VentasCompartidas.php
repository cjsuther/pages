<?php

/**
 * Cómo viene la venta de un evento, para compartirle a alguien de afuera.
 *
 * Es la pantalla que abre el link con clave: sirve para que el artista, el
 * socio o quien produce vean cómo se está vendiendo sin darles una cuenta ni
 * acceso a la página.
 *
 * Lo que sale de acá son números y lugares ocupados, nunca quiénes compraron.
 * Los datos de contacto son de terceros y no tienen por qué viajar en un link
 * que se reenvía por WhatsApp: para verlos hay que administrar la página.
 */
class VentasCompartidas
{
    /** Días de ritmo de venta que se muestran. Un mes explica cómo viene. */
    const DIAS_DE_RITMO = 30;

    /**
     * El estado de venta del evento.
     *
     * @return array{venta: array|null, plano: array|null, ocupados: string[], ritmo: array}
     */
    public static function estado($db, $linkId)
    {
        $config = Entradas::configDelEvento($db, $linkId);

        if ($config === null) {
            // El evento existe —la clave lo encontró— pero no vende entradas
            // por acá. No es un error: es un link que todavía no tiene nada
            // que mostrar.
            return ['venta' => null, 'plano' => null, 'ocupados' => [], 'ritmo' => []];
        }

        $plano = $config['plano'];
        $totales = self::totales($db, $linkId);
        $capacidad = (int) $config['capacidad'];

        return [
            'venta' => [
                'activo'      => (bool) $config['activo'],
                'capacidad'   => $capacidad,
                'vendidas'    => $totales['vendidas'],
                'reservadas'  => $totales['reservadas'],
                'disponibles' => max(0, $capacidad - $totales['vendidas'] - $totales['reservadas']),
                'recaudado'   => $totales['recaudado'],
                'precio'      => (float) $config['precio'],
                'moneda'      => $config['moneda'],
                'compras'     => $totales['compras'],
                'ingresadas'  => $totales['ingresadas'],
                // Cuántas se vendieron de cada tipo, o null si vende a un solo precio.
                'tipos'       => $config['tipos'] === null ? null : self::vendidasPorTipo($db, $linkId, $config['tipos']),
            ],
            'plano'    => $plano,
            'ocupados' => $plano === null ? [] : Entradas::lugaresOcupados($db, $linkId),
            'ritmo'    => self::ritmo($db, $linkId),
        ];
    }

    /**
     * Lo vendido y lo reservado.
     *
     * Las reservas vencidas no cuentan, igual que en el cupo: si contaran, el
     * link mostraría un show más vendido de lo que está.
     */
    private static function totales($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN estado = 'pagada' THEN cantidad END), 0)   AS vendidas,
                COALESCE(SUM(CASE WHEN estado = 'pagada' THEN total END), 0)      AS recaudado,
                COALESCE(SUM(CASE WHEN estado = 'pagada' THEN ingresadas END), 0) AS ingresadas,
                COALESCE(SUM(CASE WHEN estado = 'reservada' AND reserva_vence_en > NOW()
                                  THEN cantidad END), 0)                          AS reservadas,
                COALESCE(SUM(estado = 'pagada'), 0)                               AS compras
            FROM ticket_orders
            WHERE link_id = ?
        ");
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'vendidas'   => (int) $fila['vendidas'],
            'reservadas' => (int) $fila['reservadas'],
            'ingresadas' => (int) $fila['ingresadas'],
            'compras'    => (int) $fila['compras'],
            'recaudado'  => round((float) $fila['recaudado'], 2),
        ];
    }

    /**
     * Lo pagado de cada tipo, en el orden en que los cargó quien vende.
     *
     * @return array<array{nombre: string, precio: float, cupo: int|null, vendidas: int}>
     */
    private static function vendidasPorTipo($db, $linkId, array $tipos)
    {
        $stmt = $db->prepare("
            SELECT i.tipo, COALESCE(SUM(i.cantidad), 0) AS vendidas
            FROM ticket_order_items i
            INNER JOIN ticket_orders o ON o.id = i.order_id
            WHERE o.link_id = ? AND o.estado = 'pagada'
            GROUP BY i.tipo
        ");
        $stmt->execute([(int) $linkId]);

        $porTipo = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $porTipo[(string) $fila['tipo']] = (int) $fila['vendidas'];
        }

        return array_map(function ($tipo) use ($porTipo) {
            return [
                'nombre'   => $tipo['nombre'],
                'precio'   => (float) $tipo['precio'],
                'cupo'     => $tipo['cupo'] === null ? null : (int) $tipo['cupo'],
                'vendidas' => isset($porTipo[$tipo['id']]) ? $porTipo[$tipo['id']] : 0,
            ];
        }, $tipos);
    }

    /**
     * Cuántas entradas se vendieron cada día del último mes.
     *
     * Es lo que responde la pregunta que se hace quien mira el link: no
     * cuántas van, sino si se está moviendo o se frenó.
     *
     * Vienen los treinta días, también los que no vendieron nada. Antes salían
     * sólo los días con ventas, y tres días sueltos se dibujaban pegados como
     * si fueran seguidos: un show que vendió el 1, el 12 y el 27 se veía igual
     * que uno que vendió tres días corridos.
     *
     * @return array<array{dia: string, vendidas: int}>
     */
    private static function ritmo($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT DATE(pagada_en) AS dia, SUM(cantidad) AS vendidas
            FROM ticket_orders
            WHERE link_id = ? AND estado = 'pagada' AND pagada_en IS NOT NULL
              AND pagada_en >= (CURDATE() - INTERVAL " . (self::DIAS_DE_RITMO - 1) . " DAY)
            GROUP BY DATE(pagada_en)
        ");
        $stmt->execute([(int) $linkId]);

        $porDia = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $porDia[$fila['dia']] = (int) $fila['vendidas'];
        }

        $dias = [];

        // El día de hoy es el del huso del evento, no el del servidor: si no,
        // entre las 21 y la medianoche la última columna sería la de mañana.
        $hoy = Fechas::hoy();

        for ($i = self::DIAS_DE_RITMO - 1; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime("$hoy -$i day"));
            $dias[] = ['dia' => $dia, 'vendidas' => isset($porDia[$dia]) ? $porDia[$dia] : 0];
        }

        return $dias;
    }
}
