import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router';
import { Helmet } from 'react-helmet-async';
import { FileDown, Loader2, X } from 'lucide-react';
import BotonDescarga from '../components/BotonDescarga';
import { tokenDelHash } from '../utils/productos';
import { describirPeso } from '../utils/imagenes';

/**
 * La pantalla del link de descarga que llega por mail: /descarga#<token>.
 *
 * El token va después del #, que el navegador no manda al pedir la página:
 * no queda en los registros del servidor ni en el Referer. A la API se lo
 * manda en el cuerpo de un POST.
 *
 * Si el link no sirve no se dice por qué: ni si existe, ni si falta pagar.
 */
function Descarga({ apiUrl }) {
  const token = useRef(tokenDelHash(window.location.hash)).current;
  const [producto, setProducto] = useState(null);
  const [cargando, setCargando] = useState(Boolean(token));
  const [invalido, setInvalido] = useState(!token);

  useEffect(() => {
    if (!token) return undefined;

    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/public/descarga.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token }),
        });
        const cuerpo = await r.json().catch(() => ({}));

        if (!vigente) return;

        if (!r.ok || !cuerpo.producto) {
          setInvalido(true);
          return;
        }

        setProducto(cuerpo.producto);
      } catch (e) {
        if (vigente) setInvalido(true);
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
  }, [apiUrl, token]);

  return (
    <div className="min-h-screen bg-white flex items-center justify-center p-6">
      <Helmet>
        <title>{producto ? `Descargar · ${producto.titulo}` : 'Descarga'}</title>
        {/* Un link personal: no tiene que aparecer en ningún buscador. */}
        <meta name="robots" content="noindex, nofollow" />
      </Helmet>

      <div className="max-w-md w-full text-center">
        {cargando && (
          <>
            <Loader2 className="w-8 h-8 animate-spin text-tinta-suave mx-auto" />
            <span className="sr-only">Cargando…</span>
          </>
        )}

        {!cargando && invalido && (
          <>
            <X className="w-12 h-12 text-red-700 mx-auto mb-4" />
            <h1 className="text-2xl font-bold text-tinta mb-2">Este link de descarga no es válido</h1>
            <p className="text-tinta-suave">
              Revisá que lo hayas copiado entero desde el mail que te mandamos.
            </p>
          </>
        )}

        {!cargando && producto && (
          <>
            {producto.imagen && (
              <img src={producto.imagen} alt="" className="w-full max-h-72 object-cover border border-borde mb-6" />
            )}

            <h1 className="text-3xl font-bold text-tinta mb-6">{producto.titulo}</h1>

            {producto.archivo_nombre && (
              <div className="flex items-center gap-3 border border-borde bg-white p-4 text-left mb-6">
                <FileDown className="w-6 h-6 text-tinta-media shrink-0" />
                <div className="min-w-0">
                  <p className="text-tinta font-bold truncate">{producto.archivo_nombre}</p>
                  {producto.archivo_bytes > 0 && (
                    <p className="text-sm text-tinta-suave">{describirPeso(producto.archivo_bytes)}</p>
                  )}
                </div>
              </div>
            )}

            <BotonDescarga apiUrl={apiUrl} token={token} />

            <p className="text-xs text-tinta-suave mt-4">
              Este link es personal. Podés volver a usarlo cuando quieras.
            </p>

            {producto.url_slug && (
              <Link to={`/${producto.url_slug}`} className="inline-block mt-6 text-tinta-media hover:text-tinta text-sm">
                ← Volver a {producto.pagina}
              </Link>
            )}
          </>
        )}
      </div>
    </div>
  );
}

export default Descarga;
