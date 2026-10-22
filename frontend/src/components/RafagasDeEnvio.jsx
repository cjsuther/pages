import React, { useState } from 'react';
import { Check, Loader2 } from 'lucide-react';

const INPUT = 'w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors';

export const RAFAGAS_POR_DEFECTO = { tamano: 20, pausa_minutos: 10, tope_diario: null };

/**
 * Cómo salen los mails a clientes (#37): de a tandas, con una pausa entre una
 * y otra, y con un tope por día si el proveedor lo pone. Una casilla que
 * manda cientos de mails seguidos termina marcada como spam.
 */
function RafagasDeEnvio({ pageId, apiUrl, token, rafagas }) {
  const inicial = { ...RAFAGAS_POR_DEFECTO, ...(rafagas || {}) };
  const [form, setForm] = useState({
    tamano: String(inicial.tamano),
    pausa_minutos: String(inicial.pausa_minutos),
    tope_diario: inicial.tope_diario ? String(inicial.tope_diario) : '',
  });
  const [guardando, setGuardando] = useState(false);
  const [aviso, setAviso] = useState(null);

  const cambiar = (campo) => (e) => {
    setAviso(null);
    setForm({ ...form, [campo]: e.target.value });
  };

  const guardar = async (e) => {
    e.preventDefault();
    setGuardando(true);
    setAviso(null);

    try {
      const res = await fetch(`${apiUrl}/pages/email-rafagas.php?page_id=${pageId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          page_id: Number(pageId),
          tamano: Number(form.tamano),
          pausa_minutos: Number(form.pausa_minutos),
          tope_diario: form.tope_diario === '' ? '' : Number(form.tope_diario),
        }),
      });
      const datos = await res.json();
      if (!res.ok) throw new Error(datos.error || 'No se pudo guardar');
      setAviso({ tipo: 'ok', texto: 'Guardado' });
    } catch (err) {
      setAviso({ tipo: 'error', texto: err.message });
    } finally {
      setGuardando(false);
    }
  };

  return (
    <form onSubmit={guardar} className="mt-10 pt-8 border-t border-borde space-y-6" aria-label="Envío a clientes">
      <div>
        <h3 className="text-lg font-bold">Envío a clientes</h3>
        <p className="text-sm text-tinta-suave mt-1">
          Los mails a tus clientes salen de a tandas, con una pausa entre una y otra, para que tu casilla no
          quede marcada como spam. Dentro de cada tanda hay uno o dos segundos entre un mail y el siguiente.
        </p>
      </div>

      <div className="grid md:grid-cols-3 gap-6">
        <div>
          <label htmlFor="rafaga-tamano" className="block text-sm font-semibold text-tinta mb-1.5">Mails por tanda</label>
          <input id="rafaga-tamano" type="number" min="5" max="100" value={form.tamano}
            onChange={cambiar('tamano')} className={INPUT} required />
          <p className="text-xs text-tinta-suave mt-2">Entre 5 y 100.</p>
        </div>
        <div>
          <label htmlFor="rafaga-pausa" className="block text-sm font-semibold text-tinta mb-1.5">Minutos entre tandas</label>
          <input id="rafaga-pausa" type="number" min="5" value={form.pausa_minutos}
            onChange={cambiar('pausa_minutos')} className={INPUT} required />
          <p className="text-xs text-tinta-suave mt-2">Como mínimo 5.</p>
        </div>
        <div>
          <label htmlFor="rafaga-tope" className="block text-sm font-semibold text-tinta mb-1.5">Tope por día (opcional)</label>
          <input id="rafaga-tope" type="number" min="1" value={form.tope_diario} placeholder="Sin tope"
            onChange={cambiar('tope_diario')} className={INPUT} />
          <p className="text-xs text-tinta-suave mt-2">
            Cuenta las últimas 24 horas. Gmail personal permite unos 500 mails por día; Google Workspace, unos 2000.
          </p>
        </div>
      </div>

      {aviso && (
        aviso.tipo === 'error' ? (
          <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{aviso.texto}</p>
        ) : (
          <p role="status" className="flex items-center gap-2 text-verde-oscuro text-sm font-medium">
            <Check className="w-4 h-4" /> {aviso.texto}
          </p>
        )
      )}

      <button type="submit" disabled={guardando}
        className="inline-flex items-center gap-2 rounded-full bg-white border border-borde-fuerte text-tinta px-6 py-3 font-semibold hover:border-verde-oscuro transition-colors disabled:opacity-50">
        {guardando && <Loader2 className="w-4 h-4 animate-spin" />}
        {guardando ? 'Guardando...' : 'Guardar envío a clientes'}
      </button>
    </form>
  );
}

export default RafagasDeEnvio;
