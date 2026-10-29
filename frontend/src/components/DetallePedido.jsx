import React, { useEffect, useState } from 'react';
import { ArrowLeft, MapPin, CalendarClock, Phone, Mail, AlertTriangle, ExternalLink } from 'lucide-react';
import { Boton, Campo, Selector, Etiqueta, AreaTexto, Aviso, Cargando } from './ui';
import { IconoDeMarca } from './IconosRedes';
import { formatearPrecio } from '../utils/entradas';
import { urlDeWhatsApp } from '../utils/telefono';
import { DIAS, FRANJAS, describirClaves } from '../utils/productos';
import {
  ACCIONES,
  CORREGIR_FECHA,
  describirEntrega,
  esLink,
  hoy,
  nombreDelEstado,
  tonoDelEstado,
  urlDeLaOperacion,
} from '../utils/pedidos';

/** "2026-10-30 10:00:00" → "30/10/2026 10:00". */
function fechaYHora(momento) {
  const [fecha, hora = ''] = String(momento || '').split(' ');
  const [anio, mes, dia] = fecha.split('-');
  return dia ? `${dia}/${mes}/${anio}${hora ? ` ${hora.slice(0, 5)}` : ''}` : '';
}

/**
 * El detalle de un pedido de un físico para quien vende (#44): quién compró,
 * adónde va, cuándo puede recibir, el historial y los botones para pasarlo
 * al estado siguiente.
 *
 * Los botones son los que el servidor dice que se pueden (siguientes): la
 * transición se valida allá, no acá. Cada cambio le manda un mail a quien
 * compró; si no sale, se avisa, pero el cambio queda hecho.
 */
function DetallePedido({ codigo, apiUrl, token, alVolver, alCambiar }) {
  const [pedido, setPedido] = useState(null);
  const [error, setError] = useState(null);
  const [accion, setAccion] = useState(null);
  const [aviso, setAviso] = useState(null);

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/productos/pedido.php?codigo=${encodeURIComponent(codigo)}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No pudimos cargar el pedido');
          return;
        }

        setPedido(cuerpo.pedido);
      } catch (e) {
        if (vigente) setError('No pudimos conectarnos al servidor');
      }
    })();

    return () => { vigente = false; };
  }, [apiUrl, token, codigo]);

  const alGuardar = (respuesta) => {
    setPedido(respuesta.pedido);
    setAccion(null);
    setAviso(respuesta.aviso_enviado
      ? { tipo: 'ok', texto: `Listo: le avisamos por mail a ${respuesta.pedido.email}.` }
      : {
        tipo: 'atencion',
        texto: `El pedido quedó ${nombreDelEstado(respuesta.pedido.estado_pedido).toLowerCase()}, pero no pudimos `
          + `mandarle el mail a quien compró${respuesta.aviso_error ? ` (${respuesta.aviso_error})` : ''}. Avisale vos.`,
      });

    if (alCambiar) alCambiar(respuesta.pedido);
  };

  return (
    <div className="space-y-6">
      <button
        type="button"
        onClick={alVolver}
        className="text-sm text-tinta-media hover:text-tinta inline-flex items-center gap-2"
      >
        <ArrowLeft className="w-4 h-4" />
        Volver a los pedidos
      </button>

      {error && <Aviso tipo="error">{error}</Aviso>}
      {!error && !pedido && <Cargando texto="Cargando pedido..." />}

      {pedido && (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <h3 className="text-xl font-bold text-tinta">Pedido {pedido.codigo}</h3>
            {pedido.estado_pedido && (
              <span className={`text-xs px-2 py-0.5 rounded border font-semibold ${tonoDelEstado(pedido.estado_pedido)}`}>
                {nombreDelEstado(pedido.estado_pedido)}
              </span>
            )}
            {pedido.sin_stock && (
              <span className="text-xs px-2 py-0.5 rounded border font-semibold bg-red-50 text-red-800 border-red-200">
                Sin stock
              </span>
            )}
          </div>

          {aviso && (
            <div role={aviso.tipo === 'ok' ? 'status' : 'alert'}>
              <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>
            </div>
          )}

          {pedido.sin_stock && (
            <Aviso tipo="atencion">
              Cuando se acreditó el pago ya no quedaba stock de lo pedido. Coordiná con quien compró cómo seguir.
            </Aviso>
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            <Bloque titulo="Quién compró">
              <p className="font-semibold text-tinta">{pedido.nombre}</p>
              {pedido.telefono && <Telefono telefono={pedido.telefono} nombre={pedido.nombre} />}
              <a href={`mailto:${pedido.email}`} className="flex items-center gap-2 text-tinta-media hover:text-tinta">
                <Mail className="w-4 h-4 shrink-0" aria-hidden="true" />
                {pedido.email}
              </a>
            </Bloque>

            <Bloque titulo="Qué pidió">
              <p className="font-semibold text-tinta">{pedido.producto}</p>
              {pedido.opciones && <p className="text-tinta-media">{pedido.opciones}</p>}
              <p className="text-tinta-media">
                {pedido.cantidad} × {formatearPrecio(pedido.precio_unitario, pedido.moneda)}
                {' · Envío '}
                {pedido.costo_envio > 0 ? formatearPrecio(pedido.costo_envio, pedido.moneda) : 'incluido'}
              </p>
              <p className="font-bold text-tinta">
                Total {pedido.total > 0 ? formatearPrecio(pedido.total, pedido.moneda) : 'Gratis'}
              </p>
            </Bloque>

            {pedido.envio && (
              <Bloque titulo="Adónde">
                <p className="flex items-start gap-2 text-tinta">
                  <MapPin className="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
                  <span>
                    {pedido.envio.calle}
                    {pedido.envio.piso_depto && `, ${pedido.envio.piso_depto}`}
                    <span className="block text-tinta-media">
                      {pedido.envio.localidad}, {pedido.envio.provincia} ({pedido.envio.codigo_postal})
                    </span>
                  </span>
                </p>
                {pedido.envio.indicaciones && (
                  <p className="text-tinta-media">Indicaciones: {pedido.envio.indicaciones}</p>
                )}
              </Bloque>
            )}

            {pedido.horarios && (
              <Bloque titulo="Cuándo puede recibir">
                <p className="flex items-start gap-2 text-tinta">
                  <CalendarClock className="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
                  <span>
                    {describirClaves(pedido.horarios.dias, DIAS)} · {describirClaves(pedido.horarios.franjas, FRANJAS)}
                    {pedido.horarios.comentario && (
                      <span className="block text-tinta-media">{pedido.horarios.comentario}</span>
                    )}
                  </span>
                </p>
              </Bloque>
            )}
          </div>

          <DatosDelEstado pedido={pedido} />

          {pedido.siguientes.length > 0 && (
            <div>
              <p className="text-xs text-tinta-suave tracking-wide mb-2">ACCIONES</p>
              <div className="flex flex-wrap gap-2">
                {pedido.siguientes.map((estado) => (
                  <Boton
                    key={estado}
                    type="button"
                    tamano="sm"
                    variante={estado === 'cancelado' ? 'peligro' : 'secundario'}
                    onClick={() => { setAccion(estado); setAviso(null); }}
                    aria-pressed={accion === estado}
                  >
                    {estado === 'con_fecha' && pedido.estado_pedido === 'con_fecha' ? CORREGIR_FECHA : ACCIONES[estado]}
                  </Boton>
                ))}
              </div>
            </div>
          )}

          {accion && (
            <FormularioDeEstado
              key={accion}
              estado={accion}
              pedido={pedido}
              apiUrl={apiUrl}
              token={token}
              alCancelar={() => setAccion(null)}
              alGuardar={alGuardar}
            />
          )}

          <Historial historial={pedido.historial} />
        </>
      )}
    </div>
  );
}

function Bloque({ titulo, children }) {
  return (
    <div className="border border-borde bg-white p-4 text-sm space-y-1">
      <p className="text-xs text-tinta-suave tracking-wide mb-1">{titulo.toUpperCase()}</p>
      {children}
    </div>
  );
}

/** El teléfono abre WhatsApp, como en Clientes (#38); si no se puede armar el número, llama. */
function Telefono({ telefono, nombre }) {
  const whatsapp = urlDeWhatsApp(telefono);

  if (whatsapp) {
    return (
      <a
        href={whatsapp}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={`Escribirle por WhatsApp a ${nombre}`}
        className="flex items-center gap-2 text-tinta-media hover:text-verde-oscuro"
      >
        <IconoDeMarca red="whatsapp" className="w-4 h-4 shrink-0" />
        {telefono}
      </a>
    );
  }

  return (
    <a href={`tel:${telefono.replace(/[^\d+]/g, '')}`} className="flex items-center gap-2 text-tinta-media hover:text-tinta">
      <Phone className="w-4 h-4 shrink-0" aria-hidden="true" />
      {telefono}
    </a>
  );
}

/** La fecha de entrega, quién lo lleva o por qué se canceló, según el estado. */
function DatosDelEstado({ pedido }) {
  const filas = [];

  if (pedido.entrega_fecha) {
    filas.push(['Entrega', describirEntrega(pedido.entrega_fecha, pedido.entrega_franja)]);
  }

  if (pedido.envio_empresa) filas.push(['Lo lleva', pedido.envio_empresa]);

  if (pedido.envio_seguimiento) {
    filas.push(['Seguimiento', esLink(pedido.envio_seguimiento) ? (
      <a href={pedido.envio_seguimiento} target="_blank" rel="noopener noreferrer" className="underline">
        {pedido.envio_seguimiento}
      </a>
    ) : pedido.envio_seguimiento]);
  }

  if (pedido.cancelado_motivo) filas.push(['Motivo de la cancelación', pedido.cancelado_motivo]);

  if (filas.length === 0) return null;

  return (
    <dl className="border border-borde bg-white p-4 text-sm grid grid-cols-[auto,1fr] gap-x-4 gap-y-1">
      {filas.map(([etiqueta, valor]) => (
        <React.Fragment key={etiqueta}>
          <dt className="text-tinta-suave">{etiqueta}</dt>
          <dd className="text-tinta break-words">{valor}</dd>
        </React.Fragment>
      ))}
    </dl>
  );
}

/** Lo que pide cada paso antes de guardarlo. */
function FormularioDeEstado({ estado, pedido, apiUrl, token, alCancelar, alGuardar }) {
  const [datos, setDatos] = useState(() => (estado === 'con_fecha'
    ? { fecha: pedido.entrega_fecha || '', franja: pedido.entrega_franja || '' }
    : { empresa: '', seguimiento: '', motivo: '' }));
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState(null);

  const cambiar = (campo) => (e) => setDatos({ ...datos, [campo]: e.target.value });

  const enviar = async (e) => {
    e.preventDefault();

    if (estado === 'con_fecha' && !datos.fecha) {
      setError('Elegí la fecha de entrega');
      return;
    }

    if (estado === 'cancelado' && !datos.motivo.trim()) {
      setError('Contá por qué se cancela: se lo mandamos a quien compró');
      return;
    }

    setGuardando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/productos/pedido-estado.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ codigo: pedido.codigo, estado, ...datos }),
      });
      const cuerpo = await r.json().catch(() => ({}));

      if (!r.ok) {
        setError(cuerpo.error || 'No pudimos cambiar el estado');
        return;
      }

      alGuardar(cuerpo);
    } catch (err) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  return (
    <form onSubmit={enviar} className="border border-borde-fuerte bg-white p-4 space-y-4" aria-label={ACCIONES[estado]}>
      {estado === 'con_fecha' && (
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Etiqueta htmlFor="pedido-fecha">Fecha de entrega</Etiqueta>
            <Campo id="pedido-fecha" type="date" min={hoy()} value={datos.fecha} onChange={cambiar('fecha')} />
          </div>
          <div>
            <Etiqueta htmlFor="pedido-franja">Franja (opcional)</Etiqueta>
            <Selector id="pedido-franja" value={datos.franja} onChange={cambiar('franja')}>
              <option value="">Sin franja</option>
              {FRANJAS.map((f) => <option key={f.clave} value={f.clave}>{f.nombre}</option>)}
            </Selector>
          </div>
        </div>
      )}

      {estado === 'enviado' && (
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Etiqueta htmlFor="pedido-empresa">Empresa o cadete (opcional)</Etiqueta>
            <Campo id="pedido-empresa" maxLength={100} value={datos.empresa} onChange={cambiar('empresa')} />
          </div>
          <div>
            <Etiqueta htmlFor="pedido-seguimiento">Número de seguimiento o link (opcional)</Etiqueta>
            <Campo id="pedido-seguimiento" maxLength={255} value={datos.seguimiento} onChange={cambiar('seguimiento')} />
          </div>
        </div>
      )}

      {estado === 'entregado' && (
        <p className="text-sm text-tinta-media">
          Entregado es el último paso: después ya no se puede cambiar.
        </p>
      )}

      {estado === 'cancelado' && (
        <>
          <div>
            <Etiqueta htmlFor="pedido-motivo">Motivo</Etiqueta>
            <AreaTexto id="pedido-motivo" rows={3} maxLength={255} value={datos.motivo} onChange={cambiar('motivo')} />
            <p className="text-xs text-tinta-suave mt-1">Se lo mandamos por mail a quien compró.</p>
          </div>
          <Aviso tipo="atencion">
            <p className="flex items-start gap-2">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
              <span>
                Cancelar no devuelve el pago. Si ya te pagó, hacé la devolución desde Mercado Pago.
                {pedido.mp_payment_id && (
                  <>
                    {' '}
                    <a
                      href={urlDeLaOperacion(pedido.mp_payment_id)}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="underline font-semibold inline-flex items-center gap-1"
                    >
                      Ver la operación {pedido.mp_payment_id}
                      <ExternalLink className="w-3.5 h-3.5" aria-hidden="true" />
                    </a>
                  </>
                )}
              </span>
            </p>
          </Aviso>
        </>
      )}

      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

      <div className="flex gap-2">
        <Boton type="submit" tamano="sm" variante={estado === 'cancelado' ? 'peligro' : 'primario'} disabled={guardando}>
          {guardando ? 'Guardando...' : 'Confirmar y avisar'}
        </Boton>
        <Boton type="button" tamano="sm" variante="fantasma" onClick={alCancelar} disabled={guardando}>
          Volver
        </Boton>
      </div>
    </form>
  );
}

/** Cada cambio, con cuándo, quién y sus datos. */
function Historial({ historial = [] }) {
  if (historial.length === 0) return null;

  const detalle = (paso) => {
    const d = paso.datos || {};
    if (paso.estado === 'con_fecha') return describirEntrega(d.fecha, d.franja);
    if (paso.estado === 'enviado') return [d.empresa, d.seguimiento].filter(Boolean).join(' · ');
    if (paso.estado === 'cancelado') return d.motivo;
    return '';
  };

  return (
    <div>
      <p className="text-xs text-tinta-suave tracking-wide mb-2">HISTORIAL</p>
      <ol aria-label="Historial del pedido" className="border-l-2 border-borde pl-4 space-y-3 text-sm">
        {historial.map((paso, i) => (
          <li key={i}>
            <p className="font-semibold text-tinta">{paso.etiqueta}</p>
            {detalle(paso) && <p className="text-tinta-media break-words">{detalle(paso)}</p>}
            <p className="text-xs text-tinta-suave">{fechaYHora(paso.creado_en)} · {paso.quien}</p>
          </li>
        ))}
      </ol>
    </div>
  );
}

export default DetallePedido;
