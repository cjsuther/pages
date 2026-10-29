/**
 * Los estados de un pedido de un físico (#44), como los define
 * api/lib/EstadosDePedidos.php. El servidor es el que valida las
 * transiciones: acá sólo se nombran y se dibujan.
 */

import { FRANJAS } from './productos';

/** En el orden del recorrido. */
export const ESTADOS_DEL_PEDIDO = [
  { clave: 'ingresado', nombre: 'Ingresado', plural: 'Ingresados' },
  { clave: 'con_fecha', nombre: 'Con fecha de entrega', plural: 'Con fecha' },
  { clave: 'enviado', nombre: 'Enviado', plural: 'Enviados' },
  { clave: 'entregado', nombre: 'Entregado', plural: 'Entregados' },
  { clave: 'cancelado', nombre: 'Cancelado', plural: 'Cancelados' },
];

/**
 * Un retiro (#53) tiene los mismos estados con otros nombres, como en
 * EstadosDePedidos::ESTADOS_DEL_RETIRO. Envío y coordinar usan los de arriba.
 */
const DEL_RETIRO = {
  con_fecha: { nombre: 'Con fecha de retiro', plural: 'Con fecha', accion: 'Poner fecha de retiro' },
  enviado: { nombre: 'Listo para retirar', plural: 'Listos para retirar', accion: 'Marcar listo para retirar' },
  entregado: { nombre: 'Retirado', plural: 'Retirados', accion: 'Marcar retirado' },
};

/** Los estados con los nombres de esa forma de entrega, en el orden del recorrido. */
export function estadosDelPedido(forma = 'envio') {
  return ESTADOS_DEL_PEDIDO.map((e) => (forma === 'retiro' && DEL_RETIRO[e.clave]
    ? { ...e, nombre: DEL_RETIRO[e.clave].nombre, plural: DEL_RETIRO[e.clave].plural }
    : e));
}

/** "Con fecha de entrega" (o "Con fecha de retiro"), o la clave si no se conoce. */
export function nombreDelEstado(clave, forma = 'envio') {
  const estado = estadosDelPedido(forma).find((e) => e.clave === clave);
  return estado ? estado.nombre : clave || '';
}

/** Lo que dice el botón que lleva a cada estado. */
export const ACCIONES = {
  con_fecha: 'Poner fecha de entrega',
  enviado: 'Marcar enviado',
  entregado: 'Marcar entregado',
  cancelado: 'Cancelar pedido',
};

/** Lo mismo cuando ya tiene fecha y se vuelve a elegir. */
export const CORREGIR_FECHA = 'Cambiar la fecha de entrega';

/** El botón que lleva a `estado` desde `actual`, con los nombres de la forma. */
export function accionHacia(estado, actual, forma = 'envio') {
  const retiro = forma === 'retiro';
  if (estado === 'con_fecha' && actual === 'con_fecha') {
    return retiro ? 'Cambiar la fecha de retiro' : CORREGIR_FECHA;
  }
  return retiro && DEL_RETIRO[estado] ? DEL_RETIRO[estado].accion : ACCIONES[estado];
}

/** Cuántos pedidos en gestión hay de cada forma de entrega, para el filtro (#53). */
export function contarPorForma(pedidos = []) {
  const cuenta = { envio: 0, retiro: 0, coordinar: 0 };
  pedidos.forEach((p) => {
    const forma = p.forma_entrega || 'envio';
    if (forma in cuenta) cuenta[forma] += 1;
  });
  return cuenta;
}

const TONOS = {
  ingresado: 'bg-amber-50 text-amber-900 border-amber-200',
  con_fecha: 'bg-sky-50 text-sky-900 border-sky-200',
  enviado: 'bg-indigo-50 text-indigo-900 border-indigo-200',
  entregado: 'bg-verde-claro text-verde-oscuro border-verde-medio',
  cancelado: 'bg-papel-hueso text-tinta-suave border-borde',
};

/** Las clases del chip de un estado. */
export const tonoDelEstado = (clave) => TONOS[clave] || TONOS.cancelado;

/**
 * Cuántos pedidos hay en cada estado, para los contadores del filtro. Los
 * que no están en gestión (sin pagar) no cuentan en ninguno.
 */
export function contarPorEstado(pedidos = []) {
  const cuenta = Object.fromEntries(ESTADOS_DEL_PEDIDO.map((e) => [e.clave, 0]));

  pedidos.forEach((p) => {
    if (p.estado_pedido in cuenta) cuenta[p.estado_pedido] += 1;
  });

  return cuenta;
}

/** "Talle M · Color Negro × 2", o "× 2" sin opciones. */
export function describirLoPedido(pedido) {
  const cantidad = `× ${Number(pedido.cantidad) || 1}`;
  return pedido.opciones ? `${pedido.opciones} ${cantidad}` : cantidad;
}

const DIAS_DE_LA_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** "2026-11-03" → "martes 03/11". Sin zona horaria: es una fecha, no un momento. */
export function fechaDeEntrega(fecha) {
  const [anio, mes, dia] = String(fecha || '').slice(0, 10).split('-').map(Number);
  if (!anio || !mes || !dia) return '';

  const diaDeLaSemana = DIAS_DE_LA_SEMANA[new Date(anio, mes - 1, dia).getDay()];
  return `${diaDeLaSemana} ${String(dia).padStart(2, '0')}/${String(mes).padStart(2, '0')}`;
}

/** "martes 03/11 · Tarde". */
export function describirEntrega(fecha, franja) {
  const nombreFranja = FRANJAS.find((f) => f.clave === franja)?.nombre;
  return [fechaDeEntrega(fecha), nombreFranja].filter(Boolean).join(' · ');
}

/** Hoy en la hora de quien mira, como lo pide un <input type="date">. */
export function hoy(ahora = new Date()) {
  const dos = (n) => String(n).padStart(2, '0');
  return `${ahora.getFullYear()}-${dos(ahora.getMonth() + 1)}-${dos(ahora.getDate())}`;
}

/**
 * Dónde buscar la operación en Mercado Pago para devolver el pago: la
 * actividad de la cuenta filtrada por el número de operación.
 */
export const urlDeLaOperacion = (mpPaymentId) =>
  `https://www.mercadopago.com.ar/activities?q=${encodeURIComponent(mpPaymentId)}`;

/** Un seguimiento que es un link se puede abrir; otro texto, no. */
export const esLink = (texto) => /^https?:\/\/\S+$/i.test(String(texto || '').trim());

/** "pedidos-la-sala.xlsx", como lo nombra la API. */
export function nombreDelExcelDePedidos(nombre) {
  const limpio = String(nombre || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);

  return `pedidos-${limpio || 'productos'}.xlsx`;
}
