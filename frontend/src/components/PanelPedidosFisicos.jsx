import React, { useState } from 'react';
import { Download, ChevronRight } from 'lucide-react';
import DetallePedido from './DetallePedido';
import { formatearPrecio, etiquetaDeEstado, colorDeEstado } from '../utils/entradas';
import { fechaCorta } from './PanelVentas';
import {
  ESTADOS_DEL_PEDIDO,
  contarPorEstado,
  describirLoPedido,
  nombreDelEstado,
  nombreDelExcelDePedidos,
  tonoDelEstado,
} from '../utils/pedidos';

/**
 * Los pedidos de productos físicos (#44): un filtro por estado con su
 * contador, la lista para ver de un vistazo qué hay que despachar y el
 * detalle de cada uno, donde se lo pasa al estado siguiente.
 *
 * Recibe los pedidos ya cargados (los de la página o los de un producto) y
 * pide recargar con onRecargar cuando uno cambia.
 */
function PanelPedidosFisicos({ pedidos, filtro, apiUrl, token, nombre = '', porPagina = false, onRecargar }) {
  const [estado, setEstado] = useState('');
  const [abierto, setAbierto] = useState(null);
  const [error, setError] = useState(null);

  const cuenta = contarPorEstado(pedidos);
  const enGestion = pedidos.filter((p) => p.estado_pedido);
  const visibles = estado ? pedidos.filter((p) => p.estado_pedido === estado) : pedidos;

  if (abierto) {
    return (
      <DetallePedido
        codigo={abierto}
        apiUrl={apiUrl}
        token={token}
        alVolver={() => setAbierto(null)}
        alCambiar={() => onRecargar && onRecargar()}
      />
    );
  }

  const exportar = async () => {
    setError(null);

    try {
      const r = await fetch(
        `${apiUrl}/productos/ventas.php?${filtro}&formato=excel&tipo=fisico`
          + `${estado ? `&estado_pedido=${estado}` : ''}&nombre=${encodeURIComponent(nombre)}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );

      if (!r.ok) {
        setError('No pudimos generar el archivo');
        return;
      }

      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');

      enlace.href = url;
      enlace.download = nombreDelExcelDePedidos(nombre);
      document.body.appendChild(enlace);
      enlace.click();
      document.body.removeChild(enlace);
      URL.revokeObjectURL(url);
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    }
  };

  return (
    <div className="space-y-4">
      <div role="group" aria-label="Filtrar por estado" className="flex flex-wrap gap-2">
        <BotonDeFiltro activo={estado === ''} onClick={() => setEstado('')}>
          Todos {pedidos.length}
        </BotonDeFiltro>
        {ESTADOS_DEL_PEDIDO.map((e) => (
          <BotonDeFiltro key={e.clave} activo={estado === e.clave} onClick={() => setEstado(e.clave)}>
            {e.plural} {cuenta[e.clave]}
          </BotonDeFiltro>
        ))}
      </div>

      {enGestion.length > 0 && (
        <button
          type="button"
          onClick={exportar}
          className="text-sm text-tinta-media hover:text-tinta flex items-center gap-2"
        >
          <Download className="w-4 h-4" />
          Exportar pedidos a Excel
        </button>
      )}

      {error && (
        <p role="alert" className="text-sm px-4 py-3 border text-red-700 bg-red-50 border-red-200">{error}</p>
      )}

      {visibles.length === 0 ? (
        <p className="text-tinta-suave text-sm border border-borde bg-white p-6">
          {estado ? `Ningún pedido en “${nombreDelEstado(estado)}”.` : 'Todavía no hay pedidos de productos físicos.'}
        </p>
      ) : (
        <div className="overflow-x-auto border border-borde">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-papel-hueso text-tinta-suave text-left">
                <th className="px-3 py-2 font-bold">Fecha</th>
                <th className="px-3 py-2 font-bold">Comprador</th>
                <th className="px-3 py-2 font-bold">Producto</th>
                <th className="px-3 py-2 font-bold text-right">Total</th>
                <th className="px-3 py-2 font-bold">Localidad</th>
                <th className="px-3 py-2 font-bold">Estado</th>
                <th className="px-3 py-2"><span className="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              {visibles.map((p) => (
                <tr key={p.id} className="border-t border-borde">
                  <td className="px-3 py-2 text-tinta-media whitespace-nowrap">{fechaCorta(p.creado_en)}</td>
                  <td className="px-3 py-2 text-tinta">
                    {p.nombre}
                    <span className="block text-xs text-tinta-suave font-mono">{p.codigo}</span>
                  </td>
                  <td className="px-3 py-2 text-tinta">
                    {porPagina && <span className="block">{p.producto}</span>}
                    <span className={porPagina ? 'block text-xs text-tinta-media' : ''}>{describirLoPedido(p)}</span>
                  </td>
                  <td className="px-3 py-2 text-tinta text-right whitespace-nowrap">
                    {Number(p.total) > 0 ? formatearPrecio(p.total, p.moneda) : 'Gratis'}
                  </td>
                  <td className="px-3 py-2 text-tinta-media">{p.localidad || '—'}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    {p.estado_pedido ? (
                      <span className={`text-xs px-2 py-0.5 rounded border ${tonoDelEstado(p.estado_pedido)}`}>
                        {nombreDelEstado(p.estado_pedido)}
                      </span>
                    ) : (
                      // Sin pagar: todavía no está en gestión.
                      <span className={`text-xs px-2 py-0.5 rounded ${colorDeEstado(p.estado)}`}>
                        {p.estado === 'pendiente' ? 'Pendiente' : etiquetaDeEstado(p.estado)}
                      </span>
                    )}
                    {p.sin_stock && (
                      <span className="block mt-1 text-xs font-semibold text-red-700">Sin stock</span>
                    )}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {p.estado_pedido && (
                      <button
                        type="button"
                        onClick={() => setAbierto(p.codigo)}
                        aria-label={`Ver el pedido de ${p.nombre}`}
                        className="text-xs text-tinta-media hover:text-tinta inline-flex items-center gap-1 whitespace-nowrap"
                      >
                        Ver
                        <ChevronRight className="w-3.5 h-3.5" />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function BotonDeFiltro({ activo, onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={activo}
      className={`text-sm px-3 py-1 rounded-full border transition-colors ${
        activo ? 'bg-tinta text-white border-tinta' : 'bg-white text-tinta-media border-borde hover:border-tinta'
      }`}
    >
      {children}
    </button>
  );
}

export default PanelPedidosFisicos;
