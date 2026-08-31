import React, { useState, useEffect } from 'react';
import { Search, Loader2, ArrowLeft, Ticket } from 'lucide-react';
import PanelVentas from './PanelVentas';
import { formatearPrecio } from '../utils/entradas';

/**
 * Las ventas de todos los eventos de una página, sin pasar por el editor.
 *
 * Hasta ahora el único camino a las ventas era abrir el evento en CONTENIDO, y
 * para eso hay que acordarse en qué grupo quedó cargado. Acá se busca por
 * nombre o por fecha y se entra directo.
 *
 * El listado es un índice, no un informe: da lo justo para reconocer el show y
 * ver de un vistazo cuánto lleva vendido. El detalle —quién compró, con qué
 * teléfono, cancelar una compra— sigue siendo el mismo panel que se ve desde el
 * evento, para que no haya dos pantallas que digan cosas parecidas de maneras
 * distintas.
 */
function BuscadorDeVentas({ pageId, apiUrl, token }) {
  const [filtros, setFiltros] = useState({ q: '', desde: '', hasta: '' });
  const [eventos, setEventos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [elegido, setElegido] = useState(null);

  useEffect(() => {
    let vigente = true;

    const buscar = async () => {
      setCargando(true);
      setError(null);

      const query = new URLSearchParams({ page_id: pageId });

      Object.entries(filtros).forEach(([clave, valor]) => {
        if (valor) query.set(clave, valor);
      });

      try {
        const r = await fetch(`${apiUrl}/entradas/eventos.php?${query}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos buscar los eventos');
          return;
        }

        setEventos(cuerpo.eventos || []);
      } catch (e) {
        if (vigente) setError('No pudimos buscar los eventos');
      } finally {
        if (vigente) setCargando(false);
      }
    };

    // Se espera a que la persona termine de escribir en vez de pedir una
    // búsqueda por tecla. Sin esto, escribir un nombre son diez consultas y
    // las respuestas pueden llegar desordenadas.
    const id = setTimeout(buscar, filtros.q ? 300 : 0);

    return () => {
      vigente = false;
      clearTimeout(id);
    };
  }, [pageId, apiUrl, token, filtros]);

  const cambiar = (clave) => (e) => setFiltros({ ...filtros, [clave]: e.target.value });

  if (elegido) {
    return (
      <div>
        <button
          onClick={() => setElegido(null)}
          className="flex items-center gap-2 text-sm text-gray-500 hover:text-white transition mb-6"
        >
          <ArrowLeft className="w-4 h-4" />
          Volver a los eventos
        </button>

        <h3 className="text-xl font-black mb-1 tracking-tight">{elegido.text}</h3>
        <p className="text-sm text-gray-500 mb-6">{fechaLegible(elegido)}</p>

        <PanelVentas linkId={elegido.id} apiUrl={apiUrl} token={token} />
      </div>
    );
  }

  return (
    <div>
      <div className="grid gap-3 md:grid-cols-[1fr_auto_auto] mb-6">
        <div className="relative">
          <Search className="w-4 h-4 text-gray-600 absolute left-4 top-1/2 -translate-y-1/2" />
          <input
            type="search"
            value={filtros.q}
            onChange={cambiar('q')}
            placeholder="Buscar por nombre del evento"
            aria-label="Buscar por nombre del evento"
            className="w-full pl-11 pr-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
          />
        </div>

        <label className="flex items-center gap-2 text-xs text-gray-500">
          DESDE
          <input
            type="date"
            value={filtros.desde}
            onChange={cambiar('desde')}
            aria-label="Desde"
            className="px-3 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
          />
        </label>

        <label className="flex items-center gap-2 text-xs text-gray-500">
          HASTA
          <input
            type="date"
            value={filtros.hasta}
            onChange={cambiar('hasta')}
            aria-label="Hasta"
            className="px-3 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
          />
        </label>
      </div>

      {error && (
        <p className="text-sm text-red-400 border border-red-900 bg-red-950 p-4">{error}</p>
      )}

      {cargando && (
        <p className="flex items-center gap-2 text-gray-500 py-8">
          <Loader2 className="w-4 h-4 animate-spin" />
          <span>Buscando eventos…</span>
        </p>
      )}

      {!cargando && !error && eventos && eventos.length === 0 && (
        <p className="text-gray-500 py-8">
          {filtros.q || filtros.desde || filtros.hasta
            ? 'Ningún evento con entradas coincide con la búsqueda.'
            : 'Todavía no hay eventos con entradas en esta página.'}
        </p>
      )}

      {!cargando && !error && eventos && eventos.length > 0 && (
        <ul className="divide-y divide-gray-800 border border-gray-800">
          {eventos.map((evento) => (
            <li key={evento.id}>
              <button
                onClick={() => setElegido(evento)}
                className="w-full text-left px-5 py-4 hover:bg-black transition flex items-center justify-between gap-4 flex-wrap"
              >
                <span>
                  <span className="block font-bold">{evento.text}</span>
                  <span className="block text-xs text-gray-500 mt-1">{fechaLegible(evento)}</span>
                </span>

                <span className="flex items-center gap-4 text-sm">
                  <span className="flex items-center gap-2 text-gray-300">
                    <Ticket className="w-4 h-4 text-gray-600" />
                    {vendidasLegibles(evento)}
                  </span>
                  {evento.recaudado > 0 && (
                    <span className="text-emerald-400 font-bold">
                      {formatearPrecio(evento.recaudado)}
                    </span>
                  )}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Fecha y hora del evento como se leen acá. Sin fecha, no se inventa nada. */
export function fechaLegible(evento) {
  if (!evento || !evento.event_date) return 'Sin fecha';

  // Se parte el ISO en vez de pasarlo por Date: `new Date('2026-09-02')` se
  // interpreta como UTC y en Argentina muestra el día anterior.
  const [anio, mes, dia] = evento.event_date.split('-');
  const fecha = `${dia}/${mes}/${anio}`;

  return evento.event_time ? `${fecha}, ${evento.event_time.slice(0, 5)}` : fecha;
}

/**
 * Cuánto se vendió, con el cupo si lo tiene.
 *
 * Las reservadas se nombran aparte y no sumadas: son lugares tomados que
 * todavía pueden caerse, y mezclarlas con las pagadas daría un número de
 * ventas que no es.
 */
export function vendidasLegibles(evento) {
  const vendidas = evento.capacidad
    ? `${evento.vendidas}/${evento.capacidad}`
    : `${evento.vendidas}`;

  return evento.reservadas > 0
    ? `${vendidas} vendidas · ${evento.reservadas} reservadas`
    : `${vendidas} vendidas`;
}

export default BuscadorDeVentas;
