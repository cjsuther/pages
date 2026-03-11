import React, { useState, useEffect, useContext } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Helmet } from 'react-helmet-async';
import { AuthContext } from '../App';
import { Search, MapPin, Calendar, FileSymlink, ExternalLink, Users, ArrowUpDown, Map, Grid } from 'lucide-react';
import EventsMap from '../components/EventsMap';
import FollowButton from '../components/FollowButton';
import Navigation from '../components/Navigation';
import { handleApiResponse } from '../utils/apiHandler';

function Home() {
  const { apiUrl, user, token, logout } = useContext(AuthContext);
  const navigate = useNavigate();
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState([]);
  const [recentPages, setRecentPages] = useState([]);
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(false);
  const [userLocation, setUserLocation] = useState(null);
  const [locationError, setLocationError] = useState(null);
  const [sortBy, setSortBy] = useState('date');
  const [sortOrder, setSortOrder] = useState('asc');
  const [viewMode, setViewMode] = useState('map');
  const [showFollowedOnly, setShowFollowedOnly] = useState(false);

  useEffect(() => {
    loadRecentPages();
    if (token) {
      loadUserLocation();
    } else {
      getUserLocation();
    }
  }, [token]);

  useEffect(() => {
    if (token && showFollowedOnly) {
      loadFollowedEvents();
    } else {
      loadAllEvents();
    }
  }, [sortBy, sortOrder, showFollowedOnly, token]);

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

  const getUserLocation = () => {
    if ('geolocation' in navigator) {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          setUserLocation({
            lat: position.coords.latitude,
            lng: position.coords.longitude
          });
        },
        (error) => {
          console.error('Error getting location:', error);
          setLocationError('No se pudo obtener tu ubicación');
        }
      );
    } else {
      setLocationError('Tu navegador no soporta geolocalización');
    }
  };

  const loadRecentPages = async () => {
    try {
      const response = await fetch(`${apiUrl}/public/recent-pages.php`);
      const data = await response.json();
      setRecentPages(data.pages || []);
    } catch (err) {
      console.error('Error loading recent pages:', err);
    }
  };

  const loadAllEvents = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${apiUrl}/public/recent-events.php`);
      const data = await response.json();
      setEvents(data.events || []);
    } catch (err) {
      console.error('Error loading events:', err);
    } finally {
      setLoading(false);
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

  const handleSearch = async (e) => {
    e.preventDefault();
    if (!searchQuery.trim()) return;

    setLoading(true);
    try {
      const response = await fetch(`${apiUrl}/public/search.php?q=${encodeURIComponent(searchQuery)}`);
      const data = await response.json();
      setSearchResults(data.results || []);
    } catch (err) {
      console.error('Error searching:', err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-black text-white">
      <Helmet>
        <title>Rezonar - Crea tu Espacio, Comparte Eventos, Conecta</title>
        <meta name="description" content="Descubre eventos cerca de ti, explora páginas de creadores y artistas, y crea tu propio espacio para compartir enlaces y eventos con tu comunidad." />
        <meta property="og:title" content="Rezonar - Encuentra. Conecta. Rezona." />
        <meta property="og:description" content="Descubre eventos cerca de ti, explora páginas de creadores y artistas, y crea tu propio espacio para compartir enlaces y eventos con tu comunidad." />
        <meta property="og:type" content="website" />
        <meta property="og:url" content={window.location.href} />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="Rezonar - Encuentra. Conecta. Rezona." />
        <meta name="twitter:description" content="Descubre eventos cerca de ti, explora páginas de creadores y artistas." />
        <link rel="canonical" href={window.location.href} />
      </Helmet>

      {token ? <Navigation /> : (
        <nav className="border-b border-gray-800">
          <div className="max-w-7xl mx-auto px-6 py-6">
            <div className="flex justify-between items-center">
              <img src="/logo.png" alt="Rezonar" className="h-10" />
              <div className="flex gap-6">
                <Link
                  to="/login"
                  className="text-gray-300 hover:text-white transition font-medium"
                >
                  Iniciar Sesión / Registrarse
                </Link>
              </div>
            </div>
          </div>
        </nav>
      )}

      <main>
        {!token && (
          <section className="py-20 border-b border-gray-800">
            <div className="max-w-4xl mx-auto px-6">
              <div className="text-center space-y-8 mb-12">
                <h1 className="text-xl md:text-xl font-black leading-none tracking-tight">
                  ENCONTRÁ. CONECTÁ. REZONÁ.
                </h1>
              </div>
            </div>
          </section>
        )}

        <section className="py-20 border-b border-gray-800">
          <div className="max-w-7xl mx-auto px-6">
            <div className="mb-8">
              <h2 className="text-4xl font-black tracking-tight mb-4">{token ? 'EVENTOS' : 'EVENTOS CERCA'}</h2>
              <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                {token && (
                  <>
                    <button
                      onClick={() => setShowFollowedOnly(!showFollowedOnly)}
                      className={`px-4 py-2 font-medium border transition text-sm sm:text-base ${
                        showFollowedOnly
                          ? 'bg-white text-black border-white'
                          : 'bg-gray-900 text-white border-gray-800 hover:border-gray-700'
                      }`}
                    >
                      {showFollowedOnly ? 'Páginas seguidas' : 'Todos los eventos'}
                    </button>
                    <div className="flex items-center gap-3 flex-1 sm:flex-initial">
                      <button
                        onClick={() => setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc')}
                        className="p-2 hover:bg-gray-900 border border-gray-800 transition"
                        title={sortOrder === 'asc' ? 'Orden ascendente' : 'Orden descendente'}
                      >
                        <ArrowUpDown className={`w-5 h-5 transition-transform ${sortOrder === 'desc' ? 'rotate-180' : ''}`} />
                      </button>
                      <select
                        value={sortBy}
                        onChange={(e) => setSortBy(e.target.value)}
                        className="bg-gray-900 border border-gray-800 text-white px-4 py-2 font-medium focus:border-white transition flex-1 sm:flex-initial text-sm sm:text-base"
                      >
                        <option value="date">Por fecha</option>
                        <option value="distance" disabled={!userLocation}>Por distancia</option>
                      </select>
                      <button
                        onClick={() => setViewMode(viewMode === 'grid' ? 'map' : 'grid')}
                        className="p-2 hover:bg-gray-900 border border-gray-800 transition"
                        title={viewMode === 'grid' ? 'Ver mapa' : 'Ver grilla'}
                      >
                        {viewMode === 'grid' ? <Map className="w-5 h-5" /> : <Grid className="w-5 h-5" />}
                      </button>
                    </div>
                  </>
                )}
                {!token && (
                  <button
                    onClick={() => setViewMode(viewMode === 'grid' ? 'map' : 'grid')}
                    className="p-2 hover:bg-gray-900 border border-gray-800 transition self-start"
                    title={viewMode === 'grid' ? 'Ver mapa' : 'Ver grilla'}
                  >
                    {viewMode === 'grid' ? <Map className="w-5 h-5" /> : <Grid className="w-5 h-5" />}
                  </button>
                )}
              </div>
            </div>

            {!userLocation && sortBy === 'distance' && (
              <div className="bg-gray-900 border border-gray-800 p-6 mb-8">
                <p className="text-gray-400">
                  Para ordenar por distancia, necesitas configurar tu ubicación en el perfil.
                </p>
              </div>
            )}

            {viewMode === 'map' ? (
              <EventsMap
                events={events.filter(e => e.event_latitude && e.event_longitude)}
                userLocation={userLocation}
                onEventClick={(event) => {
                  window.open(`/${event.page_slug}`, '_blank');
                }}
              />
            ) : loading ? (
              <div className="text-center py-24">
                <div className="text-gray-500 text-xl font-medium">Cargando eventos...</div>
              </div>
            ) : events.length === 0 ? (
              <div className="text-center py-24 space-y-6">
                <div className="w-32 h-32 bg-gray-900 mx-auto"></div>
                <p className="text-gray-400 text-2xl font-light">No hay eventos</p>
                {token && showFollowedOnly && (
                  <>
                    <p className="text-gray-600 text-lg">Sigue algunas páginas para ver sus eventos aquí</p>
                    <Link
                      to="/pages"
                      className="inline-block bg-white text-black px-8 py-4 text-lg font-bold hover:bg-gray-200 transition"
                    >
                      BUSCAR PÁGINAS
                    </Link>
                  </>
                )}
              </div>
            ) : (
              <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                {events.map((event) => (
                  <Link
                    key={event.id}
                    to={`/${event.page_slug}`}
                    className="bg-gray-900 border border-gray-800 overflow-hidden hover:border-gray-700 transition group"
                  >
                    <img
                      src={event.image_url || 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
                      alt={event.text}
                      className="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300"
                    />
                    <div className="p-6">
                      {token && event.page_image && (
                        <div className="flex items-center gap-2 mb-3">
                          <img
                            src={event.page_image}
                            alt={event.page_title}
                            className="w-8 h-8 rounded-full object-cover"
                          />
                          <p className="text-sm text-gray-500">{event.page_title}</p>
                        </div>
                      )}
                      <div className="flex items-center gap-2 text-sm text-gray-500 mb-3">
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
                        <p className="text-sm text-gray-500 flex items-start gap-2">
                          <MapPin className="w-4 h-4 mt-0.5 flex-shrink-0" />
                          {event.event_address}
                        </p>
                      )}
                      {event.distance !== null && event.distance !== undefined && (
                        <p className="text-sm text-gray-400 mt-2">
                          {event.distance.toFixed(1)} km
                        </p>
                      )}
                      {!token && event.owner_name && (
                        <p className="text-sm text-gray-500 mt-3">
                          Por {event.owner_name}
                        </p>
                      )}
                    </div>
                  </Link>
                ))}
              </div>
            )}
          </div>
        </section>

        <section className="py-20 border-b border-gray-800">
          <div className="max-w-4xl mx-auto px-6">
            <h2 className="text-4xl font-black mb-12 tracking-tight">BUSCAR</h2>

            <form onSubmit={handleSearch} className="flex gap-3">
              <div className="flex-1 relative">
                <Search className="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 w-5 h-5" />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Buscar páginas, eventos..."
                  className="w-full pl-12 pr-4 py-4 bg-gray-900 border border-gray-800 text-white focus:border-white transition"
                />
              </div>
              <button
                type="submit"
                disabled={loading}
                className="px-8 py-4 bg-white text-black font-bold hover:bg-gray-200 transition disabled:opacity-50"
              >
                {loading ? 'Buscando...' : 'BUSCAR'}
              </button>
            </form>

            {searchResults.length > 0 && (
              <div className="mt-8 space-y-6">
                {searchResults.filter(r => r.type === 'page').length > 0 && (
                  <div>
                    <h3 className="text-2xl font-bold mb-4">PÁGINAS</h3>
                    <div className="grid md:grid-cols-2 gap-6">
                      {searchResults.filter(r => r.type === 'page').map((page) => (
                        <div
                          key={page.id}
                          className="bg-gray-900 border border-gray-800 p-8 hover:border-gray-700 transition"
                        >
                          <a
                            href={`/${page.slug}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="block mb-4"
                          >
                            <h3 className="text-2xl font-bold mb-2">{page.title}</h3>
                          </a>
                          <div className="flex items-center justify-between pt-4 border-t border-gray-800 mt-4">
                            <div className="flex items-center gap-2 text-sm text-gray-500">
                              <Users className="w-4 h-4" />
                              <span>{page.follower_count || 0} {page.follower_count === 1 ? 'seguidor' : 'seguidores'}</span>
                            </div>
                            {token && <FollowButton pageId={page.id} />}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {searchResults.filter(r => r.type === 'event').length > 0 && (
                  <div>
                    <h3 className="text-2xl font-bold mb-4">EVENTOS</h3>
                    <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                      {searchResults.filter(r => r.type === 'event').map((event) => (
                        <a
                          key={event.id}
                          href={`/${event.slug}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="bg-gray-900 border border-gray-800 overflow-hidden hover:border-gray-700 transition group"
                        >
                          <img
                            src={event.image_url || 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=800'}
                            alt={event.title}
                            className="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300"
                          />
                          <div className="p-6">
                            <div className="flex items-center gap-2 text-sm text-gray-500 mb-3">
                              <Calendar className="w-4 h-4" />
                              {new Date(event.item_date).toLocaleDateString('es-AR', {
                                timeZone: 'America/Argentina/Buenos_Aires',
                                day: '2-digit',
                                month: '2-digit',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: false,
                              })}
                            </div>
                            <h3 className="text-xl font-bold mb-2">{event.title}</h3>
                          </div>
                        </a>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        </section>

        <section className="py-20">
          <div className="max-w-7xl mx-auto px-6">
            <h2 className="text-4xl font-black mb-12 tracking-tight">PÁGINAS RECIENTES</h2>

            {recentPages.length === 0 ? (
              <p className="text-gray-500 text-center py-12">No hay páginas recientes</p>
            ) : (
              <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                {recentPages.slice(0, 6).map((page) => (
                  <div
                    key={page.id}
                    className="bg-gray-900 border border-gray-800 p-8 hover:border-gray-700 transition"
                  >
                    <a
                      href={`/${page.url_slug}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="block mb-4"
                    >
                      {page.profile_image && (
                        <img
                          src={page.profile_image}
                          alt={page.title}
                          className="w-20 h-20 rounded-full object-cover mb-4"
                        />
                      )}
                      <h3 className="text-2xl font-bold mb-2">{page.title}</h3>
                      <p className="text-gray-500 text-sm">{page.description}</p>
                      <p className="text-gray-600 text-sm mt-3">
                        Por {page.owner_name || page.owner_email}
                      </p>
                    </a>
                    <div className="flex items-center justify-between pt-4 border-t border-gray-800 mt-4">
                      <div className="flex items-center gap-2 text-sm text-gray-500">
                        <Users className="w-4 h-4" />
                        <span>{page.follower_count || 0} {page.follower_count === 1 ? 'seguidor' : 'seguidores'}</span>
                      </div>
                      <FollowButton pageId={page.id} />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </section>

        <section className="py-20 bg-gradient-to-b from-black to-gray-900 border-t border-gray-800">
          <div className="max-w-4xl mx-auto px-6 text-center space-y-12">
            <h3 className="text-5xl font-black tracking-tight">
              Crea tu espacio
            </h3>
            <p className="text-xl text-gray-400 leading-relaxed">
              Centraliza tus enlaces, comparte eventos y conecta con tu comunidad
            </p>
            <Link
              to="/login"
              className="inline-block bg-white text-black px-12 py-5 text-xl font-bold hover:bg-gray-200 transition"
            >
              COMENZAR GRATIS
            </Link>
          </div>
        </section>
      </main>

      <footer className="border-t border-gray-800 py-12">
        <div className="max-w-7xl mx-auto px-6">
          <div className="flex flex-col md:flex-row justify-between items-center gap-6">
            <img src="/logo.png" alt="Rezonar" className="h-8 opacity-50" />
            <p className="text-gray-500 font-medium">© 2026 REZONAR</p>
            <div className="flex gap-8">
              <a href="#" className="text-gray-500 hover:text-white transition font-medium">Términos</a>
              <a href="#" className="text-gray-500 hover:text-white transition font-medium">Privacidad</a>
              <a href="#" className="text-gray-500 hover:text-white transition font-medium">Soporte</a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}

export default Home;
