import React, { useContext, useEffect, useState } from 'react';
import { Instagram, ExternalLink } from 'lucide-react';
import { AuthContext } from '../App';
import { Tarjeta, Rotulo, Aviso, Cargando, Chip } from '../components/ui';
import { fechaCorta, urlDeInstagram, urlDeRezonar, cumplimiento } from '../utils/carcajada';

/**
 * La ficha de cada comediante: lo que se mira para armar una fecha.
 *
 * El número que importa no es cuánta gente trae sino si cumple lo que dijo,
 * así que lo prometido y lo que trajo van juntos y no en dos columnas
 * lejanas. Sin shows medidos no se opina: una ficha vacía no es una ficha
 * mala.
 */
function Comediantes() {
  const { token, apiUrl } = useContext(AuthContext);

  const [comediantes, setComediantes] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let vigente = true;

    (async () => {
      try {
        const r = await fetch(`${apiUrl}/carcajada/comediantes.php`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const cuerpo = await r.json();

        if (!vigente) return;
        if (!r.ok) throw new Error(cuerpo.error || 'No pudimos cargar los comediantes');

        setComediantes(cuerpo.comediantes || []);
      } catch (e) {
        if (vigente) setError(e.message);
      } finally {
        if (vigente) setCargando(false);
      }
    })();

    return () => { vigente = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (cargando) return <Cargando />;
  if (error) return <Aviso tipo="error">{error}</Aviso>;

  return (
    <div className="space-y-6">
      <div>
        <Rotulo>Producción</Rotulo>
        <h1 className="text-3xl font-bold tracking-tight text-tinta mt-1">Comediantes</h1>
        <p className="text-tinta-media mt-1">{comediantes.length} anotados</p>
      </div>

      {comediantes.length === 0 ? (
        <Tarjeta className="p-6">
          <p className="text-tinta-media">Todavía no se anotó nadie.</p>
        </Tarjeta>
      ) : (
        <ul className="space-y-3">
          {comediantes.map((c) => {
            const cumple = cumplimiento(c);

            return (
              <li key={c.id}>
                <Tarjeta className="p-5">
                  <div className="flex items-start gap-4">
                    {c.foto_url ? (
                      <img src={c.foto_url} alt="" className="w-14 h-14 rounded-xl object-cover shrink-0" />
                    ) : (
                      <span className="w-14 h-14 rounded-xl bg-papel-hueso flex items-center justify-center font-bold text-tinta-media shrink-0">
                        {c.nombre.charAt(0).toUpperCase()}
                      </span>
                    )}

                    <div className="min-w-0 flex-1">
                      <p className="font-bold text-tinta">{c.nombre}</p>

                      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs mt-0.5">
                        {c.url_slug && (
                          <a
                            href={urlDeRezonar(c.url_slug)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-verde-oscuro font-semibold"
                          >
                            rezon.ar/{c.url_slug} <ExternalLink className="w-3 h-3" />
                          </a>
                        )}
                        {c.instagram && (
                          <a
                            href={urlDeInstagram(c.instagram)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-tinta-media"
                          >
                            <Instagram className="w-3 h-3" /> @{c.instagram}
                          </a>
                        )}
                      </div>

                      <dl className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-sm">
                        <div>
                          <dt className="text-tinta-suave text-xs">Shows</dt>
                          <dd className="font-bold text-tinta">{c.shows}</dd>
                        </div>
                        <div>
                          <dt className="text-tinta-suave text-xs">Puntaje</dt>
                          <dd className="font-bold text-tinta">{c.puntaje === null ? '–' : `${c.puntaje}/5`}</dd>
                        </div>
                        <div>
                          <dt className="text-tinta-suave text-xs">Promete</dt>
                          <dd className="font-bold text-tinta">{c.comprometidas}</dd>
                        </div>
                        <div>
                          <dt className="text-tinta-suave text-xs">Trae</dt>
                          <dd className="font-bold text-tinta">
                            {c.promedio_traidas === null ? '–' : c.promedio_traidas}
                          </dd>
                        </div>
                      </dl>
                    </div>

                    <div className="shrink-0 text-right space-y-1">
                      {/* Cumplir lo prometido es el dato, no el volumen: quien
                          promete 5 y trae 5 es mejor fecha que quien promete
                          30 y trae 10. */}
                      {cumple !== null && (
                        <Chip tono={cumple >= 80 ? 'verde' : 'alerta'}>{cumple}% de lo que promete</Chip>
                      )}
                      {c.ultimo_show && (
                        <p className="text-xs text-tinta-suave">última: {fechaCorta(c.ultimo_show)}</p>
                      )}
                    </div>
                  </div>
                </Tarjeta>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

export default Comediantes;
