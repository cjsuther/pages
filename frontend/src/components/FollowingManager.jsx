import React, { useState, useEffect, useContext, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { Search, Bell, ChevronDown, ExternalLink, Users } from 'lucide-react';
import { AuthContext } from '../App';
import { Avatar, Boton, Campo, Cargando, Chip, Tarjeta, Vacio } from './ui';
import EleccionDeAlerta from './EleccionDeAlerta';
import { cuerpoDePreferencia, describirAlerta, leerPreferencia } from '../utils/alertas';

const POR_PAGINA = 8;

/**
 * Las páginas que seguís, con el aviso de cada una a la vista.
 *
 * Antes había que abrir "Editar" en cada fila para saber qué avisos tenía
 * configurados: la información más importante de la lista —de qué te vas a
 * enterar— era justamente la única que no se veía. Ahora va en la fila, y
 * abrir sirve para cambiarla, no para consultarla.
 */
function FollowingManager({ alCambiar = () => {} }) {
  const { token, apiUrl } = useContext(AuthContext);

  const [following, setFollowing] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busqueda, setBusqueda] = useState('');
  const [pagina, setPagina] = useState(1);

  const [abierta, setAbierta] = useState(null);
  const [modo, setModo] = useState('todas');
  const [radio, setRadio] = useState(50);
  const [guardando, setGuardando] = useState(false);

  useEffect(() => {
    fetchFollowing();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    setPagina(1);
  }, [busqueda]);

  const fetchFollowing = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/following.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setFollowing(data.following || []);
      alCambiar((data.following || []).length);
    } catch (err) {
      console.error('Error fetching following:', err);
    } finally {
      setLoading(false);
    }
  };

  const dejarDeSeguir = async (page) => {
    if (!confirm(`¿Dejar de seguir a ${page.title}? No vas a recibir más avisos de sus fechas.`)) {
      return;
    }

    try {
      const response = await fetch(`${apiUrl}/pages/follow.php?page_id=${page.id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (response.ok) {
        const quedan = following.filter(p => p.id !== page.id);
        setFollowing(quedan);
        alCambiar(quedan.length);
      }
    } catch (err) {
      console.error('Error unfollowing page:', err);
    }
  };

  const abrir = (page) => {
    if (abierta === page.id) {
      setAbierta(null);
      return;
    }

    const { modo: m, radio: r } = leerPreferencia(page);
    setModo(m);
    setRadio(r);
    setAbierta(page.id);
  };

  const guardar = async (pageId) => {
    setGuardando(true);
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(cuerpoDePreferencia(pageId, modo, radio))
      });

      if (response.ok) {
        setFollowing(following.map(page =>
          page.id === pageId
            ? { ...page, notify_all_events: modo === 'todas', max_distance_km: radio }
            : page
        ));
        setAbierta(null);
      }
    } catch (err) {
      console.error('Error updating preferences:', err);
    } finally {
      setGuardando(false);
    }
  };

  const filtradas = useMemo(() => {
    const t = busqueda.trim().toLowerCase();
    if (!t) return following;

    return following.filter(page =>
      page.title.toLowerCase().includes(t) ||
      (page.description && page.description.toLowerCase().includes(t))
    );
  }, [busqueda, following]);

  if (loading) {
    return <Cargando texto="Buscando las páginas que seguís..." />;
  }

  if (following.length === 0) {
    return (
      <Tarjeta>
        <Vacio
          icono={Users}
          titulo="Todavía no seguís ninguna página"
          detalle="Buscá a un artista en la pestaña Descubrir y tocá Seguir. Desde ahí elegís de qué fechas querés enterarte."
        />
      </Tarjeta>
    );
  }

  const totalPaginas = Math.ceil(filtradas.length / POR_PAGINA);
  const desde = (pagina - 1) * POR_PAGINA;
  const visibles = filtradas.slice(desde, desde + POR_PAGINA);

  return (
    <div className="space-y-4">
      <div className="relative">
        <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-tinta-suave pointer-events-none" />
        <Campo
          type="search"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          placeholder={`Buscar entre las ${following.length} que seguís...`}
          aria-label="Buscar entre las páginas que seguís"
          className="pl-11"
        />
      </div>

      {filtradas.length === 0 ? (
        <Tarjeta>
          <Vacio titulo="Ninguna coincide con esa búsqueda" />
        </Tarjeta>
      ) : (
        <ul className="space-y-3">
          {visibles.map((page) => {
            const estaAbierta = abierta === page.id;
            const soloCerca = !page.notify_all_events;

            return (
              <li key={page.id}>
                <Tarjeta className="overflow-hidden">
                  <div className="p-4 sm:p-5 flex flex-wrap items-center gap-4">
                    {/* following.php la devuelve como image_url, no como profile_image. */}
                    <Avatar src={page.image_url} nombre={page.title} />

                    <div className="min-w-0 flex-1">
                      <Link
                        to={`/${page.slug}`}
                        className="font-bold text-tinta hover:text-verde-oscuro transition-colors inline-flex items-center gap-1.5"
                      >
                        <span className="truncate">{page.title}</span>
                        <ExternalLink className="w-3.5 h-3.5 flex-shrink-0 opacity-40" />
                      </Link>
                      <div className="mt-1.5">
                        <Chip tono={soloCerca ? 'neutro' : 'verde'}>
                          <Bell className="w-3 h-3" />
                          {describirAlerta(page)}
                        </Chip>
                      </div>
                    </div>

                    <div className="flex items-center gap-2 ml-auto">
                      <Boton
                        variante="secundario"
                        tamano="sm"
                        onClick={() => abrir(page)}
                        aria-expanded={estaAbierta}
                      >
                        Avisos
                        <ChevronDown className={`w-3.5 h-3.5 transition-transform ${estaAbierta ? 'rotate-180' : ''}`} />
                      </Boton>
                      <Boton variante="fantasma" tamano="sm" onClick={() => dejarDeSeguir(page)}>
                        Dejar de seguir
                      </Boton>
                    </div>
                  </div>

                  {estaAbierta && (
                    <div className="border-t border-borde bg-papel-hueso p-4 sm:p-5">
                      <EleccionDeAlerta
                        modo={modo}
                        radio={radio}
                        alCambiarModo={setModo}
                        alCambiarRadio={setRadio}
                        nombre={`alerta-${page.id}`}
                      />
                      <div className="flex gap-3 mt-5">
                        <Boton onClick={() => guardar(page.id)} disabled={guardando} tamano="sm">
                          {guardando ? 'Guardando...' : 'Guardar'}
                        </Boton>
                        <Boton variante="fantasma" tamano="sm" onClick={() => setAbierta(null)}>
                          Cancelar
                        </Boton>
                      </div>
                    </div>
                  )}
                </Tarjeta>
              </li>
            );
          })}
        </ul>
      )}

      {totalPaginas > 1 && (
        <div className="flex items-center justify-between pt-2">
          <Boton
            variante="secundario"
            tamano="sm"
            onClick={() => setPagina(p => Math.max(1, p - 1))}
            disabled={pagina === 1}
          >
            Anterior
          </Boton>
          <span className="text-sm text-tinta-suave">Página {pagina} de {totalPaginas}</span>
          <Boton
            variante="secundario"
            tamano="sm"
            onClick={() => setPagina(p => Math.min(totalPaginas, p + 1))}
            disabled={pagina === totalPaginas}
          >
            Siguiente
          </Boton>
        </div>
      )}
    </div>
  );
}

export default FollowingManager;
