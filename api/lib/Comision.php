<?php

/**
 * Comisión que la plataforma se queda de cada venta de entradas.
 *
 * Mercado Pago la descuenta en la misma operación: el comprador paga una vez y
 * el reparto lo hace Mercado Pago. Lo que la plataforma le manda es un *monto*,
 * no un porcentaje, así que el cálculo se hace acá.
 *
 * El monto se congela en la orden. Si mañana cambia el porcentaje, lo ya
 * vendido tiene que seguir mostrando lo que efectivamente se cobró.
 */
class Comision
{
    /**
     * Tope de seguridad. No es una regla de negocio: es una red para que un
     * error de tipeo en config.php (un 100 donde iba 10) no se lleve la venta
     * entera del dueño de la página.
     */
    const MAXIMO_PORCENTAJE = 50;

    /** Porcentaje configurado, ya validado. 0 si no hay comisión. */
    public static function porcentaje()
    {
        if (!defined('PLATFORM_FEE_PERCENT')) {
            return 0.0;
        }

        $porcentaje = (float) PLATFORM_FEE_PERCENT;

        if ($porcentaje <= 0) {
            return 0.0;
        }

        return min($porcentaje, self::MAXIMO_PORCENTAJE);
    }

    /** true si hay que descontarle algo a la venta. */
    public static function activa()
    {
        return self::porcentaje() > 0;
    }

    /**
     * Monto que le corresponde a la plataforma sobre un total.
     *
     * Se redondea a dos decimales hacia abajo: si el redondeo se fuera para
     * arriba, la suma de comisión y lo que recibe el dueño podría superar el
     * total y Mercado Pago rechazaría la preferencia.
     */
    public static function sobre($total)
    {
        $total = (float) $total;

        if ($total <= 0 || !self::activa()) {
            return 0.0;
        }

        $comision = floor($total * self::porcentaje()) / 100;

        // Nunca puede quedarse con todo: al dueño le tiene que llegar algo.
        return min($comision, round($total * self::MAXIMO_PORCENTAJE / 100, 2));
    }

    /** Lo que le queda al dueño de la página. */
    public static function paraElVendedor($total)
    {
        return round((float) $total - self::sobre($total), 2);
    }
}
