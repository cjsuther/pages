import React, { useState, useEffect } from 'react';
import { Search, Download, ChevronDown, ChevronUp, Users, UserCheck, Heart } from 'lucide-react';
import { Boton, Campo, Selector, TituloSeccion, Chip, Aviso, Vacio, Cargando } from './ui';
import { formatearPrecio } from '../utils/entradas';
import { fechaLegible } from './BuscadorDeVentas';

/** Cuántos clientes se dibujan de entrada. El resto, a pedido. */
const POR_TANDA = 50;

const FILTROS_VACIOS = { q: '', evento: '', origen: '', asistencia: '', cuenta: '', desde: '', hasta: '' };

const ESTADOS = {
  pagada: 'Confirmada',
  reservada: 'Esperando pago',
  vencida: 'No pagó',
  cancelada: 'Cancelada',
  rechazada: 'Pago rechazado',
};

/**
 * Los clientes de la página: quién compró, reservó o la sigue.
 *
 * Incluye a quienes compraron en eventos en los que la página colaboró, y los
 * de eventos que ya se borraron: la compra queda aunque el evento no esté.
 * Los filtros los aplica el servidor, así el Excel baja exactamente lo que se
 * está viendo.
 */
function PanelClientes({ pageId, apiUrl, token, slug = '' }) {
  const [filtros, setFiltros] = useState(FILTROS_VACIOS);
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [abierto, setAbierto] = useState(null);
  const [visibles, setVisibles] = useState(POR_TANDA);
  const [exportando, setExportando] = useState(false);

  const consulta = (extra = {}) => {
    const query = new URLSearchParams({ page_id: pageId, ...extra });

    Object.entries(filtros).forEach(([clave, valor]) => {
      if (valor) query.set(clave, valor);
    });

    return query;
  };

  useEffect(() => {
    let vigente = true;

    const buscar = async () => {
      setCargando(true);
      setError(null);

      try {
        const r = await fetch(`${apiUrl}/pages/clientes.php?${consulta()}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos traer los clientes');
          return;
        }

        setDatos(cuerpo);
        setVisibles(POR_TANDA);
        setAbierto(null);
      } catch (e) {
        if (vigente) setError('No pudimos traer los clientes');
      } finally {
        if (vigente) setCargando(false);
      }
    };

    // Como en el buscador de ventas: se espera a que termine de escribir.
    const id = setTimeout(buscar, filtros.q ? 300 : 0);

    return () => {
      vigente = false;
      clearTimeout(id);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageId, apiUrl, token, filtros]);

  const cambiar = (clave) => (e) => setFiltros({ ...filtros, [clave]: e.target.value });

  const hayFiltros = Object.values(filtros).some(Boolean);

  const exportar = async () => {
    setExportando(true);

    try {
      // La descarga necesita la cabecera de sesión: se trae el archivo y se
      // baja desde memoria, igual que las ventas de un evento.
      const r = await fetch(`${apiUrl}/pages/clientes.php?${consulta({ formato: 'excel' })}`, {
        headers: { Authorization: `Bearer ${token}` },
      });

      if (!r.ok) {
        setError('No pudimos generar el archivo');
        return;
      }

      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');

      enlace.href = url;
      enlace.download = `clientes-${slug || pageId}.xlsx`;
      document.body.appendChild(enlace);
      enlace.click();
      document.body.removeChild(enlace);
      URL.revokeObjectURL(url);
    } catch (e) {
      setError('No pudimos generar el archivo');
    } finally {
      setExportando(false);
    }
  };

  const clientes = datos ? datos.clientes : [];
  const eventos = datos ? datos.eventos : [];
  const resumen = datos ? datos.resumen : null;

  return (
    <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
      <TituloSeccion
        titulo="Clientes"
        bajada="Quienes compraron o reservaron entradas en tus eventos y en los que colaboraste, y quienes siguen la página. Se conservan aunque borres el evento."
        acciones={
          <Boton
            variante="secundario"
            onClick={exportar}
            disabled={exportando || !clientes.length}
          >
            <Download className="w-4 h-4" />
            {exportando ? 'Generando…' : 'Exportar a Excel'}
          </Boton>
        }
      />

      {resumen && (
        <div className="flex flex-wrap gap-2 mb-6">
          <Chip><Users className="w-3.5 h-3.5" />{resumen.clientes} {resumen.clientes === 1 ? 'cliente' : 'clientes'}</Chip>
          <Chip>{resumen.compradores} compraron o reservaron</Chip>
          <Chip>{resumen.vinieron} vinieron</Chip>
          <Chip><UserCheck className="w-3.5 h-3.5" />{resumen.con_cuenta} con cuenta en Rezonar</Chip>
          <Chip><Heart className="w-3.5 h-3.5" />{resumen.seguidores} siguen la página</Chip>
        </div>
      )}

      <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4 mb-3">
        <div className="relative md:col-span-2">
          <Search className="w-4 h-4 text-tinta-suave absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none" />
          <Campo
            type="search"
            value={filtros.q}
            onChange={cambiar('q')}
            placeholder="Buscar por nombre, email o teléfono"
            aria-label="Buscar cliente"
            className="pl-11"
          />
        </div>

        <Selector value={filtros.evento} onChange={cambiar('evento')} aria-label="Evento" className="md:col-span-2">
          <option value="">Todos los eventos</option>
          {eventos.map((e) => (
            <option key={e.id} value={e.id}>
              {e.titulo} · {fechaLegible(e)}
              {e.eliminado ? ' (eliminado)' : ''}
              {e.rol === 'colaborador' ? ' · colaboración' : ''}
            </option>
          ))}
        </Selector>

        <Selector value={filtros.origen} onChange={cambiar('origen')} aria-label="Relación con la página">
          <option value="">Compraron, reservaron o siguen</option>
          <option value="compro">Compraron</option>
          <option value="reservo">Reservaron gratis</option>
          <option value="sigue">Siguen la página</option>
        </Selector>

        <Selector value={filtros.asistencia} onChange={cambiar('asistencia')} aria-label="Asistencia">
          <option value="">Vinieron o no</option>
          <option value="vino">Vinieron</option>
          <option value="no_vino">No vinieron</option>
        </Selector>

        <Selector value={filtros.cuenta} onChange={cambiar('cuenta')} aria-label="Cuenta en Rezonar">
          <option value="">Con o sin cuenta</option>
          <option value="si">Con cuenta en Rezonar</option>
          <option value="no">Sin cuenta</option>
        </Selector>

        <div className="flex gap-2">
          <label className="flex items-center gap-2 text-xs text-tinta-suave flex-1">
            Desde
            <Campo type="date" value={filtros.desde} onChange={cambiar('desde')} aria-label="Desde" className="!px-3" />
          </label>
          <label className="flex items-center gap-2 text-xs text-tinta-suave flex-1">
            Hasta
            <Campo type="date" value={filtros.hasta} onChange={cambiar('hasta')} aria-label="Hasta" className="!px-3" />
          </label>
        </div>
      </div>

      <p className="text-xs text-tinta-suave mb-6">
        Las fechas son las de los eventos. “No vinieron” son quienes tenían entrada para un evento que ya pasó y no
        entraron a ninguno.
        {hayFiltros && (
          <>
            {' '}
            <button type="button" onClick={() => setFiltros(FILTROS_VACIOS)} className="underline hover:text-tinta">
              Limpiar filtros
            </button>
          </>
        )}
      </p>

      {error && <Aviso tipo="error" className="mb-6">{error}</Aviso>}

      {cargando && !datos && <Cargando texto="Buscando clientes…" />}

      {datos && !error && clientes.length === 0 && (
        <Vacio
          icono={Users}
          titulo={hayFiltros ? 'Nadie coincide con los filtros' : 'Todavía no hay clientes'}
          detalle={hayFiltros ? null : 'Cuando alguien compre o reserve una entrada, o siga la página, va a aparecer acá.'}
        />
      )}

      {datos && clientes.length > 0 && (
        <ul className={`divide-y divide-borde border border-borde rounded-xl ${cargando ? 'opacity-60' : ''}`}>
          {clientes.slice(0, visibles).map((c) => (
            <FilaCliente
              key={c.email}
              cliente={c}
              abierto={abierto === c.email}
              alternar={() => setAbierto(abierto === c.email ? null : c.email)}
            />
          ))}
        </ul>
      )}

      {datos && clientes.length > visibles && (
        <div className="flex justify-center mt-6">
          <Boton variante="secundario" onClick={() => setVisibles(visibles + POR_TANDA)}>
            Ver más ({clientes.length - visibles} restantes)
          </Boton>
        </div>
      )}
    </div>
  );
}

function FilaCliente({ cliente: c, abierto, alternar }) {
  const Flecha = abierto ? ChevronUp : ChevronDown;

  return (
    <li>
      <button
        type="button"
        onClick={alternar}
        aria-expanded={abierto}
        className="w-full text-left px-5 py-4 hover:bg-papel-hueso transition-colors flex items-start justify-between gap-4"
      >
        <span className="min-w-0">
          <span className="block font-bold truncate">{c.nombre || c.email}</span>
          <span className="block text-sm text-tinta-media truncate">
            {c.email}
            {c.telefono ? ` · ${c.telefono}` : ''}
          </span>
          <span className="flex flex-wrap gap-1.5 mt-2">
            {c.tiene_cuenta && <Chip tono="verde">Cuenta en Rezonar</Chip>}
            {c.sigue && <Chip tono="verde">Sigue la página</Chip>}
            {c.compras > 0 && <Chip>{c.compras} {c.compras === 1 ? 'compra' : 'compras'}</Chip>}
            {c.reservas > 0 && <Chip>{c.reservas} {c.reservas === 1 ? 'reserva' : 'reservas'}</Chip>}
            {c.eventos > 0 && (
              <Chip>
                Vino a {c.asistencias} de {c.eventos} {c.eventos === 1 ? 'evento' : 'eventos'}
              </Chip>
            )}
          </span>
        </span>

        <span className="flex items-center gap-3 shrink-0 text-sm">
          {Object.entries(c.gastado || {}).map(([moneda, monto]) => (
            <span key={moneda} className="font-bold text-verde-oscuro">{formatearPrecio(monto, moneda)}</span>
          ))}
          <Flecha className="w-4 h-4 text-tinta-suave" />
        </span>
      </button>

      {abierto && (
        <div className="px-5 pb-5">
          {c.participaciones.length === 0 ? (
            <p className="text-sm text-tinta-suave">
              Sigue la página{c.sigue_desde ? ` desde el ${fechaCorta(c.sigue_desde)}` : ''}. Todavía no compró entradas.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-tinta-suave uppercase tracking-wide">
                    <th className="py-2 pr-4 font-semibold">Evento</th>
                    <th className="py-2 pr-4 font-semibold">Estado</th>
                    <th className="py-2 pr-4 font-semibold">Entradas</th>
                    <th className="py-2 pr-4 font-semibold">Vino</th>
                    <th className="py-2 pr-4 font-semibold text-right">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-borde">
                  {c.participaciones.map((p) => (
                    <tr key={p.codigo}>
                      <td className="py-2 pr-4">
                        <span className="block font-semibold">{p.evento}</span>
                        <span className="block text-xs text-tinta-suave">
                          {fechaLegible(p)}
                          {p.eliminado ? ' · evento eliminado' : ''}
                          {p.rol === 'colaborador' ? ' · colaboración' : ''}
                        </span>
                      </td>
                      <td className="py-2 pr-4">{ESTADOS[p.estado] || p.estado}</td>
                      <td className="py-2 pr-4">{p.cantidad}</td>
                      <td className="py-2 pr-4">
                        {p.ingresadas > 0 ? `Sí (${p.ingresadas}/${p.cantidad})` : 'No'}
                      </td>
                      <td className="py-2 pr-4 text-right">
                        {Number(p.total) > 0 ? formatearPrecio(p.total, p.moneda) : 'Gratis'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </li>
  );
}

/** "2026-07-01 12:00:00" → "01/07/2026". */
function fechaCorta(fechaHora) {
  const [anio, mes, dia] = String(fechaHora).slice(0, 10).split('-');
  return `${dia}/${mes}/${anio}`;
}

export default PanelClientes;
