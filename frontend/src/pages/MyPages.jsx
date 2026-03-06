import React, { useState, useEffect, useContext } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';

function MyPages() {
  const { token, apiUrl } = useContext(AuthContext);
  const [pages, setPages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newPage, setNewPage] = useState({ title: '', description: '', url_slug: '' });
  const [error, setError] = useState('');
  const navigate = useNavigate();

  useEffect(() => {
    fetchPages();
  }, []);

  const fetchPages = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/index.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      setPages(data.pages || []);
    } catch (err) {
      console.error('Error fetching pages:', err);
    } finally {
      setLoading(false);
    }
  };

  const createPage = async (e) => {
    e.preventDefault();
    setError('');

    const reservedSlugs = ['login', 'register', 'dashboard', 'page', 'api', 'admin', 'auth', 'public', 'pages', 'groups', 'links', 'user', 'users', 'config', 'settings', 'logout', 'profile', 'account', 'feed', 'my-pages'];
    if (reservedSlugs.includes(newPage.url_slug.toLowerCase())) {
      setError('Esta URL está reservada y no puede ser utilizada');
      return;
    }

    try {
      const response = await fetch(`${apiUrl}/pages/index.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(newPage)
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Error al crear página');
      }

      setShowCreateModal(false);
      setNewPage({ title: '', description: '', url_slug: '' });
      navigate(`/page/${data.page.id}`);
    } catch (err) {
      setError(err.message);
    }
  };

  const deletePage = async (id) => {
    if (!confirm('¿Estás seguro de eliminar esta página?')) return;

    try {
      await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      fetchPages();
    } catch (err) {
      console.error('Error deleting page:', err);
    }
  };

  return (
    <div className="min-h-screen bg-black text-white">
      <Navigation />

      <div className="max-w-7xl mx-auto px-6 py-16">
        <div className="flex justify-between items-center mb-8">
          <h1 className="text-4xl font-bold">MIS PÁGINAS</h1>
          <button
            onClick={() => setShowCreateModal(true)}
            className="bg-white text-black px-8 py-4 text-lg font-bold hover:bg-gray-200 transition"
          >
            + NUEVA PÁGINA
          </button>
        </div>

        {loading ? (
          <div className="text-center py-24">
            <div className="text-gray-500 text-xl font-medium">Cargando...</div>
          </div>
        ) : pages.length === 0 ? (
          <div className="text-center py-24 space-y-6">
            <div className="w-32 h-32 bg-gray-900 mx-auto"></div>
            <p className="text-gray-400 text-2xl font-light">No tienes páginas todavía</p>
            <p className="text-gray-600 text-lg">Crea tu primera página personal</p>
          </div>
        ) : (
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            {pages.map((page) => (
              <div key={page.id} className="bg-gray-900 border border-gray-800 p-8 hover:border-gray-700 transition group">
                <h3 className="text-2xl font-bold mb-4">{page.title}</h3>
                <p className="text-gray-400 mb-8 leading-relaxed">{page.description}</p>
                <div className="mb-8">
                  <span className="text-gray-600 text-sm font-medium">URL</span>
                  <a
                    href={`/${page.url_slug}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="block text-white hover:text-gray-300 transition mt-1 font-medium"
                  >
                    /{page.url_slug}
                  </a>
                </div>
                <div className="space-y-3">
                  <Link
                    to={`/page/${page.id}`}
                    className="block text-center bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition"
                  >
                    EDITAR
                  </Link>
                  <button
                    onClick={() => deletePage(page.id)}
                    className="w-full text-center text-red-400 hover:text-red-300 px-6 py-3 font-bold transition"
                  >
                    ELIMINAR
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {showCreateModal && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center p-4 z-50">
          <div className="bg-gray-900 border border-gray-800 max-w-lg w-full p-10">
            <h2 className="text-3xl font-black mb-8">NUEVA PÁGINA</h2>

            {error && (
              <div className="bg-red-900 border border-red-700 text-red-200 px-4 py-3 mb-6 font-medium">
                {error}
              </div>
            )}

            <form onSubmit={createPage} className="space-y-6">
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  TÍTULO
                </label>
                <input
                  type="text"
                  value={newPage.title}
                  onChange={(e) => setNewPage({ ...newPage, title: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  DESCRIPCIÓN
                </label>
                <textarea
                  value={newPage.description}
                  onChange={(e) => setNewPage({ ...newPage, description: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  rows="3"
                />
              </div>

              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  URL
                </label>
                <input
                  type="text"
                  value={newPage.url_slug}
                  onChange={(e) => setNewPage({ ...newPage, url_slug: e.target.value.toLowerCase() })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  pattern="[a-z0-9-]+"
                  placeholder="solo-letras-numeros-guiones"
                  required
                />
              </div>

              <div className="flex gap-4 pt-6">
                <button
                  type="button"
                  onClick={() => {
                    setShowCreateModal(false);
                    setError('');
                  }}
                  className="flex-1 px-6 py-3 border border-gray-700 hover:border-gray-600 transition font-bold"
                >
                  CANCELAR
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition"
                >
                  CREAR
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

export default MyPages;
