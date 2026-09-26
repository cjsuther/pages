import React, { useEffect, useState } from 'react';
import { Helmet } from 'react-helmet-async';
import { Instagram, ExternalLink, Loader2 } from 'lucide-react';
import { fechaLarga, urlDeInstagram, urlDeRezonar } from '../utils/carcajada';

/**
 * Lo que ve el público cuando escanea el QR durante el show.
 *
 * Es un cartel en la pared de un bar: sin sesión, sin clave y sin nada que
 * completar. La pregunta que viene a contestar es una sola —"¿quién era el
 * que me hizo reír?"— así que muestra la cara, el nombre y a dónde seguirlo.
 *
 * La dirección es fija y siempre muestra el show del día; si hoy no hay,
 * muestra el próximo. Eso es lo que permite imprimir el QR una vez y que
 * siga sirviendo la fecha que viene.
 */
function Hoy({ apiUrl }) {
  const [datos, setDatos] = useState(null);
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/public/carcajada-hoy.php`);
        const cuerpo = await r.json();

        if (vigente && r.ok) setDatos(cuerpo);
      } catch (e) {
        // Sin conexión no hay nada que mostrar: abajo se explica.
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
  }, [apiUrl]);

  if (cargando) {
    return (
      <div className="min-h-screen bg-tinta flex items-center justify-center">
        <Loader2 className="w-6 h-6 animate-spin text-white/70" />
      </div>
    );
  }

  const show = datos && datos.show;
  const comediantes = (datos && datos.comediantes) || [];

  return (
    // Oscuro y no blanco: esto se abre en la oscuridad de un bar, con el show
    // empezado. Una pantalla blanca en la mano molesta a toda la mesa.
    <div className="min-h-screen bg-tinta text-white">
      <Helmet>
        <title>{show ? `${show.ciclo} · Carcajada` : 'Carcajada'}</title>
        <meta name="robots" content="noindex, nofollow" />
      </Helmet>

      <div className="max-w-lg mx-auto px-5 py-10">
        {!show ? (
          <div className="text-center py-16">
            <p className="text-3xl font-bold">Carcajada</p>
            <p className="text-white/60 mt-3">
              No hay ninguna fecha cargada todavía. Volvé a escanear durante el próximo show.
            </p>
          </div>
        ) : (
          <>
            <header className="text-center mb-10">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-white/50">
                {show.es_hoy ? 'Esta noche' : 'Próxima fecha'}
              </p>
              <h1 className="text-4xl font-extrabold tracking-tight mt-2">{show.ciclo}</h1>
              <p className="text-white/70 mt-1 capitalize">{fechaLarga(show.fecha, show.hora)}</p>
              {show.lugar && <p className="text-white/50 text-sm">{show.lugar}</p>}
            </header>

            {comediantes.length === 0 ? (
              <p className="text-center text-white/60">El line-up se anuncia en un rato.</p>
            ) : (
              <ul className="space-y-4">
                {comediantes.map((c) => (
                  <li key={`${c.nombre}-${c.url_slug || ''}`} className="bg-white/[0.06] rounded-2xl p-4 flex items-center gap-4">
                    {c.foto_url ? (
                      <img src={c.foto_url} alt="" className="w-16 h-16 rounded-full object-cover shrink-0" />
                    ) : (
                      <span className="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center text-xl font-bold shrink-0">
                        {c.nombre.charAt(0).toUpperCase()}
                      </span>
                    )}

                    <div className="min-w-0 flex-1">
                      <p className="text-lg font-bold truncate">{c.nombre}</p>

                      <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-sm">
                        {c.url_slug && (
                          <a
                            href={urlDeRezonar(c.url_slug)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-verde font-semibold"
                          >
                            Sus fechas <ExternalLink className="w-3.5 h-3.5" />
                          </a>
                        )}
                        {c.instagram && (
                          <a
                            href={urlDeInstagram(c.instagram)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-white/70"
                          >
                            <Instagram className="w-3.5 h-3.5" /> @{c.instagram}
                          </a>
                        )}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}

            <p className="text-center text-white/40 text-xs mt-10">
              Seguilos: así se enteran de la próxima fecha antes que nadie.
            </p>
          </>
        )}
      </div>
    </div>
  );
}

export default Hoy;
