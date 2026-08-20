/**
 * Colores derivados de la paleta de la página.
 *
 * Nada de lo que se dibuja tiene color propio: la página elige fondo y
 * tipografía, y todo sale de ahí. Una tarjeta con un color clavado en el
 * código es un bloque ajeno sobre la página, y si además hereda el texto,
 * ilegible: es lo que pasaba con las tarjetas blancas y la tipografía clara.
 *
 * La tarjeta no se configura aparte. Acompaña al fondo elegido y se delimita
 * con una diferencia mínima de tono y un borde tenue, no cambiando de color.
 */

/** Cuánto se corre la tarjeta del fondo de la página. Lo justo para verla. */
const TONO_TARJETA = 0.06;

/** Opacidad del borde que la delimita. */
const ALFA_BORDE = 0.14;

/** Descompone #rgb o #rrggbb. Devuelve null si no se entiende el color. */
export function aRgb(color) {
  const texto = typeof color === 'string' ? color.trim() : '';

  const corto = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(texto);
  if (corto) {
    return [1, 2, 3].map((i) => parseInt(corto[i] + corto[i], 16));
  }

  const largo = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(texto);
  if (largo) {
    return [1, 2, 3].map((i) => parseInt(largo[i], 16));
  }

  return null;
}

/** Mezcla dos colores y devuelve uno opaco. `proporcion` es cuánto del segundo. */
export function mezclar(base, encima, proporcion) {
  const a = aRgb(base);
  const b = aRgb(encima);

  if (!a || !b) return null;

  const canal = (i) => {
    const v = Math.round(a[i] * (1 - proporcion) + b[i] * proporcion);

    return Math.min(255, Math.max(0, v)).toString(16).padStart(2, '0');
  };

  return `#${canal(0)}${canal(1)}${canal(2)}`;
}

/** El mismo color con opacidad, para bordes y velos. */
export function conAlfa(color, alfa) {
  const rgb = aRgb(color);

  return rgb ? `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${alfa})` : null;
}

/**
 * Color de una tarjeta apoyada sobre el fondo de la página.
 *
 * Es el fondo elegido corrido apenas hacia la tipografía: sobre fondo oscuro
 * queda un poco más claro y sobre fondo claro un poco más oscuro, sin que
 * haga falta saber cuál es cuál. La tarjeta se distingue del fondo pero sigue
 * siendo el color que eligió la persona, no uno nuevo.
 */
export function superficie(fondo, texto) {
  return mezclar(fondo, texto, TONO_TARJETA);
}

/** Luminancia relativa de la WCAG: cuánta luz emite el color, de 0 a 1. */
function luminancia([r, g, b]) {
  const canal = (v) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };

  return 0.2126 * canal(r) + 0.7152 * canal(g) + 0.0722 * canal(b);
}

/** Contraste entre dos colores, de 1 (idénticos) a 21 (negro contra blanco). */
export function contraste(unColor, otroColor) {
  const a = aRgb(unColor);
  const b = aRgb(otroColor);

  if (!a || !b) return null;

  const la = luminancia(a);
  const lb = luminancia(b);

  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/**
 * Color para escribir sobre un fondo que elegimos nosotros.
 *
 * Se usa sólo donde el fondo no es el de la página sino uno propio del
 * control —el botón de compra, que se pinta con el color de acento—. Ahí la
 * tipografía no la eligió nadie: la elegimos nosotros, y tiene que leerse.
 * Sobre las superficies que acompañan al fondo de la página no hace falta,
 * porque ahí vale el color que eligió la persona.
 */
export function textoSobre(fondo, preferido = '#ffffff') {
  // 3:1 y no 4.5:1 porque esto se aplica a etiquetas de botón, que son
  // grandes y en negrita: es el umbral que la WCAG pide para ese tamaño.
  // Con 4.5 el azul de acento por defecto daba vuelta la etiqueta a negro,
  // que se lee bien pero no es lo que nadie eligió.
  const suficiente = contraste(fondo, preferido);

  if (suficiente !== null && suficiente >= 3) return preferido;

  const contraNegro = contraste(fondo, '#000000');

  if (contraNegro === null) return preferido;

  return contraNegro >= contraste(fondo, '#ffffff') ? '#000000' : '#ffffff';
}

/** Borde tenue que delimita la tarjeta sin agregar un color a la paleta. */
export function borde(texto) {
  return conAlfa(texto, ALFA_BORDE);
}
