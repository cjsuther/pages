import React, { useState } from 'react';
import { X, Loader2, Check } from 'lucide-react';
import { formatearPrecio, opcionesDeCantidad } from '../utils/entradas';

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

  const cantidades = opcionesDeCantidad(entradas);
  const total = (Number(entradas.precio) || 0) * Number(datos.cantidad);

  const cambiar = (campo, valor) => {
    setDatos((previos) => ({ ...previos, [campo]: valor }));
    setError(null);
  };

  const enviar = async (e) => {
    e.preventDefault();
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
            <Check className="w-7 h-7 text-white" />
          </div>

          <h3 className="text-2xl font-black text-white mb-2">¡Lugar reservado!</h3>
          <p className="text-gray-400 mb-6">
            Te esperamos en {evento.text}.
          </p>

          <p className="text-sm text-gray-500 mb-1">Tu código de reserva</p>
          <p className="text-xl font-mono font-bold text-white tracking-wider mb-6">{reservado}</p>

          <a
            href={`/entrada/${reservado}`}
            className="inline-block px-6 py-3 font-bold text-white"
            style={{ backgroundColor: color }}
          >
            VER MI RESERVA
          </a>
        </div>
      </Marco>
    );
  }

  return (
    <Marco onCerrar={onCerrar}>
      <h3 className="text-2xl font-black text-white mb-1">
        {entradas.es_gratis ? 'Reservar lugar' : 'Comprar entradas'}
      </h3>
      <p className="text-gray-500 text-sm mb-6">{evento.text}</p>

      <form onSubmit={enviar} className="space-y-4">
        <Campo
          id="entrada-nombre"
          etiqueta="NOMBRE Y APELLIDO"
          value={datos.nombre}
          onChange={(v) => cambiar('nombre', v)}
          autoComplete="name"
          required
        />

        <Campo
          id="entrada-email"
          etiqueta="EMAIL"
          type="email"
          value={datos.email}
          onChange={(v) => cambiar('email', v)}
          autoComplete="email"
          ayuda="Te mandamos ahí la confirmación"
          required
        />

        <Campo
          id="entrada-telefono"
          etiqueta="TELÉFONO"
          type="tel"
          value={datos.telefono}
          onChange={(v) => cambiar('telefono', v)}
          autoComplete="tel"
          ayuda="Por si hay que avisarte de un cambio"
          required
        />

        <div>
          <label htmlFor="entrada-cantidad" className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
            CANTIDAD
          </label>
          <select
            id="entrada-cantidad"
            value={datos.cantidad}
            onChange={(e) => cambiar('cantidad', Number(e.target.value))}
            className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
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
          <div className="flex items-baseline justify-between border-t border-gray-800 pt-4">
            <span className="text-gray-400">Total</span>
            <span className="text-2xl font-black text-white">
              {formatearPrecio(total, entradas.moneda)}
            </span>
          </div>
        )}

        {error && (
          <p className="text-sm text-red-400 bg-red-950 border border-red-900 px-4 py-3">{error}</p>
        )}

        <button
          type="submit"
          disabled={enviando}
          className="w-full py-4 font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60"
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
          <p className="text-xs text-gray-600 text-center">
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
      className="fixed inset-0 bg-black bg-opacity-90 flex items-start justify-center p-4 z-[60] overflow-y-auto"
      onClick={onCerrar}
    >
      <div
        className="bg-gray-900 border border-gray-800 max-w-md w-full p-8 my-8 relative"
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          onClick={onCerrar}
          aria-label="Cerrar"
          className="absolute top-4 right-4 text-gray-500 hover:text-white transition"
        >
          <X className="w-5 h-5" />
        </button>

        {children}
      </div>
    </div>
  );
}

function Campo({ id, etiqueta, ayuda, value, onChange, type = 'text', ...resto }) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-bold text-gray-400 mb-2 tracking-wide">
        {etiqueta}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
        {...resto}
      />
      {ayuda && <p className="text-xs text-gray-600 mt-1">{ayuda}</p>}
    </div>
  );
}

export default ComprarEntradas;
