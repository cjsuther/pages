import React from 'react';
import { precioDeReferencia } from '../utils/entradas';

/**
 * Precio de referencia de un evento, en el detalle.
 *
 * Es informativo: aplica a los eventos que se cobran en otro lado o que llegan
 * importados desde la cartelera del lugar. Cuando el evento vende entradas por
 * acá, el precio lo dice el botón de compra y esto no se muestra, para no
 * mostrar dos precios que podrían no coincidir.
 */
function PrecioEvento({ evento, className = '' }) {
  const vendeAca = !!(evento && evento.entradas && evento.entradas.activo);

  if (vendeAca) {
    return null;
  }

  const texto = precioDeReferencia(evento && evento.precio_desde);

  if (texto === null) {
    return null;
  }

  const esGratis = texto === 'Gratis';

  return (
    <p className={`text-sm font-bold ${className}`}>
      <span
        className={`inline-block px-3 py-1 rounded ${
          esGratis ? 'bg-emerald-900 text-emerald-300' : 'bg-gray-800 text-gray-200'
        }`}
      >
        {texto}
      </span>
    </p>
  );
}

export default PrecioEvento;
