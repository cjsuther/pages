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
    <div className="bg-gray-900 border border-gray-800 p-8 mb-8">
      <h2 className="text-2xl font-black mb-2 tracking-tight">REDES SOCIALES</h2>
      <p className="text-sm text-gray-500 mb-8">
        Completá sólo las que uses. En tu página aparecen como iconos arriba de todo;
        las que dejes vacías no se muestran.
      </p>

      <div className="grid md:grid-cols-2 gap-5 mb-8">
        {REDES.map((red) => (
          <div key={red.clave}>
            <label
              htmlFor={`red-${red.clave}`}
              className="block text-sm font-bold text-gray-400 mb-2 tracking-wide"
            >
              {red.nombre.toUpperCase()}
            </label>

            <div className="flex items-stretch">
              {red.base && (
                <span className="px-3 flex items-center bg-black border border-r-0 border-gray-700 text-gray-600 text-sm whitespace-nowrap">
                  {red.base.replace(/^https?:\/\//, '')}
                </span>
              )}
              <input
                id={`red-${red.clave}`}
                type="text"
                value={valores[red.clave] || ''}
                onChange={(e) => cambiar(red.clave, e.target.value)}
                placeholder={red.ejemplo}
                className="flex-1 min-w-0 px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
              />
            </div>

            {red.ayuda && <p className="text-xs text-gray-600 mt-1">{red.ayuda}</p>}
          </div>
        ))}
      </div>

      {/* Ver el resultado antes de guardar evita el viaje de ida y vuelta a
          la página pública para comprobar que quedó bien. */}
      <div className="border-t border-gray-800 pt-6 mb-6">
        <div className="flex items-center gap-2 text-gray-500 text-sm mb-4">
          <Eye className="w-4 h-4" />
          <span>Así se van a ver en tu página</span>
        </div>

        {aGuardar.length > 0 ? (
          <div className="bg-black border border-gray-800 py-6">
            <RedesSociales socials={aGuardar} />
          </div>
        ) : (
          <p className="text-sm text-gray-600">
            Todavía no cargaste ninguna. Mientras estén vacías, la sección no aparece en tu página.
          </p>
        )}
      </div>

      <div className="flex items-center gap-4">
        <button
          onClick={guardar}
          disabled={guardando}
          className="bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition disabled:opacity-50"
        >
          {guardando ? 'GUARDANDO...' : 'GUARDAR REDES'}
        </button>

        {guardado && !guardando && (
          <span className="flex items-center gap-2 text-emerald-400 text-sm font-medium">
            <Check className="w-4 h-4" />
            Guardado
          </span>
        )}
      </div>
    </div>
  );
}

export default SeccionRedes;
