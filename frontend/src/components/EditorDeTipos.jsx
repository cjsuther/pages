import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { formatearPrecio } from '../utils/entradas';
import { MAX_TIPOS, nuevoTipo } from '../utils/tiposDeEntrada';

const CLASE_CAMPO = 'w-full px-3 py-2 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none';

/**
 * Los tipos de entrada de un evento: nombre, precio y, si hace falta, cupo.
 *
 * Borrar un tipo que ya vendió se puede: cada compra guardó el nombre y el
 * precio que pagó, así que lo vendido no cambia. Lo que deja de existir es la
 * opción de comprarlo.
 *
 * @param {Object<string, number>} vendidas Entradas ya tomadas de cada tipo.
 */
function EditorDeTipos({ tipos, onCambiar, vendidas = {} }) {
  const cambiar = (indice, cambios) => {
    onCambiar(tipos.map((t, i) => (i === indice ? { ...t, ...cambios } : t)));
  };

  const borrar = (indice) => {
    onCambiar(tipos.filter((_, i) => i !== indice));
  };

  return (
    <div className="space-y-3">
      {tipos.map((tipo, i) => {
        const tomadas = vendidas[tipo.id] || 0;

        return (
          <div key={tipo.id} className="border border-borde bg-white p-3">
            <div className="grid grid-cols-2 sm:grid-cols-[2fr_1fr_1fr_auto] gap-3 items-end">
              <div className="col-span-2 sm:col-span-1">
                <label htmlFor={`tipo-nombre-${tipo.id}`} className="block text-xs font-semibold text-tinta mb-1">Nombre</label>
                <input
                  id={`tipo-nombre-${tipo.id}`}
                  type="text"
                  maxLength={60}
                  value={tipo.nombre}
                  placeholder={i === 0 ? 'General' : 'Jubilados, VIP, Anticipada...'}
                  onChange={(e) => cambiar(i, { nombre: e.target.value })}
                  className={CLASE_CAMPO}
                />
              </div>
              <div>
                <label htmlFor={`tipo-precio-${tipo.id}`} className="block text-xs font-semibold text-tinta mb-1">Precio</label>
                <input
                  id={`tipo-precio-${tipo.id}`}
                  type="number"
                  min="0"
                  step="0.01"
                  value={tipo.precio}
                  onChange={(e) => cambiar(i, { precio: e.target.value === '' ? '' : Number(e.target.value) })}
                  className={CLASE_CAMPO}
                />
              </div>
              <div>
                <label htmlFor={`tipo-cupo-${tipo.id}`} className="block text-xs font-semibold text-tinta mb-1">Cupo</label>
                <input
                  id={`tipo-cupo-${tipo.id}`}
                  type="number"
                  min={Math.max(1, tomadas)}
                  value={tipo.cupo === null ? '' : tipo.cupo}
                  placeholder="Sin tope"
                  onChange={(e) => cambiar(i, { cupo: e.target.value === '' ? null : Number(e.target.value) })}
                  className={CLASE_CAMPO}
                />
              </div>
              <button
                type="button"
                onClick={() => borrar(i)}
                disabled={tipos.length <= 1}
                aria-label={`Borrar ${tipo.nombre || 'este tipo'}`}
                title="Borrar"
                className="p-2 text-red-700 disabled:opacity-30 justify-self-end"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            </div>

            <p className="text-xs text-tinta-suave mt-2">
              {Number(tipo.precio) > 0 ? formatearPrecio(tipo.precio) : 'Sin costo'}
              {tomadas > 0 && ` · ${tomadas} ${tomadas === 1 ? 'tomada' : 'tomadas'}: el cupo no puede ser menor`}
            </p>
          </div>
        );
      })}

      {tipos.length < MAX_TIPOS && (
        <button
          type="button"
          onClick={() => onCambiar([...tipos, nuevoTipo(tipos)])}
          className="inline-flex items-center gap-1.5 border border-borde-fuerte px-3 py-2 text-sm font-semibold text-tinta hover:border-tinta"
        >
          <Plus className="w-4 h-4" /> Otro tipo
        </button>
      )}

      <p className="text-xs text-tinta-suave">
        El cupo de un tipo es aparte de la capacidad del evento: sirve para vender, por
        ejemplo, sólo 20 de jubilados. Vacío, el tipo se vende hasta llenar el evento.
      </p>
    </div>
  );
}

export default EditorDeTipos;
