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

/**
 * Hasta dónde llega el texto de un link de WhatsApp, ya codificado. wa.me no
 * publica un límite, pero con mucho más los navegadores y la app lo cortan.
 */
export const LARGO_MAXIMO_WHATSAPP = 2000;

const PUNTOS = '…';

// Una mitad de surrogate suelta hace fallar a encodeURIComponent.
const SURROGATE_SUELTO = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g;

/** Recorta `texto` con "…" para que codificado no pase de `maximo`. */
export function recortarParaWhatsApp(texto, maximo = LARGO_MAXIMO_WHATSAPP) {
  const limpio = String(texto || '').replace(SURROGATE_SUELTO, '�');

  if (encodeURIComponent(limpio).length <= maximo) {
    return { texto: limpio, recortado: false };
  }

  const lugar = maximo - encodeURIComponent(PUNTOS).length;
  let largo = 0;
  let corte = '';

  // De a caracteres enteros, para no partir un emoji o una letra con tilde.
  for (const caracter of limpio) {
    largo += encodeURIComponent(caracter).length;
    if (largo > lugar) break;
    corte += caracter;
  }

  return { texto: `${corte.trimEnd()}${PUNTOS}`, recortado: true };
}

/**
 * El texto de WhatsApp de un mensaje guardado para un cliente (#38): el cuerpo
 * sin el asunto, con el nombre reemplazado como en el mail.
 */
export function textoParaWhatsApp(mensaje, nombre) {
  if (!mensaje) return { texto: '', recortado: false };

  const texto = reemplazarVariables(mensaje.cuerpo, nombre, mensaje.reemplazo_nombre).trim();

  return recortarParaWhatsApp(texto);
}

export const ESTADOS_DE_ENVIO = {
  en_curso: 'En curso',
  pausado: 'Pausado',
  terminado: 'Terminado',
  cancelado: 'Cancelado',
};
