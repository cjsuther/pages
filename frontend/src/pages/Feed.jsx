import React, { useState, useEffect, useContext } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { Calendar, MapPin, ArrowUpDown } from 'lucide-react';
import { handleApiResponse } from '../utils/apiHandler';

function Feed() {
  const { token, apiUrl, logout } = useContext(AuthContext);
  const navigate = useNavigate();
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [sortBy, setSortBy] = useState('date');
  const [sortOrder, setSortOrder] = useState('asc');
  const [userLocation, setUserLocation] = useState(null);

  useEffect(() => {
    loadUserLocation();
  }, []);

  useEffect(() => {
    loadFollowedEvents();
  }, [sortBy, sortOrder]);

  const loadUserLocation = async () => {
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      await handleApiResponse(response, navigate, logout);
      const data = await response.json();
      if (data.latitude && data.longitude) {
        setUserLocation({
          lat: data.latitude,
          lng: data.longitude
        });
      }
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        console.error('Error loading location:', err);
      }
    }
  };

  const loadFollowedEvents = async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({
        sortBy: sortBy,
        sortOrder: sortOrder
      });

      const response = await fetch(`${apiUrl}/pages/feed-events.php?${params}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      await handleApiResponse(response, navigate, logout);
      const data = await response.json();

      setEvents(data.events || []);
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        console.error('Error loading events:', err);
      }
    } finally {
      setLoading(false);
    }
  };


  return (
    <div className="min-h-screen bg-white text-tinta">
      <Navigation />

      <div className="max-w-7xl mx-auto px-6 py-16">
        <div className="flex justify-between items-center mb-8">
          <h1 className="text-4xl font-bold">Feed de eventos</h1>
          <div className="flex items-center gap-4">
            <button
              onClick={() => setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc')}
              className="p-2 hover:bg-white border border-borde transition"
              title={sortOrder === 'asc' ? 'Orden ascendente' : 'Orden descendente'}
            >
              <ArrowUpDown className={`w-5 h-5 transition-transform ${sortOrder === 'desc' ? 'rotate-180' : ''}`} />
            </button>
            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value)}
              className="bg-white border border-borde text-tinta px-4 py-2 font-medium focus:border-verde-oscuro transition"
            >
              <option value="date">Por fecha</option>
              <option value="distance" disabled={!userLocation}>Por distancia</option>
            </select>
          </div>
        </div>

        {!userLocation && sortBy === 'distance' && (
          <div className="bg-white border border-borde rounded-2xl p-6 mb-8">
            <p className="text-tinta-media">
              Para ordenar por distancia, necesitas configurar tu ubicación en el perfil.
            </p>
          </div>
        )}

        {loading ? (
          <div className="text-center py-24">
            <div className="text-tinta-suave text-xl font-medium">Cargando eventos...</div>
          </div>
        ) : events.length === 0 ? (
          <div className="text-center py-24 space-y-6">
            <div className="w-32 h-32 bg-white mx-auto"></div>
            <p className="text-tinta-media text-2xl font-light">No hay eventos</p>
            <p className="text-tinta-suave text-lg">Sigue algunas páginas para ver sus eventos aquí</p>
            <Link
              to="/pages"
              className="inline-block inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-7 py-3.5 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
            >
              Buscar páginas
            </Link>
          </div>
        ) : (
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            {events.map((event) => (
              <Link
                key={event.id}
                to={`/${event.page_slug}`}
                className="bg-white border border-borde overflow-hidden hover:border-borde-fuerte transition group"
              >
                <img
                  src={event.image_url || 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
                  alt={event.text}
                  className="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300"
                />
                <div className="p-6">
                  <div className="flex items-center gap-2 mb-3">
                    {event.page_image && (
                      <img
                        src={event.page_image}
                        alt={event.page_title}
                        className="w-8 h-8 rounded-full object-cover"
                      />
                    )}
                    <p className="text-sm text-tinta-suave">{event.page_title}</p>
                  </div>
                  <div className="flex items-center gap-2 text-sm text-tinta-suave mb-3">
                    <Calendar className="w-4 h-4" />
                    {new Date(event.event_date + ' ' + (event.event_time || '00:00')).toLocaleDateString('es-AR', {
                      timeZone: 'America/Argentina/Buenos_Aires',
                      day: '2-digit',
                      month: '2-digit',
                      year: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                      hour12: false,
                    })}
                  </div>
                  <h3 className="text-xl font-bold mb-2">{event.text}</h3>
                  {event.event_address && (
                    <p className="text-sm text-tinta-suave flex items-start gap-2">
                      <MapPin className="w-4 h-4 mt-0.5 flex-shrink-0" />
                      {event.event_address}
                    </p>
                  )}
                  {event.distance !== null && event.distance !== undefined && (
                    <p className="text-sm text-tinta-media mt-2">
                      {event.distance.toFixed(1)} km
                    </p>
                  )}
                </div>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

export default Feed;
