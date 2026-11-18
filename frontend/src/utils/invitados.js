import { urlDeWhatsApp } from './telefono';

/** Cuántos lugares puede tener un invitado: él y sus acompañantes. */
export const MAX_LUGARES_POR_INVITADO = 20;

/** "Solo", "+1", "+2"…: cómo se dice la cantidad de lugares de un invitado. */
export function etiquetaDeLugares(cantidad) {
  const n = Number(cantidad) || 1;

  return n <= 1 ? 'Solo' : `+${n - 1}`;
}

/** El mensaje que se le manda al invitado con el link de su entrada. */
export function mensajeDeInvitacion({ nombre, evento, url }) {
  const saludo = nombre ? `¡Hola ${String(nombre).split(' ')[0]}!` : '¡Hola!';
  const donde = evento ? ` para ${evento}` : '';

  return `${saludo} Te invito${donde}. Esta es tu entrada sin cargo, mostrá el QR en la puerta: ${url}`;
}

/**
 * El link de WhatsApp para pasarle la entrada.
 *
 * Con un teléfono que se entiende, abre el chat con esa persona. Si no, abre
 * WhatsApp para elegir a quién mandárselo, con el mensaje ya escrito.
 */
export function whatsAppDeInvitado(invitado, evento) {
  const texto = mensajeDeInvitacion({ nombre: invitado.nombre, evento, url: invitado.url });

  return urlDeWhatsApp(invitado.telefono, texto) || `https://wa.me/?text=${encodeURIComponent(texto)}`;
}

/** Cómo se ve el estado de un invitado en la lista. */
export function estadoDeInvitado(invitado) {
  if (invitado.estado === 'anulado') return { texto: 'Anulado', clase: 'bg-papel-hueso text-tinta-suave' };

  if (invitado.ingresadas >= invitado.cantidad) return { texto: 'Entró', clase: 'bg-verde text-tinta' };

  if (invitado.ingresadas > 0) {
    return { texto: `Entraron ${invitado.ingresadas}/${invitado.cantidad}`, clase: 'bg-verde-medio text-verde-tinta' };
  }

  return { texto: 'Invitado', clase: 'bg-verde-claro text-verde-oscuro' };
}
