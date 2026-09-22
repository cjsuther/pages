import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Helmet } from 'react-helmet-async';
import {
  Camera, CameraOff, Search, Check, X, AlertTriangle, Loader2, Undo2,
} from 'lucide-react';
import EscanerQr from '../components/EscanerQr';
import { resumirLugares } from '../utils/plano';
import { codigoDesdeQr, filtrarOrdenes, horaCorta, describirResultado } from '../utils/puerta';

/** "sábado 31 de diciembre · 22:00", o null si el evento no tiene fecha. */
function fechaDelEvento(evento) {
  if (!evento.event_date) return null;

  const fecha = new Date(`${String(evento.event_date).slice(0, 10)}T00:00:00`);

  if (Number.isNaN(fecha.getTime())) return null;

  const dia = fecha.toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });

  return evento.event_time ? `${dia} · ${String(evento.event_time).slice(0, 5)}` : dia;
}

/** Cada cuánto se refresca la lista, para ver lo que marcan las otras puertas. */
const SEGUNDOS_ENTRE_ACTUALIZACIONES = 15;

/**
 * La puerta de un evento: escanear entradas o buscar en la lista, y marcar
 * quién entró.
 *
 * No lleva sesión: se entra con un link que genera quien organiza, y la clave
 * va después del # (el navegador no la manda al servidor al pedir la página).
 * Se le puede dar a cualquiera que esté en la puerta sin darle una cuenta.
 */
function Puerta({ apiUrl }) {
  const clave = useRef(window.location.hash.replace(/^#/, '')).current;
  const [evento, setEvento] = useState(null);
  const [ordenes, setOrdenes] = useState([]);
  const [resumen, setResumen] = useState(null);
  const [error, setError] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [escaneando, setEscaneando] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [resultado, setResultado] = useState(null);
  const [procesando, setProcesando] = useState(false);

  const pedir = useCallback(async (accion, datos = {}) => {
    const r = await fetch(`${apiUrl}/public/puerta.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clave, accion, ...datos }),
    });
    const cuerpo = await r.json();

    if (!r.ok) throw new Error(cuerpo.error || 'No se pudo completar');

    return cuerpo;
  }, [apiUrl, clave]);

  const actualizar = useCallback(async () => {
    try {
      const cuerpo = await pedir('estado');
      setEvento(cuerpo.evento);
      setOrdenes(cuerpo.ordenes || []);
      setResumen(cuerpo.resumen || null);
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setCargando(false);
    }
  }, [pedir]);

  useEffect(() => {
    if (!clave) {
      setError('Este link de puerta está incompleto. Pedile a quien organiza que te lo mande de nuevo.');
      setCargando(false);
      return undefined;
    }

    actualizar();

    // Si hay dos puertas, lo que marca una tiene que aparecer en la otra. Con
    // la pestaña en segundo plano no se pide nada.
    const intervalo = setInterval(() => {
      if (document.visibilityState === 'visible') actualizar();
    }, SEGUNDOS_ENTRE_ACTUALIZACIONES * 1000);

    return () => clearInterval(intervalo);
  }, [clave, actualizar]);

  /** Mira una entrada y abre su tarjeta. */
  const mirar = async (codigo) => {
    setProcesando(true);

    try {
      setResultado(await pedir('mirar', { codigo }));
    } catch (e) {
      setResultado({ resultado: 'error', orden: null, mensaje: e.message });
    } finally {
      setProcesando(false);
    }
  };

  const alLeerQr = (texto) => {
    if (resultado || procesando) return;

    const codigo = codigoDesdeQr(texto);

    if (codigo === null) {
      setResultado({ resultado: 'no_existe', orden: null });
      return;
    }

    mirar(codigo);
  };

  const ingresar = async (codigo, cantidad) => {
    setProcesando(true);

    try {
      const r = await pedir('ingresar', { codigo, cantidad });
      setResultado({ ...r, recienIngresadas: r.ok ? cantidad : 0 });
      actualizar();
    } catch (e) {
      setResultado({ resultado: 'error', orden: null, mensaje: e.message });
    } finally {
      setProcesando(false);
    }
  };

  const deshacer = async (codigo, cantidad) => {
    setProcesando(true);

    try {
      setResultado(await pedir('deshacer', { codigo, cantidad }));
      actualizar();
    } catch (e) {
      setResultado({ resultado: 'error', orden: null, mensaje: e.message });
    } finally {
      setProcesando(false);
    }
  };

  const encontradas = filtrarOrdenes(ordenes, busqueda);

  return (
    <div className="min-h-screen bg-papel-hueso">
      <Helmet>
        <title>Puerta{evento ? ` · ${evento.text}` : ''}</title>
        {/* La lista tiene nombres de personas: no tiene que aparecer en ningún buscador. */}
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
      </Helmet>

      <div className="max-w-lg mx-auto px-4 py-6 space-y-5">
        {cargando && (
          <p className="flex items-center gap-2 text-tinta-suave">
            <Loader2 className="w-4 h-4 animate-spin" /> Cargando...
          </p>
        )}

        {error && !evento && (
          <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>
        )}

        {evento && (
          <>
            <header>
              <p className="text-xs font-bold tracking-widest text-tinta-suave">PUERTA · {evento.pagina}</p>
              <h1 className="text-2xl font-bold text-tinta">{evento.text}</h1>
              {fechaDelEvento(evento) && (
                <p className="text-sm text-tinta-media">{fechaDelEvento(evento)}</p>
              )}
            </header>

            {resumen && (
              <div className="bg-white border border-borde p-4">
                <p className="text-sm text-tinta-suave">Entraron</p>
                <p className="text-3xl font-bold text-tinta">
                  {resumen.ingresadas}
                  <span className="text-lg text-tinta-suave font-semibold"> de {resumen.entradas}</span>
                </p>
                <div className="h-2 bg-borde mt-2">
                  <div
                    className="h-2 bg-verde"
                    style={{ width: `${resumen.entradas ? (100 * resumen.ingresadas) / resumen.entradas : 0}%` }}
                  />
                </div>
              </div>
            )}

            {error && (
              <p className="text-xs text-amber-700">No se pudo actualizar la lista: {error}</p>
            )}

            <button
              type="button"
              onClick={() => setEscaneando((e) => !e)}
              className={`w-full py-4 font-bold flex items-center justify-center gap-2 ${
                escaneando ? 'bg-white border border-borde-fuerte text-tinta' : 'bg-tinta text-white'
              }`}
            >
              {escaneando ? <CameraOff className="w-5 h-5" /> : <Camera className="w-5 h-5" />}
              {escaneando ? 'Cerrar cámara' : 'Escanear QR'}
            </button>

            {escaneando && <EscanerQr onLeer={alLeerQr} pausado={Boolean(resultado) || procesando} />}

            <div>
              <label htmlFor="puerta-buscar" className="sr-only">Buscar por nombre, código o lugar</label>
              <div className="relative">
                <Search className="w-4 h-4 text-tinta-suave absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  id="puerta-buscar"
                  type="search"
                  value={busqueda}
                  onChange={(e) => setBusqueda(e.target.value)}
                  placeholder="Buscar por nombre, código o lugar"
                  className="w-full pl-9 pr-3 py-3 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro focus:outline-none"
                />
              </div>
            </div>

            <ul className="bg-white border border-borde divide-y divide-borde">
              {encontradas.length === 0 && (
                <li className="p-4 text-sm text-tinta-suave">
                  {ordenes.length === 0 ? 'Todavía no hay entradas vendidas.' : 'Nadie coincide con esa búsqueda.'}
                </li>
              )}
              {encontradas.map((o) => {
                const completa = o.ingresadas >= o.cantidad;
                return (
                  <li key={o.codigo}>
                    <button
                      type="button"
                      onClick={() => mirar(o.codigo)}
                      className="w-full text-left p-4 flex items-center justify-between gap-3 hover:bg-papel-hueso"
                    >
                      <span className="min-w-0">
                        <span className="block font-semibold text-tinta truncate">{o.nombre}</span>
                        <span className="block text-xs text-tinta-suave font-mono">{o.codigo}</span>
                        {o.lugares.length > 0 && (
                          <span className="block text-xs text-tinta-media">{resumirLugares(o.lugares)}</span>
                        )}
                      </span>
                      <span
                        className={`shrink-0 text-sm font-bold px-2 py-1 ${
                          completa ? 'bg-verde-claro text-verde-oscuro' : 'text-tinta'
                        }`}
                      >
                        {o.ingresadas}/{o.cantidad}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          </>
        )}
      </div>

      {resultado && (
        <TarjetaDeEntrada
          key={`${resultado.resultado}-${resultado.orden ? resultado.orden.ingresadas : ''}-${resultado.recienIngresadas || 0}`}
          respuesta={resultado}
          procesando={procesando}
          onIngresar={ingresar}
          onDeshacer={deshacer}
          onCerrar={() => setResultado(null)}
        />
      )}
    </div>
  );
}

const TONOS = {
  bien: { fondo: 'bg-verde', texto: 'text-verde-tinta', Icono: Check },
  aviso: { fondo: 'bg-amber-400', texto: 'text-tinta', Icono: AlertTriangle },
  mal: { fondo: 'bg-red-600', texto: 'text-white', Icono: X },
};

/**
 * Lo que pasó con una entrada, grande y de un color que se entiende de lejos:
 * en una puerta con gente esperando no hay tiempo de leer.
 */
function TarjetaDeEntrada({ respuesta, procesando, onIngresar, onDeshacer, onCerrar }) {
  const orden = respuesta.orden;
  const [cantidad, setCantidad] = useState(orden ? Math.max(1, orden.restantes) : 1);

  const recien = respuesta.recienIngresadas || 0;
  const descripcion = recien > 0
    ? { titulo: '¡Adelante!', tono: 'bien', detalle: `${recien === 1 ? 'Entró 1 persona' : `Entraron ${recien} personas`}.` }
    : respuesta.resultado === 'error'
      ? { titulo: 'No se pudo', tono: 'mal', detalle: respuesta.mensaje }
      : describirResultado(respuesta);

  const tono = TONOS[descripcion.tono];

  return (
    <div className="fixed inset-0 z-50 bg-tinta/50 flex items-end sm:items-center justify-center" onClick={onCerrar}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={descripcion.titulo}
        className="bg-white w-full max-w-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <div className={`${tono.fondo} ${tono.texto} px-5 py-6 flex items-center gap-3`}>
          <tono.Icono className="w-9 h-9 shrink-0" />
          <div>
            <p className="text-2xl font-extrabold">{descripcion.titulo}</p>
            {descripcion.detalle && <p className="text-sm font-medium">{descripcion.detalle}</p>}
          </div>
        </div>

        <div className="p-5 space-y-4">
          {orden && (
            <div>
              <p className="text-xl font-bold text-tinta">{orden.nombre}</p>
              <p className="text-sm text-tinta-suave font-mono">{orden.codigo}</p>
              <p className="text-sm text-tinta-media mt-1">
                {orden.cantidad} {orden.cantidad === 1 ? 'entrada' : 'entradas'}
                {orden.ingresadas > 0 && ` · entraron ${orden.ingresadas}`}
                {orden.ingreso_en && ` (${horaCorta(orden.ingreso_en)})`}
              </p>
              {orden.lugares.length > 0 && (
                <p className="text-base font-bold text-tinta mt-1">{resumirLugares(orden.lugares)}</p>
              )}
            </div>
          )}

          {respuesta.resultado === 'valida' && orden && recien === 0 && (
            <div className="space-y-3">
              {orden.restantes > 1 && (
                <div>
                  <label htmlFor="puerta-cantidad" className="block text-sm font-semibold text-tinta mb-1">
                    ¿Cuántos entran ahora?
                  </label>
                  <select
                    id="puerta-cantidad"
                    value={cantidad}
                    onChange={(e) => setCantidad(Number(e.target.value))}
                    className="w-full px-3 py-3 bg-white border border-borde-fuerte text-tinta"
                  >
                    {Array.from({ length: orden.restantes }, (_, i) => i + 1).map((n) => (
                      <option key={n} value={n}>{n} de {orden.restantes}</option>
                    ))}
                  </select>
                </div>
              )}
              <button
                type="button"
                disabled={procesando}
                onClick={() => onIngresar(orden.codigo, cantidad)}
                className="w-full py-4 bg-verde text-verde-tinta font-bold text-lg flex items-center justify-center gap-2 disabled:opacity-60"
              >
                {procesando && <Loader2 className="w-5 h-5 animate-spin" />}
                {cantidad === 1 ? 'Marcar que entró' : `Marcar que entraron ${cantidad}`}
              </button>
            </div>
          )}

          <div className="flex items-center justify-between gap-3">
            {orden && (recien > 0 || orden.ingresadas > 0) ? (
              <button
                type="button"
                disabled={procesando}
                onClick={() => onDeshacer(orden.codigo, recien > 0 ? recien : 1)}
                className="inline-flex items-center gap-1.5 text-sm font-semibold text-tinta-media hover:text-tinta disabled:opacity-50"
              >
                <Undo2 className="w-4 h-4" />
                {recien > 1 ? `Deshacer (${recien})` : 'Deshacer uno'}
              </button>
            ) : <span />}
            <button
              type="button"
              onClick={onCerrar}
              className="px-5 py-3 border border-borde-fuerte text-tinta font-semibold"
            >
              Seguir
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default Puerta;
