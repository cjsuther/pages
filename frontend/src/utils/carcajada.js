/**
 * Carcajada vive en su propio subdominio, con la misma aplicación.
 *
 * No es otro proyecto: usa las cuentas de Rezonar, la misma API y los mismos
 * componentes. Lo único distinto es qué pantallas se muestran, y eso lo dice
 * el host. En desarrollo se entra con ?carcajada, porque nadie tiene un
 * subdominio levantado en su máquina.
 */
export function esSitioCarcajada() {
  if (typeof window === 'undefined') return false;

  const host = window.location.hostname || '';

  return host.startsWith('carcajada.') || window.location.search.includes('carcajada');
}

/** Dónde se crean la cuenta y la página, que es el requisito para anotarse. */
export const URL_REZONAR = 'https://rezon.ar';

/** El link a un Instagram, armado acá para que no dependa de cómo lo pegó cada uno. */
export function urlDeInstagram(usuario) {
  return usuario ? `https://instagram.com/${usuario}` : null;
}

/** La página de Rezonar de un comediante. */
export function urlDeRezonar(slug) {
  return slug ? `${URL_REZONAR}/${slug}` : null;
}

/** "jueves 2 de octubre", que es como se anuncia una fecha. */
export function fechaLarga(fecha, hora = null) {
  if (!fecha) return '';

  const dia = new Date(`${String(fecha).slice(0, 10)}T00:00:00`);

  if (Number.isNaN(dia.getTime())) return String(fecha);

  const texto = dia.toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });

  return hora ? `${texto} · ${String(hora).slice(0, 5)}` : texto;
}

/** "2 oct", para listas donde no entra la fecha larga. */
export function fechaCorta(fecha) {
  if (!fecha) return '';

  const dia = new Date(`${String(fecha).slice(0, 10)}T00:00:00`);

  return Number.isNaN(dia.getTime())
    ? String(fecha)
    : dia.toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });
}

/**
 * Cómo le viene yendo a alguien con la gente que prometió traer.
 *
 * Es la comparación que importa al armar una fecha: no cuánta gente trae, sino
 * si cumple lo que dijo. Sin shows medidos no se opina.
 */
export function cumplimiento(ficha) {
  if (!ficha || ficha.promedio_traidas === null || !ficha.comprometidas) {
    return null;
  }

  return Math.round((100 * ficha.promedio_traidas) / ficha.comprometidas);
}
