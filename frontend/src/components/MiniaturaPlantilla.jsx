import React from 'react';

/**
 * Cómo se ve cada plantilla, en chiquito.
 *
 * No es una captura: se dibuja con los colores que tiene puesta la página, así
 * que además de la disposición se ve la paleta elegida, y no hay imágenes que
 * queden viejas cuando una plantilla cambia.
 *
 * Lo que distingue a las cuatro es dónde va cada cosa —una columna o dos, el
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
      {/* Tarjetas rellenas con sombra, una debajo de la otra. */}
      <div className="w-full mt-1 space-y-1.5">
        {[0, 1, 2, 3].map((i) => (
          <div
            key={i}
            className="rounded flex items-center gap-1.5 p-1"
            style={{ backgroundColor: texto, opacity: 0.12, height: 18 }}
          >
            <div className="rounded flex-shrink-0" style={{ width: 12, height: 12, backgroundColor: fondo }} />
            <div className="flex-1">
              <Linea ancho="70%" alto={3} color={fondo} opacidad={1} />
              <Linea ancho="45%" alto={2} color={fondo} opacidad={0.7} margen={2} />
            </div>
          </div>
        ))}
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
      <div className="rounded-full flex-shrink-0" style={{ width: 10, height: 10, backgroundColor: acento }} />
      <Linea ancho="45%" color={texto} />
      {/* Filas apretadas, con la miniatura al lado de dos líneas de texto. */}
      <div className="w-full mt-1 space-y-1">
        {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => (
          <div key={i} className="flex items-center gap-1">
            <div className="rounded flex-shrink-0" style={{ width: 9, height: 9, backgroundColor: acento, opacity: 0.7 }} />
            <div className="flex-1">
              <Linea ancho="80%" alto={2} color={texto} />
              <Linea ancho="55%" alto={2} color={texto} opacidad={0.4} margen={2} />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

const DIBUJOS = {
  minimal: Minimal,
  cards: Cards,
  modern: Modern,
  condensed: Condensed,
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
      className="w-full overflow-hidden rounded border border-gray-700"
      style={{ aspectRatio: '3 / 4' }}
      data-plantilla={plantilla}
      aria-hidden="true"
    >
      <Dibujo fondo={fondo} texto={texto} acento={acento} />
    </div>
  );
}

export default MiniaturaPlantilla;
