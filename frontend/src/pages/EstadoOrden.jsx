import React, { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Check, Clock, X, Loader2, MapPin, Calendar } from 'lucide-react';
import { formatearPrecio } from '../utils/entradas';

/**
 * Pantalla a la que vuelve el comprador desde Mercado Pago.
 *
 * Volver acá no significa que el pago esté acreditado: eso lo confirma el aviso
 * que Mercado Pago le manda al servidor, que puede llegar unos segundos después.
 * Por eso, mientras la orden siga reservada, se vuelve a consultar sola.
 */
function EstadoOrden({ apiUrl }) {
  const { codigo } = useParams();
  const [orden, setOrden] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [intentos, setIntentos] = useState(0);

  useEffect(() => {
    let vigente = true;

    const consultar = async () => {
      try {
        const r = await fetch(`${apiUrl}/public/orden.php?codigo=${encodeURIComponent(codigo)}`);
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No encontramos esa orden');
          return;
        }

        setOrden(cuerpo.orden);
      } catch (e) {
        if (vigente) setError('No pudimos conectarnos');
      } finally {
        if (vigente) setCargando(false);
      }
    };

    consultar();

    return () => { vigente = false; };
  }, [apiUrl, codigo, intentos]);

  // El aviso de pago puede tardar unos segundos: se reintenta un rato antes de
  // darlo por no acreditado, en lugar de mostrarle "pendiente" a alguien que ya pagó.
  useEffect(() => {
    if (!orden || orden.estado !== 'reservada' || intentos >= 10) {
      return undefined;
    }

    const id = setTimeout(() => setIntentos((n) => n + 1), 3000);

    return () => clearTimeout(id);
  }, [orden, intentos]);

  if (cargando && !orden) {
    return (
      <Marco>
        <Loader2 className="w-8 h-8 animate-spin text-gray-600 mx-auto" />
        {/* Un ícono girando no le dice nada a un lector de pantalla, que se
            queda con una página en blanco sin saber que está esperando. */}
        <span className="sr-only">Cargando…</span>
      </Marco>
    );
  }

  if (error) {
    return (
      <Marco>
        <X className="w-12 h-12 text-red-400 mx-auto mb-4" />
        <h1 className="text-2xl font-black text-white mb-2">No encontramos tu orden</h1>
        <p className="text-gray-500">{error}</p>
      </Marco>
    );
  }

  const estados = {
    pagada: {
      icono: <Check className="w-8 h-8 text-white" />,
      fondo: 'bg-emerald-600',
      titulo: '¡Listo!',
      detalle: 'Tu lugar está confirmado. Guardá este código, te lo van a pedir en la entrada.',
    },
    reservada: {
      icono: <Clock className="w-8 h-8 text-white" />,
      fondo: 'bg-amber-600',
      titulo: 'Estamos confirmando tu pago',
      detalle: 'Mercado Pago todavía no nos confirmó la operación. Puede tardar unos segundos.',
    },
    vencida: {
      icono: <Clock className="w-8 h-8 text-white" />,
      fondo: 'bg-gray-700',
      titulo: 'La reserva venció',
      detalle: 'Pasaron los 15 minutos sin que se completara el pago. Podés volver a intentarlo.',
    },
    rechazada: {
      icono: <X className="w-8 h-8 text-white" />,
      fondo: 'bg-red-700',
      titulo: 'El pago fue rechazado',
      detalle: 'Mercado Pago no aprobó la operación. Podés intentar de nuevo con otro medio de pago.',
    },
    cancelada: {
      icono: <X className="w-8 h-8 text-white" />,
      fondo: 'bg-gray-700',
      titulo: 'La orden se canceló',
      detalle: 'No llegó a completarse. Podés volver a intentarlo.',
    },
  };

  const estado = estados[orden.estado] || estados.reservada;

  return (
    <Marco>
      <div className={`w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6 ${estado.fondo}`}>
        {estado.icono}
      </div>

      <h1 className="text-3xl font-black text-white mb-2">{estado.titulo}</h1>
      <p className="text-gray-500 mb-8">{estado.detalle}</p>

      <div className="border border-gray-800 bg-black p-6 text-left mb-6">
        <p className="text-xs text-gray-600 tracking-wide mb-1">CÓDIGO</p>
        <p className="text-2xl font-mono font-bold text-white tracking-wider mb-6">{orden.codigo}</p>

        <p className="text-xs text-gray-600 tracking-wide mb-1">EVENTO</p>
        <p className="text-white font-bold mb-4">{orden.evento}</p>

        {orden.event_date && (
          <p className="flex items-center gap-2 text-gray-400 text-sm mb-2">
            <Calendar className="w-4 h-4 shrink-0" />
            {new Date(`${orden.event_date}T00:00:00`).toLocaleDateString('es-AR', {
              weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
            })}
            {orden.event_time ? ` · ${orden.event_time.slice(0, 5)}` : ''}
          </p>
        )}

        {orden.event_address && (
          <p className="flex items-center gap-2 text-gray-400 text-sm mb-4">
            <MapPin className="w-4 h-4 shrink-0" />
            {orden.event_address}
          </p>
        )}

        <div className="border-t border-gray-800 pt-4 flex justify-between text-sm">
          <span className="text-gray-500">
            {orden.cantidad} {orden.cantidad === 1 ? 'entrada' : 'entradas'} a nombre de {orden.nombre}
          </span>
          {orden.total > 0 && (
            <span className="text-white font-bold">{formatearPrecio(orden.total, orden.moneda)}</span>
          )}
        </div>
      </div>

      {orden.url_slug && (
        <Link to={`/${orden.url_slug}`} className="text-gray-400 hover:text-white text-sm">
          ← Volver a {orden.pagina}
        </Link>
      )}
    </Marco>
  );
}

function Marco({ children }) {
  return (
    <div className="min-h-screen bg-black flex items-center justify-center p-6">
      <div className="max-w-md w-full text-center">{children}</div>
    </div>
  );
}

export default EstadoOrden;
