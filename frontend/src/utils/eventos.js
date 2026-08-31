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
