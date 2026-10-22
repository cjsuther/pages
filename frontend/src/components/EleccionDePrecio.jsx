import React from 'react';

/**
 * "Gratis" o "Pago, desde $ ___": el precio de un evento que no vende
 * entradas por Rezonar.
 *
 * Es una elección obligatoria y no un campo opcional: es lo que se muestra en
 * las tarjetas y por lo que se filtra en la home, y un evento sin precio
 * desaparece de esos filtros. Arranca sin marcar cuando no se sabe (un evento
 * nuevo, o uno viejo que nunca lo tuvo), para que nadie lo deje "gratis" sin
 * querer.
 *
 * La elección es { modo: null | 'gratis' | 'pago', monto: string }. Con
 * eleccionDesdePrecio() se arma a partir de precio_desde, y con
 * precioDeEleccion() se vuelve a lo que se guarda.
 */
function EleccionDePrecio({ id = 'precio', eleccion, onChange, error = null, sinPrecioGuardado = false }) {
  const elegir = (modo) => onChange({ ...eleccion, modo });
  const cambiarMonto = (monto) => onChange({ modo: 'pago', monto });

  return (
    <fieldset aria-describedby={`${id}-ayuda`}>
      <legend className="block text-sm font-semibold text-tinta mb-1.5">
        Precio de las entradas<span className="text-red-500">*</span>
      </legend>

      {sinPrecioGuardado && (
        <p className="text-sm text-tinta bg-papel-hueso border border-borde rounded-xl px-4 py-3 mb-3">
          Este evento todavía no dice si es gratis o pago. Elegí una opción para poder guardarlo.
        </p>
      )}

      <div className="flex flex-col gap-2">
        <label htmlFor={`${id}-gratis`} className="flex items-center gap-3 cursor-pointer">
          <input
            id={`${id}-gratis`}
            type="radio"
            name={`${id}-modo`}
            checked={eleccion.modo === 'gratis'}
            onChange={() => elegir('gratis')}
            className="h-4 w-4 accent-verde-oscuro"
          />
          <span className="text-tinta">Gratis</span>
        </label>

        <div className="flex flex-wrap items-center gap-3">
          <label htmlFor={`${id}-pago`} className="flex items-center gap-3 cursor-pointer">
            <input
              id={`${id}-pago`}
              type="radio"
              name={`${id}-modo`}
              checked={eleccion.modo === 'pago'}
              onChange={() => elegir('pago')}
              className="h-4 w-4 accent-verde-oscuro"
            />
            <span className="text-tinta">Pago, desde $</span>
          </label>
          <input
            id={`${id}-monto`}
            type="number"
            min="1"
            step="0.01"
            inputMode="decimal"
            aria-label="Precio de la entrada más barata"
            value={eleccion.monto}
            onChange={(e) => cambiarMonto(e.target.value)}
            onFocus={() => eleccion.modo !== 'pago' && elegir('pago')}
            placeholder="15000"
            className="w-40 px-4 py-2 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
          />
        </div>
      </div>

      <p id={`${id}-ayuda`} className="text-xs text-tinta-suave mt-1">
        Si las entradas se venden en otra plataforma, poné el precio más barato.
      </p>

      {error && (
        <p role="alert" className="text-sm text-red-600 mt-1">{error}</p>
      )}
    </fieldset>
  );
}

/** La elección que corresponde a un precio_desde guardado (null, 0 o un monto). */
export function eleccionDesdePrecio(precio) {
  if (precio === null || precio === undefined || precio === '' || Number.isNaN(Number(precio))) {
    return { modo: null, monto: '' };
  }

  const numero = Number(precio);

  return numero <= 0 ? { modo: 'gratis', monto: '' } : { modo: 'pago', monto: String(numero) };
}

/** Lo que falta para poder guardar, o null si está bien. */
export function problemaDeEleccion(eleccion) {
  if (!eleccion || !eleccion.modo) {
    return 'Elegí si el evento es gratis o desde qué precio salen las entradas.';
  }

  if (eleccion.modo === 'pago') {
    const monto = Number(eleccion.monto);

    if (eleccion.monto === '' || Number.isNaN(monto) || monto <= 0) {
      return 'Poné desde cuánto salen las entradas: un monto mayor que 0.';
    }
  }

  return null;
}

/** El precio_desde que se guarda: 0 si es gratis, el monto si es pago. */
export function precioDeEleccion(eleccion) {
  if (problemaDeEleccion(eleccion) !== null) {
    return null;
  }

  return eleccion.modo === 'gratis' ? 0 : Number(eleccion.monto);
}

export default EleccionDePrecio;
