import React, { useEffect, useState } from 'react';
import { Download, RefreshCw, Send } from 'lucide-react';
import { formatearPrecio, etiquetaDeEstado, colorDeEstado } from '../utils/entradas';
import { fechaCorta } from './PanelVentas';
import PanelPedidosFisicos from './PanelPedidosFisicos';

/** "No", o "Sí · 1 vez" / "Sí · 3 veces". Lo mismo que dice el Excel. */
export function describirDescargas(descargas) {
  const veces = Number(descargas) || 0;
  if (veces <= 0) return 'No';
  return `Sí · ${veces} ${veces === 1 ? 'vez' : 'veces'}`;
}

/** "ventas-guia-de-viaje.xlsx". */
export function nombreDelExcel(nombre) {
  const limpio = String(nombre || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60);

  return `ventas-${limpio || 'productos'}.xlsx`;
}

const etiquetaDe = (estado) => (estado === 'pendiente' ? 'Pendiente' : etiquetaDeEstado(estado));

/**
 * Las ventas de productos: las de un producto (linkId), en su pestaña Ventas,
 * o las de todos los productos de la página (pageId), los borrados incluidos.
 *
 * Por página van en dos pestañas, Digitales y Físicos (#44). En un producto,
 * la de su tipo: los pedidos de un físico se gestionan igual que desde la
 * página, filtrados a ese producto.
 *
 * Muestra datos de contacto de quienes compraron, así que el servidor sólo lo
 * responde a quien administra la página. Lo que no muestra nunca es el link de
 * descarga: el reenvío lo manda por mail a quien compró, sin pasar por acá.
 */
function PanelVentasProductos({ linkId = null, pageId = null, apiUrl, token, nombre = '', tipo = null }) {
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [reenviando, setReenviando] = useState(null);
  const [aviso, setAviso] = useState(null);
  const [pestana, setPestana] = useState(tipo === 'fisico' ? 'fisicos' : 'digitales');

  const porPagina = !linkId;
  const filtro = porPagina ? `page_id=${pageId}` : `link_id=${linkId}`;

  const cargar = async () => {
    setCargando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/productos/ventas.php?${filtro}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No pudimos cargar las ventas');
        return;
      }

      setDatos(cuerpo);
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    cargar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtro]);

  const exportar = async () => {
    // Con la cabecera de sesión no puede ser un enlace directo: se trae el
    // archivo y se descarga desde memoria.
    setAviso(null);

    try {
      const r = await fetch(
        `${apiUrl}/productos/ventas.php?${filtro}&formato=excel&tipo=digital&nombre=${encodeURIComponent(nombre)}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );

      if (!r.ok) {
        setAviso({ tipo: 'error', texto: 'No pudimos generar el archivo' });
        return;
      }

      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');

      enlace.href = url;
      enlace.download = nombreDelExcel(nombre);
      document.body.appendChild(enlace);
      enlace.click();
      document.body.removeChild(enlace);
      URL.revokeObjectURL(url);
    } catch (e) {
      setAviso({ tipo: 'error', texto: 'No pudimos conectarnos al servidor' });
    }
  };

  const reenviar = async (orden) => {
    setReenviando(orden.codigo);
    setAviso(null);

    try {
      const r = await fetch(`${apiUrl}/productos/reenviar.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo: orden.codigo }),
      });
      const cuerpo = await r.json().catch(() => ({}));

      setAviso(r.ok
        ? { tipo: 'ok', texto: cuerpo.mensaje || `Le reenviamos el link a ${orden.email}` }
        : { tipo: 'error', texto: cuerpo.error || 'No pudimos reenviar el link' });
    } catch (e) {
      setAviso({ tipo: 'error', texto: 'No pudimos conectarnos al servidor' });
    } finally {
      setReenviando(null);
    }
  };

  if (cargando && !datos) {
    return <p className="text-tinta-suave py-6">Cargando ventas...</p>;
  }

  if (error) {
    return <p className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>;
  }

  const { ordenes: todas = [], resumen = {}, resumen_fisicos: resumenFisicos = {} } = datos || {};
  const fisicos = todas.filter((o) => o.tipo === 'fisico');
  const ordenes = todas.filter((o) => o.tipo !== 'fisico');

  const pestanas = porPagina && (
    <div role="tablist" aria-label="Tipo de producto" className="flex gap-1 border-b border-borde">
      {[['digitales', 'Digitales'], ['fisicos', 'Físicos']].map(([clave, etiqueta]) => (
        <button
          key={clave}
          type="button"
          role="tab"
          aria-selected={pestana === clave}
          onClick={() => setPestana(clave)}
          className={`px-4 py-2 text-sm font-semibold -mb-px border-b-2 ${
            pestana === clave ? 'border-tinta text-tinta' : 'border-transparent text-tinta-suave hover:text-tinta'
          }`}
        >
          {etiqueta}
        </button>
      ))}
    </div>
  );

  if (pestana === 'fisicos') {
    return (
      <div className="space-y-6">
        {pestanas}

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Dato etiqueta="PEDIDOS PAGADOS" valor={resumenFisicos.pagadas || 0} />
          <Dato etiqueta="RECAUDADO" valor={formatearPrecio(resumenFisicos.recaudado)} />
        </div>

        <PanelPedidosFisicos
          pedidos={fisicos}
          filtro={filtro}
          apiUrl={apiUrl}
          token={token}
          nombre={nombre}
          porPagina={porPagina}
          onRecargar={cargar}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {pestanas}

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <Dato etiqueta="VENTAS PAGADAS" valor={resumen.pagadas || 0} />
        <Dato etiqueta="RECAUDADO" valor={formatearPrecio(resumen.recaudado)} />
        <Dato
          etiqueta="YA DESCARGARON"
          valor={resumen.pagadas ? `${resumen.descargaron || 0}/${resumen.pagadas}` : resumen.descargaron || 0}
        />
      </div>

      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={cargar}
          className="text-sm text-tinta-media hover:text-tinta flex items-center gap-2"
        >
          <RefreshCw className="w-4 h-4" />
          Actualizar
        </button>

        {ordenes.length > 0 && (
          <button
            type="button"
            onClick={exportar}
            className="text-sm text-tinta-media hover:text-tinta flex items-center gap-2"
          >
            <Download className="w-4 h-4" />
            Exportar Excel
          </button>
        )}
      </div>

      {aviso && (
        <p
          role={aviso.tipo === 'error' ? 'alert' : 'status'}
          className={`text-sm px-4 py-3 border ${
            aviso.tipo === 'error'
              ? 'text-red-700 bg-red-50 border-red-200'
              : 'text-verde-oscuro bg-verde-claro border-verde-medio'
          }`}
        >
          {aviso.texto}
        </p>
      )}

      {ordenes.length === 0 ? (
        <p className="text-tinta-suave text-sm border border-borde bg-white p-6">
          {porPagina ? 'Todavía no hay ventas de productos en esta página.' : 'Todavía no hay ventas de este producto.'}
        </p>
      ) : (
        <div className="overflow-x-auto border border-borde">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-papel-hueso text-tinta-suave text-left">
                <th className="px-3 py-2 font-bold">Estado</th>
                {porPagina && <th className="px-3 py-2 font-bold">Producto</th>}
                <th className="px-3 py-2 font-bold">Nombre</th>
                <th className="px-3 py-2 font-bold">Contacto</th>
                <th className="px-3 py-2 font-bold text-right">Total</th>
                <th className="px-3 py-2 font-bold">Fecha</th>
                <th className="px-3 py-2 font-bold">Descargó</th>
                <th className="px-3 py-2"><span className="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              {ordenes.map((o) => (
                <tr key={o.id} className="border-t border-borde">
                  <td className="px-3 py-2 whitespace-nowrap">
                    <span className={`text-xs px-2 py-0.5 rounded ${colorDeEstado(o.estado)}`}>
                      {etiquetaDe(o.estado)}
                    </span>
                  </td>
                  {porPagina && (
                    <td className="px-3 py-2 text-tinta">
                      {o.producto}
                      {o.producto_borrado && (
                        <span className="block text-xs text-tinta-suave">Producto borrado</span>
                      )}
                    </td>
                  )}
                  <td className="px-3 py-2 text-tinta">
                    {o.nombre}
                    <span className="block text-xs text-tinta-suave font-mono">{o.codigo}</span>
                  </td>
                  <td className="px-3 py-2 text-tinta-media">
                    <a href={`mailto:${o.email}`} className="hover:text-tinta block">{o.email}</a>
                    {o.telefono && <span className="block text-xs">{o.telefono}</span>}
                  </td>
                  <td className="px-3 py-2 text-tinta text-right whitespace-nowrap">
                    {Number(o.total) > 0 ? formatearPrecio(o.total, o.moneda) : 'Gratis'}
                  </td>
                  <td className="px-3 py-2 text-tinta-media whitespace-nowrap">{fechaCorta(o.creado_en)}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    <span className={o.descargas > 0 ? 'text-verde-oscuro font-semibold' : 'text-tinta-suave'}>
                      {describirDescargas(o.descargas)}
                    </span>
                    {o.descargas > 0 && fechaCorta(o.primera_descarga_en) && (
                      <span className="block text-xs text-tinta-suave">
                        la primera el {fechaCorta(o.primera_descarga_en)}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {o.estado === 'pagada' && (
                      <button
                        type="button"
                        onClick={() => reenviar(o)}
                        disabled={reenviando !== null}
                        aria-label={`Reenviar link a ${o.nombre}`}
                        className="text-xs text-tinta-media hover:text-tinta inline-flex items-center gap-1 whitespace-nowrap disabled:opacity-50"
                      >
                        <Send className="w-3.5 h-3.5" />
                        {reenviando === o.codigo ? 'Enviando...' : 'Reenviar link'}
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

function Dato({ etiqueta, valor }) {
  return (
    <div className="border border-borde bg-white p-4">
      <p className="text-xs text-tinta-suave tracking-wide mb-1">{etiqueta}</p>
      <p className="text-xl font-bold text-tinta">{valor}</p>
    </div>
  );
}

export default PanelVentasProductos;
