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

/** Borde tenue que delimita la tarjeta sin agregar un color a la paleta. */
export function borde(texto) {
  return conAlfa(texto, ALFA_BORDE);
}
