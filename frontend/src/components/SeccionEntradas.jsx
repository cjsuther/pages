import React, { useState, useEffect } from 'react';
import { Check, AlertTriangle, Loader2, ExternalLink } from 'lucide-react';

/**
 * Sección del editor donde el dueño conecta su cuenta de Mercado Pago.
 *
 * El access token no vuelve nunca del servidor: si ya hay uno cargado, sólo se
 * muestran los últimos cuatro caracteres para que el dueño reconozca cuál es.
 */
function SeccionEntradas({ pageId, apiUrl, token }) {
  const [cobros, setCobros] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState(null);
  const [aviso, setAviso] = useState(null);
  const [form, setForm] = useState({ access_token: '', public_key: '' });

  const cabeceras = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  };

  const cargar = async () => {
    try {
      const r = await fetch(`${apiUrl}/entradas/credenciales.php?page_id=${pageId}`, {
        headers: cabeceras,
      });
      const cuerpo = await r.json();

      if (r.ok) {
        setCobros(cuerpo.cobros);
      }
    } catch (e) {
      setError('No pudimos cargar la configuración de cobros');
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    cargar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageId]);

  const guardar = async (e) => {
    e.preventDefault();
    setGuardando(true);
    setError(null);
    setAviso(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/credenciales.php?page_id=${pageId}`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify(form),
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No se pudo guardar');
        return;
      }

      setCobros(cuerpo.cobros);
      setForm({ access_token: '', public_key: '' });
      setAviso(cuerpo.cuenta ? `Conectado a la cuenta ${cuerpo.cuenta}` : 'Credenciales guardadas');
    } catch (err) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  const desconectar = async (confirmar = false) => {
    setError(null);
    setAviso(null);

    const r = await fetch(`${apiUrl}/entradas/credenciales.php?page_id=${pageId}`, {
      method: 'DELETE',
      headers: cabeceras,
      body: JSON.stringify({ confirmar }),
    });
    const cuerpo = await r.json();

    if (r.status === 409) {
      // Hay eventos cobrando: se pide confirmación en vez de romperlos callado.
      if (window.confirm(`${cuerpo.error}\n\n¿Desconectar igual?`)) {
        desconectar(true);
      }
      return;
    }

    if (!r.ok) {
      setError(cuerpo.error || 'No se pudo desconectar');
      return;
    }

    setCobros(cuerpo.cobros);
    setAviso('Mercado Pago desconectado');
  };

  if (cargando) {
    return (
      <div className="bg-gray-900 border border-gray-800 p-8 mb-8 text-gray-500">
        Cargando...
      </div>
    );
  }

  return (
    <div className="bg-gray-900 border border-gray-800 p-8 mb-8">
      <h2 className="text-2xl font-black mb-2 tracking-tight">ENTRADAS</h2>
      <p className="text-sm text-gray-500 mb-8">
        Conectá tu cuenta de Mercado Pago para poder cobrar entradas. El dinero va
        directo a tu cuenta. Para eventos con reserva sin costo no hace falta.
      </p>

      {cobros && cobros.configurado ? (
        <div className="border border-gray-800 bg-black p-6 mb-6">
          <div className="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <p className="flex items-center gap-2 text-emerald-400 font-bold mb-2">
                <Check className="w-4 h-4" />
                Mercado Pago conectado
              </p>

              <p className="text-sm text-gray-500">
                Access token terminado en{' '}
                <span className="font-mono text-gray-300">…{cobros.token_ultimos4}</span>
              </p>

              {cobros.modo === 'prueba' && (
                <p className="flex items-center gap-2 text-amber-400 text-sm mt-3">
                  <AlertTriangle className="w-4 h-4 shrink-0" />
                  Son credenciales de prueba: los pagos no son reales.
                </p>
              )}
            </div>

            <button
              type="button"
              onClick={() => desconectar(false)}
              className="text-red-400 hover:text-red-300 text-sm font-bold"
            >
              DESCONECTAR
            </button>
          </div>
        </div>
      ) : (
        <p className="text-sm text-gray-500 border border-gray-800 bg-black p-6 mb-6">
          Todavía no conectaste Mercado Pago. Sin esto sólo podés ofrecer reservas
          sin costo.
        </p>
      )}

      <form onSubmit={guardar} className="space-y-5">
        <div>
          <label htmlFor="mp-access-token" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
            ACCESS TOKEN
          </label>
          <input
            id="mp-access-token"
            type="password"
            value={form.access_token}
            onChange={(e) => setForm({ ...form, access_token: e.target.value })}
            placeholder="APP_USR-…"
            autoComplete="off"
            className="w-full px-4 py-3 bg-black border border-gray-700 text-white font-mono text-sm focus:border-white transition"
          />
        </div>

        <div>
          <label htmlFor="mp-public-key" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
            PUBLIC KEY
          </label>
          <input
            id="mp-public-key"
            type="text"
            value={form.public_key}
            onChange={(e) => setForm({ ...form, public_key: e.target.value })}
            placeholder="APP_USR-…"
            autoComplete="off"
            className="w-full px-4 py-3 bg-black border border-gray-700 text-white font-mono text-sm focus:border-white transition"
          />
        </div>

        <p className="text-xs text-gray-600">
          Las encontrás en{' '}
          <a
            href="https://www.mercadopago.com.ar/developers/panel/app"
            target="_blank"
            rel="noopener noreferrer"
            className="text-gray-400 hover:text-white inline-flex items-center gap-1"
          >
            Mercado Pago → Tus integraciones → Credenciales
            <ExternalLink className="w-3 h-3" />
          </a>
          . Tienen que ser las dos del mismo par: o las de producción, o las de prueba.
          El access token se guarda cifrado y no se vuelve a mostrar.
        </p>

        {error && (
          <p className="text-sm text-red-400 bg-red-950 border border-red-900 px-4 py-3">{error}</p>
        )}

        {aviso && (
          <p className="text-sm text-emerald-400 bg-emerald-950 border border-emerald-900 px-4 py-3">
            {aviso}
          </p>
        )}

        <button
          type="submit"
          disabled={guardando || !form.access_token || !form.public_key}
          className="bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition disabled:opacity-50 flex items-center gap-2"
        >
          {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
          {guardando ? 'VERIFICANDO...' : cobros && cobros.configurado ? 'REEMPLAZAR CREDENCIALES' : 'CONECTAR'}
        </button>

        <p className="text-xs text-gray-600">
          Antes de guardar comprobamos contra Mercado Pago que las credenciales
          funcionen, así no te enterás de que están mal cuando alguien intente pagar.
        </p>
      </form>
    </div>
  );
}

export default SeccionEntradas;
