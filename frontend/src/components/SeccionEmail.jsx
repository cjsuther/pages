import React, { useEffect, useState } from 'react';
import { Check, Loader2, Mail, Send, Trash2 } from 'lucide-react';

/** Al elegir la seguridad se sugiere el puerto que casi siempre va con ella. */
const PUERTO_SUGERIDO = { ssl: '465', tls: '587' };

const VACIO = {
  host: '',
  port: '587',
  seguridad: 'tls',
  usuario: '',
  contrasena: '',
  remitente_email: '',
  remitente_nombre: '',
  responder_a: '',
};

const INPUT = 'w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors';

function formularioDe(cuenta) {
  if (!cuenta) return VACIO;
  return {
    host: cuenta.host || '',
    port: String(cuenta.port || ''),
    seguridad: cuenta.seguridad || 'tls',
    usuario: cuenta.usuario || '',
    // La contraseña no vuelve nunca del servidor: vacía es "la que estaba".
    contrasena: '',
    remitente_email: cuenta.remitente_email || '',
    remitente_nombre: cuenta.remitente_nombre || '',
    responder_a: cuenta.responder_a || '',
  };
}

function Campo({ id, etiqueta, ayuda, children }) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-semibold text-tinta mb-1.5">{etiqueta}</label>
      {children}
      {ayuda && <p className="text-xs text-tinta-suave mt-2">{ayuda}</p>}
    </div>
  );
}

/**
 * Configuración → Email: la casilla SMTP propia de la página, para escribirles
 * a sus clientes desde su dirección y no desde la de Rezonar.
 */
function SeccionEmail({ pageId, apiUrl, token }) {
  const [cargando, setCargando] = useState(true);
  const [noDisponible, setNoDisponible] = useState('');
  const [cuenta, setCuenta] = useState(null);
  const [predeterminados, setPredeterminados] = useState({ remitente_nombre: '', responder_a: '' });
  const [form, setForm] = useState(VACIO);
  const [ocupado, setOcupado] = useState(null); // 'guardar' | 'probar' | 'quitar'
  const [aviso, setAviso] = useState(null); // { tipo: 'ok' | 'error', texto }

  const url = (archivo) => `${apiUrl}/pages/${archivo}?page_id=${pageId}`;
  const headers = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  const aplicar = (datos) => {
    setCuenta(datos.cuenta || null);
    setPredeterminados(datos.predeterminados || { remitente_nombre: '', responder_a: '' });
    setForm(formularioDe(datos.cuenta));
  };

  useEffect(() => {
    let vigente = true;
    (async () => {
      try {
        const res = await fetch(url('email.php'), { headers });
        const datos = await res.json();
        if (!vigente) return;
        if (res.status === 503) {
          setNoDisponible(datos.error || 'El envío por SMTP propio no está disponible.');
        } else if (!res.ok) {
          setAviso({ tipo: 'error', texto: datos.error || 'No pudimos cargar la configuración de email' });
        } else {
          aplicar(datos);
        }
      } catch (e) {
        if (vigente) setAviso({ tipo: 'error', texto: 'No pudimos cargar la configuración de email' });
      } finally {
        if (vigente) setCargando(false);
      }
    })();
    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageId]);

  const cambiar = (campo, valor) => {
    setAviso(null);
    setForm((actual) => {
      const proximo = { ...actual, [campo]: valor };
      if (campo === 'seguridad' && PUERTO_SUGERIDO[valor]) proximo.port = PUERTO_SUGERIDO[valor];
      return proximo;
    });
  };

  const pedir = async (accion, archivo, opciones, alSalirBien) => {
    setOcupado(accion);
    setAviso(null);
    try {
      const res = await fetch(url(archivo), { headers, ...opciones });
      const datos = await res.json();
      if (!res.ok) throw new Error(datos.error || 'Algo salió mal. Probá de nuevo.');
      alSalirBien(datos);
    } catch (e) {
      setAviso({ tipo: 'error', texto: e.message });
    } finally {
      setOcupado(null);
    }
  };

  const guardar = (e) => {
    e.preventDefault();
    pedir('guardar', 'email.php', {
      method: 'PUT',
      body: JSON.stringify({ ...form, page_id: Number(pageId), port: Number(form.port) }),
    }, (datos) => {
      aplicar(datos);
      setAviso({ tipo: 'ok', texto: 'Guardado' });
    });
  };

  const probar = () => {
    pedir('probar', 'email-prueba.php', {
      method: 'POST',
      body: JSON.stringify({ page_id: Number(pageId) }),
    }, (datos) => setAviso({ tipo: 'ok', texto: datos.mensaje || 'Te mandamos un mail de prueba' }));
  };

  const quitar = () => {
    if (!window.confirm('¿Quitar la cuenta de email? Se borran el servidor, el usuario y la contraseña.')) return;
    pedir('quitar', 'email.php', { method: 'DELETE' }, () => {
      aplicar({ cuenta: null, predeterminados });
      setAviso({ tipo: 'ok', texto: 'Se quitó la cuenta de email' });
    });
  };

  return (
    <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
      <h2 className="text-2xl font-bold mb-2 tracking-tight">Email</h2>
      <p className="text-sm text-tinta-suave mb-6">
        Cargá tu propia casilla para escribirles a tus clientes desde tu dirección y no desde
        la de Rezonar. Las entradas y los productos se siguen mandando desde Rezonar.
      </p>

      {cargando ? (
        <p className="flex items-center gap-2 text-sm text-tinta-suave">
          <Loader2 className="w-4 h-4 animate-spin" /> Cargando...
        </p>
      ) : noDisponible ? (
        <p role="alert" className="text-sm text-amber-800 bg-amber-50 border border-amber-200 px-4 py-3">
          {noDisponible}
        </p>
      ) : (
        <form onSubmit={guardar} className="space-y-6">
          <div className="grid md:grid-cols-3 gap-6">
            <div className="md:col-span-2">
              <Campo id="smtp-host" etiqueta="Servidor">
                <input id="smtp-host" type="text" value={form.host} placeholder="smtp.gmail.com"
                  onChange={(e) => cambiar('host', e.target.value)} className={INPUT} required />
              </Campo>
            </div>
            <Campo id="smtp-seguridad" etiqueta="Seguridad">
              <select id="smtp-seguridad" value={form.seguridad}
                onChange={(e) => cambiar('seguridad', e.target.value)} className={INPUT}>
                <option value="ssl">SSL</option>
                <option value="tls">TLS (STARTTLS)</option>
                <option value="ninguna">Ninguna</option>
              </select>
            </Campo>
            <Campo id="smtp-port" etiqueta="Puerto">
              <input id="smtp-port" type="number" min="1" max="65535" value={form.port}
                onChange={(e) => cambiar('port', e.target.value)} className={INPUT} required />
            </Campo>
            <Campo id="smtp-usuario" etiqueta="Usuario">
              <input id="smtp-usuario" type="text" autoComplete="off" value={form.usuario}
                placeholder="tu@casilla.com" onChange={(e) => cambiar('usuario', e.target.value)}
                className={INPUT} required />
            </Campo>
            <Campo
              id="smtp-contrasena"
              etiqueta="Contraseña"
              ayuda={cuenta?.tiene_contrasena ? 'Dejala vacía para mantener la que ya está guardada.' : null}
            >
              <input id="smtp-contrasena" type="password" autoComplete="new-password" value={form.contrasena}
                placeholder={cuenta?.tiene_contrasena ? '••••••••' : ''}
                onChange={(e) => cambiar('contrasena', e.target.value)} className={INPUT}
                required={!cuenta?.tiene_contrasena} />
            </Campo>
          </div>

          <div className="grid md:grid-cols-3 gap-6">
            <Campo id="smtp-remitente-email" etiqueta="Email del remitente">
              <input id="smtp-remitente-email" type="email" value={form.remitente_email}
                placeholder="tu@casilla.com" onChange={(e) => cambiar('remitente_email', e.target.value)}
                className={INPUT} required />
            </Campo>
            <Campo id="smtp-remitente-nombre" etiqueta="Nombre del remitente"
              ayuda={predeterminados.remitente_nombre ? `Si lo dejás vacío: ${predeterminados.remitente_nombre}` : null}>
              <input id="smtp-remitente-nombre" type="text" value={form.remitente_nombre}
                placeholder={predeterminados.remitente_nombre}
                onChange={(e) => cambiar('remitente_nombre', e.target.value)} className={INPUT} />
            </Campo>
            <Campo id="smtp-responder-a" etiqueta="Responder a (opcional)"
              ayuda={predeterminados.responder_a ? `Si lo dejás vacío: ${predeterminados.responder_a}` : null}>
              <input id="smtp-responder-a" type="email" value={form.responder_a}
                placeholder={predeterminados.responder_a}
                onChange={(e) => cambiar('responder_a', e.target.value)} className={INPUT} />
            </Campo>
          </div>

          <div className="text-sm text-tinta-media bg-papel-hueso border border-borde rounded-xl px-4 py-3 space-y-1">
            <p className="font-semibold text-tinta flex items-center gap-2"><Mail className="w-4 h-4" /> Datos de los proveedores más comunes</p>
            <p>
              <strong>Gmail:</strong> smtp.gmail.com, SSL 465 o TLS 587. La contraseña normal de Gmail
              no funciona: necesitás una <em>contraseña de aplicación</em>, que se crea en tu cuenta de
              Google → Seguridad (hace falta tener activada la verificación en dos pasos).
            </p>
            <p><strong>Hostinger:</strong> smtp.hostinger.com, SSL 465. Usuario y contraseña de la casilla.</p>
            <p><strong>Outlook / Hotmail:</strong> smtp-mail.outlook.com, TLS 587. Tu email y su contraseña.</p>
          </div>

          {aviso && (
            aviso.tipo === 'error' ? (
              <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">
                {aviso.texto}
              </p>
            ) : (
              <p role="status" className="flex items-center gap-2 text-verde-oscuro text-sm font-medium">
                <Check className="w-4 h-4" /> {aviso.texto}
              </p>
            )
          )}

          <div className="flex flex-wrap items-center gap-3">
            <button type="submit" disabled={ocupado !== null}
              className="inline-flex items-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-50">
              {ocupado === 'guardar' && <Loader2 className="w-4 h-4 animate-spin" />}
              {ocupado === 'guardar' ? 'Guardando...' : 'Guardar'}
            </button>
            <button type="button" onClick={probar} disabled={!cuenta || ocupado !== null}
              className="inline-flex items-center gap-2 rounded-full bg-white border border-borde-fuerte text-tinta px-6 py-3 font-semibold hover:border-verde-oscuro transition-colors disabled:opacity-50">
              {ocupado === 'probar' ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
              {ocupado === 'probar' ? 'Enviando...' : 'Enviar prueba'}
            </button>
            {cuenta && (
              <button type="button" onClick={quitar} disabled={ocupado !== null}
                className="inline-flex items-center gap-2 text-red-700 text-sm font-bold px-2 py-3 disabled:opacity-50">
                <Trash2 className="w-4 h-4" /> Quitar cuenta
              </button>
            )}
          </div>
          <p className="text-xs text-tinta-suave">
            La prueba te llega a vos, con la configuración guardada. Hasta 5 pruebas por hora.
            {cuenta?.verificado_en && ' La última prueba salió bien.'}
          </p>
        </form>
      )}
    </div>
  );
}

export default SeccionEmail;
