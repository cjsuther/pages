import React, { useState } from 'react';
import { Copy, Loader2 } from 'lucide-react';

/** "12/10/2026", o nada si el evento no tiene fecha. */
function fechaCorta(fecha) {
  if (!fecha) return '';
  const [anio, mes, dia] = String(fecha).slice(0, 10).split('-');
  return anio && mes && dia ? `${dia}/${mes}/${anio}` : '';
}

/**
 * Trae el plano de otro evento de la misma página.
 *
 * Un lugar arma su plano una vez y lo usa en cada show. La lista se pide
 * recién cuando alguien la quiere: la mayoría de las veces que se abren las
 * entradas de un evento no es para esto, y no hace falta cargar planos ajenos
 * cada vez.
 *
 * Se copia sólo la forma. Lo que se haya vendido en el otro evento no viene.
 */
function CopiarPlano({ linkId, apiUrl, token, onCopiar, deshabilitado = false }) {
  const [abierto, setAbierto] = useState(false);
  const [cargando, setCargando] = useState(false);
  const [planos, setPlanos] = useState(null);
  const [elegido, setElegido] = useState('');
  const [error, setError] = useState(null);

  const abrir = async () => {
    setAbierto(true);

    if (planos !== null) return;

    setCargando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/planos.php?link_id=${linkId}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const cuerpo = await r.json();

      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos traer los planos');

      setPlanos(cuerpo.planos || []);
      if (cuerpo.planos && cuerpo.planos.length > 0) setElegido(String(cuerpo.planos[0].id));
    } catch (e) {
      setError(e.message || 'No pudimos traer los planos');
    } finally {
      setCargando(false);
    }
  };

  const copiar = () => {
    const origen = (planos || []).find((p) => String(p.id) === elegido);
    if (!origen) return;

    // Una copia de verdad: si se editara el mismo objeto, el plano del otro
    // evento cambiaría en pantalla junto con este.
    onCopiar(JSON.parse(JSON.stringify(origen.plano)), origen);
    setAbierto(false);
  };

  if (!abierto) {
    return (
      <button
        type="button"
        onClick={abrir}
        disabled={deshabilitado}
        className="inline-flex items-center gap-1.5 text-sm font-semibold text-verde-oscuro hover:underline disabled:opacity-50 disabled:no-underline"
      >
        <Copy className="w-4 h-4" />
        Usar el plano de otro evento
      </button>
    );
  }

  return (
    <div className="border border-borde bg-papel-hueso p-4 space-y-3">
      {cargando && (
        <p className="flex items-center gap-2 text-sm text-tinta-suave">
          <Loader2 className="w-4 h-4 animate-spin" /> Buscando planos...
        </p>
      )}

      {error && <p className="text-sm text-red-700">{error}</p>}

      {planos && planos.length === 0 && (
        <p className="text-sm text-tinta-media">
          Ningún otro evento de esta página tiene plano todavía.
        </p>
      )}

      {planos && planos.length > 0 && (
        <>
          <div>
            <label htmlFor="copiar-plano" className="block text-xs font-semibold text-tinta mb-1">
              Plano de
            </label>
            <select
              id="copiar-plano"
              value={elegido}
              onChange={(e) => setElegido(e.target.value)}
              className="w-full px-3 py-2 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none"
            >
              {planos.map((p) => (
                <option key={p.id} value={p.id}>
                  {[p.text, fechaCorta(p.event_date)].filter(Boolean).join(' · ')} ({p.lugares} lugares)
                </option>
              ))}
            </select>
          </div>
          <p className="text-xs text-tinta-suave">
            Reemplaza el plano de este evento. Se copian las filas y las mesas, no lo vendido.
          </p>
        </>
      )}

      <div className="flex items-center gap-3">
        {planos && planos.length > 0 && (
          <button
            type="button"
            onClick={copiar}
            className="rounded-full bg-tinta text-white px-4 py-2 text-sm font-semibold"
          >
            Copiar plano
          </button>
        )}
        <button type="button" onClick={() => setAbierto(false)} className="text-sm text-tinta-media hover:text-tinta">
          Cancelar
        </button>
      </div>
    </div>
  );
}

export default CopiarPlano;
