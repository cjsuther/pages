import React, { useEffect, useState } from 'react';
import { X, Loader2, Check } from 'lucide-react';
import { comprarProducto, describirPrecio, esPago } from '../utils/productos';
import { esEmailValido, sugerenciaDeEmail } from '../utils/email';
import { textoSobre } from '../utils/colores';
import useBloqueoDeScroll from '../hooks/useBloqueoDeScroll';

/**
 * Formulario para pedir un producto digital: nombre, email y teléfono.
 *
 * Pago: crea la orden, guarda el link de descarga para la vuelta (lo hace
 * comprarProducto) y lleva a Mercado Pago.
 *
 * Gratis: no pasa por Mercado Pago. El link llega sólo por mail —la API no lo
 * devuelve, para que un email inventado no sirva para bajar nada—, así que
 * acá se avisa eso y nunca se ofrece un botón de descarga.
 */
function ComprarProducto({ producto, apiUrl, color = '#3B82F6', onCerrar }) {
  const [datos, setDatos] = useState({ nombre: '', email: '', telefono: '' });
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState(null);
  const [avisoEmail, setAvisoEmail] = useState(null);
  const [sugerencia, setSugerencia] = useState(null);
  const [pedido, setPedido] = useState(false);

  const pago = esPago(producto.precio);

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

    setEnviando(true);
    setError(null);

    try {
      const respuesta = await comprarProducto({
        apiUrl,
        linkId: producto.id,
        nombre: datos.nombre.trim(),
        email: datos.email.trim(),
        telefono: datos.telefono.trim(),
      });

      if (respuesta.url) {
        window.location.href = respuesta.url;
        return;
      }

      setPedido(true);
    } catch (err) {
      setError(err.status ? err.message : 'No pudimos conectarnos. Revisá tu conexión e intentá de nuevo.');
    } finally {
      setEnviando(false);
    }
  };

  const colorDelBoton = { backgroundColor: color, color: textoSobre(color) };

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
              Te mandamos el link a tu email
            </h3>
            <p className="text-tinta-media mb-6">
              Revisá tu casilla ({datos.email.trim()}). Si no lo ves en unos minutos, mirá en spam.
            </p>
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
              {pago ? 'Comprar' : 'Descargar gratis'}
            </h3>
            <p className="text-tinta-suave text-sm mb-6">
              {producto.text} · {describirPrecio(producto.precio)}
            </p>

            <form onSubmit={enviar} className="space-y-4">
              <Campo
                id="producto-nombre"
                etiqueta="NOMBRE Y APELLIDO"
                value={datos.nombre}
                onChange={(v) => cambiar('nombre', v)}
                autoComplete="name"
                autoFocus
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
                  ayuda={avisoEmail ? null : 'Te mandamos ahí el link de descarga'}
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
                etiqueta="TELÉFONO (OPCIONAL)"
                type="tel"
                value={datos.telefono}
                onChange={(v) => cambiar('telefono', v)}
                autoComplete="tel"
              />

              {pago && (
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
                {enviando ? 'PROCESANDO...' : pago ? 'IR A PAGAR' : 'PEDIR EL LINK'}
              </button>

              <p className="text-xs text-tinta-suave text-center">
                {pago
                  ? 'Te vamos a llevar a Mercado Pago para completar el pago. Después te llega el link por mail.'
                  : 'El link de descarga te llega por mail.'}
              </p>
            </form>
          </>
        )}
      </div>
    </div>
  );
}

function Campo({ id, etiqueta, ayuda, value, onChange, type = 'text', ...resto }) {
  const conProblema = resto['aria-invalid'] === 'true';
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
        {etiqueta}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={`w-full px-4 py-3 bg-white border text-tinta focus:border-verde-oscuro transition ${
          conProblema ? 'border-red-200' : 'border-borde-fuerte'
        }`}
        {...resto}
      />
      {ayuda && <p className="text-xs text-tinta-suave mt-1">{ayuda}</p>}
    </div>
  );
}

export default ComprarProducto;
