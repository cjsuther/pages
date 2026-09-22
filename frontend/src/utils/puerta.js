/**
 * Ayudas para la pantalla de la puerta de un evento.
 */

import { resumirLugares } from './plano';

/**
 * El código de una orden a partir de lo que leyó la cámara o se tipeó.
 *
 * El QR de la entrada tiene la dirección de la orden
 * (https://rezon.ar/entrada/ABC123DEF456). Es la misma regla que aplica el
 * servidor: acá sirve para no mandarle algo que ya se sabe que no es un código.
 */
export function codigoDesdeQr(texto) {
  const crudo = String(texto || '').trim();
  const enLaUrl = crudo.match(/\/entrada\/([A-Za-z0-9]{12})(?:[/?#]|$)/);

  if (enLaUrl) return enLaUrl[1].toUpperCase();

  const limpio = crudo.replace(/\s+/g, '').toUpperCase();

  return /^[A-F0-9]{12}$/.test(limpio) ? limpio : null;
}

/** Sin tildes ni mayúsculas: en la puerta nadie escribe "Gómez" con tilde. */
function normalizar(texto) {
  return String(texto || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();
}

/**
 * Las compras que coinciden con lo que se busca: nombre, código o lugar.
 *
 * Por lugar sirve cuando alguien dice "tengo la A7" y no se acuerda a nombre
 * de quién sacó la entrada.
 */
export function filtrarOrdenes(ordenes, busqueda) {
  const q = normalizar(busqueda).trim();

  if (q === '') return ordenes;

  const palabras = q.split(/\s+/);

  return ordenes.filter((o) => {
    const texto = normalizar(`${o.nombre} ${o.codigo} ${resumirLugares(o.lugares)}`);
    return palabras.every((p) => texto.includes(p));
  });
}

/** "21:14", de una fecha de la base. */
export function horaCorta(fecha) {
  if (!fecha) return '';
  const hora = String(fecha).split(' ')[1] || String(fecha).split('T')[1] || '';
  return hora.slice(0, 5);
}

const RESULTADOS = {
  valida: { titulo: 'Entrada válida', tono: 'bien' },
  ya_entro: { titulo: 'Ya entró', tono: 'aviso' },
  no_pagada: { titulo: 'No válida', tono: 'mal' },
  otro_evento: { titulo: 'Es de otro evento', tono: 'mal' },
  no_existe: { titulo: 'Entrada inexistente', tono: 'mal' },
};

const ESTADOS = {
  reservada: 'la compra no se terminó de pagar',
  vencida: 'la reserva venció sin pagarse',
  cancelada: 'la compra fue cancelada',
  rechazada: 'el pago fue rechazado',
};

/**
 * Cómo se muestra lo que respondió la API al mirar una entrada.
 *
 * @returns {{titulo: string, tono: 'bien'|'aviso'|'mal', detalle: string|null}}
 */
export function describirResultado(respuesta) {
  const base = RESULTADOS[respuesta.resultado] || { titulo: 'No se pudo leer', tono: 'mal' };
  const orden = respuesta.orden;
  let detalle = null;

  if (respuesta.resultado === 'otro_evento') {
    detalle = respuesta.evento ? `Es una entrada para "${respuesta.evento}".` : null;
  } else if (respuesta.resultado === 'no_pagada' && orden) {
    detalle = ESTADOS[orden.estado] ? `No puede pasar: ${ESTADOS[orden.estado]}.` : null;
  } else if (respuesta.resultado === 'ya_entro' && orden) {
    const hora = horaCorta(orden.ingreso_en);
    detalle = `${orden.cantidad === 1 ? 'Entró' : `Entraron las ${orden.cantidad}`}${hora ? ` a las ${hora}` : ''}.`;
  } else if (respuesta.resultado === 'no_existe') {
    detalle = 'Ese código no es de ninguna entrada de Rezonar.';
  }

  return { ...base, detalle };
}
