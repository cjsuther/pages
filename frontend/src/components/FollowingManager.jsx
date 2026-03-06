import React, { useState, useEffect, useContext } from 'react';
import { AuthContext } from '../App';
import { Link } from 'react-router-dom';

function FollowingManager() {
  const { token, apiUrl } = useContext(AuthContext);
  const [following, setFollowing] = useState([]);
  const [filteredFollowing, setFilteredFollowing] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editingId, setEditingId] = useState(null);
  const [notificationType, setNotificationType] = useState('all');
  const [search, setSearch] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 5;

  useEffect(() => {
    fetchFollowing();
  }, []);

  useEffect(() => {
    const filtered = following.filter(page =>
      page.title.toLowerCase().includes(search.toLowerCase()) ||
      (page.description && page.description.toLowerCase().includes(search.toLowerCase()))
    );
    setFilteredFollowing(filtered);
    setCurrentPage(1);
  }, [search, following]);

  const fetchFollowing = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/following.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setFollowing(data.following || []);
      setFilteredFollowing(data.following || []);
    } catch (err) {
      console.error('Error fetching following:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleUnfollow = async (pageId) => {
    if (!confirm('¿Dejar de seguir esta página?')) {
      return;
    }

    try {
      const response = await fetch(`${apiUrl}/pages/follow.php?page_id=${pageId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (response.ok) {
        setFollowing(following.filter(page => page.id !== pageId));
      }
    } catch (err) {
      console.error('Error unfollowing page:', err);
    }
  };

  const handleEditPreferences = (page) => {
    setEditingId(page.id);
    setNotificationType(page.notify_all_events ? 'all' : 'nearby');
  };

  const handleSavePreferences = async (pageId) => {
    try {
      const response = await fetch(`${apiUrl}/pages/follow.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          page_id: pageId,
          notify_all_events: notificationType === 'all',
          max_distance_km: 30
        })
      });

      if (response.ok) {
        setFollowing(following.map(page =>
          page.id === pageId
            ? { ...page, notify_all_events: notificationType === 'all', max_distance_km: 30 }
            : page
        ));
        setEditingId(null);
      }
    } catch (err) {
      console.error('Error updating preferences:', err);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center py-12">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-white"></div>
      </div>
    );
  }

  const totalPages = Math.ceil(filteredFollowing.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedFollowing = filteredFollowing.slice(startIndex, startIndex + itemsPerPage);

  return (
    <div className="bg-gray-900 border border-gray-800 p-8">
      <h2 className="text-2xl font-bold mb-4">Páginas que Sigo</h2>
      <p className="text-gray-400 mb-8">
        Gestiona las páginas que sigues y configura tus preferencias de notificación.
      </p>

      {following.length > 0 && (
        <div className="mb-6">
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar en tus páginas..."
            className="w-full px-4 py-3 bg-black border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-gray-600"
          />
        </div>
      )}

      {following.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-gray-500 mb-4">No sigues ninguna página todavía</p>
          <p className="text-gray-600 text-sm">Usa la pestaña "BUSCAR PÁGINAS" para encontrar páginas y seguirlas</p>
        </div>
      ) : filteredFollowing.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-gray-500">No se encontraron páginas con ese término de búsqueda</p>
        </div>
      ) : (
        <>
          <div className="space-y-4">
            {paginatedFollowing.map((page) => (
              <div key={page.id} className="bg-black border border-gray-800 p-6">
                <div className="flex items-start justify-between mb-4">
                  <div className="flex-1">
                    <Link
                      to={`/${page.slug}`}
                      className="text-lg font-bold text-white hover:text-gray-300 transition"
                    >
                      {page.title}
                    </Link>
                    {page.description && (
                      <p className="text-sm text-gray-400 mt-1">{page.description}</p>
                    )}
                  </div>
                </div>

                {editingId === page.id ? (
                  <div className="bg-gray-900 border border-gray-800 p-4 space-y-4">
                    <p className="text-sm font-bold text-gray-300 mb-3">
                      ¿Qué eventos quieres recibir?
                    </p>

                    <label
                      className={`flex items-start gap-3 p-3 border-2 rounded cursor-pointer transition ${
                        notificationType === 'all'
                          ? 'border-white bg-gray-800'
                          : 'border-gray-700 hover:border-gray-600'
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
                        <div className="font-semibold text-white text-sm">Todos los eventos</div>
                        <div className="text-xs text-gray-400">
                          Recibirás notificaciones de todos los eventos
                        </div>
                      </div>
                    </label>

                    <label
                      className={`flex items-start gap-3 p-3 border-2 rounded cursor-pointer transition ${
                        notificationType === 'nearby'
                          ? 'border-white bg-gray-800'
                          : 'border-gray-700 hover:border-gray-600'
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
                        <div className="font-semibold text-white text-sm">Solo eventos cercanos</div>
                        <div className="text-xs text-gray-400">
                          Solo eventos a menos de 30 km de tu ubicación
                        </div>
                      </div>
                    </label>

                    <div className="flex gap-2">
                      <button
                        onClick={() => handleSavePreferences(page.id)}
                        className="px-4 py-2 bg-white text-black font-bold hover:bg-gray-200 transition text-sm"
                      >
                        Guardar
                      </button>
                      <button
                        onClick={() => setEditingId(null)}
                        className="px-4 py-2 bg-gray-700 text-white hover:bg-gray-600 transition text-sm"
                      >
                        Cancelar
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="flex items-center justify-between">
                    <div className="text-sm text-gray-400">
                      {page.notify_all_events ? (
                        <span>Todos los eventos</span>
                      ) : (
                        <span>Solo eventos cercanos (30 km)</span>
                      )}
                    </div>

                    <div className="flex gap-2">
                      <button
                        onClick={() => handleEditPreferences(page)}
                        className="px-3 py-1 text-sm text-gray-300 hover:text-white transition"
                      >
                        Editar
                      </button>
                      <button
                        onClick={() => handleUnfollow(page.id)}
                        className="px-3 py-1 text-sm text-red-400 hover:text-red-300 transition"
                      >
                        Dejar de seguir
                      </button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-6">
              <button
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
                className="px-4 py-2 bg-white text-black font-bold hover:bg-gray-200 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed transition"
              >
                Anterior
              </button>

              <span className="text-gray-400">
                Página {currentPage} de {totalPages}
              </span>

              <button
                onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                disabled={currentPage === totalPages}
                className="px-4 py-2 bg-white text-black font-bold hover:bg-gray-200 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed transition"
              >
                Siguiente
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

export default FollowingManager;
