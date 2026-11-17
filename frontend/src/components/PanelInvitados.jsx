import React, { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Copy, Download, Mail, QrCode, RefreshCw, UserPlus, X } from 'lucide-react';
import { Boton, Campo, Etiqueta, Selector, Tarjeta } from './ui';
import { IconoDeMarca } from './IconosRedes';
import PlanoDeLugares from './PlanoDeLugares';
import { resumirLugares } from '../utils/plano';
import {
  MAX_LUGARES_POR_INVITADO,
  estadoDeInvitado,
  etiquetaDeLugares,
  whatsAppDeInvitado,
} from '../utils/invitados';

const FORMULARIO_VACIO = { nombre: '', email: '', telefono: '', cantidad: 1, tipo: '' };

/**
 * Invitados sin costo de un evento.
 *
 * Cada invitado recibe una entrada como la de cualquier comprador —código,
 * QR, misma puerta— pero sin pagar: no pasa por Mercado Pago ni deja
 * comisión. Sí ocupa lugar: descuenta de la capacidad del evento, igual que
 * una venta.
 *
 * El email es opcional: si está, la entrada sale por mail; si no, se le pasa
 * el link por WhatsApp o se le muestra el QR.
 *
 * El servidor sólo responde a quien administra la página del evento.
 */
function PanelInvitados({ linkId, apiUrl, token, nombreEvento = '' }) {
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [form, setForm] = useState(FORMULARIO_VACIO);
  const [lugares, setLugares] = useState([]);
  const [guardando, setGuardando] = useState(false);
  const [errorForm, setErrorForm] = useState(null);
  const [aviso, setAviso] = useState(null);
  const [porAnular, setPorAnular] = useState(null);
  const [errorAccion, setErrorAccion] = useState(null);
  const [ocupado, setOcupado] = useState(null);
  const [conQr, setConQr] = useState(null);

  const cabeceras = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };

  const cargar = async () => {
    setCargando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/invitados.php?link_id=${linkId}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No pudimos cargar los invitados');
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

  const cambiar = (campo) => (e) => setForm({ ...form, [campo]: e.target.value });

  const agregar = async (e) => {
    e.preventDefault();
    setErrorForm(null);
    setAviso(null);

    if (!form.nombre.trim()) {
      setErrorForm('Falta el nombre del invitado');
      return;
    }

    const resumen = datos.resumen;
    const conPlano = !!resumen.plano;

    if (conPlano && lugares.length === 0) {
      setErrorForm('Elegí sus lugares en el plano');
      return;
    }

    const cuerpo = {
      link_id: linkId,
      nombre: form.nombre.trim(),
      email: form.email.trim(),
      telefono: form.telefono.trim(),
    };

    if (conPlano) {
      cuerpo.lugares = lugares;
    } else {
      cuerpo.cantidad = Number(form.cantidad);
    }

    if (!conPlano && resumen.tipos && resumen.tipos.length > 0) {
      cuerpo.tipo = form.tipo || resumen.tipos[0].id;
    }

    setGuardando(true);

    try {
      const r = await fetch(`${apiUrl}/entradas/invitados.php`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify(cuerpo),
      });
      const respuesta = await r.json();

      if (!r.ok) {
        setErrorForm(respuesta.error || 'No pudimos agregar el invitado');

        if (respuesta.ocupados) {
          setDatos({ ...datos, resumen: { ...resumen, ocupados: respuesta.ocupados } });
          setLugares(lugares.filter((l) => !respuesta.ocupados.includes(l)));
        }
        return;
      }

      setDatos({ invitados: respuesta.invitados, resumen: respuesta.resumen });
      setForm({ ...FORMULARIO_VACIO, tipo: form.tipo });
      setLugares([]);
      setAviso(
        respuesta.mail && respuesta.mail.enviado
          ? { ok: true, texto: `Listo: ${cuerpo.nombre} ya tiene su entrada. Se la mandamos a ${cuerpo.email}.` }
          : {
              ok: true,
              texto: cuerpo.email
                ? `${cuerpo.nombre} ya tiene su entrada, pero no pudimos mandarle el mail. Pasale el link o reenviala.`
                : `${cuerpo.nombre} ya tiene su entrada. Pasale el link por WhatsApp o mostrale el QR.`,
            }
      );
    } catch (err) {
      setErrorForm('No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  const accion = async (invitado, cual) => {
    setErrorAccion(null);
    setAviso(null);
    setOcupado(`${cual}:${invitado.codigo}`);

    try {
      const r = await fetch(`${apiUrl}/entradas/invitado.php`, {
        method: 'POST',
        headers: cabeceras,
        body: JSON.stringify({ codigo: invitado.codigo, accion: cual }),
      });
      const respuesta = await r.json();

      if (!r.ok) {
        setErrorAccion(respuesta.error || 'No se pudo completar');
        return false;
      }

      setDatos({ invitados: respuesta.invitados, resumen: respuesta.resumen });
      setAviso({
        ok: true,
        texto: cual === 'anular'
          ? `Anulamos la entrada de ${invitado.nombre}: su QR ya no vale.`
          : `Le volvimos a mandar la entrada a ${invitado.email}.`,
      });
      return true;
    } catch (err) {
      setErrorAccion('No pudimos conectarnos al servidor');
      return false;
    } finally {
      setOcupado(null);
    }
  };

  const copiar = async (invitado) => {
    try {
      await navigator.clipboard.writeText(invitado.url);
      setAviso({ ok: true, texto: `Copiamos el link de la entrada de ${invitado.nombre}.` });
    } catch (e) {
      setAviso({ ok: false, texto: `No pudimos copiar. El link es ${invitado.url}` });
    }
  };

  if (cargando && !datos) {
    return <p className="text-tinta-suave py-6">Cargando invitados...</p>;
  }

  if (error) {
    return <p className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>;
  }

  const { invitados = [], resumen = {} } = datos || {};

  if (!resumen.configurado) {
    return (
      <p className="text-sm text-tinta-media border border-borde bg-white p-6">
        Para sumar invitados, primero configurá las entradas del evento (la capacidad) en la solapa Entradas.
      </p>
    );
  }

  const conPlano = !!resumen.plano;
  const conTipos = !conPlano && resumen.tipos && resumen.tipos.length > 0;
  const tope = Math.min(
    MAX_LUGARES_POR_INVITADO,
    resumen.disponibles,
    resumen.quedan_del_limite === null || resumen.quedan_del_limite === undefined ? Infinity : resumen.quedan_del_limite
  );
  const sinLugar = tope < 1;

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Dato etiqueta="INVITADOS" valor={resumen.lugares} detalle="lugares sin costo" />
        <Dato etiqueta="ENTRARON" valor={`${resumen.ingresaron}/${resumen.lugares}`} />
        <Dato
          etiqueta="QUEDAN EN EL EVENTO"
          valor={`${resumen.disponibles}/${resumen.capacidad}`}
          detalle="Los invitados descuentan de la capacidad"
        />
        <Limite resumen={resumen} linkId={linkId} apiUrl={apiUrl} cabeceras={cabeceras} onGuardado={setDatos} />
      </div>

      <Tarjeta className="p-5">
        <form onSubmit={agregar} className="space-y-4" aria-label="Agregar invitado">
          <h3 className="font-bold text-tinta flex items-center gap-2">
            <UserPlus className="w-5 h-5" /> Agregar invitado
          </h3>
          <p className="text-sm text-tinta-suave">
            Recibe su entrada sin costo, con QR válido en la puerta. No pasa por Mercado Pago ni
            genera comisiones.
          </p>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Etiqueta htmlFor="invitado-nombre">Nombre y apellido</Etiqueta>
              <Campo id="invitado-nombre" value={form.nombre} onChange={cambiar('nombre')} maxLength={150} />
            </div>
            <div>
              <Etiqueta htmlFor="invitado-email">Email (opcional)</Etiqueta>
              <Campo
                id="invitado-email"
                type="email"
                value={form.email}
                onChange={cambiar('email')}
                placeholder="Si lo cargás, le mandamos la entrada"
              />
            </div>
            <div>
              <Etiqueta htmlFor="invitado-telefono">Teléfono (opcional)</Etiqueta>
              <Campo
                id="invitado-telefono"
                type="tel"
                value={form.telefono}
                onChange={cambiar('telefono')}
                placeholder="Para mandarle el link por WhatsApp"
                maxLength={50}
              />
            </div>

            {!conPlano && (
              <div>
                <Etiqueta htmlFor="invitado-cantidad">Lugares</Etiqueta>
                <Selector id="invitado-cantidad" value={form.cantidad} onChange={cambiar('cantidad')} disabled={sinLugar}>
                  {Array.from({ length: Math.max(1, tope === Infinity ? MAX_LUGARES_POR_INVITADO : tope) }, (_, i) => i + 1).map((n) => (
                    <option key={n} value={n}>
                      {n === 1 ? 'Solo (1 lugar)' : `${etiquetaDeLugares(n)} (${n} lugares)`}
                    </option>
                  ))}
                </Selector>
              </div>
            )}

            {conTipos && (
              <div>
                <Etiqueta htmlFor="invitado-tipo">Tipo de entrada</Etiqueta>
                <Selector id="invitado-tipo" value={form.tipo || resumen.tipos[0].id} onChange={cambiar('tipo')}>
                  {resumen.tipos.map((t) => (
                    <option key={t.id} value={t.id}>{t.nombre}</option>
                  ))}
                </Selector>
              </div>
            )}
          </div>

          {conPlano && (
            <div>
              <p className="text-sm font-semibold text-tinta mb-2">Lugares en el plano</p>
              <PlanoDeLugares
                plano={resumen.plano}
                ocupados={resumen.ocupados || []}
                elegidos={lugares}
                onCambiar={setLugares}
                maximo={tope}
              />
              <p className="text-sm text-tinta mt-2">
                {lugares.length === 0
                  ? 'Tocá los lugares que le querés dar.'
                  : `${lugares.length} ${lugares.length === 1 ? 'lugar' : 'lugares'}: ${resumirLugares(lugares)}`}
              </p>
            </div>
          )}

          {sinLugar && (
            <p role="status" className="text-sm text-amber-800 bg-amber-50 border border-amber-200 px-3 py-2">
              {resumen.disponibles < 1
                ? 'No quedan lugares en el evento.'
                : 'Ya se usó todo el límite de invitados de este evento.'}
            </p>
          )}

          {errorForm && (
            <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-3 py-2">{errorForm}</p>
          )}

          <Boton type="submit" disabled={guardando || sinLugar}>
            {guardando ? 'Agregando...' : 'Agregar invitado'}
          </Boton>
        </form>
      </Tarjeta>

      {aviso && (
        <p
          role="status"
          className={`text-sm px-3 py-2 border ${
            aviso.ok ? 'text-verde-oscuro bg-white border-borde' : 'text-amber-800 bg-amber-50 border-amber-200'
          }`}
        >
          {aviso.texto}
        </p>
      )}

      {errorAccion && (
        <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-3 py-2">{errorAccion}</p>
      )}

      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={cargar}
          className="text-sm text-tinta-media hover:text-tinta flex items-center gap-2"
        >
          <RefreshCw className="w-4 h-4" />
          Actualizar
        </button>
      </div>

      {invitados.length === 0 ? (
        <p className="text-tinta-suave text-sm border border-borde bg-white p-6">
          Todavía no hay invitados para este evento.
        </p>
      ) : (
        <ul className="divide-y divide-borde border border-borde" aria-label="Lista de invitados">
          {invitados.map((inv) => {
            const estado = estadoDeInvitado(inv);
            const vigente = inv.estado === 'vigente';

            return (
              <li key={inv.codigo} className={`p-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between ${vigente ? '' : 'opacity-60'}`}>
                <div className="min-w-0">
                  <p className="font-semibold text-tinta">
                    {inv.nombre}{' '}
                    <span className="text-sm font-normal text-tinta-media">· {etiquetaDeLugares(inv.cantidad)}</span>{' '}
                    <span className={`text-xs px-2 py-0.5 rounded ${estado.clase}`}>{estado.texto}</span>
                  </p>
                  <p className="text-xs text-tinta-suave font-mono">{inv.codigo}</p>
                  {inv.email && <p className="text-xs text-tinta-media">{inv.email}</p>}
                  {inv.tipos && <p className="text-xs text-tinta-media">{inv.tipos}</p>}
                  {inv.lugares && inv.lugares.length > 0 && (
                    <p className="text-xs text-tinta-media">{resumirLugares(inv.lugares)}</p>
                  )}
                  {inv.invitado_por && <p className="text-xs text-tinta-suave">Lo invitó {inv.invitado_por}</p>}
                </div>

                {vigente && (
                  <div className="flex flex-wrap gap-2 sm:justify-end">
                    <a
                      href={whatsAppDeInvitado(inv, nombreEvento)}
                      target="_blank"
                      rel="noopener noreferrer"
                      aria-label={`Mandarle la entrada por WhatsApp a ${inv.nombre}`}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-full border border-borde-fuerte hover:border-tinta"
                    >
                      <IconoDeMarca red="whatsapp" className="w-4 h-4" /> WhatsApp
                    </a>
                    <Boton variante="secundario" tamano="sm" type="button" onClick={() => copiar(inv)} aria-label={`Copiar el link de ${inv.nombre}`}>
                      <Copy className="w-4 h-4" /> Link
                    </Boton>
                    <Boton variante="secundario" tamano="sm" type="button" onClick={() => setConQr(inv)} aria-label={`Ver el QR de ${inv.nombre}`}>
                      <QrCode className="w-4 h-4" /> QR
                    </Boton>
                    {inv.email && (
                      <Boton
                        variante="secundario"
                        tamano="sm"
                        type="button"
                        disabled={ocupado === `reenviar:${inv.codigo}`}
                        onClick={() => accion(inv, 'reenviar')}
                        aria-label={`Reenviar la entrada a ${inv.nombre}`}
                      >
                        <Mail className="w-4 h-4" /> Reenviar
                      </Boton>
                    )}
                    <Boton variante="peligro" tamano="sm" type="button" onClick={() => setPorAnular(inv)} aria-label={`Anular a ${inv.nombre}`}>
                      Anular
                    </Boton>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {conQr && <QrDelInvitado invitado={conQr} onCerrar={() => setConQr(null)} />}

      {porAnular && (
        <div className="fixed inset-0 bg-white bg-opacity-80 z-50 flex items-center justify-center p-4">
          <div role="dialog" aria-label="Anular invitado" className="bg-white border border-borde-fuerte rounded-lg p-6 max-w-sm w-full">
            <h4 className="text-tinta font-bold text-lg mb-2">¿Anular a {porAnular.nombre}?</h4>
            <p className="text-sm text-tinta-suave mb-6">
              Su QR deja de valer en la puerta y {porAnular.cantidad === 1 ? 'el lugar vuelve' : 'los lugares vuelven'} a
              estar disponibles. No se puede deshacer: para volver a invitarlo, cargalo de nuevo.
            </p>
            <div className="flex gap-3 justify-end">
              <button
                type="button"
                onClick={() => setPorAnular(null)}
                className="px-4 py-2 text-sm text-tinta-media hover:text-tinta transition"
              >
                Volver
              </button>
              <button
                type="button"
                disabled={!!ocupado}
                onClick={async () => {
                  await accion(porAnular, 'anular');
                  setPorAnular(null);
                }}
                className="px-4 py-2 text-sm font-bold bg-red-600 text-white rounded hover:bg-red-500 transition disabled:opacity-50"
              >
                {ocupado ? 'Anulando...' : 'Anular la entrada'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * El límite opcional de lugares para invitados. Vacío = sin límite (igual
 * descuentan de la capacidad).
 */
function Limite({ resumen, linkId, apiUrl, cabeceras, onGuardado }) {
  const actual = resumen.max_invitados === null || resumen.max_invitados === undefined ? '' : String(resumen.max_invitados);
  const [valor, setValor] = useState(actual);
  const [error, setError] = useState(null);
  const [guardando, setGuardando] = useState(false);

  useEffect(() => { setValor(actual); }, [actual]);

  const guardar = async (e) => {
    e.preventDefault();
    setError(null);
    setGuardando(true);

    try {
      const r = await fetch(`${apiUrl}/entradas/invitados.php`, {
        method: 'PUT',
        headers: cabeceras,
        body: JSON.stringify({ link_id: linkId, max_invitados: valor.trim() === '' ? null : Number(valor) }),
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No se pudo guardar');
        return;
      }

      onGuardado(cuerpo);
    } catch (err) {
      setError('No pudimos conectarnos al servidor');
    } finally {
      setGuardando(false);
    }
  };

  return (
    <form onSubmit={guardar} className="border border-borde bg-white p-4" aria-label="Límite de invitados">
      <label htmlFor="invitados-limite" className="text-xs text-tinta-suave tracking-wide mb-1 block">LÍMITE DE INVITADOS</label>
      <div className="flex gap-2 items-center">
        <input
          id="invitados-limite"
          type="number"
          min="0"
          inputMode="numeric"
          value={valor}
          onChange={(e) => setValor(e.target.value)}
          placeholder="Sin límite"
          className="w-20 px-2 py-1 border border-borde-fuerte rounded text-tinta"
        />
        {valor !== actual && (
          <button type="submit" disabled={guardando} className="text-xs font-semibold text-verde-oscuro hover:text-tinta">
            {guardando ? '...' : 'Guardar'}
          </button>
        )}
      </div>
      {resumen.quedan_del_limite !== null && resumen.quedan_del_limite !== undefined && (
        <p className="text-xs text-tinta-suave mt-1">Quedan {resumen.quedan_del_limite}</p>
      )}
      {error && <p role="alert" className="text-xs text-red-700 mt-1">{error}</p>}
    </form>
  );
}

/** El QR de la entrada, para mostrarlo en pantalla o bajarlo y mandarlo. */
function QrDelInvitado({ invitado, onCerrar }) {
  const [imagen, setImagen] = useState(null);

  useEffect(() => {
    let vigente = true;

    QRCode.toDataURL(invitado.url, { width: 600, margin: 1, errorCorrectionLevel: 'H' })
      .then((dato) => { if (vigente) setImagen(dato); })
      .catch(() => { if (vigente) setImagen(null); });

    return () => { vigente = false; };
  }, [invitado.url]);

  return (
    <div className="fixed inset-0 bg-white bg-opacity-80 z-50 flex items-center justify-center p-4">
      <div role="dialog" aria-label={`QR de ${invitado.nombre}`} className="bg-white border border-borde-fuerte rounded-lg p-6 max-w-sm w-full text-center">
        <div className="flex justify-end">
          <button type="button" onClick={onCerrar} aria-label="Cerrar" className="text-tinta-suave hover:text-tinta">
            <X className="w-5 h-5" />
          </button>
        </div>
        <p className="font-bold text-tinta mb-3">{invitado.nombre}</p>
        {imagen && <img src={imagen} alt={`Código QR de la entrada de ${invitado.nombre}`} className="w-56 h-56 mx-auto" />}
        <p className="font-mono text-sm text-tinta mt-3">{invitado.codigo}</p>
        {imagen && (
          <Boton variante="secundario" tamano="sm" href={imagen} download={`entrada-${invitado.codigo}.png`} className="mt-4">
            <Download className="w-4 h-4" /> Descargar QR
          </Boton>
        )}
      </div>
    </div>
  );
}

function Dato({ etiqueta, valor, detalle }) {
  return (
    <div className="border border-borde bg-white p-4">
      <p className="text-xs text-tinta-suave tracking-wide mb-1">{etiqueta}</p>
      <p className="text-xl font-bold text-tinta">{valor}</p>
      {detalle && <p className="text-xs text-tinta-suave mt-1">{detalle}</p>}
    </div>
  );
}

export default PanelInvitados;
