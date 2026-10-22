import React, { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';
import { Helmet } from 'react-helmet-async';
import { MailX, Check } from 'lucide-react';
import { Boton, Cargando } from '../components/ui';

/**
 * La página del link "No quiero recibir más mails de…" que va al pie de los
 * mails de una página a sus clientes (#37): /baja-de-mails?t=<token>.
 *
 * Sin login: el token firmado dice de qué página y de qué email. La baja se
 * da con un botón y no al abrir el link, porque los antivirus de correo abren
 * los links solos para revisarlos y darían de baja a gente que no lo pidió.
 */
function Baja({ apiUrl }) {
  const [params] = useSearchParams();
  const token = params.get('t') || '';
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState(token ? null : 'El link para darte de baja no es válido. Revisá que esté completo.');
  const [enviando, setEnviando] = useState(false);

  const url = `${apiUrl}/public/baja.php?t=${encodeURIComponent(token)}`;

  useEffect(() => {
    if (!token) return undefined;

    let vigente = true;

    (async () => {
      try {
        const r = await fetch(url);
        const cuerpo = await r.json().catch(() => ({}));
        if (!vigente) return;
        if (!r.ok) {
          setError(cuerpo.error || 'El link para darte de baja no es válido.');
          return;
        }
        setDatos(cuerpo);
      } catch (e) {
        if (vigente) setError('No pudimos abrir el link. Probá de nuevo en un rato.');
      }
    })();

    return () => { vigente = false; };
  }, [url, token]);

  const darDeBaja = async () => {
    setEnviando(true);
    try {
      const r = await fetch(url, { method: 'POST' });
      const cuerpo = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(cuerpo.error || 'No pudimos darte de baja. Probá de nuevo.');
      setDatos(cuerpo);
    } catch (e) {
      setError(e.message);
    } finally {
      setEnviando(false);
    }
  };

  return (
    <div className="min-h-screen bg-white flex items-center justify-center p-6">
      <Helmet>
        <title>Dejar de recibir mails</title>
        <meta name="robots" content="noindex" />
      </Helmet>

      <div className="max-w-md w-full text-center">
        {error ? (
          <p role="alert" className="text-tinta-media">{error}</p>
        ) : !datos ? (
          <Cargando />
        ) : datos.de_baja ? (
          <div role="status">
            <span className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-verde-claro text-verde-oscuro mb-4">
              <Check className="w-6 h-6" />
            </span>
            <h1 className="text-2xl font-bold">Listo</h1>
            <p className="text-tinta-media mt-2">
              No vas a recibir más mails de <strong>{datos.pagina}</strong> en {datos.email}.
            </p>
          </div>
        ) : (
          <div>
            <span className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-papel-hueso text-tinta mb-4">
              <MailX className="w-6 h-6" />
            </span>
            <h1 className="text-2xl font-bold">¿Dejar de recibir mails de {datos.pagina}?</h1>
            <p className="text-tinta-media mt-2">Es para {datos.email}. No hace falta que entres con tu cuenta.</p>
            <Boton className="mt-6" onClick={darDeBaja} disabled={enviando}>
              {enviando ? 'Un momento…' : `No quiero recibir más mails de ${datos.pagina}`}
            </Boton>
          </div>
        )}
      </div>
    </div>
  );
}

export default Baja;
