import React from 'react';
import { Zap } from 'lucide-react';

/**
 * Configuración → Colaboraciones: las páginas cuyas invitaciones a colaborar
 * esta página acepta automáticamente.
 *
 * Una regla se crea al aceptar una invitación marcando "Aceptar siempre". Desde
 * acá se deja de aceptar: eso sólo afecta a las invitaciones que lleguen
 * después; las colaboraciones ya aceptadas siguen como están.
 *
 * @param {Array}    reglas   Las reglas de la página (GET collaborations/automaticas.php).
 * @param {Function} onDejar  Recibe la regla a quitar.
 * @param {number}   quitando Id de la regla que se está quitando, para desactivar su botón.
 */
function ColaboracionesAutomaticas({ reglas, onDejar, quitando = null }) {
  return (
    <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
      <h2 className="text-2xl font-bold mb-2 tracking-tight">Colaboraciones que acepto automáticamente</h2>
      <p className="text-sm text-tinta-suave mb-6">
        Las invitaciones a colaborar de estas páginas se aceptan solas y el evento se publica en el
        grupo indicado. Si dejás de aceptarlas automáticamente, las colaboraciones que ya aceptaste
        siguen como están: sólo vas a tener que responder las próximas.
      </p>

      {reglas.length === 0 ? (
        <p className="text-tinta-suave text-sm">
          Todavía no aceptás automáticamente ninguna. Para hacerlo, marcá &ldquo;Aceptar siempre&rdquo; al
          aceptar una invitación a colaborar.
        </p>
      ) : (
        <ul className="space-y-2">
          {reglas.map((r) => (
            <li
              key={r.id}
              className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white border border-borde rounded-xl px-4 py-3"
            >
              <div className="flex items-center gap-3 min-w-0">
                {r.requester_page_image ? (
                  <img src={r.requester_page_image} alt="" className="w-9 h-9 rounded-full object-cover flex-shrink-0" />
                ) : (
                  <Zap className="w-5 h-5 text-verde-oscuro flex-shrink-0" aria-hidden="true" />
                )}
                <div className="min-w-0">
                  <p className="font-medium text-tinta truncate">{r.requester_page_title}</p>
                  <p className="text-xs text-tinta-suave">
                    Se publican en <span className="text-tinta-media">{r.group_title}</span>
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => onDejar(r)}
                disabled={quitando === r.id}
                className="text-red-700 text-sm font-bold transition disabled:opacity-50 self-start sm:self-auto"
                aria-label={`Dejar de aceptar automáticamente las de ${r.requester_page_title}`}
              >
                {quitando === r.id ? 'Quitando…' : 'Dejar de aceptar automáticamente'}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default ColaboracionesAutomaticas;
