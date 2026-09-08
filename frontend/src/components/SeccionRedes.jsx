import React, { useState, useEffect } from 'react';
import { Check, Eye } from 'lucide-react';
import { REDES, normalizarUrl, valorVisible } from '../utils/redes';
import RedesSociales from './RedesSociales';

/** Referencia estable para el valor por defecto (ver el comentario del efecto). */
const SIN_REDES = [];

/**
 * Sección del editor para cargar las redes sociales de la página.
 *
 * Se muestran todos los campos del catálogo, pero sólo se guardan —y por lo
 * tanto sólo se ven en la página pública— los que el usuario complete.
 */
function SeccionRedes({ socials = SIN_REDES, guardando = false, onGuardar }) {
  // Estado del formulario: una entrada por red, con lo que el usuario ve.
  const [valores, setValores] = useState({});
  const [guardado, setGuardado] = useState(false);

  // Se depende del contenido y no de la identidad del array: el padre lo
  // vuelve a crear en cada render, y usarlo como dependencia directa provoca
  // un ciclo de efecto → setState → render → efecto que cuelga la pestaña.
  const huella = JSON.stringify(socials || []);

  useEffect(() => {
    const iniciales = {};

    JSON.parse(huella).forEach((s) => {
      iniciales[s.red] = valorVisible(s.red, s.url);
    });

    setValores(iniciales);
  }, [huella]);

  const cambiar = (clave, valor) => {
    setValores((previos) => ({ ...previos, [clave]: valor }));
    setGuardado(false);
  };

  /** Lo que se manda al servidor: sólo lo completado, ya normalizado. */
  const aGuardar = REDES
    .filter((red) => (valores[red.clave] || '').trim() !== '')
    .map((red) => ({ red: red.clave, url: normalizarUrl(red.clave, valores[red.clave]) }));

  const guardar = async () => {
    await onGuardar(aGuardar);
    setGuardado(true);
  };

  return (
    <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
      <h2 className="text-2xl font-bold mb-2 tracking-tight">Redes sociales</h2>
      <p className="text-sm text-tinta-suave mb-8">
        Completá sólo las que uses. En tu página aparecen como iconos arriba de todo;
        las que dejes vacías no se muestran.
      </p>

      <div className="grid md:grid-cols-2 gap-5 mb-8">
        {REDES.map((red) => (
          <div key={red.clave}>
            <label
              htmlFor={`red-${red.clave}`}
              className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide"
            >
              {red.nombre.toUpperCase()}
            </label>

            <div className="flex items-stretch">
              {red.base && (
                <span className="px-3 flex items-center bg-white border border-r-0 border-borde-fuerte text-tinta-suave text-sm whitespace-nowrap">
                  {red.base.replace(/^https?:\/\//, '')}
                </span>
              )}
              <input
                id={`red-${red.clave}`}
                type="text"
                value={valores[red.clave] || ''}
                onChange={(e) => cambiar(red.clave, e.target.value)}
                placeholder={red.ejemplo}
                className="flex-1 min-w-0 px-4 py-3 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro transition"
              />
            </div>

            {red.ayuda && <p className="text-xs text-tinta-suave mt-1">{red.ayuda}</p>}
          </div>
        ))}
      </div>

      {/* Ver el resultado antes de guardar evita el viaje de ida y vuelta a
          la página pública para comprobar que quedó bien. */}
      <div className="border-t border-borde pt-6 mb-6">
        <div className="flex items-center gap-2 text-tinta-suave text-sm mb-4">
          <Eye className="w-4 h-4" />
          <span>Así se van a ver en tu página</span>
        </div>

        {aGuardar.length > 0 ? (
          <div className="bg-white border border-borde py-6">
            <RedesSociales socials={aGuardar} />
          </div>
        ) : (
          <p className="text-sm text-tinta-suave">
            Todavía no cargaste ninguna. Mientras estén vacías, la sección no aparece en tu página.
          </p>
        )}
      </div>

      <div className="flex items-center gap-4">
        <button
          onClick={guardar}
          disabled={guardando}
          className="inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-50"
        >
          {guardando ? 'Guardando...' : 'Guardar redes'}
        </button>

        {guardado && !guardando && (
          <span className="flex items-center gap-2 text-verde-oscuro text-sm font-medium">
            <Check className="w-4 h-4" />
            Guardado
          </span>
        )}
      </div>
    </div>
  );
}

export default SeccionRedes;
