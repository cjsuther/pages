import React from 'react';
import { textoSobre } from '../utils/colores';
import { opcionDisponible } from '../utils/productos';

/**
 * Un selector por característica de un producto físico (#42), con las
 * opciones como botones-píldora. Una opción que con lo ya elegido sólo lleva
 * a combinaciones agotadas dice "Agotado" y no se puede elegir. Tocar la
 * opción elegida la suelta, para poder probar otra combinación.
 *
 * `seleccion` es { [caracteristicaId]: opcionId }.
 */
function SelectorDeCaracteristicas({ producto, seleccion, onCambiar, color }) {
  return (
    <div className="space-y-4">
      {producto.caracteristicas.map((c) => (
        <div key={c.id} role="radiogroup" aria-label={c.nombre}>
          <p className="text-sm font-semibold mb-2">{c.nombre}</p>
          <div className="flex flex-wrap gap-2">
            {c.opciones.map((o) => {
              const elegida = seleccion[c.id] === o.id;
              const disponible = opcionDisponible(producto, c.id, o.id, seleccion);
              return (
                <button
                  key={o.id}
                  type="button"
                  role="radio"
                  aria-checked={elegida}
                  disabled={!disponible}
                  onClick={() => {
                    const nueva = { ...seleccion };
                    if (elegida) delete nueva[c.id];
                    else nueva[c.id] = o.id;
                    onCambiar(nueva);
                  }}
                  className={`px-4 py-1.5 rounded-full border text-sm font-semibold transition ${
                    disponible ? 'hover:opacity-80' : 'opacity-40 line-through cursor-not-allowed'
                  }`}
                  style={elegida
                    ? { backgroundColor: color, borderColor: color, color: textoSobre(color) }
                    : { borderColor: 'currentColor' }}
                >
                  {o.nombre}
                  {!disponible && <span className="sr-only"> (agotado)</span>}
                  {!disponible && <span aria-hidden="true" className="ml-1 text-xs no-underline">· Agotado</span>}
                </button>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}

export default SelectorDeCaracteristicas;
