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
     * Cuántas entradas se vendieron cada día.
     *
     * Es lo que responde la pregunta que se hace quien mira el link: no
     * cuántas van, sino si se está moviendo o se frenó.
     *
     * @return array<array{dia: string, vendidas: int}>
     */
    private static function ritmo($db, $linkId)
    {
        $stmt = $db->prepare("
            SELECT DATE(pagada_en) AS dia, SUM(cantidad) AS vendidas
            FROM ticket_orders
            WHERE link_id = ? AND estado = 'pagada' AND pagada_en IS NOT NULL
              AND pagada_en >= (NOW() - INTERVAL " . self::DIAS_DE_RITMO . " DAY)
            GROUP BY DATE(pagada_en)
            ORDER BY dia
        ");
        $stmt->execute([(int) $linkId]);

        $dias = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $dias[] = ['dia' => $fila['dia'], 'vendidas' => (int) $fila['vendidas']];
        }

        return $dias;
    }
}
