<?php

/**
 * El plano de un evento con lugares asignados: filas de butacas y mesas.
 *
 * Es sólo la forma del lugar, sin nada de ventas. Se guarda como JSON en la
 * configuración de entradas del evento, y de él sale la lista de lugares que
 * se pueden reservar. La capacidad de un evento con plano no se carga a mano:
 * es la cantidad de lugares que tiene el plano, porque dos números que dicen
 * lo mismo terminan diciendo cosas distintas.
 *
 * Cada lugar tiene un identificador que se entiende sin el plano:
 *
 *     f:A:7   fila A, butaca 7
 *     m:3:2   mesa 3, lugar 2
 *
 * Eso es lo que se guarda en cada compra. Si el plano cambia después, la
 * compra sigue diciendo qué lugar era, y el mail y el listado de ventas lo
 * pueden mostrar sin ir a buscar un plano que ya no es el mismo.
 */
class Plano
{
    const ANCHO_MAXIMO = 80;
    const ALTO_MAXIMO = 80;
    const MAX_ELEMENTOS = 150;
    const MAX_LUGARES = 3000;
    const MAX_BUTACAS_POR_FILA = 60;
    const MAX_LUGARES_POR_MESA = 16;

    /** Nombre de fila o de mesa: corto y sin nada que confunda al identificador. */
    const PATRON_NOMBRE = '/^[A-Za-z0-9]{1,6}$/';

    /**
     * Valida un plano y lo devuelve limpio: sólo las claves que se conocen,
     * con los tipos que corresponden.
     *
     * @param mixed $plano Lo que mandó el editor, ya decodificado.
     * @return array{ok: bool, error: string|null, plano: array|null}
     */
    public static function normalizar($plano)
    {
        if (!is_array($plano) || !isset($plano['elementos']) || !is_array($plano['elementos'])) {
            return self::mal('El plano no tiene el formato esperado');
        }

        $ancho = isset($plano['ancho']) ? (int) $plano['ancho'] : 0;
        $alto = isset($plano['alto']) ? (int) $plano['alto'] : 0;

        if ($ancho < 4 || $ancho > self::ANCHO_MAXIMO || $alto < 4 || $alto > self::ALTO_MAXIMO) {
            return self::mal('El plano tiene que medir entre 4 y ' . self::ANCHO_MAXIMO . ' de ancho y de alto');
        }

        if (count($plano['elementos']) > self::MAX_ELEMENTOS) {
            return self::mal('El plano no puede tener más de ' . self::MAX_ELEMENTOS . ' filas y mesas');
        }

        $elementos = [];

        foreach (array_values($plano['elementos']) as $i => $crudo) {
            $elemento = self::normalizarElemento($crudo, $ancho, $alto);

            if (is_string($elemento)) {
                return self::mal('Elemento ' . ($i + 1) . ': ' . $elemento);
            }

            $elementos[] = $elemento;
        }

        $limpio = ['ancho' => $ancho, 'alto' => $alto, 'elementos' => $elementos];
        $lugares = self::lugares($limpio);

        if (count($lugares) === 0) {
            return self::mal('El plano no tiene ningún lugar: agregá una fila o una mesa');
        }

        if (count($lugares) > self::MAX_LUGARES) {
            return self::mal('El plano no puede tener más de ' . self::MAX_LUGARES . ' lugares');
        }

        // Dos filas "A" que numeran desde 1 dan dos butacas A1: no habría
        // forma de saber cuál se vendió.
        $repetidos = array_unique(array_diff_assoc($lugares, array_unique($lugares)));

        if ($repetidos !== []) {
            return self::mal('Hay lugares repetidos en el plano: ' . self::resumir(array_slice($repetidos, 0, 5)));
        }

        return ['ok' => true, 'error' => null, 'plano' => $limpio];
    }

    /**
     * Todos los lugares del plano, en orden.
     *
     * @return string[]
     */
    public static function lugares($plano)
    {
        if (!is_array($plano) || empty($plano['elementos'])) {
            return [];
        }

        $lugares = [];

        foreach ($plano['elementos'] as $elemento) {
            if ($elemento['tipo'] === 'fila') {
                for ($n = 0; $n < $elemento['butacas']; $n++) {
                    $lugares[] = 'f:' . $elemento['nombre'] . ':' . ($elemento['desde'] + $n);
                }
            } elseif ($elemento['tipo'] === 'mesa') {
                for ($n = 1; $n <= $elemento['lugares']; $n++) {
                    $lugares[] = 'm:' . $elemento['nombre'] . ':' . $n;
                }
            }
        }

        return $lugares;
    }

    /** Un lugar para leer: "Fila A, butaca 7" o "Mesa 3, lugar 2". */
    public static function describir($lugar)
    {
        $partes = explode(':', (string) $lugar);

        if (count($partes) !== 3) {
            return (string) $lugar;
        }

        list($tipo, $nombre, $numero) = $partes;

        if ($tipo === 'f') {
            return "Fila $nombre, butaca $numero";
        }

        if ($tipo === 'm') {
            return "Mesa $nombre, lugar $numero";
        }

        return (string) $lugar;
    }

    /**
     * Varios lugares en una línea, agrupados: "Fila A: 7, 8 · Mesa 3: 1, 2".
     *
     * Es lo que va en el mail y en el listado de ventas, donde una lista de
     * diez "Fila A, butaca N" no se lee.
     */
    public static function resumir(array $lugares)
    {
        $grupos = [];

        foreach ($lugares as $lugar) {
            $partes = explode(':', (string) $lugar);

            if (count($partes) !== 3) {
                $grupos[(string) $lugar] = [];
                continue;
            }

            $titulo = ($partes[0] === 'm' ? 'Mesa ' : 'Fila ') . $partes[1];
            $grupos[$titulo][] = (int) $partes[2];
        }

        $textos = [];

        foreach ($grupos as $titulo => $numeros) {
            sort($numeros);
            $textos[] = $numeros === [] ? $titulo : $titulo . ': ' . implode(', ', $numeros);
        }

        return implode(' · ', $textos);
    }

    // ------------------------------------------------------------ internos

    /** @return array|string El elemento limpio, o el motivo por el que no sirve. */
    private static function normalizarElemento($crudo, $ancho, $alto)
    {
        if (!is_array($crudo) || !isset($crudo['tipo'])) {
            return 'no tiene tipo';
        }

        $x = isset($crudo['x']) ? (int) $crudo['x'] : -1;
        $y = isset($crudo['y']) ? (int) $crudo['y'] : -1;

        if ($x < 0 || $y < 0 || $x >= $ancho || $y >= $alto) {
            return 'quedó fuera del plano';
        }

        $nombre = isset($crudo['nombre']) ? trim((string) $crudo['nombre']) : '';

        switch ($crudo['tipo']) {
            case 'fila':
                $butacas = isset($crudo['butacas']) ? (int) $crudo['butacas'] : 0;
                $desde = isset($crudo['desde']) ? (int) $crudo['desde'] : 1;

                if (!preg_match(self::PATRON_NOMBRE, $nombre)) {
                    return 'el nombre de la fila tiene que ser de 1 a 6 letras o números';
                }

                if ($butacas < 1 || $butacas > self::MAX_BUTACAS_POR_FILA) {
                    return 'una fila tiene que tener entre 1 y ' . self::MAX_BUTACAS_POR_FILA . ' butacas';
                }

                if ($desde < 1 || $desde > 999) {
                    return 'la numeración de la fila tiene que empezar entre 1 y 999';
                }

                return self::conEntrada(
                    ['tipo' => 'fila', 'nombre' => $nombre, 'desde' => $desde, 'butacas' => $butacas, 'x' => $x, 'y' => $y],
                    $crudo
                );

            case 'mesa':
                $lugares = isset($crudo['lugares']) ? (int) $crudo['lugares'] : 0;
                $forma = isset($crudo['forma']) && $crudo['forma'] === 'cuadrada' ? 'cuadrada' : 'redonda';

                if (!preg_match(self::PATRON_NOMBRE, $nombre)) {
                    return 'el nombre de la mesa tiene que ser de 1 a 6 letras o números';
                }

                if ($lugares < 1 || $lugares > self::MAX_LUGARES_POR_MESA) {
                    return 'una mesa tiene que tener entre 1 y ' . self::MAX_LUGARES_POR_MESA . ' lugares';
                }

                return self::conEntrada(
                    ['tipo' => 'mesa', 'nombre' => $nombre, 'lugares' => $lugares, 'forma' => $forma, 'x' => $x, 'y' => $y],
                    $crudo
                );

            case 'escenario':
                $texto = isset($crudo['texto']) ? trim((string) $crudo['texto']) : '';
                $anchoEscenario = isset($crudo['ancho']) ? (int) $crudo['ancho'] : 0;
                $altoEscenario = isset($crudo['alto']) ? (int) $crudo['alto'] : 0;

                if ($anchoEscenario < 1 || $anchoEscenario > $ancho || $altoEscenario < 1 || $altoEscenario > $alto) {
                    return 'el escenario no entra en el plano';
                }

                return [
                    'tipo' => 'escenario',
                    'texto' => mb_substr($texto === '' ? 'Escenario' : $texto, 0, 40),
                    'ancho' => $anchoEscenario,
                    'alto' => $altoEscenario,
                    'x' => $x,
                    'y' => $y,
                ];
        }

        return 'tipo desconocido';
    }

    /**
     * El tipo de entrada de una fila o una mesa, si lo dice.
     *
     * El plano no conoce los tipos del evento: sólo guarda a cuál apunta cada
     * zona. Que exista lo resuelve quien vende, que manda los que no existen
     * al primer tipo.
     */
    private static function conEntrada(array $elemento, array $crudo)
    {
        if (isset($crudo['entrada']) && preg_match(TiposDeEntrada::PATRON_ID, (string) $crudo['entrada'])) {
            $elemento['entrada'] = (string) $crudo['entrada'];
        }

        return $elemento;
    }

    private static function mal($error)
    {
        return ['ok' => false, 'error' => $error, 'plano' => null];
    }
}
