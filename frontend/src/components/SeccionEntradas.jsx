import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Check, AlertTriangle, Loader2, ExternalLink } from 'lucide-react';
import { formatearPorcentaje } from '../utils/comisiones';

/**
 * Sección del editor donde el dueño conecta su cuenta de Mercado Pago.
 *
 * Se conecta por OAuth y no pegando credenciales: es lo único que permite que
 * Mercado Pago descuente la comisión de la plataforma en la misma operación.
 * Con un access token cargado a mano el cobro sale igual, pero la comisión se
 * ignora sin dar ningún error.
 */

/** Lo que puede salir mal en la vuelta desde Mercado Pago. */
const ERRORES = {
  cancelado: 'No autorizaste la conexión en Mercado Pago.',
  estado_invalido: 'El link de conexión venció. Probá de nuevo.',
  sin_codigo: 'Mercado Pago no devolvió la autorización.',
  sin_permiso: 'Ya no tenés permiso para administrar esta página.',
  fallo_mercadopago: 'Mercado Pago rechazó la conexión. Probá de nuevo.',
  no_se_pudo_guardar: 'No pudimos guardar la conexión. Probá de nuevo.',
};

function SeccionEntradas({ pageId, apiUrl, token, emailContacto = '', onGuardarContacto }) {
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [conectando, setConectando] = useState(false);
  const [error, setError] = useState(null);
  const [aviso, setAviso] = useState(null);
  const [parametros, setParametros] = useSearchParams();

  const cabeceras = {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`,
  };

  const cargar = async () => {
    try {
      const r = await fetch(`${apiUrl}/entradas/credenciales.php?page_id=${pageId}`, {
        headers: cabeceras,
      });
      const cuerpo = await r.json();

      if (r.ok) {
        setDatos(cuerpo);
      }
    } catch (e) {
      setError('No pudimos cargar la configuración de cobros');
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    cargar();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pageId]);

  // Al volver desde Mercado Pago la URL trae el resultado. Se limpia enseguida
  // para que recargar la página no vuelva a mostrar el mismo cartel.
  useEffect(() => {
    const conectado = parametros.get('conectado');
    const falla = parametros.get('error');

    if (!conectado && !falla) {
      return;
    }

    if (conectado) {
      setAviso('Tu cuenta de Mercado Pago quedó conectada.');
    } else {
      setError(ERRORES[falla] || 'No se pudo conectar la cuenta.');
    }

    const limpios = new URLSearchParams(parametros);
    limpios.delete('conectado');
    limpios.delete('error');
    setParametros(limpios, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const conectar = async () => {
    setConectando(true);
    setError(null);

    try {
      const r = await fetch(`${apiUrl}/entradas/conectar.php?page_id=${pageId}`, {
        method: 'POST',
        headers: cabeceras,
      });
      const cuerpo = await r.json();

      if (!r.ok) {
        setError(cuerpo.error || 'No se pudo iniciar la conexión');
        setConectando(false);
        return;
      }

      // Se sale a Mercado Pago; se vuelve a esta misma sección.
      window.location.href = cuerpo.url;
    } catch (e) {
      setError('No pudimos conectarnos al servidor');
      setConectando(false);
    }
  };

  const desconectar = async (confirmar = false) => {
    setError(null);
    setAviso(null);

    const r = await fetch(`${apiUrl}/entradas/credenciales.php?page_id=${pageId}`, {
      method: 'DELETE',
      headers: cabeceras,
      body: JSON.stringify({ confirmar }),
    });
    const cuerpo = await r.json();

    if (r.status === 409) {
      // Hay eventos cobrando: se pide confirmación en vez de romperlos callado.
      if (window.confirm(`${cuerpo.error}\n\n¿Desconectar igual?`)) {
        desconectar(true);
      }
      return;
    }

    if (!r.ok) {
      setError(cuerpo.error || 'No se pudo desconectar');
      return;
    }

    setDatos({ ...datos, cobros: cuerpo.cobros });
    setAviso('Mercado Pago desconectado');
  };

  if (cargando) {
    return (
      <div className="bg-gray-900 border border-gray-800 p-8 mb-8 text-gray-500">
        Cargando...
      </div>
    );
  }

  const cobros = (datos && datos.cobros) || { configurado: false };
  const comision = (datos && datos.comision) || 0;
  const mercadoPago = (datos && datos.mercadopago) || null;
  const disponible = datos && datos.disponible;

  return (
    <div className="bg-gray-900 border border-gray-800 p-8 mb-8">
      <h2 className="text-2xl font-black mb-2 tracking-tight">ENTRADAS</h2>
      <p className="text-sm text-gray-500 mb-8">
        Conectá tu cuenta de Mercado Pago para cobrar entradas. El dinero va directo
        a tu cuenta. Para eventos con reserva sin costo no hace falta.
      </p>

      <ContactoDeCompradores
        valor={emailContacto}
        onGuardar={onGuardarContacto}
      />

      {cobros.configurado ? (
        <div className="border border-gray-800 bg-black p-6 mb-6">
          <div className="flex items-start justify-between gap-4 flex-wrap">
            <div>
              <p className="flex items-center gap-2 text-emerald-400 font-bold mb-2">
                <Check className="w-4 h-4" />
                Mercado Pago conectado
              </p>

              {cobros.cuenta && (
                <p className="text-sm text-gray-500">
                  Cuenta <span className="font-mono text-gray-300">{cobros.cuenta}</span>
                </p>
              )}

              {cobros.modo === 'prueba' && (
                <p className="flex items-center gap-2 text-amber-400 text-sm mt-3">
                  <AlertTriangle className="w-4 h-4 shrink-0" />
                  Es una cuenta de prueba: los pagos no son reales.
                </p>
              )}

              {/* Una cuenta cargada a mano cobra igual, pero la comisión no se
                  descuenta. Conviene decirlo antes de que aparezca la diferencia
                  en la liquidación. */}
              {!cobros.admite_split && comision > 0 && (
                <p className="flex items-start gap-2 text-amber-400 text-sm mt-3">
                  <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                  Esta cuenta se cargó a mano y no permite el descuento automático
                  de la comisión. Volvé a conectarla desde acá.
                </p>
              )}
            </div>

            <button
              type="button"
              onClick={() => desconectar(false)}
              className="text-red-400 hover:text-red-300 text-sm font-bold"
            >
              DESCONECTAR
            </button>
          </div>
        </div>
      ) : (
        <div className="border border-gray-800 bg-black p-6 mb-6">
          <p className="text-sm text-gray-500 mb-6">
            Todavía no conectaste Mercado Pago. Sin esto sólo podés ofrecer reservas
            sin costo.
          </p>

          <button
            type="button"
            onClick={conectar}
            disabled={conectando || !disponible}
            className="bg-[#009ee3] text-white px-6 py-3 font-bold hover:opacity-90 transition disabled:opacity-50 flex items-center gap-2"
          >
            {conectando && <Loader2 className="w-4 h-4 animate-spin" />}
            {conectando ? 'REDIRIGIENDO...' : 'CONECTAR CON MERCADO PAGO'}
          </button>

          {!disponible && (
            <p className="flex items-start gap-2 text-amber-400 text-sm mt-4">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              La plataforma todavía no terminó de configurar su integración con
              Mercado Pago. Escribinos y lo resolvemos.
            </p>
          )}

          <p className="text-xs text-gray-600 mt-4">
            Te vamos a llevar a Mercado Pago para que autorices el cobro en tu nombre.
            No vemos ni guardamos tu usuario ni tu contraseña.
          </p>
        </div>
      )}

      {comision > 0 && (
        <div className="border border-gray-800 bg-black p-6 mb-6">
          <p className="text-sm text-gray-400 mb-2">
            <strong className="text-white">Comisión de Rezonar: {formatearPorcentaje(comision)}%</strong> de cada
            entrada vendida.
          </p>
          <p className="text-xs text-gray-600 mb-3">
            Se descuenta en el momento del cobro: el comprador paga una sola vez y a
            tu cuenta entra el resto. En una entrada de $10.000 son{' '}
            ${(10000 * comision / 100).toLocaleString('es-AR')} de comisión.
            Las reservas sin costo no pagan nada.
          </p>

          {/* Sin esto el dueño hace la cuenta sólo con nuestra comisión y no le
              cierra con lo que ve en su cuenta. El porcentaje y el plazo van
              juntos: en Mercado Pago uno depende del otro. Los dos salen de la
              configuración del servidor; si no están, no inventamos un número. */}
          {mercadoPago && (
            <p className="text-xs text-gray-500 border-t border-gray-800 pt-3">
              <strong className="text-gray-400">Aparte de esto, Mercado Pago cobra{' '}
              {formatearPorcentaje(mercadoPago.porcentaje)}%</strong> por procesar el pago, y libera
              la plata a los {mercadoPago.dias} días de la compra. Podés verlo en{' '}
              <a
                href="https://www.mercadopago.com.ar/costs-section/release-options"
                target="_blank"
                rel="noopener noreferrer"
                className="text-gray-400 hover:text-white inline-flex items-center gap-1"
              >
                Mercado Pago → Costos
                <ExternalLink className="w-3 h-3" />
              </a>
              .
            </p>
          )}
        </div>
      )}

      {error && (
        <p className="text-sm text-red-400 bg-red-950 border border-red-900 px-4 py-3">{error}</p>
      )}

      {aviso && (
        <p className="text-sm text-emerald-400 bg-emerald-950 border border-emerald-900 px-4 py-3">
          {aviso}
        </p>
      )}

      <p className="text-xs text-gray-600 mt-6">
        Podés revisar los permisos otorgados en{' '}
        <a
          href="https://www.mercadopago.com.ar/settings/security/connected-apps"
          target="_blank"
          rel="noopener noreferrer"
          className="text-gray-400 hover:text-white inline-flex items-center gap-1"
        >
          Mercado Pago → Aplicaciones conectadas
          <ExternalLink className="w-3 h-3" />
        </a>
        .
      </p>
    </div>
  );
}

/**
 * Casilla a la que le llegan las respuestas de quienes compraron.
 *
 * La entrada sale de una casilla de la plataforma, porque es la que el SPF del
 * dominio autoriza; sin este dato, "responder este mail" no llega a ninguna
 * parte. Con el dato cargado, el mail lleva Reply-To y la respuesta va derecho
 * a quien organiza.
 *
 * Se guarda al salir del campo y no con un botón aparte: es un dato suelto, y
 * un formulario de un solo campo con su propio "Guardar" es más ceremonia que
 * ayuda.
 */
function ContactoDeCompradores({ valor, onGuardar }) {
  const [email, setEmail] = useState(valor);
  const [invalido, setInvalido] = useState(false);

  useEffect(() => {
    setEmail(valor);
  }, [valor]);

  const guardar = () => {
    const limpio = email.trim();

    // Vaciarlo es válido: es la forma de dejar de publicar un contacto.
    if (limpio !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(limpio)) {
      setInvalido(true);
      return;
    }

    setInvalido(false);

    if (limpio !== (valor || '').trim() && onGuardar) {
      onGuardar(limpio);
    }
  };

  return (
    <div className="border border-gray-800 bg-black p-6 mb-6">
      <label htmlFor="email-contacto" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
        EMAIL DE CONTACTO
      </label>
      <input
        id="email-contacto"
        type="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        onBlur={guardar}
        placeholder="hola@tulugar.com"
        className="w-full bg-gray-900 border border-gray-700 px-4 py-3 text-white focus:border-white focus:outline-none"
      />
      <p className="text-xs text-gray-600 mt-2">
        {invalido
          ? 'Revisá el email: no parece una dirección válida.'
          : 'Cuando alguien responda el mail de su entrada, le llega acá. Si lo dejás vacío, el mail no invita a responder.'}
      </p>
    </div>
  );
}

export default SeccionEntradas;
