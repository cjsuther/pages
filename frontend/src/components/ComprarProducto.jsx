import React, { useEffect, useState } from 'react';
import { X, Loader2, Check, Truck } from 'lucide-react';
import Campo from './CampoDeCompra';
import DatosDeEntrega from './DatosDeEntrega';
import SelectorDeCaracteristicas from './SelectorDeCaracteristicas';
import {
  MAX_CANTIDAD, comprarProducto, cuerpoDelPedido, describirPrecio, entregaVacia, esFisico, esPago,
  pesos, problemaDelPedido, totalDelPedido,
} from '../utils/productos';
import { esEmailValido, sugerenciaDeEmail } from '../utils/email';
import { textoSobre } from '../utils/colores';
import useBloqueoDeScroll from '../hooks/useBloqueoDeScroll';

/**
 * Formulario para pedir un producto: nombre, email y teléfono.
 *
 * Pago: crea la orden, guarda el link de descarga para la vuelta (lo hace
 * comprarProducto) y lleva a Mercado Pago.
 *
 * Gratis: no pasa por Mercado Pago. El link llega sólo por mail —la API no lo
 * devuelve, para que un email inventado no sirva para bajar nada—, así que
 * acá se avisa eso y nunca se ofrece un botón de descarga.
 *
 * Un físico (#43) pide además las opciones, la cantidad, la dirección y los
 * horarios de entrega, con el teléfono obligatorio, y muestra el total con
 * envío antes de pagar. `seleccionInicial` son las opciones que ya se
 * eligieron en el detalle.
 */
function ComprarProducto({ producto, apiUrl, color = '#3B82F6', seleccionInicial = {}, onCerrar }) {
  const [datos, setDatos] = useState({ nombre: '', email: '', telefono: '' });
  const [seleccion, setSeleccion] = useState(seleccionInicial || {});
  const [cantidad, setCantidad] = useState(1);
  const [entrega, setEntrega] = useState(entregaVacia);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState(null);
  const [avisoEmail, setAvisoEmail] = useState(null);
  const [sugerencia, setSugerencia] = useState(null);
  const [pedido, setPedido] = useState(null);

  const fisico = esFisico(producto);
  const cuentas = totalDelPedido(producto.precio, fisico ? cantidad : 1, fisico ? producto.costo_envio : 0);
  const pago = cuentas.total > 0;

  useBloqueoDeScroll(true);

  useEffect(() => {
    const alApretar = (e) => {
      if (e.key === 'Escape') onCerrar();
    };
    document.addEventListener('keydown', alApretar);
    return () => document.removeEventListener('keydown', alApretar);
  }, [onCerrar]);

  const cambiar = (campo, valor) => {
    setDatos((previos) => ({ ...previos, [campo]: valor }));
    setError(null);

    if (campo === 'email') {
      setAvisoEmail(null);
      setSugerencia(null);
    }
  };

  const revisarEmail = () => {
    const email = datos.email.trim();
    if (email === '') return;

    if (!esEmailValido(email)) {
      setAvisoEmail('Revisá el email: parece que le falta algo.');
      return;
    }

    setAvisoEmail(null);
    setSugerencia(sugerenciaDeEmail(email));
  };

  const enviar = async (e) => {
    e.preventDefault();

    // El link llega por mail: con el email mal escrito no llega nunca.
    if (!esEmailValido(datos.email)) {
      setAvisoEmail('Revisá el email: parece que le falta algo.');
      return;
    }

    if (fisico) {
      const problema = problemaDelPedido({ producto, seleccion, telefono: datos.telefono, entrega });
      if (problema) {
        setError(problema);
        return;
      }
    }

    setEnviando(true);
    setError(null);

    try {
      const respuesta = await comprarProducto({
        apiUrl,
        linkId: producto.id,
        nombre: datos.nombre.trim(),
        email: datos.email.trim(),
        telefono: datos.telefono.trim(),
        pedido: fisico ? cuerpoDelPedido({ producto, seleccion, cantidad, entrega }) : undefined,
      });

      if (respuesta.url) {
        window.location.href = respuesta.url;
        return;
      }

      setPedido(respuesta);
    } catch (err) {
      setError(err.status ? err.message : 'No pudimos conectarnos. Revisá tu conexión e intentá de nuevo.');
    } finally {
      setEnviando(false);
    }
  };

  const colorDelBoton = { backgroundColor: color, color: textoSobre(color) };

  let textoDelBoton = pago ? 'IR A PAGAR' : 'PEDIR EL LINK';
  if (fisico && !pago) textoDelBoton = 'CONFIRMAR PEDIDO';
  if (enviando) textoDelBoton = 'PROCESANDO...';

  return (
    <div
      className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-start justify-center p-4 z-[60] overflow-y-auto"
      onClick={onCerrar}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="comprar-producto-titulo"
        className="bg-white border border-borde max-w-md w-full p-5 sm:p-8 my-8 relative text-tinta"
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          onClick={onCerrar}
          aria-label="Cerrar"
          className="absolute top-4 right-4 text-tinta-suave hover:text-tinta transition"
        >
          <X className="w-5 h-5" />
        </button>

        {pedido ? (
          <div className="text-center py-4" role="status">
            <div
              className="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-5"
              style={colorDelBoton}
            >
              <Check className="w-7 h-7" />
            </div>
            <h3 id="comprar-producto-titulo" className="text-2xl font-bold mb-2">
              {fisico ? 'Recibimos tu pedido' : 'Te mandamos el link a tu email'}
            </h3>
            <p className="text-tinta-media mb-6">
              {fisico
                ? `Te mandamos el detalle a ${datos.email.trim()}. Quien vende se va a poner en contacto para coordinar la entrega.`
                : `Revisá tu casilla (${datos.email.trim()}). Si no lo ves en unos minutos, mirá en spam.`}
            </p>
            {fisico && pedido.codigo && (
              <p className="mb-6">
                <a href={`/compra/${pedido.codigo}`} className="underline font-semibold">
                  Seguir mi pedido
                </a>
              </p>
            )}
            <button
              type="button"
              onClick={onCerrar}
              className="px-6 py-3 font-bold"
              style={colorDelBoton}
            >
              Listo
            </button>
          </div>
        ) : (
          <>
            <h3 id="comprar-producto-titulo" className="text-2xl font-bold mb-1">
              {pago || fisico ? 'Comprar' : 'Descargar gratis'}
            </h3>
            <p className="text-tinta-suave text-sm mb-6">
              {producto.text} · {describirPrecio(producto.precio)}
            </p>

            {fisico && producto.zona_envio && (
              <p className="flex items-start gap-2 text-sm bg-papel-hueso border border-borde px-4 py-3 mb-6">
                <Truck className="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
                <span><span className="font-semibold">Zona de envío:</span> {producto.zona_envio}</span>
              </p>
            )}

            <form onSubmit={enviar} className="space-y-4">
              {fisico && (
                <div className="space-y-4">
                  {(producto.caracteristicas || []).length > 0 && (
                    <SelectorDeCaracteristicas
                      producto={producto}
                      seleccion={seleccion}
                      onCambiar={(nueva) => { setSeleccion(nueva); setError(null); }}
                      color={color}
                    />
                  )}

                  <div>
                    <label htmlFor="producto-cantidad" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
                      CANTIDAD
                    </label>
                    <select
                      id="producto-cantidad"
                      value={cantidad}
                      onChange={(e) => { setCantidad(Number(e.target.value)); setError(null); }}
                      className="w-24 px-4 py-3 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro transition"
                    >
                      {Array.from({ length: MAX_CANTIDAD }, (_, i) => i + 1).map((n) => (
                        <option key={n} value={n}>{n}</option>
                      ))}
                    </select>
                  </div>
                </div>
              )}

              <Campo
                id="producto-nombre"
                etiqueta="NOMBRE Y APELLIDO"
                value={datos.nombre}
                onChange={(v) => cambiar('nombre', v)}
                autoComplete="name"
                autoFocus={!fisico}
                required
              />

              <div>
                <Campo
                  id="producto-email"
                  etiqueta="EMAIL"
                  type="email"
                  value={datos.email}
                  onChange={(v) => cambiar('email', v)}
                  onBlur={revisarEmail}
                  autoComplete="email"
                  ayuda={avisoEmail ? null : fisico ? 'Te mandamos ahí el detalle del pedido' : 'Te mandamos ahí el link de descarga'}
                  aria-invalid={avisoEmail ? 'true' : undefined}
                  aria-describedby={avisoEmail ? 'producto-email-aviso' : undefined}
                  required
                />

                {avisoEmail && (
                  <p id="producto-email-aviso" role="alert" className="text-xs text-red-700 mt-1">
                    {avisoEmail}
                  </p>
                )}

                {sugerencia && !avisoEmail && (
                  <p className="text-xs text-amber-700 mt-1">
                    ¿Quisiste decir{' '}
                    <button
                      type="button"
                      onClick={() => {
                        cambiar('email', sugerencia);
                        setSugerencia(null);
                      }}
                      className="underline font-bold"
                    >
                      {sugerencia}
                    </button>
                    ?
                  </p>
                )}
              </div>

              <Campo
                id="producto-telefono"
                etiqueta={fisico ? 'TELÉFONO' : 'TELÉFONO (OPCIONAL)'}
                type="tel"
                value={datos.telefono}
                onChange={(v) => cambiar('telefono', v)}
                autoComplete="tel"
                ayuda={fisico ? 'Para coordinar la entrega' : null}
                required={fisico}
              />

              {fisico && (
                <DatosDeEntrega entrega={entrega} onCambiar={(nueva) => { setEntrega(nueva); setError(null); }} />
              )}

              {fisico ? (
                <dl aria-label="Resumen" className="border-t border-borde pt-4 space-y-1 text-sm">
                  <div className="flex justify-between">
                    <dt className="text-tinta-media">{describirPrecio(producto.precio)} × {cantidad}</dt>
                    <dd>{pesos(cuentas.subtotal)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-tinta-media">Envío</dt>
                    <dd>{cuentas.envio > 0 ? pesos(cuentas.envio) : 'Incluido'}</dd>
                  </div>
                  <div className="flex items-baseline justify-between pt-2">
                    <dt className="text-tinta-media">Total</dt>
                    <dd className="text-2xl font-bold">{pago ? pesos(cuentas.total) : 'Gratis'}</dd>
                  </div>
                </dl>
              ) : pago && (
                <div className="flex items-baseline justify-between border-t border-borde pt-4">
                  <span className="text-tinta-media">Total</span>
                  <span className="text-2xl font-bold">{describirPrecio(producto.precio)}</span>
                </div>
              )}

              {error && (
                <p role="alert" className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">
                  {error}
                </p>
              )}

              <button
                type="submit"
                disabled={enviando}
                className="w-full py-4 font-bold flex items-center justify-center gap-2 disabled:opacity-60"
                style={colorDelBoton}
              >
                {enviando && <Loader2 className="w-4 h-4 animate-spin" />}
                {textoDelBoton}
              </button>

              <p className="text-xs text-tinta-suave text-center">
                {fisico && (pago
                  ? 'Te vamos a llevar a Mercado Pago para completar el pago. Después te llega el detalle por mail.'
                  : 'Te llega el detalle del pedido por mail.')}
                {!fisico && (pago
                  ? 'Te vamos a llevar a Mercado Pago para completar el pago. Después te llega el link por mail.'
                  : 'El link de descarga te llega por mail.')}
              </p>
            </form>
          </>
        )}
      </div>
    </div>
  );
}

export default ComprarProducto;
