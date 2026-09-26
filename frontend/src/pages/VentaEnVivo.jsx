import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Helmet } from 'react-helmet-async';
import { Calendar, MapPin, Loader2, RefreshCw } from 'lucide-react';
import PlanoDeLugares from '../components/PlanoDeLugares';
import RitmoDeVenta from '../components/RitmoDeVenta';
import { formatearPrecio } from '../utils/entradas';
import { temaDeEvento } from '../utils/tema';
import { ProveedorDeTema, Fondo, Tarjeta, Boton, Rotulo, Dato } from '../components/UiDeEvento';

/** Cada cuánto se vuelve a pedir el estado, para dejar la pantalla abierta. */
const SEGUNDOS_ENTRE_ACTUALIZACIONES = 60;

/**
 * Cómo viene la venta de un evento, para quien tiene el link.
 *
 * Se comparte con el artista, el socio o quien produce: gente que necesita
 * ver cómo se está vendiendo pero no administra la página ni tiene cuenta.
 * Por eso muestra números y lugares ocupados, nunca quiénes compraron.
 *
 * La clave va después del # de la dirección, igual que en la puerta: el
 * navegador no la manda al pedir la página, así que no queda escrita en los
 * registros del servidor.
 */
function VentaEnVivo({ apiUrl }) {
  const clave = useRef(window.location.hash.replace(/^#/, '')).current;
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [actualizando, setActualizando] = useState(false);

  const traer = useCallback(async () => {
    setActualizando(true);

    try {
      const r = await fetch(`${apiUrl}/public/venta.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ clave }),
      });
      const cuerpo = await r.json();

      if (!r.ok) throw new Error(cuerpo.error || 'No se pudo cargar');

      setDatos(cuerpo);
      setError(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setCargando(false);
      setActualizando(false);
    }
  }, [apiUrl, clave]);

  useEffect(() => {
    if (!clave) {
      setError('Este link está incompleto. Pedile a quien organiza que te lo mande de nuevo.');
      setCargando(false);
      return undefined;
    }

    traer();

    const intervalo = setInterval(() => {
      if (document.visibilityState === 'visible') traer();
    }, SEGUNDOS_ENTRE_ACTUALIZACIONES * 1000);

    return () => clearInterval(intervalo);
  }, [clave, traer]);

  if (cargando) {
    return (
      <div className="min-h-screen bg-papel-hueso flex items-center justify-center">
        <Loader2 className="w-6 h-6 animate-spin text-tinta-suave" />
      </div>
    );
  }

  if (error && !datos) {
    return (
      <div className="min-h-screen bg-papel-hueso flex items-center justify-center p-6">
        <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3 max-w-md">
          {error}
        </p>
      </div>
    );
  }

  const { evento, venta, plano, ocupados = [], ritmo = [] } = datos;
  const tema = temaDeEvento(evento.colores);

  return (
    <ProveedorDeTema tema={tema}>
      <Fondo>
      <Helmet>
        <title>Venta · {evento.text}</title>
        {/* El link se reenvía por mensaje: no tiene que aparecer en ningún buscador. */}
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
      </Helmet>

      <div className="max-w-xl mx-auto px-4 py-10 space-y-6">
        <header>
          <Rotulo>Cómo viene la venta</Rotulo>
          <h1 className="text-3xl sm:text-4xl font-bold tracking-tight mt-1" style={{ color: tema.titulo }}>
            {evento.text}
          </h1>
          <p className="text-sm" style={{ color: tema.suave }}>{evento.pagina}</p>

          {evento.event_date && (
            <p className="flex items-center gap-2 text-sm mt-4" style={{ color: tema.suave }}>
              <Calendar className="w-4 h-4 shrink-0" />
              {new Date(`${String(evento.event_date).slice(0, 10)}T00:00:00`).toLocaleDateString('es-AR', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
              })}
              {evento.event_time ? ` · ${String(evento.event_time).slice(0, 5)}` : ''}
            </p>
          )}

          {evento.event_address && (
            <p className="flex items-center gap-2 text-sm" style={{ color: tema.suave }}>
              <MapPin className="w-4 h-4 shrink-0" />
              {evento.event_address}
            </p>
          )}
        </header>

        {!venta ? (
          <Tarjeta className="p-6">
            <p className="text-sm" style={{ color: tema.suave }}>
              Este evento todavía no vende entradas por Rezonar.
            </p>
          </Tarjeta>
        ) : (
          <>
            <Tarjeta className="p-6">
              <Rotulo>Vendidas</Rotulo>
              <p className="text-5xl font-bold tracking-tight mt-1" style={{ color: tema.titulo }}>
                {venta.vendidas}
                <span className="text-xl font-semibold" style={{ color: tema.tenue }}> de {venta.capacidad}</span>
              </p>

              <Barra
                porcentaje={venta.capacidad ? (100 * venta.vendidas) / venta.capacidad : 0}
                color={tema.acento}
                fondo={tema.borde}
              />

              <dl className="grid grid-cols-2 gap-4 mt-6">
                <Dato etiqueta="Disponibles" valor={venta.disponibles} />
                {venta.reservadas > 0 && <Dato etiqueta="Reservando ahora" valor={venta.reservadas} />}
                <Dato etiqueta="Compras" valor={venta.compras} />
                <Dato etiqueta="Recaudado" valor={formatearPrecio(venta.recaudado, venta.moneda)} />
                {venta.ingresadas > 0 && <Dato etiqueta="Ya entraron" valor={venta.ingresadas} />}
              </dl>

              {!venta.activo && (
                <p className="text-xs mt-4" style={{ color: tema.suave }}>La venta está pausada.</p>
              )}
            </Tarjeta>

            {ritmo.length > 0 && (
              <Tarjeta className="p-6">
                {/* La pregunta de quien abre esto no es cuántas van, sino si se
                    está moviendo o se frenó. */}
                <Rotulo className="mb-3">Últimos 30 días</Rotulo>
                <RitmoDeVenta dias={ritmo} color={tema.acento} colorTexto={tema.texto} />
              </Tarjeta>
            )}

            {plano && (
              <Tarjeta className="p-6">
                <Rotulo className="mb-3">Lugares</Rotulo>
                {/* El mismo plano que ve quien compra, con lo vendido marcado.
                    Sin nada para elegir: acá sólo se mira. */}
                <PlanoDeLugares
                  plano={plano}
                  ocupados={ocupados}
                  color={tema.acento}
                  superficie={tema.fondo}
                  trazo={tema.texto}
                />
              </Tarjeta>
            )}
          </>
        )}

        <footer className="flex items-center justify-between">
          <Boton variante="fantasma" tamano="sm" onClick={traer}>
            {actualizando ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
            Actualizar
          </Boton>
          {error && <span className="text-xs text-red-500">No se pudo actualizar</span>}
        </footer>
      </div>
      </Fondo>
    </ProveedorDeTema>
  );
}

function Barra({ porcentaje, color, fondo }) {
  const ancho = Math.max(0, Math.min(100, porcentaje));

  return (
    <div
      className="h-3 mt-3"
      style={{ backgroundColor: fondo }}
      role="img"
      aria-label={`${Math.round(ancho)}% vendido`}
    >
      <div className="h-3" style={{ width: `${ancho}%`, backgroundColor: color }} />
    </div>
  );
}

export default VentaEnVivo;
