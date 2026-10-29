import React from 'react';

/**
 * Activo / Inactivo de un producto (#42). Es la columna "oculto" de siempre,
 * dicha al revés: inactivo no se muestra en la página ni en su link y no
 * acepta pedidos; lo ya vendido sigue funcionando.
 *
 * Se usa en el editor del producto y en la lista del grupo, para activarlo o
 * desactivarlo sin entrar.
 */
function InterruptorActivo({ activo, onChange, etiqueta, disabled = false, chico = false }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={activo}
      aria-label={etiqueta}
      disabled={disabled}
      onClick={() => onChange(!activo)}
      className={`inline-flex items-center gap-2 font-semibold transition disabled:opacity-50 ${chico ? 'text-xs' : 'text-sm'}`}
    >
      <span
        aria-hidden="true"
        className={`relative inline-flex shrink-0 rounded-full transition-colors ${chico ? 'h-4 w-7' : 'h-5 w-9'} ${
          activo ? 'bg-verde-oscuro' : 'bg-borde-fuerte'
        }`}
      >
        <span
          className={`absolute top-0.5 left-0.5 rounded-full bg-white shadow transition-transform ${chico ? 'h-3 w-3' : 'h-4 w-4'} ${
            activo ? (chico ? 'translate-x-3' : 'translate-x-4') : 'translate-x-0'
          }`}
        />
      </span>
      <span className={activo ? 'text-verde-oscuro' : 'text-tinta-suave'}>{activo ? 'Activo' : 'Inactivo'}</span>
    </button>
  );
}

export default InterruptorActivo;
