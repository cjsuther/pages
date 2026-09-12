import React, { useState, useEffect, useContext } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';
import NotificationBell from '../components/NotificationBell';

function Dashboard() {
  const { token, user, logout, apiUrl } = useContext(AuthContext);
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

    const reservedSlugs = ['login', 'register', 'dashboard', 'page', 'api', 'admin', 'auth', 'public', 'pages', 'groups', 'links', 'user', 'users', 'config', 'settings', 'logout', 'profile', 'account'];
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
    <div className="min-h-screen bg-white text-tinta">
      <nav className="border-b border-borde">
        <div className="max-w-7xl mx-auto px-6 py-6">
          <div className="flex justify-between items-center">
            <Link to="/">
              <img src="/logo-negro.png" alt="Rezonar" className="h-10" />
            </Link>
            <div className="flex items-center gap-6">
              <NotificationBell />
              <Link
                to="/profile"
                className="text-tinta-media hover:text-tinta transition font-medium"
              >
                Perfil
              </Link>
              <div className="text-right">
                <p className="text-tinta-suave text-sm font-medium">{user?.email}</p>
              </div>
              <button
                onClick={logout}
                className="text-tinta-media hover:text-tinta transition font-medium"
              >
                {user ? 'Salir de la Cuenta' : 'Iniciar Sesión / Registrarse'}
              </button>
            </div>
          </div>
        </div>
      </nav>

      <div className="max-w-7xl mx-auto px-6 py-16">
        <button
          onClick={() => setShowCreateModal(true)}
          className="mb-16 inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-7 py-3.5 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
        >
          Nueva página
        </button>

        {loading ? (
          <div className="text-center py-24">
            <div className="text-tinta-suave text-xl font-medium">Cargando...</div>
          </div>
        ) : pages.length === 0 ? (
          <div className="text-center py-24 space-y-6">
            <div className="w-32 h-32 bg-white mx-auto"></div>
            <p className="text-tinta-media text-2xl font-light">No tienes páginas todavía</p>
            <p className="text-tinta-suave text-lg">Crea tu primera página personal</p>
          </div>
        ) : (
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            {pages.map((page) => (
              <div key={page.id} className="bg-white border border-borde rounded-2xl p-6 sm:p-8 hover:border-borde-fuerte transition group">
                <h3 className="text-2xl font-bold mb-4">{page.title}</h3>
                <p className="text-tinta-media mb-8 leading-relaxed whitespace-pre-line">{page.description}</p>
                <div className="mb-8">
                  <span className="text-tinta-suave text-sm font-medium">URL</span>
                  <a
                    href={`/${page.url_slug}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="block text-tinta hover:text-tinta-media transition mt-1 font-medium"
                  >
                    /{page.url_slug}
                  </a>
                </div>
                <div className="space-y-3">
                  <Link
                    to={`/page/${page.id}`}
                    className="block text-center inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
                  >
                    Editar
                  </Link>
                  <button
                    onClick={() => deletePage(page.id)}
                    className="w-full text-center text-red-700 hover:text-red-700 px-6 py-3 font-bold transition"
                  >
                    Eliminar
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {showCreateModal && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-borde max-w-lg w-full p-10">
            <h2 className="text-3xl font-bold mb-8">Nueva página</h2>

            {error && (
              <div className="bg-red-50 border border-red-200 text-red-800 px-4 py-3 mb-6 font-medium">
                {error}
              </div>
            )}

            <form onSubmit={createPage} className="space-y-6">
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">
                  Título
                </label>
                <input
                  type="text"
                  value={newPage.title}
                  onChange={(e) => setNewPage({ ...newPage, title: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">
                  Descripción
                </label>
                <textarea
                  value={newPage.description}
                  onChange={(e) => setNewPage({ ...newPage, description: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  rows="3"
                />
              </div>

              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">
                  URL
                </label>
                <input
                  type="text"
                  value={newPage.url_slug}
                  onChange={(e) => setNewPage({ ...newPage, url_slug: e.target.value.toLowerCase() })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
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
                  className="flex-1 px-6 py-3 rounded-full border border-borde-fuerte hover:border-tinta transition-colors font-semibold"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="flex-1 inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
                >
                  Crear
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

export default Dashboard;
