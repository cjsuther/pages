<?php

/**
 * Lo que se descuenta de cada venta de entradas.
 *
 * Son dos cosas distintas y las dos salen de config.php: la comisión que se
 * queda la plataforma, que además se cobra de verdad, y lo que cobra Mercado
 * Pago, que es sólo informativo. Van juntas porque el dueño de la página las
 * necesita juntas para poner un precio: lo que le importa no es lo que paga el
 * comprador sino lo que termina entrando a su cuenta, y cuándo.
 *
 * --- La comisión de la plataforma ---
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

    /**
     * Lo que cobra Mercado Pago, aparte de lo nuestro.
     *
     * No entra en ningún cálculo: Mercado Pago lo descuenta por su cuenta y lo
     * que informa después es el neto real. Esto es sólo para que el dueño pueda
     * hacer la cuenta antes de poner un precio, así que se manda tal cual a la
     * pantalla.
     *
     * El porcentaje y el plazo van juntos a propósito: en Mercado Pago el
     * costo depende del plazo de acreditación que tenga elegido la cuenta, y
     * nombrar uno sin el otro se presta a confusión. Si falta cualquiera de los
     * dos no se informa nada: es mejor no decir nada que decir un número que no
     * es el que le van a cobrar.
     *
     * @return array{porcentaje: float, dias: int}|null
     */
    public static function mercadoPago()
    {
        if (!defined('MP_FEE_PERCENT') || !defined('MP_RELEASE_DAYS')) {
            return null;
        }

        $porcentaje = (float) MP_FEE_PERCENT;
        $dias = (int) MP_RELEASE_DAYS;

        if ($porcentaje <= 0 || $dias < 0) {
            return null;
        }

        return ['porcentaje' => $porcentaje, 'dias' => $dias];
    }
}
