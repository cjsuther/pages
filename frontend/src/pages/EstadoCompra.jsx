import React, { useState, useEffect, useRef } from 'react';
import { useParams, Link } from 'react-router';
import { Helmet } from 'react-helmet-async';
import { Check, Clock, X, Loader2, Mail } from 'lucide-react';
import BotonDescarga from '../components/BotonDescarga';
import { formatearPrecio } from '../utils/entradas';
import { tokenDeDescarga } from '../utils/productos';
import { evento } from '../utils/metaPixel';

/**
 * Pantalla a la que vuelve el comprador de un producto digital desde Mercado
 * Pago: /compra/:codigo. La hermana de EstadoOrden.
 *
 * El servidor concilia con Mercado Pago al consultarla, y mientras siga
 * pendiente se vuelve a preguntar sola un rato.
 *
 * Para descargar ahí mismo hace falta el token, que nunca va en la URL: se
 * guardó en este navegador antes de salir a pagar. Si no está —se pagó desde
 * otro dispositivo— el link llega por mail igual.
 */
function EstadoCompra({ apiUrl }) {
  const { codigo } = useParams();
  const [orden, setOrden] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [intentos, setIntentos] = useState(0);
  const token = useRef(tokenDeDescarga(codigo)).current;

  useEffect(() => {
    let vigente = true;

    const consultar = async () => {
      try {
        const r = await fetch(`${apiUrl}/public/orden-producto.php?codigo=${encodeURIComponent(codigo)}`);
        const cuerpo = await r.json();

        if (!vigente) return;

        if (!r.ok) {
          setError(cuerpo.error || 'No encontramos esa compra');
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

  // La compra, en el pixel de quien vendió, una sola vez por orden.
  const yaRegistrada = useRef(false);

  useEffect(() => {
    if (!orden || orden.estado !== 'pagada' || yaRegistrada.current) {
      return;
    }

    yaRegistrada.current = true;

    evento(orden.meta_pixel_id, 'Purchase', {
      content_type: 'product',
      content_name: orden.producto,
      num_items: 1,
      value: orden.total,
      currency: orden.moneda,
    });
  }, [orden]);

  useEffect(() => {
    if (!orden || orden.estado !== 'pendiente' || intentos >= 10) {
      return undefined;
    }

    const id = setTimeout(() => setIntentos((n) => n + 1), 3000);

    return () => clearTimeout(id);
  }, [orden, intentos]);

  if (cargando && !orden) {
    return (
      <Marco>
        <Loader2 className="w-8 h-8 animate-spin text-tinta-suave mx-auto" />
        <span className="sr-only">Cargando…</span>
      </Marco>
    );
  }

  if (error) {
    return (
      <Marco>
        <X className="w-12 h-12 text-red-700 mx-auto mb-4" />
        <h1 className="text-2xl font-bold text-tinta mb-2">No encontramos tu compra</h1>
        <p className="text-tinta-suave">{error}</p>
      </Marco>
    );
  }

  const estados = {
    pagada: {
      icono: <Check className="w-8 h-8 text-tinta" />,
      fondo: 'bg-verde',
      titulo: '¡Listo!',
      detalle: 'Tu pago está aprobado.',
    },
    pendiente: {
      icono: <Clock className="w-8 h-8 text-tinta" />,
      fondo: 'bg-amber-600',
      titulo: 'Estamos confirmando tu pago',
      detalle: 'Mercado Pago todavía no nos confirmó la operación. Puede tardar unos segundos.',
    },
    vencida: {
      icono: <Clock className="w-8 h-8 text-tinta" />,
      fondo: 'bg-papel-hueso',
      titulo: 'La compra no se completó',
      detalle: 'No recibimos el pago a tiempo. Si pagaste, te va a llegar el link por mail apenas se confirme.',
    },
    rechazada: {
      icono: <X className="w-8 h-8 text-tinta" />,
      fondo: 'bg-red-700',
      titulo: 'El pago fue rechazado',
      detalle: 'Mercado Pago no aprobó la operación. Podés intentar de nuevo con otro medio de pago.',
    },
    cancelada: {
      icono: <X className="w-8 h-8 text-tinta" />,
      fondo: 'bg-papel-hueso',
      titulo: 'La compra se canceló',
      detalle: 'No llegó a completarse. Podés volver a intentarlo.',
    },
  };

  const estado = estados[orden.estado] || estados.pendiente;

  return (
    <Marco>
      <div className={`w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-6 ${estado.fondo}`}>
        {estado.icono}
      </div>

      <h1 className="text-3xl font-bold text-tinta mb-2">{estado.titulo}</h1>
      <p className="text-tinta-suave mb-8">{estado.detalle}</p>

      <div className="border border-borde bg-white p-6 text-left mb-6">
        <p className="text-xs text-tinta-suave tracking-wide mb-1">Producto</p>
        <p className="text-tinta font-bold mb-4">{orden.producto}</p>

        <div className="border-t border-borde pt-4 flex justify-between text-sm">
          <span className="text-tinta-suave">Compra {orden.codigo}</span>
          {orden.total > 0 && (
            <span className="text-tinta font-bold">{formatearPrecio(orden.total, orden.moneda)}</span>
          )}
        </div>
      </div>

      {orden.estado === 'pagada' && (
        token
          ? <div className="mb-6"><BotonDescarga apiUrl={apiUrl} token={token} /></div>
          : (
            <p className="flex items-center justify-center gap-2 text-tinta mb-6">
              <Mail className="w-5 h-5 shrink-0" />
              Te mandamos el link de descarga a tu email.
            </p>
          )
      )}

      {orden.url_slug && (
        <Link to={`/${orden.url_slug}`} className="text-tinta-media hover:text-tinta text-sm">
          ← Volver a {orden.pagina}
        </Link>
      )}
    </Marco>
  );
}

function Marco({ children }) {
  return (
    <div className="min-h-screen bg-white flex items-center justify-center p-6">
      <Helmet>
        <title>Tu compra</title>
        <meta name="robots" content="noindex, nofollow" />
      </Helmet>
      <div className="max-w-md w-full text-center">{children}</div>
    </div>
  );
}

export default EstadoCompra;
