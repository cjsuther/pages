import React, { useState } from 'react';

/**
 * Cuántas entradas se vendieron cada día del último mes.
 *
 * Una columna por día, también los días en cero: los vacíos son parte de la
 * respuesta, porque muestran que la venta se frenó. Antes se dibujaban sólo
 * los días con ventas, así que tres días sueltos aparecían pegados —tres
 * cuadrados y nada más— y se leían como si hubieran sido seguidos.
 *
 * Son columnas y no una línea porque entre un día y el siguiente no hay nada
 * que interpolar: cada día es un número suelto.
 *
 * El color es el de la página, que sobre fondo claro puede quedar flojo de
 * contraste, así que el gráfico nunca depende sólo de él: las fechas y el
 * máximo están escritos, cada columna se puede enfocar con el teclado y su
 * valor se lee también con un lector de pantalla.
 */
function RitmoDeVenta({ dias, color, colorTexto = 'currentColor' }) {
  const [activo, setActivo] = useState(null);

  const total = dias.reduce((suma, d) => suma + d.vendidas, 0);

  if (total === 0) {
    return (
      <p className="text-sm opacity-70">
        Sin ventas en los últimos 30 días.
      </p>
    );
  }

  const tope = Math.max(...dias.map((d) => d.vendidas));
  const dia = activo === null ? null : dias[activo];

  return (
    <figure className="m-0">
      <figcaption className="flex items-baseline justify-between text-sm mb-2">
        <span className="opacity-70">
          {total} {total === 1 ? 'entrada' : 'entradas'} en 30 días
        </span>
        {/* El pico escrito: sin él, el alto de una columna no dice cuánto es. */}
        <span className="opacity-60 text-xs">máximo {tope} por día</span>
      </figcaption>

      <div className="relative">
        <ul className="flex items-end gap-[2px] h-28" onMouseLeave={() => setActivo(null)}>
          {dias.map((d, i) => (
            <li key={d.dia} className="flex-1 h-full flex items-end">
              {/* El botón ocupa el alto entero: así se puede apuntar a un día
                  sin tener que acertarle a una columna de dos píxeles. */}
              <button
                type="button"
                className="w-full h-full flex items-end"
                onMouseEnter={() => setActivo(i)}
                onFocus={() => setActivo(i)}
                onBlur={() => setActivo(null)}
                aria-label={`${fechaLegible(d.dia)}: ${d.vendidas} ${d.vendidas === 1 ? 'entrada' : 'entradas'}`}
              >
                <span
                  className="w-full block rounded-t-[4px] transition-opacity"
                  style={{
                    height: d.vendidas === 0 ? '2px' : `${Math.max(6, (100 * d.vendidas) / tope)}%`,
                    backgroundColor: color,
                    // Un día sin ventas deja la marca del eje, no una columna.
                    opacity: d.vendidas === 0 ? 0.25 : (activo === null || activo === i ? 1 : 0.55),
                  }}
                />
              </button>
            </li>
          ))}
        </ul>

        {dia && (
          <p
            className="absolute -top-1 left-0 right-0 text-center text-xs font-bold pointer-events-none"
            style={{ color: colorTexto }}
            role="status"
          >
            {fechaLegible(dia.dia)}: {dia.vendidas}
          </p>
        )}
      </div>

      <div className="flex justify-between text-xs opacity-60 mt-1">
        <span>{fechaLegible(dias[0].dia)}</span>
        <span>hoy</span>
      </div>
    </figure>
  );
}

/** "12 sep": corto, porque van dos por gráfico. */
function fechaLegible(dia) {
  const fecha = new Date(`${String(dia).slice(0, 10)}T00:00:00`);

  return Number.isNaN(fecha.getTime())
    ? String(dia)
    : fecha.toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });
}

export default RitmoDeVenta;
