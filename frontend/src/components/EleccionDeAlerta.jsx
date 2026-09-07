import React from 'react';
import { Globe2, Compass } from 'lucide-react';
import { RADIOS_KM } from '../utils/alertas';

/**
 * Las dos formas de recibir los avisos de una página: todas sus fechas, o
 * solamente las que caen cerca.
 *
 * Es el mismo control en el botón de seguir, en la lista de páginas seguidas y
 * en la pantalla de alertas. Que sea un componente y no tres copias es lo que
 * garantiza que las tres guarden lo mismo.
 */
function EleccionDeAlerta({ modo, radio, alCambiarModo, alCambiarRadio, nombre = 'alerta' }) {
  const opciones = [
    {
      id: 'todas',
      icono: Globe2,
      titulo: 'Todas sus fechas',
      detalle: 'Te avisamos cada vez que publica un show, toque donde toque.',
    },
    {
      id: 'cerca',
      icono: Compass,
      titulo: 'Solo si es cerca',
      detalle: 'Nada más las fechas que caen dentro del radio que elijas.',
    },
  ];

  return (
    <div className="space-y-3">
      {opciones.map(({ id, icono: Icono, titulo, detalle }) => {
        const elegida = modo === id;

        return (
          <div key={id}>
            <label
              className={`flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors ${
                elegida ? 'border-verde bg-verde-claro' : 'border-borde hover:border-borde-fuerte'
              }`}
            >
              <input
                type="radio"
                name={nombre}
                checked={elegida}
                onChange={() => alCambiarModo(id)}
                className="mt-1 accent-[#6FBE44]"
              />
              <span className="min-w-0">
                <span className="flex items-center gap-2 font-semibold text-tinta text-sm">
                  <Icono className={`w-4 h-4 ${elegida ? 'text-verde-oscuro' : 'text-tinta-suave'}`} />
                  {titulo}
                </span>
                <span className="block text-sm text-tinta-media mt-1">{detalle}</span>
              </span>
            </label>

            {/* El radio aparece dentro de la opción que lo usa: suelto arriba
                parecía aplicar también a "todas las fechas". */}
            {id === 'cerca' && elegida && (
              <div className="mt-2 ml-4 pl-4 border-l-2 border-verde-medio">
                <p className="text-xs font-semibold uppercase tracking-wider text-tinta-suave mb-2">
                  Hasta qué distancia
                </p>
                <div className="flex flex-wrap gap-2">
                  {RADIOS_KM.map((km) => (
                    <button
                      key={km}
                      type="button"
                      onClick={() => alCambiarRadio(km)}
                      aria-pressed={radio === km}
                      className={`px-3.5 py-1.5 rounded-full text-sm font-semibold border transition-colors ${
                        radio === km
                          ? 'bg-verde text-verde-tinta border-verde'
                          : 'bg-white text-tinta-media border-borde hover:border-borde-fuerte'
                      }`}
                    >
                      {km} km
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

export default EleccionDeAlerta;
