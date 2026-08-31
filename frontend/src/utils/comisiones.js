/**
 * Un porcentaje como se escribe acá: 1,5 y no 1.5.
 *
 * Los porcentajes vienen como número —salen de la configuración del servidor,
 * tanto el nuestro como el de Mercado Pago— y sin esto una comisión de 1,5% se
 * mostraba "1.5%".
 */
export function formatearPorcentaje(valor) {
  return Number(valor).toLocaleString('es-AR', { maximumFractionDigits: 2 });
}
