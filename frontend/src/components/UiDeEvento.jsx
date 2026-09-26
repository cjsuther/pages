import React, { createContext, useContext } from 'react';

/**
 * Las piezas de las pantallas que se abren con un link: la puerta y el
 * reporte de venta.
 *
 * Son las mismas formas que el resto del sitio —tarjeta redondeada, botón
 * píldora, rótulo en versalitas— pero pintadas con los colores de la página
 * del evento, que es de donde sale que la pantalla sea clara u oscura. Las de
 * ui.jsx no servían para esto: tienen el blanco y el verde escritos en las
 * clases, y acá los colores recién se conocen cuando contesta el servidor.
 *
 * El tema viaja por contexto y no por prop: son pantallas de una sola pieza y
 * pasarlo a mano por cada tarjeta y cada botón sólo agregaba ruido.
 */

const Contexto = createContext(null);

export function ProveedorDeTema({ tema, children }) {
  return <Contexto.Provider value={tema}>{children}</Contexto.Provider>;
}

export function useTema() {
  return useContext(Contexto);
}

/** El fondo de la pantalla entera. */
export function Fondo({ className = '', children }) {
  const tema = useTema();

  return (
    <div
      className={`min-h-screen ${className}`}
      style={{ backgroundColor: tema.fondo, color: tema.texto }}
    >
      {children}
    </div>
  );
}

export function Tarjeta({ className = '', children, ...props }) {
  const tema = useTema();

  return (
    <div
      className={`rounded-2xl border ${className}`}
      style={{ backgroundColor: tema.tarjeta, borderColor: tema.borde }}
      {...props}
    >
      {children}
    </div>
  );
}

const TAMANOS = {
  sm: 'px-3 py-1.5 text-sm gap-1.5',
  md: 'px-5 py-2.5 text-sm gap-2',
  lg: 'px-7 py-3.5 text-base gap-2.5',
  bloque: 'w-full px-6 py-4 text-base gap-2.5',
};

/**
 * `primario` va relleno con el color de botones de la página; `secundario`
 * es contorno; `fantasma` no tiene caja hasta que se lo toca. Es la misma
 * escala que la del sitio.
 */
export function Boton({
  variante = 'primario', tamano = 'md', className = '', children, ...props
}) {
  const tema = useTema();

  const estilos = {
    primario: { backgroundColor: tema.boton, color: tema.textoBoton, borderColor: 'transparent' },
    secundario: { backgroundColor: 'transparent', color: tema.texto, borderColor: tema.borde },
    fantasma: { backgroundColor: 'transparent', color: tema.suave, borderColor: 'transparent' },
  };

  return (
    <button
      className={`inline-flex items-center justify-center rounded-full font-semibold border
        transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed
        ${TAMANOS[tamano] || TAMANOS.md} ${className}`}
      style={estilos[variante] || estilos.primario}
      {...props}
    >
      {children}
    </button>
  );
}

/** Rótulo chico en versalitas, para nombrar una zona sin competir con el título. */
export function Rotulo({ className = '', children }) {
  const tema = useTema();

  return (
    <p
      className={`text-xs font-semibold uppercase tracking-[0.14em] ${className}`}
      style={{ color: tema.tenue }}
    >
      {children}
    </p>
  );
}

/** Un dato con su etiqueta arriba, como los del panel de ventas. */
export function Dato({ etiqueta, valor }) {
  const tema = useTema();

  return (
    <div>
      <dt className="text-sm" style={{ color: tema.tenue }}>{etiqueta}</dt>
      <dd className="text-lg font-bold">{valor}</dd>
    </div>
  );
}
