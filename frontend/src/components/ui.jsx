import React from 'react';
import { Link } from 'react-router-dom';

/**
 * Las piezas visuales que se repiten en todo el sitio.
 *
 * Existen para que "un botón primario" sea una sola decisión y no treinta
 * copias de la misma lista de clases, que es como se desincroniza un diseño.
 * Todo sale de los colores de tailwind.config.js: blanco de fondo, tipografía
 * negra y el verde de la marca como único acento.
 */

// --------------------------------------------------------------------- botón

const VARIANTES_BOTON = {
  // Relleno verde. Es la acción principal de la pantalla; va una sola por vista.
  primario: 'bg-verde text-verde-tinta hover:bg-verde-oscuro hover:text-white border border-transparent',
  // Contorno negro. Acciones secundarias que igual son importantes.
  secundario: 'bg-white text-tinta border border-borde-fuerte hover:border-tinta',
  // Sin caja hasta que se lo toca. Para acciones de tercer orden.
  fantasma: 'bg-transparent text-tinta-media border border-transparent hover:text-tinta hover:bg-papel-hueso',
  // Destructivo. Rojo sólo acá: en el resto del sitio el color es información.
  peligro: 'bg-white text-red-700 border border-red-200 hover:bg-red-50 hover:border-red-400',
};

const TAMANOS_BOTON = {
  sm: 'px-3 py-1.5 text-sm gap-1.5',
  md: 'px-5 py-2.5 text-sm gap-2',
  lg: 'px-7 py-3.5 text-base gap-2.5',
};

export function Boton({
  variante = 'primario',
  tamano = 'md',
  a = null,
  href = null,
  className = '',
  children,
  ...props
}) {
  const clases = [
    'inline-flex items-center justify-center rounded-full font-semibold',
    'transition-colors disabled:opacity-40 disabled:cursor-not-allowed',
    VARIANTES_BOTON[variante] || VARIANTES_BOTON.primario,
    TAMANOS_BOTON[tamano] || TAMANOS_BOTON.md,
    className,
  ].join(' ');

  if (a) {
    return <Link to={a} className={clases} {...props}>{children}</Link>;
  }

  if (href) {
    return <a href={href} className={clases} {...props}>{children}</a>;
  }

  return <button className={clases} {...props}>{children}</button>;
}

// ------------------------------------------------------------------- tarjeta

/**
 * Caja de contenido. `destacada` la pinta de verde claro: se reserva para lo
 * que rezon.ar quiere que se mire primero, no para decorar.
 */
export function Tarjeta({ destacada = false, className = '', children, ...props }) {
  return (
    <div
      className={`rounded-2xl border ${
        destacada ? 'bg-verde-claro border-verde-medio' : 'bg-white border-borde'
      } ${className}`}
      {...props}
    >
      {children}
    </div>
  );
}

// ----------------------------------------------------------------- formulario

export function Etiqueta({ className = '', children, ...props }) {
  return (
    <label className={`block text-sm font-semibold text-tinta mb-1.5 ${className}`} {...props}>
      {children}
    </label>
  );
}

const CLASES_CAMPO =
  'w-full px-4 py-3 bg-white border border-borde-fuerte rounded-xl text-tinta ' +
  'placeholder-tinta-suave transition-colors focus:border-verde-oscuro focus:outline-none ' +
  'disabled:bg-papel-hueso disabled:text-tinta-suave';

export function Campo({ className = '', ...props }) {
  return <input className={`${CLASES_CAMPO} ${className}`} {...props} />;
}

export function AreaTexto({ className = '', ...props }) {
  return <textarea className={`${CLASES_CAMPO} ${className}`} {...props} />;
}

export function Selector({ className = '', children, ...props }) {
  return (
    <select className={`${CLASES_CAMPO} ${className}`} {...props}>
      {children}
    </select>
  );
}

/**
 * Opción excluyente dibujada como tarjeta clickeable, con el radio real
 * adentro para que siga funcionando con teclado y lectores de pantalla.
 */
export function OpcionRadio({ nombre, elegida, alElegir, titulo, detalle }) {
  return (
    <label
      className={`flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors ${
        elegida ? 'border-verde bg-verde-claro' : 'border-borde hover:border-borde-fuerte'
      }`}
    >
      <input
        type="radio"
        name={nombre}
        checked={elegida}
        onChange={alElegir}
        className="mt-1 accent-[#6FBE44]"
      />
      <span className="block">
        <span className="block font-semibold text-tinta text-sm">{titulo}</span>
        {detalle && <span className="block text-sm text-tinta-media mt-0.5">{detalle}</span>}
      </span>
    </label>
  );
}

// -------------------------------------------------------------------- títulos

export function TituloSeccion({ titulo, bajada = null, acciones = null, className = '' }) {
  return (
    <div className={`flex flex-wrap items-end justify-between gap-4 mb-6 ${className}`}>
      <div className="min-w-0">
        <h2 className="text-2xl sm:text-3xl font-bold tracking-tight text-tinta text-balance">{titulo}</h2>
        {bajada && <p className="text-tinta-media mt-1.5 max-w-2xl">{bajada}</p>}
      </div>
      {acciones && <div className="flex flex-wrap items-center gap-2">{acciones}</div>}
    </div>
  );
}

/** Rótulo chico en versalitas. Nombra una zona sin competir con el título. */
export function Rotulo({ className = '', children }) {
  return (
    <span className={`text-xs font-semibold uppercase tracking-[0.14em] text-tinta-suave ${className}`}>
      {children}
    </span>
  );
}

// --------------------------------------------------------------------- chips

export function Chip({ tono = 'neutro', className = '', children }) {
  const tonos = {
    neutro: 'bg-papel-hueso text-tinta-media border-borde',
    verde: 'bg-verde-claro text-verde-oscuro border-verde-medio',
    solido: 'bg-verde text-verde-tinta border-transparent',
  };

  return (
    <span
      className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-semibold ${
        tonos[tono] || tonos.neutro
      } ${className}`}
    >
      {children}
    </span>
  );
}

// -------------------------------------------------------------------- avisos

export function Aviso({ tipo = 'info', className = '', children }) {
  const tipos = {
    info: 'bg-papel-hueso border-borde text-tinta-media',
    ok: 'bg-verde-claro border-verde-medio text-verde-oscuro',
    error: 'bg-red-50 border-red-200 text-red-800',
    atencion: 'bg-amber-50 border-amber-200 text-amber-900',
  };

  return (
    <div className={`px-4 py-3 rounded-xl border text-sm ${tipos[tipo] || tipos.info} ${className}`}>
      {children}
    </div>
  );
}

// ------------------------------------------------------------ estados vacíos

export function Vacio({ icono: Icono = null, titulo, detalle = null, accion = null }) {
  return (
    <div className="text-center py-16 px-6">
      {Icono && (
        <span className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-verde-claro text-verde-oscuro mb-4">
          <Icono className="w-6 h-6" />
        </span>
      )}
      <p className="text-lg font-semibold text-tinta">{titulo}</p>
      {detalle && <p className="text-tinta-media mt-1.5 max-w-md mx-auto">{detalle}</p>}
      {accion && <div className="mt-6 flex justify-center">{accion}</div>}
    </div>
  );
}

export function Cargando({ texto = 'Cargando...' }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 gap-3">
      <div className="w-8 h-8 rounded-full border-2 border-borde border-t-verde animate-spin" />
      <p className="text-tinta-suave text-sm">{texto}</p>
    </div>
  );
}

// -------------------------------------------------------------------- avatar

const TAMANOS_AVATAR = { sm: 'w-8 h-8 text-xs', md: 'w-12 h-12 text-base', lg: 'w-14 h-14 text-xl' };

/**
 * La foto de una página, con la inicial de reserva.
 *
 * La inicial no es sólo para las páginas sin foto: también aparece cuando la
 * imagen existe pero no carga —una URL vieja, una subida que se perdió—, que
 * antes dejaba un círculo vacío sin explicación.
 */
export function Avatar({ src, nombre = '?', tamano = 'md', className = '' }) {
  const [fallo, setFalló] = React.useState(false);

  React.useEffect(() => { setFalló(false); }, [src]);

  const base = `${TAMANOS_AVATAR[tamano] || TAMANOS_AVATAR.md} rounded-full flex-shrink-0 ${className}`;

  if (src && !fallo) {
    return (
      <img
        src={src}
        alt=""
        loading="lazy"
        onError={() => setFalló(true)}
        className={`${base} object-cover border border-borde`}
      />
    );
  }

  return (
    <span className={`${base} bg-verde-claro text-verde-oscuro font-bold flex items-center justify-center border border-verde-medio`}>
      {(nombre || '?').trim().charAt(0).toUpperCase()}
    </span>
  );
}

// -------------------------------------------------------------------- modal

/**
 * Diálogo centrado sobre un velo. Cierra con Escape y con click afuera: un
 * modal del que sólo se sale con el botón correcto es una trampa.
 */
export function Modal({ abierto, alCerrar, titulo = null, ancho = 'max-w-lg', children }) {
  React.useEffect(() => {
    if (!abierto) return undefined;

    const alTeclear = (e) => {
      if (e.key === 'Escape') alCerrar();
    };

    document.addEventListener('keydown', alTeclear);
    return () => document.removeEventListener('keydown', alTeclear);
  }, [abierto, alCerrar]);

  if (!abierto) return null;

  return (
    <div
      className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
      onClick={alCerrar}
    >
      <div
        role="dialog"
        aria-modal="true"
        className={`bg-white border border-borde rounded-2xl w-full ${ancho} p-6 sm:p-8 max-h-[90vh] overflow-y-auto`}
        onClick={(e) => e.stopPropagation()}
      >
        {titulo && <h3 className="text-xl font-bold text-tinta mb-5">{titulo}</h3>}
        {children}
      </div>
    </div>
  );
}
