/**
 * Los colores de las pantallas que se abren con un link: la puerta y el
 * reporte de venta.
 *
 * Son pantallas de Rezonar, no de la página: se ven como el sitio —blanco,
 * verde y tipografía negra— y no adoptan la paleta de cada página. Antes sí lo
 * hacían, y el resultado era una pantalla distinta por evento: con un acento
 * dorado o violeta según quién hubiera armado la página, y nada que dijera que
 * era Rezonar.
 *
 * De la página se toma una sola cosa: si es clara u oscura. Quien la armó ya
 * decidió eso para su público, y la puerta de un show de noche no tiene por
 * qué encandilar a nadie con una pantalla blanca.
 */

import { contraste, conAlfa } from './colores';

/** El verde de la marca, el mismo de tailwind.config.js. */
const VERDE = '#6FBE44';
const VERDE_TINTA = '#14310A';

const CLARO = {
  fondo: '#F7F8F5',
  tarjeta: '#FFFFFF',
  texto: '#111311',
  titulo: '#111311',
  suave: '#4A4F45',
  tenue: '#6E7367',
  borde: '#E2E6DC',
};

const OSCURO = {
  fondo: '#111311',
  // Levantada sobre el fondo, como el blanco sobre el hueso en el sitio claro.
  tarjeta: '#1B1E1A',
  texto: '#F7F8F5',
  titulo: '#FFFFFF',
  suave: conAlfa('#F7F8F5', 0.72),
  tenue: conAlfa('#F7F8F5', 0.5),
  borde: conAlfa('#F7F8F5', 0.14),
};

/**
 * Si la página es oscura.
 *
 * Se mira el contraste del fondo contra el blanco y no un umbral inventado:
 * es la misma cuenta que decide en todo el sitio si un texto va en blanco o en
 * negro, así que una página al límite cae siempre del mismo lado.
 */
export function esPaginaOscura(colores) {
  const fondo = (colores && colores.background_color) || '#FFFFFF';
  const contra = contraste(fondo, '#FFFFFF');

  return contra !== null && contra >= 3;
}

export function temaDeEvento(colores) {
  const base = esPaginaOscura(colores) ? OSCURO : CLARO;

  return {
    ...base,
    // El acento es el de Rezonar en las dos variantes: es lo que hace que la
    // pantalla se vea como el sitio y no como una página cualquiera.
    acento: VERDE,
    boton: VERDE,
    textoBoton: VERDE_TINTA,
  };
}
