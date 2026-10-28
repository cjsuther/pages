/**
 * Las variables de los mails a clientes (#37), igual que MensajeACliente del
 * servidor: lo que muestra la vista previa es lo que le llega al cliente.
 */

export const VARIABLES = {
  nombre: '{nombre}',
  primerNombre: '{primer_nombre}',
};

const VARIABLE = '\\{(?:nombre|primer_nombre)\\}';

/**
 * Reemplaza {nombre} y {primer_nombre}. Sin nombre va el reemplazo; si
 * también está vacío, se limpia el espacio que queda colgando: "Hola
 * {primer_nombre}," queda "Hola,".
 */
export function reemplazarVariables(texto, nombre, reemplazo = '') {
  const original = String(texto || '');
  const limpio = String(nombre || '').replace(/\s+/g, ' ').trim();

  if (limpio) {
    return original.split(VARIABLES.nombre).join(limpio).split(VARIABLES.primerNombre).join(limpio.split(' ')[0]);
  }

  const otro = String(reemplazo || '').trim();

  if (otro) {
    return original.split(VARIABLES.nombre).join(otro).split(VARIABLES.primerNombre).join(otro);
  }

  return original
    .replace(new RegExp(`[ \\t]*${VARIABLE}(?=[ \\t]*(?:[,.;:!?)]|$))`, 'gm'), '')
    .replace(new RegExp(`[ \\t]+${VARIABLE}(?=[ \\t])`, 'g'), '')
    .replace(new RegExp(`^${VARIABLE}[ \\t]*`, 'gm'), '')
    .replace(new RegExp(VARIABLE, 'g'), '');
}

/** Inserta `agregado` en lugar de la selección. Devuelve el texto y dónde queda el cursor. */
export function insertarEnCursor(texto, inicio, fin, agregado) {
  const base = String(texto || '');
  const desde = Number.isInteger(inicio) ? inicio : base.length;
  const hasta = Number.isInteger(fin) ? fin : desde;

  return {
    texto: base.slice(0, desde) + agregado + base.slice(hasta),
    cursor: desde + agregado.length,
  };
}

export const ESTADOS_DE_ENVIO = {
  en_curso: 'En curso',
  pausado: 'Pausado',
  terminado: 'Terminado',
  cancelado: 'Cancelado',
};
