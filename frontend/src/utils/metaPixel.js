/**
 * Pixel de Meta, el de cada página.
 *
 * No es la medición de Rezonar —esa es una sola, en utils/analytics.js, y
 * sirve para mostrarle a cada dueño cómo le va—: este es el pixel propio de
 * quien tiene la página, en su cuenta de Meta, para que pueda anunciar sus
 * shows y saber qué avisos venden entradas.
 *
 * Por eso se carga recién cuando se sabe de qué página es la pantalla, y con
 * el identificador que vino del servidor. Una página sin pixel no carga nada:
 * nadie tiene que pagar con la velocidad de su página una medición que no
 * pidió.
 */

const URL_PIXEL = 'https://connect.facebook.net/en_US/fbevents.js';

/** Los pixeles ya inicializados, para no volver a declararlos. */
const declarados = new Set();

/** Un pixel es un número: lo que no lo sea no se manda a Meta. */
export function esPixelValido(pixelId) {
  return /^[0-9]{10,20}$/.test(String(pixelId || '').trim());
}

/** Carga fbevents.js una sola vez, con el arranque que publica Meta. */
function cargarLibreria() {
  if (window.fbq) {
    return;
  }

  const fbq = function encolar(...args) {
    // Hasta que la librería termina de cargar, las llamadas se guardan y
    // después ella misma las reproduce en orden.
    if (fbq.callMethod) {
      fbq.callMethod.apply(fbq, args);
    } else {
      fbq.queue.push(args);
    }
  };

  fbq.push = fbq;
  fbq.loaded = true;
  fbq.version = '2.0';
  fbq.queue = [];

  window.fbq = fbq;
  window._fbq = window._fbq || fbq;

  const script = document.createElement('script');
  script.async = true;
  script.src = URL_PIXEL;
  document.head.appendChild(script);
}

/**
 * Deja listo el pixel de una página.
 *
 * Se puede llamar en cada pantalla: declarar dos veces el mismo pixel
 * duplicaría las vistas, así que el segundo llamado no hace nada.
 *
 * @returns {boolean} si esta pantalla mide con Meta
 */
export function iniciarPixel(pixelId) {
  if (!esPixelValido(pixelId) || typeof document === 'undefined') {
    return false;
  }

  const pixel = String(pixelId).trim();

  cargarLibreria();

  if (!declarados.has(pixel)) {
    window.fbq('init', pixel);
    declarados.add(pixel);
  }

  return true;
}

/**
 * Un evento del pixel de una página.
 *
 * Va siempre con `trackSingle`: en una pestaña pueden haber quedado
 * declarados los pixeles de dos páginas distintas —se navega de una a otra sin
 * recargar—, y `track` a secas se los manda a todos. La compra de una página
 * aparecería en la cuenta de la otra.
 */
export function evento(pixelId, nombre, datos = undefined) {
  if (!iniciarPixel(pixelId)) {
    return false;
  }

  window.fbq('trackSingle', String(pixelId).trim(), nombre, datos);

  return true;
}

/** La vista de una pantalla. */
export function vista(pixelId) {
  return evento(pixelId, 'PageView');
}

/** Sólo para los tests: olvida lo declarado. */
export function olvidarPixeles() {
  declarados.clear();
}
