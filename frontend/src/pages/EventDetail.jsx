import React, { useState, useEffect, useContext } from 'react';
import { useParams, Link } from 'react-router-dom';
import { AuthContext } from '../App';
import BotonEntradas, { vendeEntradas } from '../components/BotonEntradas';
import { Calendar, MapPin, ExternalLink, ArrowLeft } from 'lucide-react';

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

  const formatDate = (date, time) => new Date(date + ' ' + (time || '00:00')).toLocaleDateString('es-AR', {
    timeZone: 'America/Argentina/Buenos_Aires',
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  });

  return (
    <div className="min-h-screen bg-white text-tinta">
      <nav className="flex items-center justify-between px-6 py-4 border-b border-borde">
        <a href="/">
          <img src="/logo-negro.png" alt="Rezonar" className="h-8" />
        </a>
        <Link
          to={`/${event.page_slug}`}
          className="text-sm text-tinta-media hover:text-tinta transition flex items-center gap-1"
        >
          <ArrowLeft className="w-4 h-4" />
          Ver página
        </Link>
      </nav>

      <main className="max-w-2xl mx-auto px-6 py-12">
        <img
          src={event.image_url || 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
          alt={event.text}
          className="w-full h-auto rounded-lg mb-8"
        />

        {event.page_title && (
          <Link
            to={`/${event.page_slug}`}
            className="flex items-center gap-3 mb-6 hover:opacity-80 transition"
          >
            {event.page_image && (
              <img
                src={event.page_image}
                alt={event.page_title}
                className="w-10 h-10 rounded-full object-cover"
              />
            )}
            <span className="text-tinta-media text-sm">{event.page_title}</span>
          </Link>
        )}

        <h1 className="text-4xl font-bold mb-6">{event.text}</h1>

        {(event.event_date || event.event_time) && (
          <div className="flex items-center gap-3 text-lg text-tinta-media mb-4">
            <Calendar className="w-5 h-5 text-verde-oscuro flex-shrink-0" />
            <span className="capitalize">{formatDate(event.event_date, event.event_time)}</span>
          </div>
        )}

        {event.event_address && (
          <div className="flex items-start gap-3 text-tinta-media mb-4">
            <MapPin className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
            {event.event_maps_url ? (
              <a
                href={event.event_maps_url}
                target="_blank"
                rel="noopener noreferrer"
                className="hover:text-tinta underline"
              >
                {event.event_address}
              </a>
            ) : (
              <span>{event.event_address}</span>
            )}
          </div>
        )}

        {event.description && (
          <p className="text-tinta-media text-lg leading-relaxed mt-6 border-t border-borde pt-6">
            {event.description}
          </p>
        )}

        <BotonEntradas evento={event} color="#059669" />

        {event.url && !vendeEntradas(event) && (
          <a
            href={event.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 mt-8 px-6 py-3 rounded-full bg-verde text-verde-tinta hover:bg-verde-oscuro hover:text-white font-semibold transition-colors"
          >
            <ExternalLink className="w-4 h-4" />
            {event.url_text || 'Más información'}
          </a>
        )}
      </main>
    </div>
  );
}

export default EventDetail;
