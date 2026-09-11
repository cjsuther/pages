import React, { useState, useEffect, useContext } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Calendar, MapPin, ExternalLink, ArrowLeft } from 'lucide-react';
import { AuthContext } from '../App';
import BotonEntradas, { vendeEntradas } from '../components/BotonEntradas';
import FondoDeLaCaja from '../components/FondoDeLaCaja';
import RezonarBadge from '../components/RezonarBadge';
import { paleta, conAlfa, textoSobre } from '../utils/colores';
import { CLASES_ALREDEDOR, CLASES_CAJA, estiloDeAlrededor, estiloDeCaja } from '../utils/plantillas';

/**
 * El detalle de un evento, en su propia pantalla.
 *
 * Es la pantalla a la que se llega desde un enlace directo —el que se comparte
 * por WhatsApp o se pega en una historia—, así que muchas veces es lo primero
 * que alguien ve de esa página. Por eso se pinta con los colores de la página
 * y no con los de Rezonar: estaba blanca con nuestro verde, y quien llegaba por
 * ahí no tenía cómo reconocer de quién era la fecha.
 *
 * Es la misma caja que usan las plantillas: misma columna, mismo fondo, mismo
 * recorte. Lo que cambia es el contenido.
 */
function EventDetail() {
  const { id } = useParams();
  const { apiUrl } = useContext(AuthContext);
  const [event, setEvent] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetch(`${apiUrl}/public/event.php?id=${id}`)
      .then(res => res.json())
      .then(data => {
        if (data.error) setError(data.error);
        else setEvent(data.event);
      })
      .catch(() => setError('Error al cargar el evento'))
      .finally(() => setLoading(false));
  }, [id]);

  // Mientras no se sabe de qué página es, no hay colores que usar: se muestra
  // neutro. Pintar con la paleta por defecto y cambiarla un segundo después es
  // peor que esperar.
  if (loading) return (
    <div className="min-h-screen bg-white flex items-center justify-center">
      <p className="text-tinta-media">Cargando...</p>
    </div>
  );

  if (error || !event) return (
    <div className="min-h-screen bg-white flex flex-col items-center justify-center gap-4">
      <p className="text-tinta-media">{error || 'Evento no encontrado'}</p>
      <Link to="/" className="text-tinta underline">Ir al inicio</Link>
    </div>
  );

  // El evento viaja con los colores de su página; paleta() deriva los que
  // estén vacíos igual que en las plantillas.
  const colores = paleta(event);
  const { background_color: backgroundColor, text_color: textColor } = event;

  return (
    <div className={CLASES_ALREDEDOR} style={estiloDeAlrededor({ backgroundColor, textColor })}>
      <RezonarBadge />

      <div
        className={`${CLASES_CAJA} px-6 py-12`}
        style={estiloDeCaja({ backgroundColor, textColor })}
      >
        <FondoDeLaCaja imagen={event.background_image} />

        <Link
          to={`/${event.page_slug}`}
          className="inline-flex items-center gap-2 text-sm font-semibold opacity-70 transition-opacity hover:opacity-100"
        >
          <ArrowLeft className="w-4 h-4" />
          Ver la página
        </Link>

        {event.image_url && (
          <img
            src={event.image_url}
            alt={event.text}
            className="mt-6 w-full h-auto rounded-2xl"
          />
        )}

        {event.page_title && (
          <Link
            to={`/${event.page_slug}`}
            className="mt-8 flex items-center gap-3 transition-opacity hover:opacity-80"
          >
            {event.page_image && (
              <img
                src={event.page_image}
                alt={event.page_title}
                className="w-10 h-10 rounded-full object-cover"
              />
            )}
            <span className="text-sm opacity-70">{event.page_title}</span>
          </Link>
        )}

        <h1 className="mt-6 text-4xl font-bold" style={{ color: colores.titulo }}>
          {event.text}
        </h1>

        {(event.event_date || event.event_time) && (
          <Dato icono={Calendar} color={colores.acento}>
            <span className="capitalize">{formatearFecha(event.event_date, event.event_time)}</span>
          </Dato>
        )}

        {event.event_address && (
          <Dato icono={MapPin} color={colores.acento}>
            {event.event_maps_url ? (
              <a
                href={event.event_maps_url}
                target="_blank"
                rel="noopener noreferrer"
                className="underline underline-offset-2 hover:opacity-80"
              >
                {event.event_address}
              </a>
            ) : (
              <span>{event.event_address}</span>
            )}
          </Dato>
        )}

        {event.description && (
          <p
            className="mt-6 pt-6 border-t text-lg leading-relaxed opacity-80 whitespace-pre-line"
            style={{ borderColor: conAlfa(colores.texto, 0.15) }}
          >
            {event.description}
          </p>
        )}

        <BotonEntradas evento={event} color={colores.boton} />

        {event.url && !vendeEntradas(event) && (
          <a
            href={event.url}
            target="_blank"
            rel="noopener noreferrer"
            className="mt-8 inline-flex items-center gap-2 rounded-full px-6 py-3 font-semibold transition-opacity hover:opacity-85"
            style={{ backgroundColor: colores.boton, color: textoSobre(colores.boton, colores.texto) }}
          >
            <ExternalLink className="w-4 h-4" />
            {event.url_text || 'Más información'}
          </a>
        )}
      </div>
    </div>
  );
}

/** Una línea de dato con su ícono, en el acento de la página. */
function Dato({ icono: Icono, color, children }) {
  return (
    <div className="mt-4 flex items-start gap-3 text-lg opacity-90">
      <Icono className="w-5 h-5 flex-shrink-0 mt-1" style={{ color }} />
      <span>{children}</span>
    </div>
  );
}

export function formatearFecha(fecha, hora) {
  return new Date(fecha + ' ' + (hora || '00:00')).toLocaleDateString('es-AR', {
    timeZone: 'America/Argentina/Buenos_Aires',
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });
}

export default EventDetail;
