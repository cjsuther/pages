import React, { useState, useEffect } from 'react';
import { Download, RefreshCw } from 'lucide-react';
import { formatearPrecio, etiquetaDeEstado, colorDeEstado } from '../utils/entradas';
import { urlDeWhatsApp } from '../utils/telefono';
import { IconoDeMarca } from './IconosRedes';

/**
 * Listado de ventas de un evento, dentro del modal de edición.
 *
 * Muestra los datos de contacto de los compradores, así que el servidor sólo lo
 * responde a quien administra la página.
 */
function PanelVentas({ linkId, apiUrl, token }) {
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

  const cargar = async () => {
    setCargando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/ventas.php?link_id=${linkId}`, {
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
  }, [linkId]);

  const exportar = async () => {
    // La descarga necesita la cabecera de sesión, así que no puede ser un
    // enlace directo: se trae el archivo y se descarga desde memoria.
    const r = await fetch(`${apiUrl}/entradas/ventas.php?link_id=${linkId}&formato=csv`, {
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
    enlace.download = `ventas-evento-${linkId}.csv`;
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
    URL.revokeObjectURL(url);
  };

  if (cargando) {
    return <p className="text-gray-500 py-6">Cargando ventas...</p>;
  }

  if (error) {
    return <p className="text-sm text-red-400 bg-red-950 border border-red-900 px-4 py-3">{error}</p>;
  }

  const { ordenes = [], resumen = {}, capacidad = 0 } = datos || {};

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Dato etiqueta="VENDIDAS" valor={capacidad ? `${resumen.vendidas}/${capacidad}` : resumen.vendidas} />
        <Dato etiqueta="RESERVANDO" valor={resumen.reservadas} />
        <Dato etiqueta="RECAUDADO" valor={formatearPrecio(resumen.recaudado)} />
        {/* Lo que efectivamente entra a la cuenta, ya descontada la comisión:
            es el número con el que el dueño hace sus cuentas. */}
        <Dato
          etiqueta="TE QUEDA"
          valor={formatearPrecio(resumen.neto !== undefined ? resumen.neto : resumen.recaudado)}
          detalle={resumen.comision > 0 ? `comisión ${formatearPrecio(resumen.comision)}` : null}
        />
      </div>

      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={cargar}
          className="text-sm text-gray-400 hover:text-white flex items-center gap-2"
        >
          <RefreshCw className="w-4 h-4" />
          Actualizar
        </button>

        {ordenes.length > 0 && (
          <button
            type="button"
            onClick={exportar}
            className="text-sm text-gray-400 hover:text-white flex items-center gap-2"
          >
            <Download className="w-4 h-4" />
            Exportar CSV
          </button>
        )}
      </div>

      {ordenes.length === 0 ? (
        <p className="text-gray-500 text-sm border border-gray-800 bg-black p-6">
          Todavía no hay ventas para este evento.
        </p>
      ) : (
        <div className="overflow-x-auto border border-gray-800">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-black text-gray-500 text-left">
                <th className="px-3 py-2 font-bold">Nombre</th>
                <th className="px-3 py-2 font-bold">Contacto</th>
                <th className="px-3 py-2 font-bold text-right">Cant.</th>
                <th className="px-3 py-2 font-bold text-right">Total</th>
                <th className="px-3 py-2 font-bold">Estado</th>
              </tr>
            </thead>
            <tbody>
              {ordenes.map((o) => (
                <tr key={o.id} className="border-t border-gray-800">
                  <td className="px-3 py-2 text-white">
                    {o.nombre}
                    <span className="block text-xs text-gray-600 font-mono">{o.codigo}</span>
                  </td>
                  <td className="px-3 py-2 text-gray-400">
                    <a href={`mailto:${o.email}`} className="hover:text-white block">{o.email}</a>
                    <Telefono numero={o.telefono} nombre={o.nombre} />
                  </td>
                  <td className="px-3 py-2 text-white text-right">{o.cantidad}</td>
                  <td className="px-3 py-2 text-white text-right whitespace-nowrap">
                    {formatearPrecio(o.total, o.moneda)}
                  </td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-2 py-0.5 rounded ${colorDeEstado(o.estado)}`}>
                      {etiquetaDeEstado(o.estado)}
                    </span>
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

/**
 * Teléfono del comprador, como link directo a WhatsApp.
 *
 * Es por donde se le escribe en la práctica: avisar de un cambio de horario o
 * confirmar una reserva se hace por ahí, no llamando.
 *
 * Si el número está tan mal escrito que no se puede armar un WhatsApp
 * confiable, se deja como texto en vez de mandar a un número equivocado.
 */
function Telefono({ numero, nombre }) {
  const url = urlDeWhatsApp(numero);

  if (!url) {
    return <span className="block text-xs" title="No se pudo interpretar el número">{numero}</span>;
  }

  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`Escribirle por WhatsApp a ${nombre}`}
      className="hover:text-emerald-400 flex items-center gap-1 text-xs transition"
    >
      <IconoDeMarca red="whatsapp" className="w-3.5 h-3.5 shrink-0" />
      {numero}
    </a>
  );
}

function Dato({ etiqueta, valor, detalle }) {
  return (
    <div className="border border-gray-800 bg-black p-4">
      <p className="text-xs text-gray-500 tracking-wide mb-1">{etiqueta}</p>
      <p className="text-xl font-black text-white">{valor}</p>
      {detalle && <p className="text-xs text-gray-600 mt-1">{detalle}</p>}
    </div>
  );
}

export default PanelVentas;
