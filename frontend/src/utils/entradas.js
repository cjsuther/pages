/**
 * Ayudas para el módulo de venta de entradas.
 */

/** Un precio de 0 significa reserva sin cobro. */
export function esGratis(precio) {
  return !precio || Number(precio) <= 0;
}

export function formatearPrecio(monto, moneda = 'ARS') {
  const numero = Number(monto) || 0;

  try {
    return new Intl.NumberFormat('es-AR', {
      style: 'currency',
      currency: moneda,
      minimumFractionDigits: numero % 1 === 0 ? 0 : 2,
    }).format(numero);
  } catch (e) {
    // Una moneda desconocida hace tirar a Intl; mejor mostrar algo que romper.
    return `${moneda} ${numero}`;
  }
}

/** Texto del botón según si hay que pagar o sólo reservar. */
export function textoDeAccion(entradas) {
  if (!entradas || !entradas.activo) {
    return null;
  }

  if (entradas.agotado) {
    return 'AGOTADO';
  }

  return entradas.es_gratis ? 'RESERVAR LUGAR' : 'COMPRAR ENTRADAS';
}

/**
 * Cuántas entradas se pueden elegir. Se ofrece hasta lo que realmente queda,
 * porque ofrecer 10 cuando quedan 3 lleva a un error recién al confirmar.
 */
export function opcionesDeCantidad(entradas) {
  if (!entradas) {
    return [];
  }

  const tope = Math.min(
    Number(entradas.max_por_compra) || 0,
    Number(entradas.disponibles) || 0
  );

  return Array.from({ length: Math.max(0, tope) }, (unused, i) => i + 1);
}

const ETIQUETAS_DE_ESTADO = {
  pagada: 'Pagada',
  reservada: 'Reservada',
  vencida: 'Vencida',
  cancelada: 'Cancelada',
  rechazada: 'Rechazada',
};

export function etiquetaDeEstado(estado) {
  return ETIQUETAS_DE_ESTADO[estado] || estado;
}

/** Clases de color por estado, para el listado de ventas. */
export function colorDeEstado(estado) {
  if (estado === 'pagada') return 'bg-emerald-900 text-emerald-300';
  if (estado === 'reservada') return 'bg-amber-900 text-amber-300';
  if (estado === 'rechazada') return 'bg-red-900 text-red-300';
  return 'bg-gray-800 text-gray-400';
}

/** Minutos que faltan para que venza una reserva, o 0 si ya venció. */
export function minutosRestantes(vencimiento, ahora = Date.now()) {
  if (!vencimiento) {
    return 0;
  }

  // Safari no parsea "YYYY-MM-DD HH:MM:SS"; necesita la T.
  const fecha = new Date(String(vencimiento).replace(' ', 'T'));
  const restante = fecha.getTime() - ahora;

  return restante <= 0 ? 0 : Math.ceil(restante / 60000);
}

/**
 * Cómo se anuncia el precio de un evento en la tarjeta y en el detalle.
 *
 * Es el precio de referencia, distinto del de la venta interna: aplica a los
 * eventos que se cobran en otro lado o que llegan importados desde la
 * cartelera del lugar, donde lo único que se sabe es desde cuánto sale.
 *
 * @returns {string|null} null cuando no hay dato y no corresponde mostrar nada
 */
export function precioDeReferencia(precio) {
  if (precio === null || precio === undefined || precio === '') {
    return null;
  }

  const numero = Number(precio);

  if (Number.isNaN(numero)) {
    return null;
  }

  // El cero no es "sin dato": es la afirmación de que el evento es gratis, y
  // eso el público lo quiere ver.
  return numero <= 0 ? 'Gratis' : `Desde ${formatearPrecio(numero)}`;
}
