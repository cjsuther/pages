/**
 * Datos derivados de un evento.
 */

/** Códigos postales argentinos: 1417, C1417, C1417ABC. */
const CODIGO_POSTAL = /^[A-Za-z]?\d{4}[A-Za-z]{0,3}\s+/;

/**
 * La ciudad o provincia de un evento, sacada de su dirección.
 *
 * No se guarda como dato propio: de Google llega la dirección completa y ya
 * armada, así que hay que leerla. Se toma la parte anterior al país, que es la
 * única que ocupa siempre el mismo lugar: las direcciones vienen con tres,
 * cuatro o cinco partes según tengan nombre del lugar, barrio o ninguno de los
 * dos, y por eso no se puede contar desde el principio.
 *
 *   Humboldt 1574, Palermo, Ciudad Autónoma de Buenos Aires, Argentina
 *                                     └─ esta
 *
 * Con menos de tres partes se devuelve null: ahí la anterior al país es la
 * calle, y decir que la localidad es "Av. San Martín 5743" es peor que no
 * decir nada.
 */
export function localidadDe(direccion) {
  if (typeof direccion !== 'string') return null;

  const partes = direccion
    .split(',')
    .map((p) => p.trim())
    .filter(Boolean);

  if (partes.length < 3) return null;

  // La última es el país; la anterior, lo que buscamos.
  const localidad = partes[partes.length - 2].replace(CODIGO_POSTAL, '').trim();

  return localidad || null;
}

const RADIO_TIERRA_KM = 6371;

/**
 * Distancia en línea recta entre dos puntos, en kilómetros.
 *
 * Se calcula acá y no en el servidor porque el listado público de eventos es
 * el mismo para todos —así se puede cachear— y la ubicación de quien mira es
 * de quien mira: no hace falta mandarla a ningún lado para saber qué le queda
 * cerca.
 */
export function distanciaKm(desde, hasta) {
  if (!desde || !hasta) return null;

  const lat1 = Number(desde.lat);
  const lng1 = Number(desde.lng);
  const lat2 = Number(hasta.lat);
  const lng2 = Number(hasta.lng);

  if ([lat1, lng1, lat2, lng2].some((n) => !Number.isFinite(n))) return null;

  const rad = (g) => (g * Math.PI) / 180;
  const dLat = rad(lat2 - lat1);
  const dLng = rad(lng2 - lng1);

  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;

  return RADIO_TIERRA_KM * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** La distancia de un evento a un punto, leyendo las coordenadas del evento. */
export function distanciaDelEvento(evento, ubicacion) {
  if (!evento || !ubicacion) return null;
  if (!evento.event_latitude || !evento.event_longitude) return null;

  return distanciaKm(ubicacion, {
    lat: evento.event_latitude,
    lng: evento.event_longitude,
  });
}

/** "hoy", "mañana", "sáb 14 mar" — cómo se nombra la fecha de un evento. */
export function cuandoEs(evento) {
  if (!evento?.event_date) return null;

  const fecha = new Date(`${evento.event_date}T${evento.event_time || '00:00'}`);
  if (Number.isNaN(fecha.getTime())) return null;

  const soloDia = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const dias = Math.round((soloDia(fecha) - soloDia(new Date())) / 86400000);

  if (dias === 0) return 'Hoy';
  if (dias === 1) return 'Mañana';
  if (dias > 1 && dias < 7) {
    return fecha.toLocaleDateString('es-AR', { weekday: 'long' })
      .replace(/^./, (c) => c.toUpperCase());
  }

  return fecha.toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });
}

/** La hora de un evento, o null si no tiene cargada. */
export function horaDe(evento) {
  if (!evento?.event_time) return null;

  return evento.event_time.slice(0, 5);
}
