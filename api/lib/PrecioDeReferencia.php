<?php

/**
 * El precio que se muestra de un evento: "Gratis", "Desde $X" o nada.
 *
 * Es uno solo y se calcula en un solo lugar, para que la tarjeta de la home,
 * la de la página y el detalle no digan cosas distintas del mismo show:
 *
 * - si el evento vende entradas por Rezonar, es el precio de esa venta: el
 *   más barato de sus tipos de entrada que cobran, o su precio único, tal como
 *   lo guarda Entradas (0 si no cobra, o sea gratis);
 * - si no, es el `precio_desde` que cargó el organizador o trajo el
 *   importador (null si no se sabe, 0 si es gratis).
 *
 * Las consultas que devuelven muchos eventos lo calculan en el mismo SQL, con
 * JOIN y COLUMNA sobre el alias `l` de links: así no hace falta una consulta
 * más por evento.
 */
class PrecioDeReferencia
{
    /** La venta interna activa del evento, si tiene. Va en el FROM, sobre el alias l. */
    const JOIN = 'LEFT JOIN event_ticketing pr_venta ON pr_venta.link_id = l.id AND pr_venta.activo = 1';

    /** El precio a mostrar, en SQL. Necesita JOIN. */
    const EXPRESION = '(CASE WHEN pr_venta.link_id IS NOT NULL THEN pr_venta.precio ELSE l.precio_desde END)';

    /** EXPRESION como columna del SELECT. */
    const COLUMNA = self::EXPRESION . ' AS precio_referencia';

    /** Tope del filtro "hasta": nadie busca entradas de más de esto. */
    const MAXIMO_FILTRO = 100000000;

    /**
     * Para un evento ya completado con su disponibilidad (la de
     * Entradas::disponibilidad, null si no vende por Rezonar).
     *
     * @return float|null
     */
    public static function deEvento(array $evento, $entradas)
    {
        if (is_array($entradas)) {
            return round((float) $entradas['precio'], 2);
        }

        return self::valor(isset($evento['precio_desde']) ? $evento['precio_desde'] : null);
    }

    /** Lo que vino de la base, como lo espera el frontend: null o un número. */
    public static function valor($precio)
    {
        return $precio === null || $precio === '' || !is_numeric($precio) ? null : round((float) $precio, 2);
    }

    /** Pasa la columna precio_referencia de cada fila a null o número. */
    public static function completar(array $filas)
    {
        foreach ($filas as $i => $fila) {
            $filas[$i]['precio_referencia'] = self::valor(isset($fila['precio_referencia']) ? $fila['precio_referencia'] : null);
        }

        return $filas;
    }

    /**
     * Interpreta el parámetro ?precio= de los listados.
     *
     * "gratis" o "hasta:25000". Vacío es "cualquier precio".
     *
     * @return array{ok: bool, filtro: array|null}  filtro: ['tipo' => 'gratis'] o ['tipo' => 'hasta', 'monto' => float]
     */
    public static function filtro($valor)
    {
        // ?precio[]=… llega como array: no es un filtro que se entienda.
        if ($valor !== null && !is_string($valor)) {
            return ['ok' => false, 'filtro' => null];
        }

        $valor = strtolower(trim((string) $valor));

        if ($valor === '') {
            return ['ok' => true, 'filtro' => null];
        }

        if ($valor === 'gratis') {
            return ['ok' => true, 'filtro' => ['tipo' => 'gratis']];
        }

        if (preg_match('/^hasta:(\d{1,9})$/', $valor, $m) && (int) $m[1] > 0 && (int) $m[1] <= self::MAXIMO_FILTRO) {
            return ['ok' => true, 'filtro' => ['tipo' => 'hasta', 'monto' => (float) $m[1]]];
        }

        return ['ok' => false, 'filtro' => null];
    }

    /**
     * La condición del filtro para el WHERE, con sus parámetros. Necesita JOIN.
     *
     * Con un filtro de precio, un evento sin precio conocido no entra: no se
     * puede decir que es gratis ni que sale menos de nada.
     *
     * @return array{0: string, 1: array} '' y [] sin filtro
     */
    public static function condicion($filtro)
    {
        if ($filtro === null) {
            return ['', []];
        }

        if ($filtro['tipo'] === 'gratis') {
            return [self::EXPRESION . ' = 0', []];
        }

        return [self::EXPRESION . ' <= ?', [$filtro['monto']]];
    }

    const ERROR_FILTRO = 'El filtro de precio tiene que ser "gratis" o "hasta:<monto>"';
}
