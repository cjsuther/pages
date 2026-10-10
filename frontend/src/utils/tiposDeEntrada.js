/**
 * Tipos de entrada: varios precios con nombre en un mismo evento.
 *
 *     [{ id: 'general', nombre: 'General', precio: 8000, cupo: null },
 *      { id: 'jubilados', nombre: 'Jubilados', precio: 5000, cupo: 20 },
 *      { id: '2x1', nombre: 'Promo 2x1', precio: 8000, personas: 2, cupo: 30 }]
 *
 * Una promo —personas > 1— cobra su precio por cada grupo que empieza: 1 o 2
 * entradas de la 2x1 salen lo mismo, 3 salen dos promos. Las entradas son
 * personas; el cupo del tipo se cuenta en promos.
 *
 * El id no cambia aunque el tipo se renombre: es lo que nombra cada fila o
 * mesa del plano y lo que guarda cada compra. Sin tipos —null— el evento
 * vende a un solo precio.
 *
 * Las reglas son las mismas que aplica la API (TiposDeEntrada.php): acá se
 * usan para mostrar el total antes de comprar, no para decidirlo.
 */

import { lugaresDelElemento } from './plano';

export const MAX_TIPOS = 20;
export const MAX_PERSONAS = 10;

/** Cuántas personas entran con una del tipo. Los tipos de antes de las promos no lo dicen. */
export function personasDe(tipo) {
  return Math.max(1, Number(tipo && tipo.personas) || 1);
}

/** Cuántas veces se cobra el precio: cada grupo que empieza paga entero. */
export function unidades(cantidad, personas = 1) {
  return Math.ceil(Number(cantidad) / Math.max(1, Number(personas) || 1));
}

/** Cuántos lugares más entran sin pagar otra promo: 1 si eligió 3 de una 2x1. */
export function lugaresSinCargo(cantidad, personas = 1) {
  const n = Number(cantidad);
  return n > 0 ? unidades(n, personas) * personas - n : 0;
}

/** Un tipo nuevo, con un id que todavía no usa ningún otro. */
export function nuevoTipo(tipos = []) {
  const usados = new Set(tipos.map((t) => t.id));
  let n = tipos.length + 1;
  while (usados.has(`t${n}`)) n += 1;

  return { id: `t${n}`, nombre: '', precio: 0, personas: 1, cupo: null };
}

/** Los dos con los que arranca quien pasa a varios precios: el que ya había y uno más. */
export function tiposIniciales(precio) {
  return [
    { id: 'general', nombre: 'General', precio: Number(precio) || 0, personas: 1, cupo: null },
    { id: 't2', nombre: '', precio: 0, personas: 1, cupo: null },
  ];
}

/** Si alguno cobra: un "Invitado" en 0 no hace gratis al resto. */
export function algunoCobra(tipos) {
  return (tipos || []).some((t) => Number(t.precio) > 0);
}

/** El más barato de los que cobran, para anunciar "desde". */
export function precioDesde(tipos) {
  const pagos = (tipos || []).map((t) => Number(t.precio)).filter((p) => p > 0);
  return pagos.length === 0 ? 0 : Math.min(...pagos);
}

/** Por qué los tipos no se pueden guardar, o null. */
export function problemaConLosTipos(tipos) {
  if (!tipos) return null;

  const nombres = new Set();

  for (const tipo of tipos) {
    const nombre = String(tipo.nombre || '').trim();

    if (nombre === '') return 'Cada tipo de entrada necesita un nombre.';
    if (Number(tipo.precio) < 0) return `El precio de "${nombre}" no puede ser negativo.`;
    if (tipo.personas !== undefined && tipo.personas !== null) {
      const personas = Number(tipo.personas);
      if (!Number.isInteger(personas) || personas < 1 || personas > MAX_PERSONAS) {
        return `En "${nombre}" entran de 1 a ${MAX_PERSONAS} personas.`;
      }
    }
    if (tipo.cupo !== null && tipo.cupo !== '' && Number(tipo.cupo) < 1) {
      return `El cupo de "${nombre}" tiene que ser al menos 1, o quedar vacío.`;
    }

    const clave = nombre.toLowerCase();
    if (nombres.has(clave)) return `Hay dos tipos que se llaman "${nombre}".`;
    nombres.add(clave);
  }

  return null;
}

/**
 * El tipo de una fila o mesa del plano. Una que no dice, o que nombra un tipo
 * borrado, vende al primero: ningún lugar queda sin precio.
 */
export function tipoDelElemento(elemento, tipos) {
  if (!tipos || tipos.length === 0) return null;

  const dice = elemento.entrada && tipos.some((t) => t.id === elemento.entrada);
  return dice ? elemento.entrada : tipos[0].id;
}

/** @returns {Object<string, string>} id de lugar → id de tipo, o {} sin tipos. */
export function tiposPorLugar(plano, tipos) {
  if (!plano || !tipos || tipos.length === 0) return {};

  return plano.elementos.reduce((mapa, elemento) => {
    const tipo = tipoDelElemento(elemento, tipos);
    lugaresDelElemento(elemento).forEach((l) => { mapa[l.id] = tipo; });
    return mapa;
  }, {});
}

/**
 * Cuántas de cada tipo hay en los lugares elegidos.
 *
 * @param {string[]} elegidos
 * @param {Object<string, string>} tipoDeLugar id de lugar → id de tipo
 * @returns {Object<string, number>}
 */
export function pedidoDeLosLugares(elegidos, tipoDeLugar) {
  return (elegidos || []).reduce((pedido, lugar) => {
    const tipo = tipoDeLugar[lugar];
    if (tipo) pedido[tipo] = (pedido[tipo] || 0) + 1;
    return pedido;
  }, {});
}

/**
 * Lo que se lleva y cuánto sale, en el orden de los tipos.
 *
 * @param {Object<string, number>} pedido id de tipo → cantidad
 * Una promo cobra por grupo que empieza: 3 entradas de una 2x1 son dos promos.
 *
 * @returns {{ items: {id, nombre, precio, personas, cantidad, subtotal}[], cantidad: number, total: number }}
 */
export function resumenDelPedido(tipos, pedido) {
  const items = (tipos || [])
    .filter((t) => Number(pedido[t.id]) > 0)
    .map((t) => {
      const cantidad = Number(pedido[t.id]);
      const personas = personasDe(t);
      return {
        id: t.id, nombre: t.nombre, precio: Number(t.precio), personas, cantidad,
        subtotal: Number(t.precio) * unidades(cantidad, personas),
      };
    });

  return {
    items,
    cantidad: items.reduce((suma, i) => suma + i.cantidad, 0),
    total: items.reduce((suma, i) => suma + i.subtotal, 0),
  };
}

/** "2 General · 1 Jubilados · 2 Promo 2x1 (3 personas)", como lo arma la API. */
export function resumirTipos(items) {
  return (items || []).map((i) => {
    const personas = personasDe(i);
    if (personas === 1) return `${i.cantidad} ${i.nombre}`;
    return `${unidades(i.cantidad, personas)} ${i.nombre} (${i.cantidad} ${Number(i.cantidad) === 1 ? 'persona' : 'personas'})`;
  }).join(' · ');
}

/** "$ 8.000", o "$ 8.000 cada 2" en una promo. */
export function precioDelTipo(tipo, formatear) {
  if (!(Number(tipo.precio) > 0)) return null;
  const personas = personasDe(tipo);
  return personas === 1 ? formatear(tipo.precio) : `${formatear(tipo.precio)} cada ${personas}`;
}
