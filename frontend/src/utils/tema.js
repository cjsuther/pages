/**
 * Los colores con los que se pintan las pantallas que se abren con un link:
 * la puerta y el reporte de venta.
 *
 * No tienen la barra de navegación ni el marco del sitio, así que el diseño
 * es el mismo —las mismas formas, la misma tipografía, los mismos botones—
 * pero los colores salen de la página del evento. De ahí viene que sean
 * claras u oscuras: es lo que ya eligió quien armó la página, y la puerta de
 * un show de noche no tiene por qué encandilar a nadie con una pantalla
 * blanca.
 *
 * Todo lo que hace falta para pintar sale de acá, para que las dos pantallas
 * no resuelvan lo mismo cada una a su manera.
 */

import { paleta, conAlfa, textoSobre } from './colores';

export function temaDeEvento(colores) {
  const base = paleta(colores || {});

  return {
    fondo: base.fondo,
    texto: base.texto,
    titulo: base.titulo,
    tarjeta: base.tarjeta,
    borde: base.bordeTarjeta,
    acento: base.acento,
    boton: base.boton,
    /** Sobre el botón: blanco o negro, el que se lea. */
    textoBoton: textoSobre(base.boton),
    /** Texto secundario y terciario, sin sumar colores a la paleta. */
    suave: conAlfa(base.texto, 0.72),
    tenue: conAlfa(base.texto, 0.5),
  };
}
