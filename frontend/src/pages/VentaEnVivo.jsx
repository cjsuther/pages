import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Helmet } from 'react-helmet-async';
import { Calendar, MapPin, Loader2, RefreshCw } from 'lucide-react';
import PlanoDeLugares from '../components/PlanoDeLugares';
import { formatearPrecio } from '../utils/entradas';
import { paleta } from '../utils/colores';

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
  const colores = paleta(evento.colores || {});

  return (
    <div className="min-h-screen" style={{ backgroundColor: colores.fondo, color: colores.texto }}>
      <Helmet>
        <title>Venta · {evento.text}</title>
        {/* El link se reenvía por mensaje: no tiene que aparecer en ningún buscador. */}
        <meta name="robots" content="noindex, nofollow" />
        <meta name="referrer" content="no-referrer" />
      </Helmet>

      <div className="max-w-xl mx-auto px-4 py-8 space-y-6">
        <header>
          <p className="text-xs font-bold tracking-widest opacity-60">CÓMO VIENE LA VENTA</p>
          <h1 className="text-3xl font-bold" style={{ color: colores.titulo }}>{evento.text}</h1>
          <p className="text-sm opacity-70">{evento.pagina}</p>

          {evento.event_date && (
            <p className="flex items-center gap-2 text-sm mt-3 opacity-80">
              <Calendar className="w-4 h-4 shrink-0" />
              {new Date(`${String(evento.event_date).slice(0, 10)}T00:00:00`).toLocaleDateString('es-AR', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
              })}
              {evento.event_time ? ` · ${String(evento.event_time).slice(0, 5)}` : ''}
            </p>
          )}

          {evento.event_address && (
            <p className="flex items-center gap-2 text-sm opacity-80">
              <MapPin className="w-4 h-4 shrink-0" />
              {evento.event_address}
            </p>
          )}
        </header>

        {!venta ? (
          <p className="text-sm opacity-70 border p-6" style={{ borderColor: colores.bordeTarjeta }}>
            Este evento todavía no vende entradas por Rezonar.
          </p>
        ) : (
          <>
            <section className="p-5 border" style={{ backgroundColor: colores.tarjeta, borderColor: colores.bordeTarjeta }}>
              <p className="text-sm opacity-70">Vendidas</p>
              <p className="text-4xl font-bold" style={{ color: colores.titulo }}>
                {venta.vendidas}
                <span className="text-xl font-semibold opacity-60"> de {venta.capacidad}</span>
              </p>

              <Barra
                porcentaje={venta.capacidad ? (100 * venta.vendidas) / venta.capacidad : 0}
                color={colores.acento}
                fondo={colores.bordeTarjeta}
              />

              <dl className="grid grid-cols-2 gap-4 mt-5 text-sm">
                <Dato etiqueta="Disponibles" valor={venta.disponibles} />
                {venta.reservadas > 0 && <Dato etiqueta="Reservando ahora" valor={venta.reservadas} />}
                <Dato etiqueta="Compras" valor={venta.compras} />
                <Dato etiqueta="Recaudado" valor={formatearPrecio(venta.recaudado, venta.moneda)} />
                {venta.ingresadas > 0 && <Dato etiqueta="Ya entraron" valor={venta.ingresadas} />}
              </dl>

              {!venta.activo && (
                <p className="text-xs mt-4 opacity-70">La venta está pausada.</p>
              )}
            </section>

            {ritmo.length > 0 && (
              <section className="p-5 border" style={{ backgroundColor: colores.tarjeta, borderColor: colores.bordeTarjeta }}>
                {/* La pregunta de quien abre esto no es cuántas van, sino si se
                    está moviendo o se frenó. */}
                <h2 className="text-sm font-bold mb-3 opacity-70">ÚLTIMOS 30 DÍAS</h2>
                <Ritmo dias={ritmo} color={colores.acento} />
              </section>
            )}

            {plano && (
              <section className="p-5 border" style={{ backgroundColor: colores.tarjeta, borderColor: colores.bordeTarjeta }}>
                <h2 className="text-sm font-bold mb-3 opacity-70">LUGARES</h2>
                {/* El mismo plano que ve quien compra, con lo vendido marcado.
                    Sin nada para elegir: acá sólo se mira. */}
                <PlanoDeLugares plano={plano} ocupados={ocupados} color={colores.acento} />
              </section>
            )}
          </>
        )}

        <footer className="flex items-center justify-between text-xs opacity-60">
          <button type="button" onClick={traer} className="inline-flex items-center gap-1.5">
            {actualizando ? <Loader2 className="w-3 h-3 animate-spin" /> : <RefreshCw className="w-3 h-3" />}
            Actualizar
          </button>
          {error && <span className="text-red-700">No se pudo actualizar</span>}
        </footer>
      </div>
    </div>
  );
}

function Dato({ etiqueta, valor }) {
  return (
    <div>
      <dt className="opacity-60">{etiqueta}</dt>
      <dd className="text-lg font-bold" style={{ color: 'inherit' }}>{valor}</dd>
    </div>
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

/** Un día por barra, alto según lo vendido. */
function Ritmo({ dias, color }) {
  const tope = Math.max(...dias.map((d) => d.vendidas), 1);

  return (
    <ul className="flex items-end gap-1 h-24">
      {dias.map((d) => (
        <li
          key={d.dia}
          className="flex-1 min-w-[4px]"
          style={{ height: `${Math.max(4, (100 * d.vendidas) / tope)}%`, backgroundColor: color }}
          title={`${d.dia}: ${d.vendidas}`}
        >
          <span className="sr-only">{d.dia}: {d.vendidas} entradas</span>
        </li>
      ))}
    </ul>
  );
}

export default VentaEnVivo;
