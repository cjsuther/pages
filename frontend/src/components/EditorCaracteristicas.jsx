import React, { useState } from 'react';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Plus, X } from 'lucide-react';
import {
  MAX_CARACTERISTICAS,
  SUGERENCIAS_DE_CARACTERISTICA,
  claveDeFila,
  claveNueva,
  combinaciones,
} from '../utils/productos';

const campo = 'px-3 py-2 rounded-lg bg-white border border-borde-fuerte text-tinta text-sm placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors';
const botonChico = 'p-1 rounded hover:bg-papel-hueso transition disabled:opacity-30 disabled:cursor-not-allowed';

/** Mueve el elemento i de la lista un lugar (-1 arriba, +1 abajo). */
function mover(lista, i, paso) {
  const j = i + paso;
  if (j < 0 || j >= lista.length) return lista;
  const nueva = [...lista];
  [nueva[i], nueva[j]] = [nueva[j], nueva[i]];
  return nueva;
}

/**
 * Las características de un producto físico (talle, color...) y su stock
 * (#42). De 0 a 3, cada una con sus opciones.
 *
 * Sin características, el stock es uno solo (lo pide quien usa esto). Con
 * características, va por combinación en la grilla de abajo; vacío es sin
 * límite. Quitar una opción que ya está en un pedido la desactiva: lo
 * resuelve la API al guardar.
 */
function EditorCaracteristicas({ caracteristicas, onChange, stock, onStockChange }) {
  const cambiar = (i, cambios) => onChange(caracteristicas.map((c, j) => (j === i ? { ...c, ...cambios } : c)));

  const agregar = () => onChange([
    ...caracteristicas,
    { clave: claveNueva(), nombre: '', opciones: [] },
  ]);

  return (
    <div className="space-y-4">
      <div>
        <p className="block text-sm font-semibold text-tinta">Características (opcional)</p>
        <p className="text-xs text-tinta-suave mt-0.5">
          Hasta {MAX_CARACTERISTICAS}, como talle o color. Quien compra elige una opción de cada una. El precio es el mismo para todas.
        </p>
      </div>

      <datalist id="sugerencias-de-caracteristica">
        {SUGERENCIAS_DE_CARACTERISTICA.map((s) => <option key={s} value={s} />)}
      </datalist>

      {caracteristicas.map((c, i) => (
        <Caracteristica
          key={c.clave}
          caracteristica={c}
          numero={i + 1}
          primera={i === 0}
          ultima={i === caracteristicas.length - 1}
          onCambiar={(cambios) => cambiar(i, cambios)}
          onMover={(paso) => onChange(mover(caracteristicas, i, paso))}
          onQuitar={() => onChange(caracteristicas.filter((_, j) => j !== i))}
        />
      ))}

      {caracteristicas.length < MAX_CARACTERISTICAS && (
        <button
          type="button"
          onClick={agregar}
          className="inline-flex items-center gap-1.5 text-sm font-semibold text-verde-oscuro hover:underline"
        >
          <Plus className="w-4 h-4" aria-hidden="true" />
          Agregar característica
        </button>
      )}

      {caracteristicas.some((c) => c.opciones.length > 0) && (
        <GrillaDeStock caracteristicas={caracteristicas} stock={stock} onStockChange={onStockChange} />
      )}
    </div>
  );
}

function Caracteristica({ caracteristica, numero, primera, ultima, onCambiar, onMover, onQuitar }) {
  const [nueva, setNueva] = useState('');
  const { nombre, opciones } = caracteristica;
  const etiqueta = nombre.trim() || `característica ${numero}`;

  const agregarOpcion = () => {
    const texto = nueva.trim();
    if (!texto) return;
    onCambiar({ opciones: [...opciones, { clave: claveNueva(), nombre: texto }] });
    setNueva('');
  };

  const cambiarOpcion = (j, texto) => onCambiar({
    opciones: opciones.map((o, k) => (k === j ? { ...o, nombre: texto } : o)),
  });

  return (
    <fieldset className="border border-borde rounded-xl p-4 space-y-3">
      <legend className="sr-only">{`Característica ${numero}`}</legend>
      <div className="flex items-center gap-2">
        <input
          type="text"
          list="sugerencias-de-caracteristica"
          value={nombre}
          onChange={(e) => onCambiar({ nombre: e.target.value })}
          placeholder="Talle, Color..."
          maxLength={50}
          aria-label={`Nombre de la característica ${numero}`}
          className={`${campo} flex-1 min-w-0`}
        />
        <button type="button" onClick={() => onMover(-1)} disabled={primera} aria-label={`Subir ${etiqueta}`} className={botonChico}>
          <ChevronUp className="w-4 h-4" />
        </button>
        <button type="button" onClick={() => onMover(1)} disabled={ultima} aria-label={`Bajar ${etiqueta}`} className={botonChico}>
          <ChevronDown className="w-4 h-4" />
        </button>
        <button type="button" onClick={onQuitar} aria-label={`Quitar ${etiqueta}`} className={`${botonChico} text-red-700`}>
          <X className="w-4 h-4" />
        </button>
      </div>

      <ul className="flex flex-wrap gap-2" aria-label={`Opciones de ${etiqueta}`}>
        {opciones.map((o, j) => (
          <li key={o.clave} className="inline-flex items-center gap-0.5 rounded-full border border-borde bg-papel-hueso pl-1 pr-1">
            <button
              type="button"
              onClick={() => onCambiar({ opciones: mover(opciones, j, -1) })}
              disabled={j === 0}
              aria-label={`Mover ${o.nombre || 'opción'} antes`}
              className={botonChico}
            >
              <ChevronLeft className="w-3.5 h-3.5" />
            </button>
            <input
              type="text"
              value={o.nombre}
              onChange={(e) => cambiarOpcion(j, e.target.value)}
              maxLength={50}
              aria-label={`Opción ${j + 1} de ${etiqueta}`}
              className="w-16 bg-transparent text-sm text-tinta text-center focus:outline-none"
            />
            <button
              type="button"
              onClick={() => onCambiar({ opciones: mover(opciones, j, 1) })}
              disabled={j === opciones.length - 1}
              aria-label={`Mover ${o.nombre || 'opción'} después`}
              className={botonChico}
            >
              <ChevronRight className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={() => onCambiar({ opciones: opciones.filter((_, k) => k !== j) })}
              aria-label={`Quitar ${o.nombre || 'opción'}`}
              className={`${botonChico} text-red-700`}
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </li>
        ))}
      </ul>

      <div className="flex gap-2">
        <input
          type="text"
          value={nueva}
          onChange={(e) => setNueva(e.target.value)}
          onKeyDown={(e) => {
            // Enter agrega la opción; no manda el formulario entero.
            if (e.key === 'Enter') {
              e.preventDefault();
              agregarOpcion();
            }
          }}
          placeholder="S, M, L..."
          maxLength={50}
          aria-label={`Nueva opción de ${etiqueta}`}
          className={`${campo} flex-1 min-w-0`}
        />
        <button
          type="button"
          onClick={agregarOpcion}
          className="shrink-0 px-3 py-2 rounded-lg border border-borde-fuerte text-sm font-semibold hover:bg-papel-hueso transition"
        >
          Agregar opción
        </button>
      </div>
      <p className="text-xs text-tinta-suave">
        Si quitás una opción que ya está en un pedido, deja de ofrecerse pero el pedido la conserva.
      </p>
    </fieldset>
  );
}

/**
 * Una fila por combinación, con su stock. Vacío es sin límite; 0 es
 * agotado, y en la página esa combinación no se puede elegir.
 */
function GrillaDeStock({ caracteristicas, stock, onStockChange }) {
  const conOpciones = caracteristicas.filter((c) => c.opciones.length > 0);
  const filas = combinaciones(conOpciones);

  return (
    <div>
      <p className="block text-sm font-semibold text-tinta mb-1.5">Stock por combinación</p>
      <div className="overflow-x-auto border border-borde rounded-xl">
        <table className="w-full text-sm">
          <thead className="bg-papel-hueso text-left">
            <tr>
              {conOpciones.map((c) => (
                <th key={c.clave} scope="col" className="px-3 py-2 font-semibold">{c.nombre || 'Sin nombre'}</th>
              ))}
              <th scope="col" className="px-3 py-2 font-semibold">Stock</th>
            </tr>
          </thead>
          <tbody>
            {filas.map((combo) => {
              const clave = claveDeFila(combo);
              const nombre = combo.map((o) => o.nombre).join(' / ');
              const valor = stock[clave] ?? '';
              return (
                <tr key={clave} className="border-t border-borde">
                  {combo.map((o) => <td key={o.clave} className="px-3 py-2">{o.nombre}</td>)}
                  <td className="px-3 py-1.5">
                    <input
                      type="number"
                      min="0"
                      step="1"
                      inputMode="numeric"
                      value={valor}
                      onChange={(e) => onStockChange({ ...stock, [clave]: e.target.value })}
                      placeholder="Sin límite"
                      aria-label={`Stock de ${nombre}`}
                      className={`${campo} w-28`}
                    />
                    {valor !== '' && Number(valor) === 0 && (
                      <span className="ml-2 text-xs font-semibold text-red-700">Agotado</span>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-tinta-suave mt-1">Vacío es sin límite. Con 0 se muestra "Agotado" y no se puede elegir.</p>
    </div>
  );
}

export default EditorCaracteristicas;
