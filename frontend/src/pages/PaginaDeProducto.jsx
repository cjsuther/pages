import React, { useState, useEffect, useContext } from 'react';
import { useParams, Link } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { AuthContext } from '../App';
import ComprarProducto from '../components/ComprarProducto';
import FondoDeLaCaja from '../components/FondoDeLaCaja';
import RezonarBadge from '../components/RezonarBadge';
import Sugerencias from '../components/Sugerencias';
import { Producto } from '../components/DetalleProducto';
import { paleta } from '../utils/colores';
import { MENSAJE_NO_DISPONIBLE } from '../utils/productos';
import { CLASES_ALREDEDOR, CLASES_CAJA, estiloDeAlrededor, estiloDeCaja } from '../utils/plantillas';

/**
 * Un producto digital en su propia pantalla: el link que el organizador
 * comparte por WhatsApp o redes (/producto/:id).
 *
 * Se arma como el detalle de un evento (EventDetail): la caja de las
 * plantillas con los colores de la página, el nombre de la página arriba y
 * el producto en una tarjeta. El producto es la misma ficha del detalle de la
 * página, sin carrusel: acá hay uno solo.
 *
 * Si el producto no se puede comprar —no existe, está oculto, borrado o sin
 * archivo— la API da 404 y acá se dice que no está disponible, sin más.
 */
function PaginaDeProducto() {
  const { id } = useParams();
  const { apiUrl } = useContext(AuthContext);
  const [producto, setProducto] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);
  const [comprando, setComprando] = useState(false);

  useEffect(() => {
    let vigente = true;
    setCargando(true);
    setError(null);

    fetch(`${apiUrl}/public/producto.php?id=${encodeURIComponent(id)}`)
      .then(async (r) => {
        const data = await r.json().catch(() => ({}));
        if (!vigente) return;

        if (!r.ok || !data.producto) {
          // Un 404 es "no disponible"; otro error se dice como vino.
          setError(r.status === 404 || r.ok ? MENSAJE_NO_DISPONIBLE : (data.error || 'Error al cargar el producto'));
          return;
        }

        setProducto(data.producto);
      })
      .catch(() => {
        if (vigente) setError('Error al cargar el producto');
      })
      .finally(() => {
        if (vigente) setCargando(false);
      });

    return () => { vigente = false; };
  }, [apiUrl, id]);

  // Como en EventDetail: sin saber de qué página es, se muestra neutro.
  if (cargando) return (
    <div className="min-h-screen bg-white flex items-center justify-center">
      <p className="text-tinta-media">Cargando...</p>
    </div>
  );

  if (error || !producto) return (
    <div className="min-h-screen bg-white flex flex-col items-center justify-center gap-4 px-6 text-center">
      <p className="text-tinta-media">{error || MENSAJE_NO_DISPONIBLE}</p>
      <Link to="/" className="text-tinta underline">Ir al inicio</Link>
    </div>
  );

  const colores = paleta(producto);
  const { background_color: backgroundColor, text_color: textColor } = producto;

  return (
    <div className={CLASES_ALREDEDOR} style={estiloDeAlrededor({ backgroundColor, textColor })}>
      <RezonarBadge />

      <div
        className={`${CLASES_CAJA} px-6 py-12`}
        style={estiloDeCaja({ backgroundColor, textColor })}
      >
        <FondoDeLaCaja imagen={producto.background_image} />

        <Link
          to={`/${producto.page_slug}`}
          className="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition-opacity hover:opacity-80"
          style={{ backgroundColor: colores.tarjeta, borderColor: colores.bordeTarjeta }}
        >
          <ArrowLeft className="w-4 h-4" />
          Ver la página
        </Link>

        <div
          className="mt-6 rounded-3xl border p-6 sm:p-8"
          style={{ backgroundColor: colores.tarjeta, borderColor: colores.bordeTarjeta }}
        >
          {producto.page_title && (
            <Link
              to={`/${producto.page_slug}`}
              className="mb-6 flex items-center gap-3 transition-opacity hover:opacity-80"
            >
              {producto.page_image && (
                <img
                  src={producto.page_image}
                  alt={producto.page_title}
                  className="w-10 h-10 rounded-full object-cover"
                />
              )}
              <span className="text-sm opacity-70">{producto.page_title}</span>
            </Link>
          )}

          <Producto
            producto={producto}
            colores={colores}
            onComprar={() => setComprando(true)}
            Titulo="h1"
            colorTitulo={colores.titulo}
          />
        </div>

        <Sugerencias tipo="producto" id={producto.id} colores={colores} className="mt-8" />
      </div>

      {comprando && (
        <ComprarProducto
          producto={producto}
          apiUrl={apiUrl}
          color={colores.boton}
          onCerrar={() => setComprando(false)}
        />
      )}
    </div>
  );
}

export default PaginaDeProducto;
