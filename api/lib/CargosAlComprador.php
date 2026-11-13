<?php

/**
 * Comisiones que se le suman al precio de la entrada y paga quien compra.
 *
 * Hasta acá las dos comisiones —la de Mercado Pago y la de Rezon.ar— salían
 * de lo que cobraba el organizador. Con esto encendido, el organizador recibe
 * el precio que cargó y quien compra paga, aparte y a la vista, lo que cuesta
 * cobrarle.
 *
 * Se enciende en dos lugares, y hacen falta los dos:
 *   - CARGOS_AL_COMPRADOR en config.php (global, empieza en false): mientras
 *     esté apagado nada cambia para nadie, ni siquiera se ofrece la opción.
 *   - cargos_al_comprador en event_ticketing (por evento). Los eventos que ya
 *     tenían entradas configuradas quedan en 0 con la migración, así que
 *     encender la global no le cambia el precio a lo que ya está a la venta.
 *     Los que configuran entradas por primera vez arrancan en 1 si la global
 *     está prendida (porDefecto()); el organizador la puede apagar.
 *
 * --- La cuenta (gross-up) ---
 *
 * Mercado Pago cobra su comisión sobre lo que se cobra, no sobre el precio de
 * la entrada. Si se le sumara al precio "4,39% del precio", Mercado Pago se
 * llevaría 4,39% de un número más grande y al organizador le faltaría. Por eso
 * se despeja el total:
 *
 *   P   = precio de las entradas (ya con promos; las de precio 0 no suman)
 *   R   = comisión de Rezon.ar = P × pR × (1 + ivaR)          (sobre el precio)
 *   f   = pMP × (1 + ivaMP)                                    (tasa efectiva de MP)
 *   F   = fijoMP × (1 + ivaMP)                                 (fijo por operación)
 *
 *   El organizador recibe T − (f·T + F) − R, y tiene que ser P:
 *
 *   T   = (P + R + F) / (1 − f)        redondeado HACIA ARRIBA al centavo
 *   MP  = T − P − R                    lo que se muestra como "Mercado Pago"
 *
 * El redondeo hacia arriba es a favor del organizador: nunca recibe menos que
 * P por culpa nuestra (como mucho recibe menos de un centavo de más). Y como
 * el cargo de Mercado Pago se despeja como resto, precio + MP + Rezon.ar suma
 * siempre exactamente el total que se le manda a Mercado Pago.
 *
 * Todo se hace en centavos enteros para que PHP y el navegador —que repite la
 * cuenta en frontend/src/utils/cargos.js para mostrarla mientras se elige—
 * lleguen al mismo número. Los dos se prueban contra los mismos casos:
 * api/tests/Fixtures/cargos-al-comprador.json.
 */
class CargosAlComprador
{
    /**
     * Tope de la tasa efectiva de Mercado Pago. No es una regla de negocio: un
     * error de tipeo (43,9 en vez de 4,39) no puede llevar el precio al doble,
     * y con f >= 1 la fórmula ni siquiera tiene solución.
     */
    const MAXIMA_TASA_MP = 20;

    /** Tope del IVA, por el mismo motivo. */
    const MAXIMO_IVA = 50;

    /** Tasas impuestas por los tests; null = leerlas de config.php. */
    private static $forzadas = null;

    /** true si la plataforma ofrece la función. Empieza apagada. */
    public static function disponible()
    {
        return self::tasas() !== null;
    }

    /**
     * Con qué valor arranca la llave de un evento que configura entradas por
     * primera vez: prendida si la función global lo está. Los que ya tenían
     * configuración quedaron en 0 con la migración y no pasan por acá.
     */
    public static function porDefecto()
    {
        return self::disponible();
    }

    /**
     * Las tasas configuradas, ya validadas, o null si la función está apagada
     * o mal configurada. Mal configurada se trata como apagada: se vende como
     * hasta ahora (el organizador absorbe) en vez de cobrar un número dudoso.
     *
     * @return array{mp_porcentaje: float, mp_fijo: float, mp_iva: float, plataforma_porcentaje: float, plataforma_iva: float}|null
     */
    public static function tasas()
    {
        if (self::$forzadas !== null) {
            return self::$forzadas === false ? null : self::$forzadas;
        }

        if (!defined('CARGOS_AL_COMPRADOR') || CARGOS_AL_COMPRADOR !== true) {
            return null;
        }

        return self::validar([
            'mp_porcentaje'         => defined('MP_FEE_PERCENT') ? MP_FEE_PERCENT : null,
            'mp_fijo'               => defined('MP_FEE_FIXED') ? MP_FEE_FIXED : 0,
            'mp_iva'                => defined('MP_FEE_IVA_PERCENT') ? MP_FEE_IVA_PERCENT : 0,
            'plataforma_porcentaje' => Comision::porcentaje(),
            'plataforma_iva'        => defined('PLATFORM_FEE_IVA_PERCENT') ? PLATFORM_FEE_IVA_PERCENT : 0,
        ]);
    }

    /**
     * Sólo para tests: fija las tasas (un array), apaga la función (false) o
     * vuelve a leer config.php (null).
     */
    public static function forzar($tasas)
    {
        if (is_array($tasas)) {
            $tasas = self::validar($tasas);

            if ($tasas === null) {
                throw new InvalidArgumentException('Tasas inválidas');
            }
        }

        self::$forzadas = $tasas;
    }

    /**
     * Valida y normaliza un juego de tasas.
     *
     * @return array|null null si algo no tiene sentido
     */
    public static function validar(array $tasas)
    {
        foreach (['mp_porcentaje', 'mp_fijo', 'mp_iva', 'plataforma_porcentaje', 'plataforma_iva'] as $clave) {
            if (!isset($tasas[$clave]) || !is_numeric($tasas[$clave]) || (float) $tasas[$clave] < 0) {
                return null;
            }
        }

        $normalizadas = [
            'mp_porcentaje'         => (float) $tasas['mp_porcentaje'],
            'mp_fijo'               => round((float) $tasas['mp_fijo'], 2),
            'mp_iva'                => (float) $tasas['mp_iva'],
            'plataforma_porcentaje' => min((float) $tasas['plataforma_porcentaje'], (float) Comision::MAXIMO_PORCENTAJE),
            'plataforma_iva'        => (float) $tasas['plataforma_iva'],
        ];

        if ($normalizadas['mp_iva'] > self::MAXIMO_IVA || $normalizadas['plataforma_iva'] > self::MAXIMO_IVA) {
            return null;
        }

        if ($normalizadas['mp_porcentaje'] * (1 + $normalizadas['mp_iva'] / 100) > self::MAXIMA_TASA_MP) {
            return null;
        }

        return $normalizadas;
    }

    /**
     * Las tasas que corresponden a un evento, o null si en ese evento las
     * comisiones las sigue absorbiendo el organizador.
     *
     * Sin split (una cuenta conectada a mano) Mercado Pago no nos entrega
     * nada: cobrarle al comprador una "comisión de Rezon.ar" que termina en la
     * cuenta del organizador sería mentirle. Ahí sólo se suma la de MP.
     *
     * @param array $config      configuración del evento (event_ticketing)
     * @param bool  $admiteSplit si la página está conectada por OAuth
     */
    public static function tasasDelEvento(array $config, $admiteSplit)
    {
        if (empty($config['cargos_al_comprador'])) {
            return null;
        }

        $tasas = self::tasas();

        if ($tasas === null) {
            return null;
        }

        if (!$admiteSplit) {
            $tasas['plataforma_porcentaje'] = 0.0;
        }

        return $tasas;
    }

    /**
     * El desglose de lo que paga quien compra.
     *
     * @param float      $precio Lo que valen las entradas (lo que recibe el organizador)
     * @param array|null $tasas  null = función apagada: no se suma nada
     * @return array{precio: float, mercadopago: float, plataforma: float, total: float}
     */
    public static function calcular($precio, $tasas)
    {
        $precioC = self::redondear((float) $precio * 100);

        if ($precioC <= 0 || $tasas === null) {
            $precioC = max(0, $precioC);

            return self::enPesos($precioC, 0, 0);
        }

        // Rezon.ar: porcentaje sobre el precio, más su IVA, redondeado al centavo.
        $plataformaC = self::redondear(
            $precioC * $tasas['plataforma_porcentaje'] * (100 + $tasas['plataforma_iva']) / 10000
        );

        $factorIvaMp = (100 + $tasas['mp_iva']) / 100;
        $f = $tasas['mp_porcentaje'] / 100 * $factorIvaMp;
        $fijoC = $tasas['mp_fijo'] * 100 * $factorIvaMp;

        // El margen antes del ceil: 123456.00000000001 por error de coma
        // flotante no puede convertirse en un centavo más.
        $totalC = (int) ceil(($precioC + $plataformaC + $fijoC) / (1 - $f) - 0.000001);

        return self::enPesos($precioC, $totalC - $precioC - $plataformaC, $plataformaC);
    }

    /** Suma de los cargos de una orden guardada (0 si no tiene). */
    public static function deLaOrden(array $orden)
    {
        return round(
            (isset($orden['cargo_mercadopago']) ? (float) $orden['cargo_mercadopago'] : 0)
            + (isset($orden['cargo_plataforma']) ? (float) $orden['cargo_plataforma'] : 0),
            2
        );
    }

    /**
     * Lo que valían las entradas de una orden: el total cobrado menos lo que
     * se le sumó a quien compró. Es lo que recibe el organizador.
     */
    public static function precioDeLaOrden(array $orden)
    {
        return round((float) $orden['total'] - self::deLaOrden($orden), 2);
    }

    /**
     * Medio centavo para arriba, escrito igual que en el navegador
     * (Math.floor(x + 0.5)). round() de PHP no sirve: antes de 8.4 redondea
     * primero a 15 cifras y en algún caso límite no daría lo mismo que JS.
     */
    private static function redondear($x)
    {
        return (int) floor($x + 0.5);
    }

    private static function enPesos($precioC, $mercadopagoC, $plataformaC)
    {
        return [
            'precio'      => $precioC / 100.0,
            'mercadopago' => $mercadopagoC / 100.0,
            'plataforma'  => $plataformaC / 100.0,
            'total'       => ($precioC + $mercadopagoC + $plataformaC) / 100.0,
        ];
    }
}
