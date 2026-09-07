/**
 * Cómo se configuran los avisos de una página que seguís.
 *
 * Vive en un solo lugar porque la misma preferencia se edita desde tres
 * pantallas —el botón de seguir, la lista de páginas seguidas y la pantalla de
 * alertas— y antes cada una mandaba lo suyo: el botón guardaba 30 km fijos
 * mientras el servidor tomaba 50 por defecto, así que el radio que se veía en
 * pantalla no era siempre el que quedaba guardado.
 */

/** Radios ofrecidos para "solo si es cerca". Son los que el servidor guarda tal cual. */
export const RADIOS_KM = [10, 25, 50, 100];

/** El que viene marcado si la persona no elige otro. Igual al del servidor. */
export const RADIO_POR_DEFECTO = 50;

/**
 * Traduce una preferencia guardada a las dos cosas que maneja la interfaz:
 * el modo (todas / cerca) y el radio.
 */
export function leerPreferencia(seguimiento) {
  const cerca = seguimiento && !seguimiento.notify_all_events;

  return {
    modo: cerca ? 'cerca' : 'todas',
    radio: Number(seguimiento?.max_distance_km) || RADIO_POR_DEFECTO,
  };
}

/** El cuerpo que espera la API a partir del modo y el radio elegidos. */
export function cuerpoDePreferencia(pageId, modo, radio) {
  return {
    page_id: pageId,
    notify_all_events: modo === 'todas',
    max_distance_km: modo === 'cerca' ? radio : RADIO_POR_DEFECTO,
  };
}

/** Cómo se nombra la preferencia en una lista, en una sola línea. */
export function describirAlerta(seguimiento) {
  const { modo, radio } = leerPreferencia(seguimiento);

  return modo === 'todas' ? 'Todas sus fechas' : `Solo a menos de ${radio} km`;
}
