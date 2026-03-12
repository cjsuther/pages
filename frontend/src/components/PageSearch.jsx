import React, { useState, useEffect, useContext } from 'react';
import { AuthContext } from '../App';
import { Link } from 'react-router-dom';
import FollowersPopup from './FollowersPopup';

function PageSearch() {
  const { token, apiUrl } = useContext(AuthContext);
  const [search, setSearch] = useState('');
  const [pages, setPages] = useState([]);
  const [followingIds, setFollowingIds] = useState(new Set());
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [showModal, setShowModal] = useState(false);
  const [selectedPage, setSelectedPage] = useState(null);
  const [notificationType, setNotificationType] = useState('all');
  const limit = 10;

  useEffect(() => {
    fetchFollowing();
  }, []);

  useEffect(() => {
    searchPages();
  }, [page]);

  useEffect(() => {
    setPage(1);
    searchPages();
  }, [search]);

  const fetchFollowing = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/following.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      const ids = new Set((data.following || []).map(p => p.id));
      setFollowingIds(ids);
    } catch (err) {
      console.error('Error fetching following:', err);
    }
  };

  const searchPages = async () => {
    if (!search.trim() && page === 1) {
      setPages([]);
      return;
    }

    setLoading(true);
    try {
      const offset = (page - 1) * limit;
      const response = await fetch(
        `${apiUrl}/public/search.php?q=${encodeURIComponent(search)}&type=pages&limit=${limit}&offset=${offset}`
      );
      const data = await response.json();

      const results = (data.results || []).filter(pg => !followingIds.has(pg.id));

      if (page === 1) {
        setPages(results);
      } else {
        setPages(prev => [...prev, ...results]);
      }

      setHasMore((data.results || []).length === limit);
    } catch (err) {
      console.error('Error searching pages:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleFollowClick = (pg) => {
    setSelectedPage(pg);
    setNotificationType('all');
    setShowModal(true);
  };

  const handleConfirmFollow = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          page_id: selectedPage.id,
          notify_all_events: notificationType === 'all',
          max_distance_km: 30
        })
      });

      if (response.ok) {
        setFollowingIds(prev => new Set([...prev, selectedPage.id]));
        setPages(pages.filter(p => p.id !== selectedPage.id));
        setShowModal(false);
        alert('Ahora sigues esta página');
      }
    } catch (err) {
      console.error('Error following page:', err);
      alert('Error al seguir la página');
    }
  };

  return (
    <div className="bg-gray-900 border border-gray-800 p-8">
      <h2 className="text-2xl font-bold mb-4">Buscar Páginas</h2>
      <p className="text-gray-400 mb-8">
        Busca páginas por nombre o descripción para seguirlas y recibir notificaciones.
      </p>

      <div className="mb-8">
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar páginas..."
          className="w-full px-4 py-3 bg-black border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-gray-600"
        />
      </div>

      {loading && page === 1 ? (
        <div className="flex justify-center py-12">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-white"></div>
        </div>
      ) : pages.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-gray-500">
            {search ? 'No se encontraron páginas' : 'Ingresa un término de búsqueda'}
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {pages.map((pg) => (
            <div key={pg.id} className="bg-black border border-gray-800 p-6">
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <Link
                    to={`/${pg.slug}`}
                    className="text-lg font-bold text-white hover:text-gray-300 transition"
                  >
                    {pg.title}
                  </Link>
                  {pg.description && (
                    <p className="text-sm text-gray-400 mt-2">{pg.description}</p>
                  )}
                  {pg.follower_count !== undefined && (
                    <FollowersPopup pageId={pg.id} followerCount={pg.follower_count} className="text-gray-500 mt-2" />
                  )}
                </div>
                <button
                  onClick={() => handleFollowClick(pg)}
                  className="ml-4 px-4 py-2 bg-white text-black font-bold hover:bg-gray-200 transition"
                >
                  SEGUIR
                </button>
              </div>
            </div>
          ))}

          {hasMore && (
            <button
              onClick={() => setPage(p => p + 1)}
              disabled={loading}
              className="w-full bg-white text-black py-3 font-bold hover:bg-gray-200 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed transition"
            >
              {loading ? 'Cargando...' : 'Cargar Más'}
            </button>
          )}
        </div>
      )}

      {showModal && selectedPage && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg max-w-md w-full p-6">
            <h3 className="text-xl font-bold text-gray-900 mb-4">
              ¿Qué eventos quieres recibir?
            </h3>

            <div className="space-y-3 mb-6">
              <label
                className={`flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer transition ${
                  notificationType === 'all'
                    ? 'border-blue-600 bg-blue-50'
                    : 'border-gray-300 hover:border-gray-400'
                }`}
              >
                <input
                  type="radio"
                  name="notificationType"
                  checked={notificationType === 'all'}
                  onChange={() => setNotificationType('all')}
                  className="mt-1"
                />
                <div>
                  <div className="font-semibold text-gray-900">Todos los eventos</div>
                  <div className="text-sm text-gray-600">
                    Recibirás notificaciones de todos los eventos que publique esta página
                  </div>
                </div>
              </label>

              <label
                className={`flex items-start gap-3 p-4 border-2 rounded-lg cursor-pointer transition ${
                  notificationType === 'nearby'
                    ? 'border-blue-600 bg-blue-50'
                    : 'border-gray-300 hover:border-gray-400'
                }`}
              >
                <input
                  type="radio"
                  name="notificationType"
                  checked={notificationType === 'nearby'}
                  onChange={() => setNotificationType('nearby')}
                  className="mt-1"
                />
                <div>
                  <div className="font-semibold text-gray-900">Solo eventos cercanos</div>
                  <div className="text-sm text-gray-600">
                    Solo eventos a menos de 30 km de tu ubicación
                  </div>
                </div>
              </label>
            </div>

            <div className="flex gap-3">
              <button
                onClick={handleConfirmFollow}
                className="flex-1 bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition-colors font-semibold"
              >
                Seguir página
              </button>
              <button
                onClick={() => setShowModal(false)}
                className="flex-1 bg-gray-200 text-gray-700 py-2 rounded-lg hover:bg-gray-300 transition-colors font-semibold"
              >
                Cancelar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default PageSearch;
