import React, { useState, useEffect } from 'react';
import { Download, RefreshCw } from 'lucide-react';
import { formatearPrecio, etiquetaDeEstado, colorDeEstado } from '../utils/entradas';
import { urlDeWhatsApp } from '../utils/telefono';
import { IconoDeMarca } from './IconosRedes';

/**
 * Estados desde los que una compra se puede dar de baja.
 *
 * Una vencida ya no ocupa lugar y una rechazada nunca lo ocupó: cancelarlas no
 * cambiaría nada y sólo agregaría un botón que no hace lo que promete.
 */
const CANCELABLES = ['pagada', 'reservada'];

export const sePuedeCancelar = (estado) => CANCELABLES.includes(estado);

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
  const [porCancelar, setPorCancelar] = useState(null);
  const [cancelando, setCancelando] = useState(false);
  const [errorCancelacion, setErrorCancelacion] = useState(null);

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

  /**
   * Cancelar devuelve los lugares al cupo y no se deshace desde acá, así que
   * se pregunta antes. El servidor contesta con las ventas ya actualizadas:
   * volver a pedirlas mostraría un cupo viejo por un instante.
   */
  const confirmarCancelacion = async () => {
    setCancelando(true);
    // Aparte del error de carga: ése reemplaza el panel entero, y una
    // cancelación fallida no puede llevarse puesto el listado de ventas.
    setErrorCancelacion(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/cancelar.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo: porCancelar.codigo }),
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setErrorCancelacion(cuerpo.error || 'No pudimos cancelar la compra');
        return;
      }

      setDatos(cuerpo.ventas);
      setPorCancelar(null);
    } catch (e) {
      setErrorCancelacion('No pudimos conectarnos al servidor');
    } finally {
      setCancelando(false);
    }
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

      <Acreditacion resumen={resumen} />

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
                <th className="px-3 py-2"><span className="sr-only">Acciones</span></th>
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
                    {o.mp_neto !== null && o.mp_neto !== undefined && (
                      <span className="block text-xs text-gray-600">
                        te quedan {formatearPrecio(o.mp_neto, o.moneda)}
                        {fechaCorta(o.acreditacion_en) && ` el ${fechaCorta(o.acreditacion_en)}`}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-2 py-0.5 rounded ${colorDeEstado(o.estado)}`}>
                      {etiquetaDeEstado(o.estado)}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-right">
                    {sePuedeCancelar(o.estado) && (
                      <button
                        type="button"
                        onClick={() => setPorCancelar(o)}
                        className="text-xs text-gray-500 hover:text-red-400 transition"
                      >
                        Cancelar
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {porCancelar && (
        <div className="fixed inset-0 bg-black bg-opacity-80 z-50 flex items-center justify-center p-4">
          <div className="bg-gray-900 border border-gray-700 rounded-lg p-6 max-w-sm w-full">
            <h4 className="text-white font-bold text-lg mb-2">¿Cancelar esta compra?</h4>
            <p className="text-sm text-gray-400 mb-1">
              {porCancelar.cantidad}{' '}
              {porCancelar.cantidad === 1 ? 'entrada' : 'entradas'} de {porCancelar.nombre}.
            </p>
            <p className="text-sm text-gray-500 mb-6">
              Los lugares vuelven a estar disponibles y la compra queda registrada como cancelada.
            </p>

            {errorCancelacion && (
              <p role="alert" className="text-sm text-red-400 bg-red-950 border border-red-900 px-3 py-2 mb-4">
                {errorCancelacion}
              </p>
            )}

            <div className="flex gap-3 justify-end">
              <button
                type="button"
                onClick={() => {
                  setPorCancelar(null);
                  setErrorCancelacion(null);
                }}
                disabled={cancelando}
                className="px-4 py-2 text-sm text-gray-400 hover:text-white transition"
              >
                Volver
              </button>
              <button
                type="button"
                onClick={confirmarCancelacion}
                disabled={cancelando}
                className="px-4 py-2 text-sm font-bold bg-red-600 text-white rounded hover:bg-red-500 transition disabled:opacity-50"
              >
                {cancelando ? 'Cancelando...' : 'Cancelar la compra'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/** Fecha corta, o null si no hay dato. Mercado Pago la manda con hora. */
export function fechaCorta(momento) {
  if (!momento) return null;

  const [anio, mes, dia] = String(momento).slice(0, 10).split('-');

  return dia ? `${dia}/${mes}/${anio}` : null;
}

/**
 * Cuándo y cuánto entra la plata, según Mercado Pago.
 *
 * "Te queda" es una cuenta nuestra: el total menos nuestra comisión. Esto es
 * otra cosa —lo que Mercado Pago dice que va a depositar, ya descontada
 * también la suya— y por eso puede no coincidir. El plazo lo fija cada
 * vendedor en su cuenta de Mercado Pago, no Rezonar: con tarjeta de crédito
 * puede ser un mes, con dinero en cuenta el mismo día.
 */
export function Acreditacion({ resumen }) {
  const porAcreditar = resumen.por_acreditar || 0;
  const acreditado = resumen.acreditado || 0;
  const sinDato = resumen.ventas_sin_dato || 0;

  if (porAcreditar === 0 && acreditado === 0 && sinDato === 0) {
    return null;
  }

  const proxima = fechaCorta(resumen.proxima_acreditacion);

  return (
    <div className="border border-gray-800 bg-black p-4 text-sm">
      <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2">
        <span className="text-gray-500">
          Ya disponible <strong className="text-white">{formatearPrecio(acreditado)}</strong>
        </span>

        {porAcreditar > 0 && (
          <span className="text-gray-500">
            Por acreditarse <strong className="text-white">{formatearPrecio(porAcreditar)}</strong>
            {proxima && <span className="text-gray-600"> · desde el {proxima}</span>}
          </span>
        )}
      </div>

      <p className="text-xs text-gray-600 mt-2">
        Según Mercado Pago, ya descontada su comisión. El plazo lo elegís en tu cuenta
        de Mercado Pago, en Costos y plazos.
        {sinDato > 0 && ` (${sinDato} ${sinDato === 1 ? 'venta' : 'ventas'} sin este dato: son anteriores a que lo empezáramos a guardar.)`}
      </p>
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
