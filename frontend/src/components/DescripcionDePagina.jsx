import React, { useState, useRef, useEffect, useCallback } from 'react';

/**
 * La descripción de una página, recortada a dos renglones.
 *
 * Una descripción larga empujaba los links y las fechas fuera de la pantalla:
 * quien entra por el link de una bio viene a ver qué hay, no a leer una
 * biografía. Se muestran dos renglones y el resto se despliega si le interesa.
 *
 * El "ver más" aparece sólo cuando de verdad hay algo escondido. Ofrecerlo
 * sobre un texto que ya se lee entero es prometer algo que no pasa cuando se
 * toca, y además ensucia la mayoría de las páginas, que tienen una línea.
 *
 * Va con los colores de la página, no con los de Rezonar: esta pantalla es del
 * artista.
 */
function DescripcionDePagina({ texto, className = '', color, centrado = true }) {
  const parrafo = useRef(null);
  const [abierta, setAbierta] = useState(false);
  const [hayMas, setHayMas] = useState(false);

  /**
   * Sólo se puede medir plegado: ahí el recorte hace que el alto visible sea
   * menor que el del contenido. Desplegado los dos coinciden y la respuesta
   * sería siempre "no hay más", así que no se toca lo que ya se sabe.
   */
  const medir = useCallback(() => {
    const el = parrafo.current;

    if (!el || abierta) return;

    setHayMas(el.scrollHeight > el.clientHeight + 1);
  }, [abierta]);

  useEffect(() => {
    medir();

    // El ancho del texto cambia al girar el teléfono, y el alto cambia cuando
    // termina de bajar la tipografía: en los dos casos la respuesta puede ser
    // otra. ResizeObserver no existe en el entorno de los tests.
    const observador = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(medir) : null;

    if (observador && parrafo.current) observador.observe(parrafo.current);
    if (typeof window !== 'undefined') window.addEventListener('resize', medir);
    if (typeof document !== 'undefined' && document.fonts) {
      document.fonts.ready.then(medir).catch(() => {});
    }

    return () => {
      if (observador) observador.disconnect();
      if (typeof window !== 'undefined') window.removeEventListener('resize', medir);
    };
  }, [texto, medir]);

  if (!texto) return null;

  return (
    <div className={centrado ? 'text-center' : ''}>
      <p
        ref={parrafo}
        // Los saltos de línea que escribió la persona se respetan: los tipeó
        // en una caja de texto y esperaba verlos. El recorte a dos renglones
        // sigue andando igual, y el "ver más" también: el alto del contenido
        // se mide contra el visible y `pre-line` no lo altera.
        className={`${className} whitespace-pre-line ${abierta ? '' : 'line-clamp-2'}`}
      >
        {texto}
      </p>

      {hayMas && (
        <button
          type="button"
          onClick={() => setAbierta(!abierta)}
          aria-expanded={abierta}
          className="mt-1 text-sm font-semibold underline underline-offset-2 opacity-70 hover:opacity-100 transition-opacity"
          style={color ? { color } : undefined}
        >
          {abierta ? 'Ver menos' : 'Ver más'}
        </button>
      )}
    </div>
  );
}

export default DescripcionDePagina;
