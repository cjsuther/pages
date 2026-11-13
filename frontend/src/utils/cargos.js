/**
 * Comisiones que se le suman al precio y paga quien compra.
 *
 * Es la misma cuenta que api/lib/CargosAlComprador.php, operación por
 * operación y en centavos enteros, para que lo que se muestra mientras se
 * eligen las entradas sea al centavo lo que después cobra Mercado Pago. Los
 * dos lados se prueban contra los mismos casos
 * (api/tests/Fixtures/cargos-al-comprador.json). Si cambia una, cambia la otra.
 *
 *   R  = precio × pR × (1 + ivaR)           comisión de Rezon.ar
 *   f  = pMP × (1 + ivaMP)                  tasa efectiva de Mercado Pago
 *   F  = fijoMP × (1 + ivaMP)
 *   T  = ⌈(precio + R + F) / (1 − f)⌉       al centavo, para arriba
 *   MP = T − precio − R
 */

/** Medio centavo para arriba, igual que en PHP. */
function redondear(x) {
  return Math.floor(x + 0.5);
}

/**
 * @param {number} precio Lo que valen las entradas.
 * @param {object|null} tasas Las que manda el servidor; null = no se suma nada.
 * @returns {{precio: number, mercadopago: number, plataforma: number, total: number}}
 */
export function calcularCargos(precio, tasas) {
  let precioC = redondear(Number(precio) * 100);

  if (!(precioC > 0) || !tasas) {
    precioC = Math.max(0, precioC || 0);
    return enPesos(precioC, 0, 0);
  }

  const plataformaC = redondear(
    (precioC * Number(tasas.plataforma_porcentaje) * (100 + Number(tasas.plataforma_iva))) / 10000
  );

  const factorIvaMp = (100 + Number(tasas.mp_iva)) / 100;
  const f = (Number(tasas.mp_porcentaje) / 100) * factorIvaMp;
  const fijoC = Number(tasas.mp_fijo) * 100 * factorIvaMp;

  const totalC = Math.ceil((precioC + plataformaC + fijoC) / (1 - f) - 0.000001);

  return enPesos(precioC, totalC - precioC - plataformaC, plataformaC);
}

function enPesos(precioC, mercadopagoC, plataformaC) {
  return {
    precio: precioC / 100,
    mercadopago: mercadopagoC / 100,
    plataforma: plataformaC / 100,
    total: (precioC + mercadopagoC + plataformaC) / 100,
  };
}

/** Cómo se llaman los cargos en todas las pantallas, el checkout y el mail. */
export const CARGO_MERCADOPAGO = 'Comisión de Mercado Pago';
export const CARGO_PLATAFORMA = 'Comisión de Rezon.ar';

/**
 * Los renglones del desglose, para mostrar: [[concepto, monto]], sin el total.
 * Un cargo en 0 no se nombra (sin split no hay comisión de Rezon.ar).
 */
export function renglonesDelDesglose({ entradas, mercadopago, plataforma }) {
  const renglones = [['Entradas', entradas]];
  if (mercadopago > 0) renglones.push([CARGO_MERCADOPAGO, mercadopago]);
  if (plataforma > 0) renglones.push([CARGO_PLATAFORMA, plataforma]);
  return renglones;
}
