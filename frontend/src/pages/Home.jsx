import React, { useState, useEffect, useContext, useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Helmet } from 'react-helmet-async';
import {
  Search, MapPin, Bell, UserPlus, CalendarDays, Map, List,
  ArrowRight, Compass, Sparkles,
} from 'lucide-react';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import { PieDePagina } from '../components/Marco';
import AvisoNotificaciones from '../components/AvisoNotificaciones';
import EventsMap from '../components/EventsMap';
import TarjetaEvento from '../components/TarjetaEvento';
import TarjetaPagina from '../components/TarjetaPagina';
import { Boton, Campo, Cargando, Rotulo, Tarjeta, TituloSeccion, Vacio } from '../components/ui';
import { handleApiResponse } from '../utils/apiHandler';
import { distanciaDelEvento } from '../utils/eventos';

/** Radio del filtro "cerca mío". El mismo que usan las alertas de eventos cercanos. */
const RADIO_CERCA_KM = 30;

/** Los tres pasos para seguir a un artista, tal como se explican en la home. */
const PASOS_PARA_SEGUIR = [
  {
    icono: Search,
    titulo: 'Buscá al artista',
    detalle: 'Por su nombre o el de un ciclo. Si todavía no está en Rezonar, pedile que arme su página.',
  },
  {
    icono: UserPlus,
    titulo: 'Tocá Seguir',
    detalle: 'Elegís si querés enterarte de todas sus fechas o solo de las que caen cerca tuyo.',
  },
  {
    icono: Bell,
    titulo: 'Te avisamos',
    detalle: 'Cuando publica una fecha nueva te llega un aviso al teléfono. No hay que entrar a mirar.',
  },
];

function Home() {
  const { apiUrl, user, token, logout } = useContext(AuthContext);
  const navigate = useNavigate();

  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState(null);
  const [buscando, setBuscando] = useState(false);

  const [recentPages, setRecentPages] = useState([]);
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [cantidadSeguidas, setCantidadSeguidas] = useState(null);

  const [userLocation, setUserLocation] = useState(null);
  const [pidiendoUbicacion, setPidiendoUbicacion] = useState(false);
  const [errorUbicacion, setErrorUbicacion] = useState(null);

  const [filtro, setFiltro] = useState('todos');
  const [vista, setVista] = useState('lista');

  // ------------------------------------------------------------------ carga

  useEffect(() => {
    loadRecentPages();

    if (token) {
      loadUserLocation();
      loadCantidadSeguidas();
    }
  }, [token]);

  useEffect(() => {
    if (token && filtro === 'siguiendo') {
      loadFollowedEvents();
    } else {
      loadAllEvents();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filtro, token]);

  const loadUserLocation = async () => {
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      await handleApiResponse(response, navigate, logout);
      const data = await response.json();
      if (data.latitude && data.longitude) {
        setUserLocation({ lat: data.latitude, lng: data.longitude });
      }
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        console.error('Error loading location:', err);
      }
    }
  };

  /**
   * La ubicación se pide cuando la persona la pide, no al entrar. El permiso
   * de geolocalización se concede una vez: preguntarlo antes de haber
   * explicado para qué sirve es la forma más rápida de perderlo para siempre.
   */
  const pedirUbicacion = () => {
    if (!('geolocation' in navigator)) {
      setErrorUbicacion('Tu navegador no puede darnos la ubicación.');
      return;
    }

    setPidiendoUbicacion(true);
    setErrorUbicacion(null);

    navigator.geolocation.getCurrentPosition(
      (position) => {
        setUserLocation({
          lat: position.coords.latitude,
          lng: position.coords.longitude,
        });
        setPidiendoUbicacion(false);
      },
      () => {
        setErrorUbicacion('No pudimos acceder a tu ubicación. Podés habilitarla desde los permisos del navegador.');
        setPidiendoUbicacion(false);
      },
      { timeout: 10000 }
    );
  };

  const loadCantidadSeguidas = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/following.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setCantidadSeguidas((data.following || []).length);
    } catch (err) {
      console.error('Error loading following:', err);
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
      const response = await fetch(`${apiUrl}/pages/feed-events.php?sortBy=date&sortOrder=asc`, {
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

    if (searchQuery.trim().length < 2) return;

    setBuscando(true);
    try {
      const response = await fetch(`${apiUrl}/public/search.php?q=${encodeURIComponent(searchQuery)}`);
      const data = await response.json();
      setSearchResults(data.results || []);
    } catch (err) {
      console.error('Error searching:', err);
      setSearchResults([]);
    } finally {
      setBuscando(false);
    }
  };

  // ---------------------------------------------------------------- derivados

  /**
   * Los eventos con su distancia ya calculada y en el orden en que se muestran.
   * Con el filtro "cerca" el orden pasa a ser por distancia: quien filtra por
   * cercanía está preguntando qué tiene al lado, no qué pasa primero.
   */
  const eventosVisibles = useMemo(() => {
    const conDistancia = events.map((evento) => ({
      evento,
      distancia: distanciaDelEvento(evento, userLocation),
    }));

    if (filtro !== 'cerca') return conDistancia;

    return conDistancia
      .filter(({ distancia }) => distancia !== null && distancia <= RADIO_CERCA_KM)
      .sort((a, b) => a.distancia - b.distancia);
  }, [events, userLocation, filtro]);

  const paginasResultado = (searchResults || []).filter(r => r.type === 'page');
  const eventosResultado = (searchResults || []).filter(r => r.type === 'event');

  // Se explica cómo seguir a quien todavía no siguió a nadie. A quien ya sigue
  // páginas la explicación le sobra, y ocuparía el lugar de su agenda.
  const explicarComoSeguir = !token || cantidadSeguidas === 0;

  const FILTROS = [
    { id: 'todos', texto: 'Todas las fechas', icono: CalendarDays },
    ...(token ? [{ id: 'siguiendo', texto: 'De quienes sigo', icono: UserPlus }] : []),
    { id: 'cerca', texto: 'Cerca mío', icono: Compass },
  ];

  // ------------------------------------------------------------------ pintura

  return (
    <div className="min-h-screen bg-white text-tinta flex flex-col">
      <Helmet>
        <title>Rezonar — Seguí a tus artistas y enterate de cada fecha</title>
        <meta name="description" content="Seguí a los artistas y ciclos que te gustan, mirá qué hay cerca tuyo y recibí un aviso cuando publican una fecha nueva." />
        <meta property="og:title" content="Rezonar — Seguí a tus artistas y enterate de cada fecha" />
        <meta property="og:description" content="Seguí a los artistas y ciclos que te gustan, mirá qué hay cerca tuyo y recibí un aviso cuando publican una fecha nueva." />
        <meta property="og:type" content="website" />
        <meta property="og:url" content={window.location.href} />
        <meta name="twitter:card" content="summary_large_image" />
        <link rel="canonical" href={window.location.href} />
      </Helmet>

      <Navigation />

      <main className="flex-1">

        {/* ------------------------------------------------------------ hero */}

        <section className="border-b border-borde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14 sm:py-20">
            {!token ? (
              <>
                <Rotulo>Agenda de artistas independientes</Rotulo>
                <h1 className="mt-4 text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.05] text-balance max-w-3xl">
                  Seguí a los artistas que te gustan y{' '}
                  <span className="text-verde-oscuro">no te enteres tarde.</span>
                </h1>
                <p className="mt-5 text-lg text-tinta-media max-w-xl leading-relaxed">
                  Cada artista tiene su página con todas sus fechas. Vos la seguís, y te
                  avisamos cuando publica una nueva.
                </p>
              </>
            ) : (
              <>
                <Rotulo>Tu agenda</Rotulo>
                <h1 className="mt-3 text-3xl sm:text-4xl font-bold tracking-tight text-balance">
                  Hola{user?.name ? `, ${user.name.split(' ')[0]}` : ''}.
                </h1>
                <p className="mt-2 text-tinta-media">
                  {cantidadSeguidas === null
                    ? 'Mirá qué se viene.'
                    : cantidadSeguidas === 0
                      ? 'Todavía no seguís a nadie. Empezá buscando a un artista.'
                      : `Seguís ${cantidadSeguidas} ${cantidadSeguidas === 1 ? 'página' : 'páginas'}.`}
                </p>
              </>
            )}

            <form onSubmit={handleSearch} className="mt-8 flex flex-col sm:flex-row gap-3 max-w-2xl">
              <div className="flex-1 relative">
                <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-tinta-suave w-5 h-5 pointer-events-none" />
                <Campo
                  type="search"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Buscá un artista, un ciclo, una sala..."
                  aria-label="Buscar artistas y eventos"
                  className="pl-12 py-4"
                />
              </div>
              <Boton type="submit" tamano="lg" disabled={buscando || searchQuery.trim().length < 2}>
                {buscando ? 'Buscando...' : 'Buscar'}
              </Boton>
            </form>

            {/* La landing de artistas se entra desde acá y no sólo desde la
                barra de arriba, donde en un teléfono vive adentro del menú y
                con la sesión abierta ni siquiera aparece. */}
            <div className="mt-6 flex flex-wrap items-center gap-x-3 gap-y-2">
              <Boton a="/artistas" variante="secundario">
                <Sparkles className="w-4 h-4" /> Para artistas
              </Boton>
              <p className="text-sm text-tinta-suave">
                Armá tu página gratis, cargá tus fechas y que te sigan.
              </p>
            </div>

            {/* Las notificaciones son lo que diferencia a Rezonar de una lista
                de links, y se ofrecían a mitad de la página: en un teléfono,
                varias pantallas de scroll más abajo. */}
            <div className="mt-8">
              <AvisoNotificaciones />
            </div>
          </div>
        </section>

        {/* ------------------------------------------- resultados de búsqueda */}

        {searchResults !== null && (
          <section className="border-b border-borde bg-papel-hueso">
            <div className="max-w-7xl mx-auto px-5 sm:px-6 py-12">
              <TituloSeccion
                titulo={`Resultados para "${searchQuery}"`}
                bajada={
                  searchResults.length === 0
                    ? 'No encontramos nada con ese nombre.'
                    : `${paginasResultado.length} ${paginasResultado.length === 1 ? 'página' : 'páginas'} y ${eventosResultado.length} ${eventosResultado.length === 1 ? 'fecha' : 'fechas'}.`
                }
                acciones={
                  <Boton
                    variante="secundario"
                    tamano="sm"
                    onClick={() => { setSearchResults(null); setSearchQuery(''); }}
                  >
                    Limpiar
                  </Boton>
                }
              />

              {paginasResultado.length > 0 && (
                <div className="mb-10">
                  <Rotulo className="block mb-4">Páginas para seguir</Rotulo>
                  <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {paginasResultado.map((pagina) => (
                      <TarjetaPagina key={`p-${pagina.id}`} pagina={pagina} />
                    ))}
                  </div>
                </div>
              )}

              {eventosResultado.length > 0 && (
                <div>
                  <Rotulo className="block mb-4">Fechas</Rotulo>
                  <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {eventosResultado.map((evento) => (
                      <TarjetaEvento
                        key={`e-${evento.id}`}
                        evento={{ ...evento, event_date: evento.item_date }}
                      />
                    ))}
                  </div>
                </div>
              )}
            </div>
          </section>
        )}

        {/* ------------------------------------------------- cómo se sigue */}

        {explicarComoSeguir && (
          <section className="border-b border-borde">
            <div className="max-w-5xl mx-auto px-5 sm:px-6 py-14">
              <TituloSeccion
                titulo="Cómo seguir a un artista"
                bajada="Tres pasos, una sola vez. Después las fechas te llegan solas."
              />

              <ol className="grid sm:grid-cols-3 gap-4">
                {PASOS_PARA_SEGUIR.map(({ icono: Icono, titulo, detalle }, i) => (
                  <li key={titulo}>
                    <Tarjeta className="p-6 h-full flex flex-col gap-3">
                      <div className="flex items-center gap-3">
                        <span className="w-10 h-10 rounded-full bg-verde-claro text-verde-oscuro flex items-center justify-center border border-verde-medio flex-shrink-0">
                          <Icono className="w-5 h-5" />
                        </span>
                        <Rotulo>Paso {i + 1}</Rotulo>
                      </div>
                      <h3 className="text-lg font-bold text-tinta">{titulo}</h3>
                      <p className="text-tinta-media text-sm leading-relaxed">{detalle}</p>
                    </Tarjeta>
                  </li>
                ))}
              </ol>

              <div className="mt-6 flex flex-wrap gap-3">
                <Boton a={token ? '/pages' : '/register'} tamano="lg">
                  {token ? 'Buscar páginas para seguir' : 'Crear mi cuenta y seguir artistas'}
                  <ArrowRight className="w-4 h-4" />
                </Boton>
                {!token && (
                  <Boton a="/login" variante="secundario" tamano="lg">Ya tengo cuenta</Boton>
                )}
              </div>
            </div>
          </section>
        )}

        {/* --------------------------------------------------------- agenda */}

        <section className="border-b border-borde">
          <div className="max-w-7xl mx-auto px-5 sm:px-6 py-14">

            <TituloSeccion
              titulo="Qué se viene"
              bajada="Las próximas fechas publicadas en Rezonar."
              acciones={
                <div className="flex items-center gap-1 p-1 bg-papel-hueso border border-borde rounded-full">
                  <button
                    onClick={() => setVista('lista')}
                    className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold transition-colors ${
                      vista === 'lista' ? 'bg-white text-tinta shadow-sm' : 'text-tinta-suave hover:text-tinta'
                    }`}
                    aria-pressed={vista === 'lista'}
                  >
                    <List className="w-4 h-4" /> Lista
                  </button>
                  <button
                    onClick={() => setVista('mapa')}
                    className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold transition-colors ${
                      vista === 'mapa' ? 'bg-white text-tinta shadow-sm' : 'text-tinta-suave hover:text-tinta'
                    }`}
                    aria-pressed={vista === 'mapa'}
                  >
                    <Map className="w-4 h-4" /> Mapa
                  </button>
                </div>
              }
            />

            <div className="flex flex-wrap items-center gap-2 mb-6">
              {FILTROS.map(({ id, texto, icono: Icono }) => (
                <button
                  key={id}
                  onClick={() => setFiltro(id)}
                  className={`inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold border transition-colors ${
                    filtro === id
                      ? 'bg-verde text-verde-tinta border-verde'
                      : 'bg-white text-tinta-media border-borde hover:border-borde-fuerte hover:text-tinta'
                  }`}
                >
                  <Icono className="w-4 h-4" />
                  {texto}
                </button>
              ))}
            </div>

            {/* La ubicación se pide acá, donde ya se ve para qué sirve. */}
            {filtro === 'cerca' && !userLocation && (
              <Tarjeta destacada className="p-6 mb-6 flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-start gap-3 min-w-0">
                  <MapPin className="w-5 h-5 text-verde-oscuro flex-shrink-0 mt-0.5" />
                  <div>
                    <p className="font-semibold text-tinta">Necesitamos saber dónde estás</p>
                    <p className="text-sm text-tinta-media mt-0.5">
                      {errorUbicacion || `Para mostrarte solo lo que pasa a menos de ${RADIO_CERCA_KM} km. No guardamos tu ubicación.`}
                    </p>
                  </div>
                </div>
                <Boton onClick={pedirUbicacion} disabled={pidiendoUbicacion}>
                  {pidiendoUbicacion ? 'Buscando...' : 'Usar mi ubicación'}
                </Boton>
              </Tarjeta>
            )}

            {vista === 'mapa' ? (
              <div className="rounded-2xl overflow-hidden border border-borde">
                <EventsMap
                  events={eventosVisibles
                    .map(({ evento }) => evento)
                    .filter(e => e.event_latitude && e.event_longitude)}
                  userLocation={userLocation}
                  onEventClick={(event) => navigate(`/evento/${event.id}`)}
                />
              </div>
            ) : loading ? (
              <Cargando texto="Buscando fechas..." />
            ) : eventosVisibles.length === 0 ? (
              <Tarjeta>
                <Vacio
                  icono={CalendarDays}
                  titulo={
                    filtro === 'siguiendo'
                      ? 'Las páginas que seguís no tienen fechas cargadas'
                      : filtro === 'cerca'
                        ? `No hay nada a menos de ${RADIO_CERCA_KM} km`
                        : 'Todavía no hay fechas publicadas'
                  }
                  detalle={
                    filtro === 'siguiendo'
                      ? 'Cuando publiquen una, la vas a ver acá y te va a llegar un aviso.'
                      : filtro === 'cerca'
                        ? 'Probá con todas las fechas: puede haber algo un poco más lejos que valga el viaje.'
                        : 'Volvé en unos días, o armá tu página y publicá la primera.'
                  }
                  accion={
                    filtro === 'todos'
                      ? <Boton a="/artistas" variante="secundario">Publicar mis fechas</Boton>
                      : <Boton onClick={() => setFiltro('todos')} variante="secundario">Ver todas las fechas</Boton>
                  }
                />
              </Tarjeta>
            ) : (
              <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                {eventosVisibles.map(({ evento, distancia }) => (
                  <TarjetaEvento
                    key={evento.id}
                    evento={evento}
                    distanciaKm={userLocation ? distancia : null}
                  />
                ))}
              </div>
            )}
          </div>
        </section>

        {/* ------------------------------------------------ páginas a seguir */}

        <section className="border-b border-borde bg-papel-hueso">
          <div className="max-w-7xl mx-auto px-5 sm:px-6 py-14">
            <TituloSeccion
              titulo="Páginas para seguir"
              bajada="Artistas, ciclos y salas que se sumaron hace poco."
              acciones={
                token && (
                  <Boton a="/pages" variante="secundario" tamano="sm">
                    Ver todas <ArrowRight className="w-3.5 h-3.5" />
                  </Boton>
                )
              }
            />

            {recentPages.length === 0 ? (
              <Tarjeta>
                <Vacio icono={Sparkles} titulo="Todavía no hay páginas para mostrar" />
              </Tarjeta>
            ) : (
              <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                {recentPages.slice(0, 6).map((pagina) => (
                  <TarjetaPagina key={pagina.id} pagina={pagina} />
                ))}
              </div>
            )}
          </div>
        </section>

        {/* -------------------------------------------------- para artistas */}

        <section className="bg-verde">
          <div className="max-w-5xl mx-auto px-5 sm:px-6 py-16 text-center">
            <Rotulo className="!text-verde-tinta/70">Para artistas</Rotulo>
            <h2 className="mt-4 text-3xl sm:text-4xl font-bold tracking-tight text-verde-tinta text-balance max-w-2xl mx-auto leading-tight">
              Un solo link en tu Instagram, con todas tus fechas.
            </h2>
            <p className="mt-4 text-verde-tinta/80 max-w-xl mx-auto leading-relaxed">
              Armá tu página en minutos, cargá tus shows y dejá que tu público se entere
              solo. Es gratis.
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
              <Boton a="/artistas" variante="secundario" tamano="lg">
                Ver cómo funciona
              </Boton>
              <Boton
                a={token ? '/my-pages' : '/register'}
                tamano="lg"
                className="!bg-verde-tinta !text-white hover:!bg-tinta"
              >
                {token ? 'Ir a mis páginas' : 'Crear mi página'}
                <ArrowRight className="w-4 h-4" />
              </Boton>
            </div>
          </div>
        </section>
      </main>

      <PieDePagina />
    </div>
  );
}

export default Home;
