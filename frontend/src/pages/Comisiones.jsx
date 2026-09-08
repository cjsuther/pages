import React, { useState, useEffect, useContext } from 'react';
import { Helmet } from 'react-helmet-async';
import { AlertTriangle, ExternalLink } from 'lucide-react';
import { AuthContext } from '../App';
import Marco from '../components/Marco';
import { Aviso, Boton, Campo, Cargando, Etiqueta, Rotulo, Tarjeta, Vacio } from '../components/ui';
import { formatearPrecio } from '../utils/entradas';

/**
 * Reporte de comisiones de la plataforma.
 *
 * Contesta dos preguntas y en ese orden: cuánto se cobró, y cuánto se pidió
 * cobrar y no entró. Lo segundo no es un detalle: mandar el marketplace_fee no
 * garantiza nada, y cuando Mercado Pago lo ignora no devuelve ningún error.
 *
 * El acceso lo decide el servidor. Si esta pantalla se abre sin permiso, la
 * API contesta 404 y acá se muestra como no encontrada: para quien no
 * administra la plataforma, este reporte no existe.
 */
function Comisiones() {
  const { apiUrl, token } = useContext(AuthContext);

  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [sinAcceso, setSinAcceso] = useState(false);
  const [error, setError] = useState(null);
  const [rango, setRango] = useState({ desde: '', hasta: '' });

  useEffect(() => {
    let vigente = true;

    const traer = async () => {
      setCargando(true);
      setError(null);

      const query = new URLSearchParams();
      if (rango.desde) query.set('desde', rango.desde);
      if (rango.hasta) query.set('hasta', rango.hasta);

      try {
        const r = await fetch(`${apiUrl}/plataforma/comisiones.php?${query}`, {
          headers: { Authorization: `Bearer ${token}` },
        });

        if (!vigente) return;

        if (r.status === 404) {
          setSinAcceso(true);
          return;
        }

        const cuerpo = await r.json();

        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos traer el reporte');
          return;
        }

        setDatos(cuerpo);
      } catch (e) {
        if (vigente) setError('No pudimos traer el reporte');
      } finally {
        if (vigente) setCargando(false);
      }
    };

    traer();

    return () => { vigente = false; };
  }, [apiUrl, token, rango]);

  if (sinAcceso) {
    return (
      <Marco ancho="angosto">
        <Tarjeta>
          <Vacio
            titulo="No encontramos esa página"
            detalle="Puede que el enlace esté mal escrito o que ya no exista."
            accion={<Boton a="/">Ir al inicio</Boton>}
          />
        </Tarjeta>
      </Marco>
    );
  }

  const resumen = datos?.resumen;

  return (
    <Marco ancho="ancho">
      <Helmet><title>Comisiones — Rezonar</title></Helmet>

      <header className="mb-8">
        <Rotulo>Plataforma</Rotulo>
        <h1 className="mt-3 text-3xl sm:text-4xl font-bold tracking-tight">Comisiones cobradas</h1>
        <p className="mt-3 text-tinta-media max-w-2xl">
          Lo que Mercado Pago descontó para Rezonar en cada venta de entradas, sobre todas las
          páginas.
        </p>
      </header>

      <div className="flex flex-wrap items-end gap-4 mb-8">
        <div>
          <Etiqueta htmlFor="desde">Desde</Etiqueta>
          <Campo
            id="desde"
            type="date"
            value={rango.desde}
            onChange={(e) => setRango({ ...rango, desde: e.target.value })}
            className="!w-auto"
          />
        </div>
        <div>
          <Etiqueta htmlFor="hasta">Hasta</Etiqueta>
          <Campo
            id="hasta"
            type="date"
            value={rango.hasta}
            onChange={(e) => setRango({ ...rango, hasta: e.target.value })}
            className="!w-auto"
          />
        </div>
        {(rango.desde || rango.hasta) && (
          <Boton variante="fantasma" onClick={() => setRango({ desde: '', hasta: '' })}>
            Todo el historial
          </Boton>
        )}
      </div>

      {error && <Aviso tipo="error" className="mb-6">{error}</Aviso>}

      {cargando && !datos ? (
        <Cargando texto="Juntando las ventas..." />
      ) : !resumen ? null : (
        <div className="space-y-8">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Numero etiqueta="Cobrado" valor={formatearPrecio(resumen.cobrada)} destacado />
            <Numero etiqueta="Ventas" valor={resumen.ventas} />
            <Numero
              etiqueta="Recaudado por las páginas"
              valor={formatearPrecio(resumen.recaudado)}
            />
            <Numero
              etiqueta="Pedido y no cobrado"
              valor={formatearPrecio(resumen.diferencia)}
              alerta={resumen.diferencia > 0}
            />
          </div>

          {resumen.diferencia > 0 && (
            <Aviso tipo="atencion">
              <span className="flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>
                  <strong className="font-semibold">
                    Se pidieron {formatearPrecio(resumen.diferencia)} que Mercado Pago no cobró.
                  </strong>{' '}
                  Suele ser una página cuya cuenta de cobro no quedó conectada desde Rezonar: la
                  venta se hace igual, pero el reparto no.
                </span>
              </span>
            </Aviso>
          )}

          {resumen.sin_dato > 0 && (
            <Aviso>
              De {resumen.sin_dato} {resumen.sin_dato === 1 ? 'venta' : 'ventas'} todavía no
              tenemos el desglose de Mercado Pago; ahí se cuenta lo que se pidió. El repaso
              automático las completa.
            </Aviso>
          )}

          <Seccion titulo="Por mes" vacio="Todavía no hay ventas con comisión.">
            {datos.meses.length > 0 && (
              <Tabla
                columnas={['Mes', 'Ventas', 'Recaudado', 'Cobrado', 'Sin cobrar']}
                filas={datos.meses.map((m) => ({
                  clave: m.mes,
                  celdas: [mesLegible(m.mes), m.ventas, formatearPrecio(m.recaudado),
                           formatearPrecio(m.cobrada), m.diferencia],
                }))}
              />
            )}
          </Seccion>

          <Seccion titulo="Por página" vacio="Todavía no hay ventas con comisión.">
            {datos.paginas.length > 0 && (
              <Tabla
                columnas={['Página', 'Ventas', 'Recaudado', 'Cobrado', 'Sin cobrar']}
                filas={datos.paginas.map((p) => ({
                  clave: p.id,
                  celdas: [
                    <a
                      href={`/${p.url_slug}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 font-semibold text-tinta hover:text-verde-oscuro transition-colors"
                    >
                      {p.title}
                      <ExternalLink className="w-3 h-3 opacity-50" />
                    </a>,
                    p.ventas, formatearPrecio(p.recaudado), formatearPrecio(p.cobrada), p.diferencia,
                  ],
                }))}
              />
            )}
          </Seccion>

          {datos.revisar.length > 0 && (
            <Seccion titulo="Ventas donde no se cobró la comisión">
              <Tabla
                columnas={['Fecha', 'Página', 'Evento', 'Venta', 'Se pidió', 'Se cobró']}
                filas={datos.revisar.map((v) => ({
                  clave: v.codigo,
                  celdas: [
                    fechaCorta(v.pagada_en), v.pagina, v.evento,
                    formatearPrecio(v.total), formatearPrecio(v.pedida), formatearPrecio(v.cobrada),
                  ],
                }))}
              />
            </Seccion>
          )}
        </div>
      )}
    </Marco>
  );
}

function Numero({ etiqueta, valor, destacado = false, alerta = false }) {
  return (
    <Tarjeta destacada={destacado} className="p-5">
      <p className="text-xs font-semibold uppercase tracking-wider text-tinta-suave">{etiqueta}</p>
      <p className={`text-2xl font-bold mt-2 tabular-nums ${alerta ? 'text-amber-700' : 'text-tinta'}`}>
        {valor}
      </p>
    </Tarjeta>
  );
}

function Seccion({ titulo, vacio = null, children }) {
  return (
    <section>
      <h2 className="text-xl font-bold text-tinta mb-4">{titulo}</h2>
      {children || (vacio && <Tarjeta className="p-6"><p className="text-tinta-media">{vacio}</p></Tarjeta>)}
    </section>
  );
}

/**
 * Tabla del reporte. La última columna es siempre "sin cobrar": va en verde
 * cuando es cero —está todo bien— y en ámbar cuando falta plata, que es lo
 * único que hay que mirar de un vistazo.
 */
function Tabla({ columnas, filas }) {
  return (
    <div className="overflow-x-auto border border-borde rounded-2xl">
      <table className="w-full text-sm">
        <thead>
          <tr className="bg-papel-hueso text-tinta-suave text-left">
            {columnas.map((c) => (
              <th key={c} className="px-4 py-3 font-semibold whitespace-nowrap">{c}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-borde">
          {filas.map((fila) => (
            <tr key={fila.clave} className="hover:bg-papel-hueso transition-colors">
              {fila.celdas.map((celda, i) => {
                const ultima = i === fila.celdas.length - 1;
                const esDiferencia = ultima && typeof celda === 'number';

                return (
                  <td
                    key={i}
                    className={`px-4 py-3 whitespace-nowrap ${
                      esDiferencia
                        ? celda > 0 ? 'text-amber-700 font-semibold' : 'text-tinta-suave'
                        : 'text-tinta'
                    }`}
                  >
                    {esDiferencia ? (celda > 0 ? formatearPrecio(celda) : '—') : celda}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** "2026-09" → "septiembre 2026". */
export function mesLegible(mes) {
  if (typeof mes !== 'string' || !/^\d{4}-\d{2}$/.test(mes)) return mes;

  const [anio, numero] = mes.split('-');
  const nombre = new Date(Number(anio), Number(numero) - 1, 1)
    .toLocaleDateString('es-AR', { month: 'long' });

  return `${nombre} ${anio}`;
}

/** Sin hora: en una tabla de meses el minuto exacto es ruido. */
export function fechaCorta(momento) {
  if (!momento) return '—';

  const [anio, mes, dia] = String(momento).slice(0, 10).split('-');

  return `${dia}/${mes}/${anio}`;
}

export default Comisiones;
