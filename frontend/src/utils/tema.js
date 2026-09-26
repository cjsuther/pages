/**
 * Los colores de las pantallas que se abren con un link: la puerta y el
 * reporte de venta.
 *
 * Son pantallas de Rezonar y se ven como Rezonar: blanco, verde y tipografía
 * negra, igual que el resto del sitio.
 *
 * Antes tomaban los colores de la página del evento, y eso daba dos
 * sorpresas: una pantalla distinta por evento —con el acento que hubiera
 * elegido cada uno— y, sobre todo, una pantalla oscura para quien tiene la
 * página oscura, aunque el sitio desde donde entra sea claro. El fondo de una
 * página es una decisión sobre cómo se ve esa página para su público, no sobre
 * cómo se ve el panel interno de quien la administra.
 *
 * Es una sola variante porque el sitio tiene una sola. Si algún día Rezonar
 * ofrece modo oscuro, estas dos pantallas lo van a seguir desde acá.
 */

/** Los mismos valores que tailwind.config.js, que es donde vive la identidad. */
const SITIO = {
  fondo: '#F7F8F5',    // papel-hueso
  tarjeta: '#FFFFFF',  // papel
  texto: '#111311',    // tinta
  titulo: '#111311',
  suave: '#4A4F45',    // tinta-media
  tenue: '#6E7367',    // tinta-suave
  borde: '#E2E6DC',    // borde
  acento: '#6FBE44',   // verde
  boton: '#6FBE44',
  textoBoton: '#14310A', // verde-tinta
};

export function temaDelSitio() {
  return { ...SITIO };
}
