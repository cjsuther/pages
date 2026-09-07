import React, { useState } from 'react';
import { X, Loader2, Check } from 'lucide-react';
import { formatearPrecio, opcionesDeCantidad } from '../utils/entradas';
import { esEmailValido, sugerenciaDeEmail } from '../utils/email';

/**
 * Formulario de compra o reserva de entradas de un evento.
 *
 * Con precio manda a Mercado Pago; sin precio la reserva queda confirmada en el
 * acto y no hay checkout de por medio.
 */
function ComprarEntradas({ evento, entradas, apiUrl, color = '#3B82F6', onCerrar }) {
  const [datos, setDatos] = useState({ nombre: '', email: '', telefono: '', cantidad: 1 });
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState(null);
  const [reservado, setReservado] = useState(null);
  // Se avisa recién al salir del campo: marcar en rojo mientras se escribe
  // es molesto, porque todo email está incompleto hasta que se termina.
  const [avisoEmail, setAvisoEmail] = useState(null);
  const [sugerencia, setSugerencia] = useState(null);

  const cantidades = opcionesDeCantidad(entradas);
  const total = (Number(entradas.precio) || 0) * Number(datos.cantidad);

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

    if (email === '') {
      return;
    }

    if (!esEmailValido(email)) {
      setAvisoEmail('Revisá el email: parece que le falta algo.');
      return;
    }

    setAvisoEmail(null);
    setSugerencia(sugerenciaDeEmail(email));
  };

  const enviar = async (e) => {
    e.preventDefault();

    // La confirmación va por mail: mandar una compra con el email mal escrito
    // deja a la persona sin entrada y sin forma de reclamarla.
    if (!esEmailValido(datos.email)) {
      setAvisoEmail('Revisá el email: parece que le falta algo.');
      return;
    }

    setEnviando(true);
    setError(null);

    try {
      const respuesta = await fetch(`${apiUrl}/public/comprar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ link_id: evento.id, ...datos }),
      });

      const cuerpo = await respuesta.json();

      if (!respuesta.ok) {
        setError(cuerpo.error || 'No se pudo completar la operación');
        setEnviando(false);
        return;
      }

      // Con cobro se sale a Mercado Pago; sin cobro ya está confirmada.
      if (cuerpo.url) {
        window.location.href = cuerpo.url;
        return;
      }

      setReservado(cuerpo.codigo);
      setEnviando(false);
    } catch (err) {
      setError('No pudimos conectarnos. Revisá tu conexión e intentá de nuevo.');
      setEnviando(false);
    }
  };

  if (reservado) {
    return (
      <Marco onCerrar={onCerrar}>
        <div className="text-center py-4">
          <div
            className="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-5"
            style={{ backgroundColor: color }}
          >
            <Check className="w-7 h-7 text-tinta" />
          </div>

          <h3 className="text-2xl font-bold text-tinta mb-2">¡Lugar reservado!</h3>
          <p className="text-tinta-media mb-6">
            Te esperamos en {evento.text}.
          </p>

          <p className="text-sm text-tinta-suave mb-1">Tu código de reserva</p>
          <p className="text-xl font-mono font-bold text-tinta tracking-wider mb-6">{reservado}</p>

          <a
            href={`/entrada/${reservado}`}
            className="inline-block px-6 py-3 font-bold text-tinta"
            style={{ backgroundColor: color }}
          >
            Ver mi reserva
          </a>
        </div>
      </Marco>
    );
  }

  return (
    <Marco onCerrar={onCerrar}>
      <h3 className="text-2xl font-bold text-tinta mb-1">
        {entradas.es_gratis ? 'Reservar lugar' : 'Comprar entradas'}
      </h3>
      <p className="text-tinta-suave text-sm mb-6">{evento.text}</p>

      <form onSubmit={enviar} className="space-y-4">
        <Campo
          id="entrada-nombre"
          etiqueta="NOMBRE Y APELLIDO"
          value={datos.nombre}
          onChange={(v) => cambiar('nombre', v)}
          autoComplete="name"
          required
        />

        <div>
          <Campo
            id="entrada-email"
            etiqueta="EMAIL"
            type="email"
            value={datos.email}
            onChange={(v) => cambiar('email', v)}
            onBlur={revisarEmail}
            autoComplete="email"
            ayuda={avisoEmail ? null : 'Te mandamos ahí la confirmación'}
            aria-invalid={avisoEmail ? 'true' : undefined}
            aria-describedby={avisoEmail ? 'entrada-email-aviso' : undefined}
            required
          />

          {avisoEmail && (
            <p id="entrada-email-aviso" role="alert" className="text-xs text-red-700 mt-1">
              {avisoEmail}
            </p>
          )}

          {/* Un dominio mal tipeado pasa cualquier validación y no llega nunca.
              Se pregunta en vez de corregir solo: .co es un dominio real. */}
          {sugerencia && !avisoEmail && (
            <p className="text-xs text-amber-400 mt-1">
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
          id="entrada-telefono"
          etiqueta="TELÉFONO (OPCIONAL)"
          type="tel"
          value={datos.telefono}
          onChange={(v) => cambiar('telefono', v)}
          autoComplete="tel"
          ayuda="Por si hay que avisarte de un cambio"
        />

        <div>
          <label htmlFor="entrada-cantidad" className="block text-sm font-semibold text-tinta mb-1.5 tracking-wide">
            Cantidad
          </label>
          <select
            id="entrada-cantidad"
            value={datos.cantidad}
            onChange={(e) => cambiar('cantidad', Number(e.target.value))}
            className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
          >
            {cantidades.map((n) => (
              <option key={n} value={n}>{n}</option>
            ))}
          </select>

          {entradas.disponibles <= 10 && (
            <p className="text-xs text-amber-400 mt-1">
              Quedan {entradas.disponibles} {entradas.disponibles === 1 ? 'entrada' : 'entradas'}
            </p>
          )}
        </div>

        {!entradas.es_gratis && (
          <div className="flex items-baseline justify-between border-t border-borde pt-4">
            <span className="text-tinta-media">Total</span>
            <span className="text-2xl font-bold text-tinta">
              {formatearPrecio(total, entradas.moneda)}
            </span>
          </div>
        )}

        {error && (
          <p className="text-sm text-red-700 bg-red-50 border border-red-200 px-4 py-3">{error}</p>
        )}

        <button
          type="submit"
          disabled={enviando}
          className="w-full py-4 font-bold text-tinta flex items-center justify-center gap-2 disabled:opacity-60"
          style={{ backgroundColor: color }}
        >
          {enviando && <Loader2 className="w-4 h-4 animate-spin" />}
          {enviando
            ? 'PROCESANDO...'
            : entradas.es_gratis
              ? 'CONFIRMAR RESERVA'
              : 'IR A PAGAR'}
        </button>

        {!entradas.es_gratis && (
          <p className="text-xs text-tinta-suave text-center">
            Te vamos a llevar a Mercado Pago para completar el pago.
            Tu lugar queda reservado 15 minutos.
          </p>
        )}
      </form>
    </Marco>
  );
}

function Marco({ children, onCerrar }) {
  return (
    <div
      className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-start justify-center p-4 z-[60] overflow-y-auto"
      onClick={onCerrar}
    >
      <div
        className="bg-white border border-borde max-w-md w-full p-8 my-8 relative"
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

        {children}
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

export default ComprarEntradas;
