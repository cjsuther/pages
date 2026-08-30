/**
 * Lo que cobra Mercado Pago, aparte de la comisión de Rezonar.
 *
 * Son dos datos que van juntos: en Mercado Pago el porcentaje depende del
 * plazo de acreditación que tenga elegido la cuenta, así que nombrar uno sin
 * el otro se presta a confusión. Estos valores son los de la cuenta de
 * Rezonar; si algún día cambian, se cambian acá y no en cada pantalla.
 *
 * No entran en ningún cálculo: Mercado Pago descuenta lo suyo por su cuenta y
 * lo que informa después es el neto real (mp_neto). Esto es sólo para que el
 * dueño de la página pueda hacer la cuenta antes de poner un precio.
 */
export const MP_PORCENTAJE = 4.39;

/** Días desde la compra hasta que la plata queda disponible. */
export const MP_DIAS_ACREDITACION = 10;

/**
 * Un porcentaje como se escribe acá: 1,5 y no 1.5.
 *
 * Los porcentajes vienen como número —la comisión sale de la configuración del
 * servidor— y sin esto una comisión de 1,5% se mostraba "1.5%".
 */
export function formatearPorcentaje(valor) {
  return Number(valor).toLocaleString('es-AR', { maximumFractionDigits: 2 });
}
