import React from 'react';
import { borde } from '../utils/colores';

/**
 * Cómo se ve cada plantilla, en chiquito.
 *
 * No es una captura: se dibuja con los colores que tiene puesta la página, así
 * que además de la disposición se ve la paleta elegida, y no hay imágenes que
 * queden viejas cuando una plantilla cambia.
 *
 * Todas llevan la barra del acento bajo el encabezado, que es lo que las cinco
 * plantillas dibujan desde que cada color tiene un rol.
 *
 * Lo que distingue a las cinco es dónde va cada cosa —una columna o dos, el
 * encabezado centrado o al costado, bloques anchos o filas apretadas—, y eso
 * es lo que reproduce cada miniatura.
 */

/** Una línea de texto simulada. */
const Linea = ({ ancho = '100%', alto = 3, color, opacidad = 0.75, margen = 0 }) => (
  <div
    style={{
      width: ancho,
      height: alto,
      marginTop: margen,
      borderRadius: 1,
      backgroundColor: color,
      opacity: opacidad,
      // Dentro de una columna flex, sin esto las líneas se aplastan a cero en
      // cuanto el dibujo llena el alto.
      flexShrink: 0,
    }}
  />
);

function Minimal({ fondo, texto, acento }) {
  return (
    <div className="w-full h-full flex flex-col items-center gap-1.5 p-2" style={{ backgroundColor: fondo }}>
      <div className="rounded-full flex-shrink-0" style={{ width: 14, height: 14, backgroundColor: acento }} />
      <Linea ancho="55%" color={texto} />
      <Linea ancho="35%" color={texto} opacidad={0.4} />
      <div className="h-[3px] w-6 rounded-full flex-shrink-0" style={{ backgroundColor: acento }} />
      {/* Bloques anchos, uno debajo del otro y con la imagen arriba de todo. */}
      <div className="w-full mt-1 space-y-1.5">
        <div className="w-full" style={{ border: `1px solid ${texto}`, opacity: 0.85 }}>
          <div style={{ height: 12, backgroundColor: acento, opacity: 0.5 }} />
          <div className="px-1.5 py-1">
            <Linea ancho="70%" color={texto} />
          </div>
        </div>
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="px-1.5 py-1.5" style={{ border: `1px solid ${texto}`, opacity: 0.85 }}>
            <Linea ancho={i % 2 ? '45%' : '60%'} color={texto} />
          </div>
        ))}
      </div>
    </div>
  );
}

function Cards({ fondo, texto, acento }) {
  return (
    <div className="w-full h-full flex flex-col items-center gap-1.5 p-2" style={{ backgroundColor: fondo }}>
      <div className="rounded-full flex-shrink-0" style={{ width: 18, height: 18, backgroundColor: acento }} />
      <Linea ancho="60%" alto={4} color={texto} />
      <Linea ancho="40%" color={texto} opacidad={0.4} />
      <div className="h-[3px] w-6 rounded-full flex-shrink-0" style={{ backgroundColor: acento }} />
      {/* Tarjetas rellenas con sombra. Los links ocupan la fila entera; las
          fotos y los eventos van de a dos, que es lo que la distingue. */}
      <div className="w-full mt-1 space-y-1.5">
        <div
          className="rounded flex items-center gap-1.5 p-1"
          style={{ backgroundColor: texto, opacity: 0.12, height: 18 }}
        >
          <div className="rounded flex-shrink-0" style={{ width: 12, height: 12, backgroundColor: fondo }} />
          <div className="flex-1">
            <Linea ancho="70%" alto={3} color={fondo} opacidad={1} />
            <Linea ancho="45%" alto={2} color={fondo} opacidad={0.7} margen={2} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-1.5">
          {[0, 1].map((i) => (
            <div key={i} className="rounded p-1" style={{ backgroundColor: texto, opacity: 0.12 }}>
              {/* La foto es vertical, como en la plantilla de verdad. */}
              <div className="w-full rounded" style={{ aspectRatio: '1080 / 1350', backgroundColor: fondo }} />
              <Linea ancho="80%" alto={2} color={fondo} opacidad={1} margen={3} />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function Modern({ fondo, texto, acento }) {
  return (
    <div className="w-full h-full flex flex-col gap-1.5 p-2" style={{ backgroundColor: fondo }}>
      {/* La única que arranca con una portada ancha en vez de un avatar
          redondo, y la única con el texto alineado a la izquierda. */}
      <div className="rounded-lg w-full flex-shrink-0" style={{ height: 26, backgroundColor: acento }} />
      <Linea ancho="70%" alto={5} color={texto} />
      <Linea ancho="90%" color={texto} opacidad={0.4} />
      <div className="h-[3px] w-6 rounded-full flex-shrink-0 self-start" style={{ backgroundColor: acento }} />
      <div className="w-full mt-1 space-y-1.5">
        {[0, 1, 2, 3].map((i) => (
          <div
            key={i}
            className="rounded flex items-center gap-1 px-1"
            style={{ border: `1px solid ${texto}`, opacity: 0.85, height: 16 }}
          >
            <div className="rounded flex-shrink-0" style={{ width: 9, height: 9, backgroundColor: acento }} />
            <Linea ancho={i % 2 ? '45%' : '65%'} alto={4} color={texto} />
          </div>
        ))}
      </div>
    </div>
  );
}

function Condensed({ fondo, texto, acento }) {
  return (
    <div className="w-full h-full flex flex-col items-center gap-1 p-2" style={{ backgroundColor: fondo }}>
      <div className="rounded-full flex-shrink-0" style={{ width: 12, height: 12, backgroundColor: acento }} />
      <Linea ancho="45%" color={texto} />
      <div className="h-[3px] w-6 rounded-full flex-shrink-0" style={{ backgroundColor: acento }} />
      {/* Píldoras de ancho completo: miniatura redonda a la izquierda y el
          título centrado respecto de toda la píldora. */}
      <div className="w-full mt-1 space-y-1.5">
        {[0, 1, 2, 3, 4].map((i) => (
          <div
            key={i}
            className="relative flex items-center justify-center rounded-full"
            style={{ height: 16, backgroundColor: texto, opacity: 0.12 }}
          >
            <div
              className="absolute left-[3px] rounded-full"
              style={{ width: 10, height: 10, backgroundColor: fondo }}
            />
            <Linea ancho={i % 2 ? '40%' : '55%'} alto={3} color={fondo} opacidad={1} />
          </div>
        ))}
      </div>
    </div>
  );
}

function Afiches({ fondo, texto, acento }) {
  /** Manchas dentro del afiche: sin esto el marco se lee como un bloque liso. */
  const mancha = (estilo) => (
    <div className="absolute" style={{ backgroundColor: texto, ...estilo }} />
  );

  return (
    <div className="w-full h-full flex flex-col items-center gap-1 p-2" style={{ backgroundColor: fondo }}>
      <div className="rounded-full flex-shrink-0" style={{ width: 12, height: 12, backgroundColor: acento }} />
      <Linea ancho="55%" alto={3} color={texto} />
      <div className="h-[3px] w-6 rounded-full flex-shrink-0" style={{ backgroundColor: acento }} />

      {/* Una sola ficha, grande: el afiche ocupa la tarjeta entera, enmarcado,
          con el título abajo sobre el marco. De a dos se leería como la
          plantilla de tarjetas, que es justo de la que hay que distinguirla. */}
      <div className="w-full mt-1 rounded p-[3px] flex-shrink-0" style={{ backgroundColor: acento }}>
        <div
          className="relative w-full rounded-sm overflow-hidden"
          style={{ aspectRatio: '1080 / 1350', backgroundColor: fondo }}
        >
          {mancha({ left: 0, right: 0, bottom: 0, height: '38%', opacity: 0.35 })}
          {mancha({ left: '12%', bottom: '30%', width: '30%', height: '42%', opacity: 0.55, borderRadius: 2 })}
          {mancha({ right: '14%', bottom: '30%', width: '22%', height: '26%', opacity: 0.28, borderRadius: 2 })}
          {mancha({ left: '18%', top: '12%', width: '46%', height: 3, opacity: 0.5 })}
          {mancha({ left: '18%', top: '22%', width: '30%', height: 3, opacity: 0.35 })}
        </div>
        <div className="flex justify-center pt-[3px] pb-[1px]">
          <Linea ancho="65%" alto={3} color={fondo} opacidad={1} />
        </div>
      </div>

      {/* Asoma la siguiente: son una debajo de la otra. */}
      <div className="w-full rounded-t h-2 flex-shrink-0" style={{ backgroundColor: acento }} />
    </div>
  );
}

const DIBUJOS = {
  minimal: Minimal,
  cards: Cards,
  modern: Modern,
  condensed: Condensed,
  afiches: Afiches,
};

function MiniaturaPlantilla({ plantilla, page }) {
  const Dibujo = DIBUJOS[plantilla] || Minimal;

  // Los mismos valores por defecto que usan las plantillas de verdad, para que
  // la miniatura no muestre una paleta que la página no tiene.
  const fondo = page?.background_color || '#ffffff';
  const texto = page?.text_color || '#000000';
  const acento = page?.primary_color || '#3b82f6';

  return (
    <div
      className="w-full overflow-hidden rounded border border-gray-700 p-[3px]"
      style={{ aspectRatio: '3 / 4', backgroundColor: fondo }}
      data-plantilla={plantilla}
      aria-hidden="true"
    >
      {/* En una pantalla grande la página es un recuadro apoyado sobre su
          propio color de fondo, no el ancho completo de la ventana. */}
      <div
        className="w-full h-full overflow-hidden rounded-[5px] border"
        style={{ borderColor: borde(texto) || 'transparent' }}
      >
        <Dibujo fondo={fondo} texto={texto} acento={acento} />
      </div>
    </div>
  );
}

export default MiniaturaPlantilla;
