import React, { useCallback } from 'react';
import GooglePlacesAutocomplete from './GooglePlacesAutocomplete';
import { FORMAS_DE_ENTREGA, formaHabilitada } from '../utils/productos';

const claseCampo = 'w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors';

/**
 * Las formas de entrega de un producto físico en el editor (#53): envío a
 * domicilio (con su costo y su zona), retiro en un lugar (la dirección de
 * Google Maps, como la ubicación de un evento, y los horarios) y coordinar
 * después de la compra. Controlado: `onChange` recibe sólo lo que cambió.
 */
function EditorFormasDeEntrega({ item, onChange }) {
  const tildada = (forma) => formaHabilitada(item, forma);

  // Estable: GooglePlacesAutocomplete arma el autocompletado de nuevo cada
  // vez que cambia esta función.
  const elegirLugar = useCallback((lugar) => onChange({
    retiro_direccion: lugar.address,
    retiro_latitud: lugar.latitude,
    retiro_longitud: lugar.longitude,
    retiro_maps_url: lugar.mapsUrl,
  }), [onChange]);

  const envio = tildada(FORMAS_DE_ENTREGA[0]);
  const retiro = tildada(FORMAS_DE_ENTREGA[1]);

  return (
    <fieldset className="space-y-4">
      <legend className="block text-sm font-semibold text-tinta mb-1.5">Formas de entrega</legend>
      <p className="text-xs text-tinta-suave -mt-2">Elegí al menos una. Quien compra elige cuál usar.</p>

      {FORMAS_DE_ENTREGA.map((forma) => (
        <div key={forma.clave} className="border border-borde rounded-xl p-4 space-y-4">
          <label className="flex items-center gap-2 text-sm font-semibold text-tinta cursor-pointer">
            <input
              type="checkbox"
              checked={tildada(forma)}
              onChange={(e) => onChange({ [forma.columna]: e.target.checked })}
              className="w-4 h-4"
            />
            {forma.nombre}
          </label>

          {forma.clave === 'envio' && envio && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label htmlFor="costo-envio" className="block text-sm font-semibold text-tinta mb-1.5">
                  Costo de envío
                </label>
                <input
                  id="costo-envio"
                  type="number"
                  min="0"
                  step="0.01"
                  value={item.costo_envio ?? ''}
                  onChange={(e) => onChange({ costo_envio: e.target.value })}
                  placeholder="0"
                  className={claseCampo}
                />
                <p className="text-xs text-tinta-suave mt-1">En pesos. 0 = envío incluido.</p>
              </div>
              <div>
                <label htmlFor="zona-envio" className="block text-sm font-semibold text-tinta mb-1.5">
                  Zona de envío (opcional)
                </label>
                <input
                  id="zona-envio"
                  type="text"
                  maxLength={255}
                  value={item.zona_envio ?? ''}
                  onChange={(e) => onChange({ zona_envio: e.target.value })}
                  placeholder="Sólo CABA y GBA"
                  className={claseCampo}
                />
              </div>
            </div>
          )}

          {forma.clave === 'retiro' && retiro && (
            <>
              <div>
                <label htmlFor="retiro-direccion" className="block text-sm font-semibold text-tinta mb-1.5">
                  Dirección del retiro<span className="text-red-500">*</span>
                </label>
                <GooglePlacesAutocomplete
                  id="retiro-direccion"
                  value={item.retiro_direccion || ''}
                  // Escribir a mano borra el lugar elegido: hay que volver a
                  // elegirlo de las sugerencias para tener el mapa.
                  onChange={(e) => onChange({
                    retiro_direccion: e.target.value, retiro_latitud: null, retiro_longitud: null, retiro_maps_url: null,
                  })}
                  onPlaceSelect={elegirLugar}
                  placeholder="Buscar dirección en Google Maps..."
                  required
                />
                <p className="text-xs text-tinta-suave mt-1">
                  Elegí una dirección de las sugerencias: así quien compra la abre en Google Maps.
                </p>
              </div>
              <div>
                <label htmlFor="retiro-indicaciones" className="block text-sm font-semibold text-tinta mb-1.5">
                  Días, horarios e indicaciones para retirar (opcional)
                </label>
                <input
                  id="retiro-indicaciones"
                  type="text"
                  maxLength={255}
                  value={item.retiro_indicaciones ?? ''}
                  onChange={(e) => onChange({ retiro_indicaciones: e.target.value })}
                  placeholder="Lunes a viernes de 10 a 18, timbre 3"
                  className={claseCampo}
                />
              </div>
            </>
          )}

          {forma.clave === 'coordinar' && tildada(forma) && (
            <p className="text-xs text-tinta-suave">
              Quien compra deja su teléfono y vos lo contactás para coordinar la entrega.
            </p>
          )}
        </div>
      ))}
    </fieldset>
  );
}

export default EditorFormasDeEntrega;
