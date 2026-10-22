import React, { useEffect, useRef, useState } from 'react';
import { Send, Save, Trash2, UserPlus } from 'lucide-react';
import { Modal, Boton, Campo, AreaTexto, Selector, Etiqueta, Aviso, Cargando } from './ui';
import { VARIABLES, reemplazarVariables, insertarEnCursor } from '../utils/mensajes';

const VACIO = { nombre: '', asunto: '', cuerpo: '', reemplazo_nombre: '' };

/**
 * Arma y manda un email a los clientes elegidos (#37).
 *
 * Los mensajes se guardan por página para reusarlos. {nombre} y
 * {primer_nombre} se reemplazan por el de cada cliente; la vista previa usa
 * uno real de la selección. Al confirmar se crea el envío y lo manda el
 * servidor por ráfagas.
 */
function DialogoEnvio({ abierto, alCerrar, pageId, apiUrl, token, cantidad, seleccion, muestra = [], alEnviar }) {
  const [cargando, setCargando] = useState(true);
  const [cuentaConfigurada, setCuentaConfigurada] = useState(true);
  const [mensajes, setMensajes] = useState([]);
  const [elegido, setElegido] = useState('');
  const [form, setForm] = useState(VACIO);
  const [ejemplo, setEjemplo] = useState(0);
  const [ocupado, setOcupado] = useState(null); // 'guardar' | 'borrar' | 'enviar'
  const [aviso, setAviso] = useState(null);
  const cuerpoRef = useRef(null);
  const cursor = useRef(null);

  const headers = { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };
  const urlMensajes = (extra = '') => `${apiUrl}/pages/mensajes.php?page_id=${pageId}${extra}`;

  useEffect(() => {
    if (!abierto) return undefined;

    let vigente = true;
    setCargando(true);
    setAviso(null);

    (async () => {
      try {
        const r = await fetch(urlMensajes(), { headers });
        const cuerpo = await r.json();
        if (!vigente) return;
        if (!r.ok) {
          setAviso({ tipo: 'error', texto: cuerpo.error || 'No pudimos traer los mensajes guardados' });
          return;
        }
        setMensajes(cuerpo.mensajes || []);
        setCuentaConfigurada(Boolean(cuerpo.cuenta_configurada));
      } catch (e) {
        if (vigente) setAviso({ tipo: 'error', texto: 'No pudimos traer los mensajes guardados' });
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [abierto, pageId]);

  // Después de insertar una variable, el cursor queda justo detrás.
  useEffect(() => {
    if (cursor.current !== null && cuerpoRef.current) {
      cuerpoRef.current.focus();
      cuerpoRef.current.setSelectionRange(cursor.current, cursor.current);
      cursor.current = null;
    }
  }, [form.cuerpo]);

  const cambiar = (campo) => (e) => {
    setAviso(null);
    setForm({ ...form, [campo]: e.target.value });
  };

  const elegir = (e) => {
    const id = e.target.value;
    const mensaje = mensajes.find((m) => String(m.id) === id);
    setElegido(id);
    setAviso(null);
    setForm(mensaje
      ? { nombre: mensaje.nombre, asunto: mensaje.asunto, cuerpo: mensaje.cuerpo, reemplazo_nombre: mensaje.reemplazo_nombre || '' }
      : VACIO);
  };

  const insertar = (variable) => {
    const area = cuerpoRef.current;
    const { texto, cursor: despues } = insertarEnCursor(
      form.cuerpo,
      area ? area.selectionStart : undefined,
      area ? area.selectionEnd : undefined,
      variable
    );
    cursor.current = despues;
    setForm({ ...form, cuerpo: texto });
  };

  const pedir = async (accion, url, opciones) => {
    setOcupado(accion);
    setAviso(null);
    try {
      const r = await fetch(url, { headers, ...opciones });
      const cuerpo = await r.json();
      if (!r.ok) throw new Error(cuerpo.error || 'Algo salió mal. Probá de nuevo.');
      return cuerpo;
    } catch (e) {
      setAviso({ tipo: 'error', texto: e.message });
      return null;
    } finally {
      setOcupado(null);
    }
  };

  const guardar = async () => {
    const editando = elegido !== '';
    const cuerpo = await pedir('guardar', urlMensajes(), {
      method: editando ? 'PUT' : 'POST',
      body: JSON.stringify({ ...form, page_id: Number(pageId), ...(editando ? { id: Number(elegido) } : {}) }),
    });
    if (!cuerpo) return;

    const guardado = cuerpo.mensaje;
    setMensajes((actuales) => (editando
      ? actuales.map((m) => (m.id === guardado.id ? guardado : m))
      : [...actuales, guardado]));
    setElegido(String(guardado.id));
    setAviso({ tipo: 'ok', texto: 'Mensaje guardado' });
  };

  const borrar = async () => {
    if (!window.confirm('¿Borrar este mensaje guardado? Los envíos que ya se hicieron con él no cambian.')) return;

    const cuerpo = await pedir('borrar', urlMensajes(`&id=${elegido}`), { method: 'DELETE' });
    if (!cuerpo) return;

    setMensajes((actuales) => actuales.filter((m) => String(m.id) !== elegido));
    setElegido('');
    setForm(VACIO);
    setAviso({ tipo: 'ok', texto: 'Mensaje borrado' });
  };

  const enviar = async () => {
    const plural = cantidad === 1 ? 'cliente' : 'clientes';
    if (!window.confirm(`¿Mandar este email a ${cantidad} ${plural}? Sale de a tandas, con pausas entre una y otra.`)) return;

    const cuerpo = await pedir('enviar', `${apiUrl}/pages/envios.php?page_id=${pageId}`, {
      method: 'POST',
      body: JSON.stringify({
        page_id: Number(pageId),
        mensaje_id: elegido ? Number(elegido) : null,
        mensaje: { asunto: form.asunto, cuerpo: form.cuerpo, reemplazo_nombre: form.reemplazo_nombre },
        seleccion,
      }),
    });
    if (cuerpo) alEnviar(cuerpo);
  };

  const cliente = muestra[ejemplo] || muestra[0] || null;
  const listoParaEnviar = cuentaConfigurada && form.asunto.trim() && form.cuerpo.trim() && cantidad > 0;

  return (
    <Modal abierto={abierto} alCerrar={alCerrar} titulo="Enviar email" ancho="max-w-3xl">
      {cargando ? (
        <Cargando texto="Cargando mensajes…" />
      ) : (
        <div className="space-y-5">
          {!cuentaConfigurada && (
            <Aviso tipo="atencion">
              Para mandar mails primero cargá la cuenta de email de la página en Configuración → Email.
            </Aviso>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <Etiqueta htmlFor="envio-guardado">Mensaje guardado</Etiqueta>
              <Selector id="envio-guardado" value={elegido} onChange={elegir}>
                <option value="">Mensaje nuevo</option>
                {mensajes.map((m) => <option key={m.id} value={m.id}>{m.nombre}</option>)}
              </Selector>
            </div>
            <div>
              <Etiqueta htmlFor="envio-nombre">Nombre del mensaje (sólo lo ves vos)</Etiqueta>
              <Campo id="envio-nombre" value={form.nombre} onChange={cambiar('nombre')} placeholder="Aviso de nueva fecha" />
            </div>
          </div>

          <div>
            <Etiqueta htmlFor="envio-asunto">Asunto</Etiqueta>
            <Campo id="envio-asunto" value={form.asunto} onChange={cambiar('asunto')} placeholder="¡Volvemos el sábado!" />
          </div>

          <div>
            <div className="flex flex-wrap items-end justify-between gap-2 mb-1.5">
              <Etiqueta htmlFor="envio-cuerpo" className="mb-0">Mensaje</Etiqueta>
              <div className="flex gap-2">
                <Boton type="button" variante="secundario" tamano="sm" onClick={() => insertar(VARIABLES.nombre)}>
                  <UserPlus className="w-4 h-4" /> Insertar nombre
                </Boton>
                <Boton type="button" variante="fantasma" tamano="sm" onClick={() => insertar(VARIABLES.primerNombre)}>
                  Insertar primer nombre
                </Boton>
              </div>
            </div>
            <AreaTexto
              id="envio-cuerpo"
              ref={cuerpoRef}
              rows={8}
              value={form.cuerpo}
              onChange={cambiar('cuerpo')}
              placeholder={'Hola {primer_nombre},\n\nTe contamos que…'}
            />
            <p className="text-xs text-tinta-suave mt-2">
              Los links (https://…) quedan clickeables. Al pie va siempre el link para darse de baja.
            </p>
          </div>

          <div>
            <Etiqueta htmlFor="envio-reemplazo">Si el cliente no tiene nombre, poner</Etiqueta>
            <Campo id="envio-reemplazo" value={form.reemplazo_nombre} onChange={cambiar('reemplazo_nombre')} placeholder="Nada" />
            <p className="text-xs text-tinta-suave mt-2">
              Vacío: “Hola {VARIABLES.primerNombre},” queda “Hola,”.
            </p>
          </div>

          {cliente && (
            <div className="border border-borde rounded-xl p-4 bg-papel-hueso" aria-label="Vista previa">
              <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                <span className="text-sm font-semibold">Vista previa</span>
                {muestra.length > 1 && (
                  <Selector
                    aria-label="Cliente de la vista previa"
                    value={ejemplo}
                    onChange={(e) => setEjemplo(Number(e.target.value))}
                    className="!w-auto !py-1.5 text-sm"
                  >
                    {muestra.map((c, i) => <option key={c.email} value={i}>{c.nombre || c.email}</option>)}
                  </Selector>
                )}
              </div>
              <p className="text-xs text-tinta-suave">Para: {cliente.email}</p>
              <p className="font-semibold mt-1" data-testid="vista-asunto">
                {reemplazarVariables(form.asunto, cliente.nombre, form.reemplazo_nombre)}
              </p>
              <p className="mt-2 whitespace-pre-wrap text-sm" data-testid="vista-cuerpo">
                {reemplazarVariables(form.cuerpo, cliente.nombre, form.reemplazo_nombre)}
              </p>
            </div>
          )}

          {aviso && <Aviso tipo={aviso.tipo === 'ok' ? 'ok' : 'error'}>{aviso.texto}</Aviso>}

          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex gap-2">
              <Boton type="button" variante="secundario" onClick={guardar}
                disabled={ocupado !== null || !form.nombre.trim() || !form.asunto.trim() || !form.cuerpo.trim()}>
                <Save className="w-4 h-4" /> {elegido ? 'Guardar cambios' : 'Guardar mensaje'}
              </Boton>
              {elegido && (
                <Boton type="button" variante="peligro" onClick={borrar} disabled={ocupado !== null}>
                  <Trash2 className="w-4 h-4" /> Borrar
                </Boton>
              )}
            </div>
            <Boton type="button" onClick={enviar} disabled={ocupado !== null || !listoParaEnviar}>
              <Send className="w-4 h-4" />
              {ocupado === 'enviar' ? 'Enviando…' : `Enviar a ${cantidad} ${cantidad === 1 ? 'cliente' : 'clientes'}`}
            </Boton>
          </div>
        </div>
      )}
    </Modal>
  );
}

export default DialogoEnvio;
