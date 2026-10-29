import React from 'react';
import Campo from './CampoDeCompra';
import { DIAS, FRANJAS, PROVINCIAS } from '../utils/productos';

/**
 * La dirección y los horarios de entrega de un pedido de un físico (#43).
 * Controlado: `entrega` es el objeto de entregaVacia() y `onCambiar` recibe
 * el objeto nuevo.
 */
function DatosDeEntrega({ entrega, onCambiar }) {
  const cambiar = (campo, valor) => onCambiar({ ...entrega, [campo]: valor });

  const alternar = (campo, clave, orden) => {
    const elegidas = entrega[campo].includes(clave)
      ? entrega[campo].filter((c) => c !== clave)
      : [...entrega[campo], clave];
    // Siempre en el orden de la semana / del día.
    cambiar(campo, orden.map((d) => d.clave).filter((c) => elegidas.includes(c)));
  };

  return (
    <>
      <fieldset className="space-y-4 border-t border-borde pt-4">
        <legend className="text-sm font-bold tracking-wide pr-2">ENVÍO</legend>

        <Campo
          id="entrega-calle"
          etiqueta="CALLE Y NÚMERO"
          value={entrega.calle}
          onChange={(v) => cambiar('calle', v)}
          autoComplete="address-line1"
          maxLength={150}
          required
        />

        <Campo
          id="entrega-piso"
          etiqueta="PISO Y DEPTO (OPCIONAL)"
          value={entrega.piso_depto}
          onChange={(v) => cambiar('piso_depto', v)}
          autoComplete="address-line2"
          maxLength={50}
        />

        <Campo
          id="entrega-localidad"
          etiqueta="LOCALIDAD"
          value={entrega.localidad}
          onChange={(v) => cambiar('localidad', v)}
          autoComplete="address-level2"
          maxLength={100}
          required
        />

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label htmlFor="entrega-provincia" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
              PROVINCIA
            </label>
            <select
              id="entrega-provincia"
              value={entrega.provincia}
              onChange={(e) => cambiar('provincia', e.target.value)}
              autoComplete="address-level1"
              className="w-full px-4 py-3 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro transition"
              required
            >
              <option value="">Elegí…</option>
              {PROVINCIAS.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
          </div>

          <Campo
            id="entrega-cp"
            etiqueta="CÓDIGO POSTAL"
            value={entrega.codigo_postal}
            onChange={(v) => cambiar('codigo_postal', v)}
            autoComplete="postal-code"
            maxLength={10}
            required
          />
        </div>

        <Campo
          id="entrega-indicaciones"
          etiqueta="INDICACIONES (OPCIONAL)"
          value={entrega.indicaciones}
          onChange={(v) => cambiar('indicaciones', v)}
          placeholder="Por ejemplo: timbre B"
          maxLength={255}
        />
      </fieldset>

      <fieldset className="space-y-4 border-t border-borde pt-4">
        <legend className="text-sm font-bold tracking-wide pr-2">¿CUÁNDO PODÉS RECIBIRLO?</legend>

        <Casillas
          titulo="Días"
          opciones={DIAS}
          elegidas={entrega.dias}
          onAlternar={(clave) => alternar('dias', clave, DIAS)}
        />

        <Casillas
          titulo="Franja"
          opciones={FRANJAS}
          elegidas={entrega.franjas}
          onAlternar={(clave) => alternar('franjas', clave, FRANJAS)}
        />

        <Campo
          id="entrega-comentario"
          etiqueta="COMENTARIO (OPCIONAL)"
          value={entrega.comentario}
          onChange={(v) => cambiar('comentario', v)}
          placeholder="Por ejemplo: después de las 18"
          maxLength={255}
        />
      </fieldset>
    </>
  );
}

function Casillas({ titulo, opciones, elegidas, onAlternar }) {
  return (
    <fieldset>
      <legend className="block text-sm font-semibold text-tinta mb-1.5">{titulo}</legend>
      <div className="flex flex-wrap gap-x-4 gap-y-2">
        {opciones.map((o) => (
          <label key={o.clave} className="inline-flex items-center gap-1.5 text-sm cursor-pointer">
            <input
              type="checkbox"
              checked={elegidas.includes(o.clave)}
              onChange={() => onAlternar(o.clave)}
              className="w-4 h-4"
            />
            {o.nombre}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

export default DatosDeEntrega;
