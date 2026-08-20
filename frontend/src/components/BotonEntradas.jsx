import React, { useState, useContext } from 'react';
import { Ticket } from 'lucide-react';
import { AuthContext } from '../App';
import ComprarEntradas from './ComprarEntradas';
import { textoDeAccion, formatearPrecio } from '../utils/entradas';
import { textoSobre } from '../utils/colores';

/**
 * Botón de compra o reserva en el detalle de un evento.
 *
 * Cuando el evento vende entradas, reemplaza al link que el dueño haya cargado:
 * la venta interna es la acción principal y dos botones compitiendo confunden.
 *
 * Devuelve null si el evento no vende entradas, para que las plantillas puedan
 * escribir `<BotonEntradas /> || <a href={url}>` sin condicionales anidados.
 */
function BotonEntradas({ evento, color = '#3B82F6' }) {
  const { apiUrl } = useContext(AuthContext);
  const [comprando, setComprando] = useState(false);

  const entradas = evento && evento.entradas;

  if (!entradas || !entradas.activo) {
    return null;
  }

  const texto = textoDeAccion(entradas);

  if (entradas.agotado) {
    return (
      <div className="mt-4">
        <span className="inline-block px-6 py-3 rounded-lg font-bold bg-gray-800 text-gray-500 cursor-not-allowed">
          AGOTADO
        </span>
      </div>
    );
  }

  return (
    <div className="mt-4">
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          setComprando(true);
        }}
        className="inline-flex items-center gap-2 px-6 py-3 rounded-lg font-bold hover:opacity-90 transition"
        /* El fondo lo elegimos nosotros —es el color de acento de la página—
           así que el color de la etiqueta también nos toca elegirlo: fijarlo
           en blanco lo hacía desaparecer sobre un acento claro. */
        style={{ backgroundColor: color, color: textoSobre(color) }}
      >
        <Ticket className="w-4 h-4" />
        {texto}
      </button>

      {!entradas.es_gratis && (
        <p className="text-sm mt-2 opacity-70">
          {formatearPrecio(entradas.precio, entradas.moneda)} por entrada
        </p>
      )}

      {entradas.disponibles <= 10 && (
        <p className="text-sm mt-1 opacity-70">
          Quedan {entradas.disponibles} {entradas.disponibles === 1 ? 'entrada' : 'entradas'}
        </p>
      )}

      {comprando && (
        <ComprarEntradas
          evento={evento}
          entradas={entradas}
          apiUrl={apiUrl}
          color={color}
          onCerrar={() => setComprando(false)}
        />
      )}
    </div>
  );
}

/** true si el evento vende entradas y por lo tanto el link no se muestra. */
export function vendeEntradas(evento) {
  return !!(evento && evento.entradas && evento.entradas.activo);
}

export default BotonEntradas;
