import React from 'react';
import { precioDeReferencia } from '../utils/entradas';

/**
 * El precio de un evento: "Gratis" o "Desde $ 15.000".
 *
 * Sale de `precio_referencia`, que la API calcula igual para todos los
 * eventos: el de la venta si vende entradas por acá, el que se cargó (o trajo
 * el importador) si no. Sin precio conocido no se muestra nada.
 *
 * Hay dos variantes:
 * - en una tarjeta (`enTarjeta`) es una etiqueta chica y se muestra siempre,
 *   venda por acá o no: es lo que permite comparar de un vistazo;
 * - en el detalle, si el evento vende por acá, el precio ya lo dice el botón de
 *   compra y esto no se muestra, para no repetirlo.
 *
 * Un evento agotado no muestra precio: su estado lo dice el botón, y un
 * "Desde $X" al lado de "Agotado" invita a pensar que todavía hay.
 */
function PrecioEvento({ evento, className = '', enTarjeta = false }) {
  if (!evento) {
    return null;
  }

  const entradas = evento.entradas;
  const vendeAca = !!(entradas && entradas.activo);

  if ((vendeAca && !enTarjeta) || (entradas && entradas.agotado)) {
    return null;
  }

  // Sin precio_referencia (un evento que no vino de la API pública) se arma
  // igual que ella: la venta si vende acá, si no lo que se cargó.
  const precio = evento.precio_referencia !== undefined
    ? evento.precio_referencia
    : (vendeAca ? entradas.precio : evento.precio_desde);
  const texto = precioDeReferencia(precio);

  if (texto === null) {
    return null;
  }

  const esGratis = texto === 'Gratis';
  const colores = esGratis ? 'bg-verde-claro text-verde-oscuro' : 'bg-papel-hueso text-tinta-media';

  if (enTarjeta) {
    return (
      <span className={`inline-block self-start px-2 py-0.5 rounded text-xs font-bold whitespace-nowrap ${colores} ${className}`}>
        {texto}
      </span>
    );
  }

  return (
    <p className={`text-sm font-bold ${className}`}>
      <span className={`inline-block px-3 py-1 rounded ${colores}`}>
        {texto}
      </span>
    </p>
  );
}

export default PrecioEvento;
