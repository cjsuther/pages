import React, { useEffect, useState } from 'react';
import { Mail, Pause, Play, RefreshCw, X } from 'lucide-react';
import { Boton, Chip, Aviso, Vacio, Cargando } from './ui';
import { ESTADOS_DE_ENVIO } from '../utils/mensajes';

const TONOS = { en_curso: 'verde', pausado: 'alerta', terminado: 'neutro', cancelado: 'neutro' };

/** "2026-10-22 10:05:00" → "22/10/2026 10:05". */
function fechaHora(texto) {
  if (!texto) return '';
  const [fecha, hora = ''] = String(texto).split(' ');
  const [anio, mes, dia] = fecha.split('-');
  return `${dia}/${mes}/${anio} ${hora.slice(0, 5)}`.trim();
}

/**
 * Los envíos de mails a clientes de la página: cuántos salieron, cuántos
 * fallaron y cuántos faltan. Los manda un proceso por ráfagas, así que acá
 * se ven avanzar y se pueden pausar, reanudar o cancelar.
 */
function PanelEnvios({ pageId, apiUrl, token }) {
  const [envios, setEnvios] = useState(null);
  const [error, setError] = useState(null);
  const [ocupado, setOcupado] = useState(null);
  const [detalle, setDetalle] = useState(null); // { id, problemas }
  const [recargas, setRecargas] = useState(0);

  const headers = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };
  const url = (extra = '') => `${apiUrl}/pages/envios.php?page_id=${pageId}${extra}`;

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(url(), { headers });
        const cuerpo = await r.json();
        if (!vigente) return;
        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos traer los envíos');
          return;
        }
        setError(null);
        setEnvios(cuerpo.envios || []);
      } catch (e) {
        if (vigente) setError('No pudimos traer los envíos');
      }
    })();

    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageId, apiUrl, token, recargas]);

  const accion = async (envio, cual) => {
    if (cual === 'cancelar' && !window.confirm('¿Cancelar el envío? A los que todavía no les llegó no les va a llegar.')) return;

    setOcupado(envio.id);
    setError(null);

    try {
      const r = await fetch(url(), {
        method: 'PUT',
        headers,
        body: JSON.stringify({ page_id: Number(pageId), id: envio.id, accion: cual }),
      });
      const cuerpo = await r.json();
      if (!r.ok) throw new Error(cuerpo.error || 'No se pudo cambiar el envío');
      setEnvios((actuales) => actuales.map((e) => (e.id === envio.id ? cuerpo.envio : e)));
    } catch (e) {
      setError(e.message);
    } finally {
      setOcupado(null);
    }
  };

  const verDetalle = async (envio) => {
    if (detalle && detalle.id === envio.id) {
      setDetalle(null);
      return;
    }

    try {
      const r = await fetch(url(`&id=${envio.id}`), { headers });
      const cuerpo = await r.json();
      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos traer el detalle');
      setDetalle({ id: envio.id, problemas: cuerpo.problemas || [] });
    } catch (e) {
      setError(e.message);
    }
  };

  if (envios === null && !error) return <Cargando texto="Buscando envíos…" />;

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p className="text-sm text-tinta-suave">
          Los mails salen de a tandas, con pausas entre una y otra, para que tu casilla no quede marcada como spam.
        </p>
        <Boton variante="fantasma" tamano="sm" onClick={() => setRecargas((n) => n + 1)}>
          <RefreshCw className="w-4 h-4" /> Actualizar
        </Boton>
      </div>

      {error && <Aviso tipo="error" className="mb-4">{error}</Aviso>}

      {envios && envios.length === 0 && (
        <Vacio icono={Mail} titulo="Todavía no mandaste ningún email" detalle="Elegí clientes en la lista y tocá “Enviar email”." />
      )}

      {envios && envios.length > 0 && (
        <ul className="divide-y divide-borde border border-borde rounded-xl">
          {envios.map((e) => (
            <li key={e.id} className="px-5 py-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-bold truncate">{e.mensaje_nombre || e.asunto}</p>
                  <p className="text-xs text-tinta-suave">{fechaHora(e.creado_en)} · {e.asunto}</p>
                  <div className="flex flex-wrap gap-1.5 mt-2">
                    <Chip tono={TONOS[e.estado]}>{ESTADOS_DE_ENVIO[e.estado] || e.estado}</Chip>
                    <Chip>{e.total} en total</Chip>
                    <Chip>{e.enviados} enviados</Chip>
                    <Chip>{e.pendientes} pendientes</Chip>
                    {e.fallidos > 0 && <Chip tono="alerta">{e.fallidos} fallidos</Chip>}
                    {e.omitidos > 0 && <Chip>{e.omitidos} omitidos</Chip>}
                  </div>
                </div>

                <div className="flex flex-wrap gap-2">
                  {e.estado === 'en_curso' && (
                    <Boton variante="secundario" tamano="sm" disabled={ocupado === e.id} onClick={() => accion(e, 'pausar')}>
                      <Pause className="w-4 h-4" /> Pausar
                    </Boton>
                  )}
                  {e.estado === 'pausado' && (
                    <Boton variante="secundario" tamano="sm" disabled={ocupado === e.id} onClick={() => accion(e, 'reanudar')}>
                      <Play className="w-4 h-4" /> Reanudar
                    </Boton>
                  )}
                  {(e.estado === 'en_curso' || e.estado === 'pausado') && (
                    <Boton variante="peligro" tamano="sm" disabled={ocupado === e.id} onClick={() => accion(e, 'cancelar')}>
                      <X className="w-4 h-4" /> Cancelar
                    </Boton>
                  )}
                  {(e.fallidos > 0 || e.omitidos > 0) && (
                    <Boton variante="fantasma" tamano="sm" onClick={() => verDetalle(e)}>
                      {detalle && detalle.id === e.id ? 'Ocultar detalle' : 'Ver fallidos'}
                    </Boton>
                  )}
                </div>
              </div>

              {e.estado === 'pausado' && e.motivo_pausa && (
                <Aviso tipo="atencion" className="mt-3">{e.motivo_pausa}</Aviso>
              )}

              {detalle && detalle.id === e.id && (
                <ul className="mt-3 text-sm space-y-1" aria-label="Destinatarios con problemas">
                  {detalle.problemas.map((p) => (
                    <li key={p.email}>
                      <span className="font-semibold">{p.email}</span>
                      {' · '}
                      <span className="text-tinta-media">{p.error || (p.estado === 'omitido' ? 'Omitido' : 'Falló')}</span>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default PanelEnvios;
