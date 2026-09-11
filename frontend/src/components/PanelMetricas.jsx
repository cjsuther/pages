import React, { useState, useEffect, useContext, useCallback } from 'react';
import { Eye, Users, TrendingUp, TrendingDown, Minus, ExternalLink } from 'lucide-react';
import { AuthContext } from '../App';
import { Aviso, Boton, Cargando, Rotulo, Tarjeta } from './ui';

/**
 * Las métricas de una página, dentro de su administración.
 *
 * Los datos son de Google Analytics, filtrados por la ruta de esta página. No
 * se guarda nada por nuestra cuenta a propósito: con dos mediciones habría dos
 * números distintos para la misma cosa y ninguna forma de saber cuál mirar.
 *
 * Eso trae dos cosas que hay que decir en pantalla y no esconder, porque si no
 * quien mira saca conclusiones equivocadas:
 *
 * - Google tarda hasta dos días en procesar del todo, así que los últimos días
 *   siempre se ven más flacos de lo que fueron.
 * - Quien bloquea publicidad no se mide. Es una parte real del público, así que
 *   estos números son un piso y no la verdad.
 */

const VENTANAS = [
  { dias: 7, etiqueta: '7 días' },
  { dias: 30, etiqueta: '30 días' },
  { dias: 90, etiqueta: '90 días' },
];

function PanelMetricas({ pageId, slug }) {
  const { apiUrl, token } = useContext(AuthContext);

  const [dias, setDias] = useState(30);
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

  const traer = useCallback(async (ventana, vigente) => {
    setCargando(true);
    setError(null);

    try {
      const r = await fetch(
        `${apiUrl}/pages/metricas.php?page_id=${pageId}&dias=${ventana}`,
        { headers: { Authorization: `Bearer ${token}` } }
      );
      const cuerpo = await r.json();

      if (!vigente()) return;

      if (!r.ok) {
        setError(cuerpo.error || 'No pudimos traer las métricas');
        return;
      }

      setDatos(cuerpo);
    } catch (e) {
      if (vigente()) setError('No pudimos traer las métricas');
    } finally {
      if (vigente()) setCargando(false);
    }
  }, [apiUrl, token, pageId]);

  useEffect(() => {
    let activo = true;
    traer(dias, () => activo);

    return () => { activo = false; };
  }, [traer, dias]);

  if (cargando && !datos) {
    return <Cargando texto="Pidiéndole los números a Google..." />;
  }

  if (error) {
    return <Aviso tipo="error">{error}</Aviso>;
  }

  if (datos && datos.configurado === false) {
    return <SinConectar motivo={datos.motivo} />;
  }

  if (!datos) return null;

  const { resumen, por_dia: porDia, origen, dispositivo, ciudad } = datos;

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <Rotulo>Cómo viene</Rotulo>
          <h2 className="mt-2 text-2xl font-bold tracking-tight">
            Tu página en los últimos {dias} días
          </h2>
          <p className="mt-1 text-sm text-tinta-media">
            Comparado con los {dias} días anteriores.
          </p>
        </div>

        <div className="flex items-center gap-1 rounded-full border border-borde bg-papel-hueso p-1">
          {VENTANAS.map((v) => (
            <button
              key={v.dias}
              type="button"
              onClick={() => setDias(v.dias)}
              aria-pressed={dias === v.dias}
              className={`rounded-full px-3 py-1.5 text-sm font-semibold transition-colors ${
                dias === v.dias ? 'bg-white text-tinta shadow-sm' : 'text-tinta-suave hover:text-tinta'
              }`}
            >
              {v.etiqueta}
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Numero
          icono={Eye}
          etiqueta="Visitas"
          valor={resumen.actual.visitas}
          previo={resumen.previo.visitas}
          detalle="Cuántas veces se abrió tu página."
        />
        <Numero
          icono={Users}
          etiqueta="Personas"
          valor={resumen.actual.personas}
          previo={resumen.previo.personas}
          detalle="Cuánta gente distinta entró."
        />
      </div>

      <PorDia serie={porDia} />

      <div className="grid gap-6 lg:grid-cols-3">
        <Tabla
          titulo="De dónde vienen"
          bajada="Por dónde llegaron a tu página."
          columna="Origen"
          filas={origen}
          vacio="Todavía no hay visitas registradas."
        />
        <Tabla
          titulo="Con qué entran"
          bajada="Teléfono, computadora o tablet."
          columna="Dispositivo"
          filas={dispositivo}
          vacio="Todavía no hay visitas registradas."
        />
        <Tabla
          titulo="Desde dónde"
          bajada="Las ciudades de donde más te miran."
          columna="Ciudad"
          filas={ciudad}
          vacio="Todavía no hay visitas registradas."
        />
      </div>

      <Aviso>
        Los datos son de Google Analytics y tardan hasta dos días en procesarse,
        así que los últimos días se ven más flacos de lo que fueron. Quien navega
        con un bloqueador de publicidad no se mide: esto es un piso, no el número
        exacto.{' '}
        {slug && (
          <>
            Se cuenta lo que pasa en{' '}
            <a
              href={`/${slug}`}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 font-semibold text-verde-oscuro underline-offset-2 hover:underline"
            >
              rezon.ar/{slug}
              <ExternalLink className="h-3 w-3" />
            </a>
            .
          </>
        )}
      </Aviso>
    </div>
  );
}

/**
 * Un número con su variación.
 *
 * El número solo no dice nada: 300 visitas puede ser el mejor mes o la mitad
 * del anterior. Lo que se mira es si sube o baja.
 */
function Numero({ icono: Icono, etiqueta, valor, previo, detalle }) {
  const cambio = variacion(valor, previo);

  return (
    <Tarjeta className="p-5">
      <div className="flex items-center gap-2 text-tinta-suave">
        <Icono className="h-4 w-4" />
        <Rotulo>{etiqueta}</Rotulo>
      </div>

      <p className="mt-3 text-3xl font-bold tabular-nums text-tinta">
        {valor.toLocaleString('es-AR')}
      </p>

      <div className="mt-2">
        <Variacion cambio={cambio} previo={previo} />
      </div>

      <p className="mt-3 text-xs leading-relaxed text-tinta-suave">{detalle}</p>
    </Tarjeta>
  );
}

function Variacion({ cambio, previo }) {
  if (cambio === null) {
    return (
      <span className="text-xs text-tinta-suave">
        {previo === 0 ? 'Sin datos del período anterior' : 'Sin cambios'}
      </span>
    );
  }

  const sube = cambio > 0;
  const igual = cambio === 0;
  const Icono = igual ? Minus : sube ? TrendingUp : TrendingDown;

  return (
    <span
      className={`inline-flex items-center gap-1 text-xs font-semibold ${
        igual ? 'text-tinta-suave' : sube ? 'text-verde-oscuro' : 'text-amber-700'
      }`}
    >
      <Icono className="h-3.5 w-3.5" />
      {igual ? 'igual que antes' : `${sube ? '+' : ''}${cambio}% vs. antes`}
    </span>
  );
}

/**
 * La evolución día a día.
 *
 * Barras de CSS y no una librería de gráficos: son treinta valores y lo único
 * que hay que ver es la forma. Traer una librería entera para esto la paga
 * cada persona que abre la pantalla.
 */
function PorDia({ serie }) {
  const techo = Math.max(...serie.map((d) => d.visitas), 1);
  const total = serie.reduce((suma, d) => suma + d.visitas, 0);

  return (
    <section>
      <h3 className="text-lg font-bold text-tinta">Día a día</h3>
      <p className="mt-1 text-sm text-tinta-media">Visitas de cada día del período.</p>

      {total === 0 ? (
        <Tarjeta className="mt-4 p-6">
          <p className="text-tinta-media">
            Todavía no hay visitas registradas en este período.
          </p>
        </Tarjeta>
      ) : (
        <Tarjeta className="mt-4 p-5">
          {/* La separación se achica cuando hay muchos días: con noventa
              barras, tres píxeles de aire entre cada una se comen la mitad del
              ancho y las barras quedan más finas que el espacio. */}
          <div
            className={`flex h-32 items-end ${serie.length > 45 ? 'gap-px' : 'gap-[3px]'}`}
            role="img"
            aria-label={`${total} visitas en el período`}
          >
            {serie.map((d) => (
              <div
                key={d.dia}
                title={`${fechaCorta(d.dia)}: ${d.visitas} ${d.visitas === 1 ? 'visita' : 'visitas'}`}
                // Un día sin visitas se dibuja igual, en gris: si fuera una
                // barra verde mínima se leería como que entró alguien.
                className={`min-w-0 flex-1 rounded-t transition-colors ${
                  d.visitas === 0 ? 'bg-borde' : 'bg-verde-medio hover:bg-verde-oscuro'
                }`}
                style={{ height: `${Math.max((d.visitas / techo) * 100, 2)}%` }}
              />
            ))}
          </div>

          <div className="mt-3 flex justify-between text-xs text-tinta-suave">
            <span>{fechaCorta(serie[0].dia)}</span>
            <span className="font-semibold text-tinta-media">
              {total.toLocaleString('es-AR')} visitas
            </span>
            <span>{fechaCorta(serie[serie.length - 1].dia)}</span>
          </div>
        </Tarjeta>
      )}
    </section>
  );
}

function Tabla({ titulo, bajada, columna, filas, vacio }) {
  const total = filas.reduce((suma, f) => suma + f.visitas, 0);

  return (
    <section>
      <h3 className="text-lg font-bold text-tinta">{titulo}</h3>
      <p className="mt-1 text-sm text-tinta-media">{bajada}</p>

      {filas.length === 0 ? (
        <Tarjeta className="mt-4 p-5">
          <p className="text-sm text-tinta-media">{vacio}</p>
        </Tarjeta>
      ) : (
        <div className="mt-4 overflow-x-auto rounded-2xl border border-borde">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-papel-hueso text-left text-tinta-suave">
                <th className="px-4 py-3 font-semibold">{columna}</th>
                <th className="whitespace-nowrap px-4 py-3 text-right font-semibold">Visitas</th>
                <th className="whitespace-nowrap px-4 py-3 text-right font-semibold">Parte</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-borde">
              {filas.map((f) => (
                <tr key={f.nombre} className="transition-colors hover:bg-papel-hueso">
                  <td className="px-4 py-3 font-medium text-tinta">{f.nombre}</td>
                  <td className="px-4 py-3 text-right tabular-nums text-tinta">
                    {f.visitas.toLocaleString('es-AR')}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums text-tinta-suave">
                    {total === 0 ? '—' : `${Math.round((f.visitas / total) * 100)}%`}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

/**
 * Falta conectar Google Analytics en el servidor.
 *
 * Se dice qué falta y a quién escribirle. Para quien administra una página esto
 * no es accionable, así que lo que corresponde no es un error rojo.
 */
function SinConectar({ motivo }) {
  return (
    <Tarjeta className="p-6 sm:p-8">
      <Rotulo>Métricas</Rotulo>
      <h2 className="mt-2 text-2xl font-bold tracking-tight">
        Todavía no hay números para mostrar
      </h2>
      <p className="mt-3 max-w-xl leading-relaxed text-tinta-media">
        {motivo || 'Falta conectar Google Analytics en el servidor.'} Es algo que se
        configura una sola vez y no depende de tu página.
      </p>
      <div className="mt-6">
        <Boton href="mailto:hola@rezon.ar?subject=Métricas de mi página" variante="secundario">
          Escribinos
        </Boton>
      </div>
    </Tarjeta>
  );
}

/**
 * Cuánto cambió, en porcentaje.
 *
 * Sin período anterior no hay variación que mostrar: un "+100%" contra cero es
 * verdadero y no significa nada.
 */
export function variacion(actual, previo) {
  if (!previo) return null;

  return Math.round(((actual - previo) / previo) * 100);
}

/** "2026-09-10" → "10/09". El año es siempre el mismo en estas ventanas. */
export function fechaCorta(dia) {
  const [, mes, numero] = String(dia).split('-');

  return `${numero}/${mes}`;
}

export default PanelMetricas;
