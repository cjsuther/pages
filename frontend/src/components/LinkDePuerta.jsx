import React, { useEffect, useState } from 'react';
import { Copy, Check, ExternalLink, RefreshCw, Loader2, DoorOpen } from 'lucide-react';

/**
 * El link de puerta de un evento, para quien organiza.
 *
 * Es lo que se le pasa a quien controla la entrada: con él escanea los QR o
 * busca en la lista y marca quién entró, sin cuenta en la plataforma. Por eso
 * se puede cambiar o desactivar en cualquier momento: es la única forma de
 * sacarle el acceso a alguien que ya lo tiene.
 */
function LinkDePuerta({ linkId, apiUrl, token }) {
  const [url, setUrl] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [trabajando, setTrabajando] = useState(false);
  const [error, setError] = useState(null);
  const [copiado, setCopiado] = useState(false);
  // Cambiar el link deja afuera a quien tenía el anterior: se pide confirmar.
  const [confirmando, setConfirmando] = useState(null);

  const direccion = `${apiUrl}/entradas/puerta.php?link_id=${linkId}`;
  const cabeceras = { Authorization: `Bearer ${token}` };

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(direccion, { headers: cabeceras });
        const cuerpo = await r.json();
        if (!vigente) return;
        if (!r.ok) throw new Error(cuerpo.error || 'No pudimos cargar el link');
        setUrl(cuerpo.url);
      } catch (e) {
        if (vigente) setError(e.message);
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [linkId]);

  const pedir = async (method) => {
    setTrabajando(true);
    setError(null);
    setConfirmando(null);

    try {
      const r = await fetch(direccion, { method, headers: cabeceras });
      const cuerpo = await r.json();
      if (!r.ok) throw new Error(cuerpo.error || 'No se pudo completar');
      setUrl(cuerpo.url);
      setCopiado(false);
    } catch (e) {
      setError(e.message);
    } finally {
      setTrabajando(false);
    }
  };

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(url);
      setCopiado(true);
    } catch (e) {
      setError('No se pudo copiar: seleccioná el link y copialo a mano.');
    }
  };

  if (cargando) {
    return <p className="text-tinta-suave py-6">Cargando...</p>;
  }

  return (
    <div className="space-y-5">
      <div className="flex items-start gap-3">
        <DoorOpen className="w-6 h-6 text-tinta shrink-0 mt-0.5" />
        <div>
          <h2 className="font-bold text-tinta">Control de ingreso</h2>
          <p className="text-sm text-tinta-media">
            Un link para quien esté en la puerta: escanea el QR de cada entrada o busca a la
            persona en la lista, y marca que entró. No necesita cuenta en Rezonar.
          </p>
        </div>
      </div>

      {!url && (
        <button
          type="button"
          onClick={() => pedir('POST')}
          disabled={trabajando}
          className="inline-flex items-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-50"
        >
          {trabajando && <Loader2 className="w-4 h-4 animate-spin" />}
          Crear link de puerta
        </button>
      )}

      {url && (
        <>
          <div>
            <label htmlFor="link-puerta" className="block text-sm font-semibold text-tinta mb-1.5">
              Link de puerta
            </label>
            <div className="flex gap-2">
              <input
                id="link-puerta"
                type="text"
                readOnly
                value={url}
                onFocus={(e) => e.target.select()}
                className="flex-1 min-w-0 px-3 py-3 bg-papel-hueso border border-borde text-tinta text-sm font-mono"
              />
              <button
                type="button"
                onClick={copiar}
                className="shrink-0 inline-flex items-center gap-1.5 px-4 bg-tinta text-white text-sm font-semibold"
              >
                {copiado ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                {copiado ? 'Copiado' : 'Copiar'}
              </button>
            </div>
            <p className="text-xs text-tinta-suave mt-1">
              Quien tenga este link ve los nombres de quienes compraron. Pasalo sólo a la gente de la puerta.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-4 text-sm">
            <a
              href={url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-1.5 font-semibold text-verde-oscuro hover:underline"
            >
              <ExternalLink className="w-4 h-4" /> Abrir
            </a>
            <button
              type="button"
              onClick={() => setConfirmando('POST')}
              disabled={trabajando}
              className="inline-flex items-center gap-1.5 font-semibold text-tinta-media hover:text-tinta"
            >
              <RefreshCw className="w-4 h-4" /> Cambiar el link
            </button>
            <button
              type="button"
              onClick={() => setConfirmando('DELETE')}
              disabled={trabajando}
              className="font-semibold text-red-700 hover:underline"
            >
              Desactivar
            </button>
          </div>

          {confirmando && (
            <div className="border border-amber-300 bg-amber-50 p-4 text-sm space-y-3">
              <p className="text-tinta">
                {confirmando === 'POST'
                  ? 'El link actual va a dejar de funcionar y vas a tener que mandar el nuevo a la gente de la puerta.'
                  : 'El link va a dejar de funcionar. Lo que ya se marcó queda guardado.'}
              </p>
              <div className="flex gap-3">
                <button
                  type="button"
                  onClick={() => pedir(confirmando)}
                  className="px-4 py-2 bg-tinta text-white font-semibold"
                >
                  {confirmando === 'POST' ? 'Sí, cambiarlo' : 'Sí, desactivarlo'}
                </button>
                <button type="button" onClick={() => setConfirmando(null)} className="px-4 py-2 text-tinta-media">
                  Cancelar
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {error && (
        <p className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>
      )}
    </div>
  );
}

export default LinkDePuerta;
