import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Helmet } from 'react-helmet-async';
import { Instagram, Loader2, ChevronRight } from 'lucide-react';
import { fechaLarga, urlDeInstagram, urlDeRezonar } from '../utils/carcajada';

/**
 * La página de una fecha de Carcajada.
 *
 * Es lo que abre el público cuando escanea el QR durante el show, y también
 * la página de cada fecha (/fecha/:id). Va en este orden: de qué se trata la
 * noche, quiénes se presentan y qué otras fechas vienen.
 *
 * Es un cartel en la pared de un bar: sin sesión, sin clave y sin nada que
 * completar. Sin id muestra el show del día; si hoy no hay, el próximo. Eso
 * es lo que permite imprimir el QR una vez y que siga sirviendo la fecha que
 * viene.
 */
function Hoy({ apiUrl }) {
  const { id } = useParams();
  const [datos, setDatos] = useState(null);
  const [noExiste, setNoExiste] = useState(false);
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    let vigente = true;
    setCargando(true);
    setNoExiste(false);

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/public/carcajada-hoy.php${id ? `?id=${encodeURIComponent(id)}` : ''}`);
        const cuerpo = await r.json();

        if (!vigente) return;

        if (r.ok) {
          setDatos(cuerpo);
        } else if (r.status === 404) {
          setNoExiste(true);
        }
      } catch (e) {
        // Sin conexión no hay nada que mostrar: abajo se explica.
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    // Al pasar de una fecha a otra, arrancar desde arriba.
    if (typeof window !== 'undefined' && window.scrollTo) {
      try { window.scrollTo(0, 0); } catch (e) { /* jsdom */ }
    }

    return () => { vigente = false; };
  }, [apiUrl, id]);

  if (cargando) {
    return (
      <div className="min-h-screen bg-tinta flex items-center justify-center">
        <Loader2 className="w-6 h-6 animate-spin text-white/70" />
      </div>
    );
  }

  const show = !noExiste && datos && datos.show;
  const comediantes = (datos && datos.comediantes) || [];
  const otras = (datos && datos.otras) || [];

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
              {noExiste
                ? 'Esa fecha no existe o ya no está publicada.'
                : 'No hay ninguna fecha cargada todavía. Volvé a escanear durante el próximo show.'}
            </p>
            {noExiste && (
              <Link to="/hoy" className="inline-block mt-6 text-verde font-semibold">Ver la próxima fecha</Link>
            )}
          </div>
        ) : (
          <>
            <header className="text-center mb-8">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-white/50">
                {show.es_hoy ? 'Esta noche' : 'Próxima fecha'}
              </p>
              <h1 className="text-4xl font-extrabold tracking-tight mt-2">{show.ciclo}</h1>
              <p className="text-white/70 mt-1 capitalize">{fechaLarga(show.fecha, show.hora)}</p>
              {show.lugar && <p className="text-white/50 text-sm">{show.lugar}</p>}
            </header>

            {show.descripcion && (
              <section
                aria-label="Sobre la fecha"
                className="texto-rico text-white/80 leading-relaxed mb-10 [&_a]:text-verde"
                // Viene limpio del servidor (HtmlSimple): sólo p, br, strong,
                // em, u, listas y links web.
                dangerouslySetInnerHTML={{ __html: show.descripcion }}
              />
            )}

            <section aria-labelledby="titulo-lineup">
              <h2 id="titulo-lineup" className="text-xs font-semibold uppercase tracking-[0.2em] text-white/50 mb-4">
                Comediantes
              </h2>

              {comediantes.length === 0 ? (
                <p className="text-white/60">El line-up se anuncia en un rato.</p>
              ) : (
                // Tres por fila también en el celular: la cara es lo que se
                // reconoce, y así entra el line-up entero sin bajar.
                <ul className="grid grid-cols-3 gap-3">
                  {comediantes.map((c, i) => (
                    <li key={`${i}-${c.nombre}`} className="bg-white/[0.06] rounded-2xl p-2.5 pb-3 flex flex-col items-center text-center min-w-0">
                      {c.foto_url ? (
                        <img src={c.foto_url} alt="" className="w-full aspect-square rounded-xl object-cover" />
                      ) : (
                        <span className="w-full aspect-square rounded-xl bg-white/10 flex items-center justify-center text-3xl font-bold">
                          {c.nombre.charAt(0).toUpperCase()}
                        </span>
                      )}

                      {/* Con página en Rezonar, el nombre lleva a sus fechas. */}
                      {c.url_slug ? (
                        <a
                          href={urlDeRezonar(c.url_slug)}
                          target="_blank"
                          rel="noreferrer"
                          className="mt-2 w-full text-sm font-bold leading-tight break-words hover:text-verde"
                        >
                          {c.nombre}
                        </a>
                      ) : (
                        <p className="mt-2 w-full text-sm font-bold leading-tight break-words">{c.nombre}</p>
                      )}

                      {c.instagram && (
                        <a
                          href={urlDeInstagram(c.instagram)}
                          target="_blank"
                          rel="noreferrer"
                          className="mt-1 w-full inline-flex items-center justify-center gap-1 text-xs text-white/60 hover:text-white min-w-0"
                        >
                          <Instagram className="w-3 h-3 shrink-0" />
                          <span className="truncate">@{c.instagram}</span>
                        </a>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              <p className="text-center text-white/40 text-xs mt-6">
                Seguilos: así se enteran de la próxima fecha antes que nadie.
              </p>
            </section>

            {otras.length > 0 && (
              <section aria-labelledby="titulo-otras" className="mt-12">
                <h2 id="titulo-otras" className="text-xs font-semibold uppercase tracking-[0.2em] text-white/50 mb-4">
                  Otras fechas de Carcajada
                </h2>
                <ul className="divide-y divide-white/10 border-y border-white/10">
                  {otras.map((o) => (
                    <li key={o.id}>
                      <Link to={`/fecha/${o.id}`} className="flex items-center gap-3 py-4 group">
                        <div className="min-w-0 flex-1">
                          <p className="font-bold">{o.ciclo}</p>
                          <p className="text-sm text-white/60 capitalize">{fechaLarga(o.fecha, o.hora)}</p>
                          {o.lugar && <p className="text-xs text-white/40 truncate">{o.lugar}</p>}
                        </div>
                        <ChevronRight className="w-5 h-5 text-white/40 group-hover:text-white shrink-0" />
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </>
        )}
      </div>
    </div>
  );
}

export default Hoy;
