import React, { useState, useEffect, useContext } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';
import Navigation from '../components/Navigation';
import PageQRDownload from '../components/PageQRDownload';
import { Search, ChevronLeft, ChevronRight } from 'lucide-react';

function MyPages() {
  const { token, apiUrl } = useContext(AuthContext);
  const [pages, setPages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newPage, setNewPage] = useState({ title: '', description: '', url_slug: '' });
  const [error, setError] = useState('');
  const [pendingCollabPageIds, setPendingCollabPageIds] = useState(new Set());
  const [busqueda, setBusqueda] = useState('');
  const [pagina, setPagina] = useState(1);
  const [paginacion, setPaginacion] = useState({ total: 0, paginas: 0, pagina: 1 });
  const [adminInvites, setAdminInvites] = useState([]);
  const navigate = useNavigate();

  useEffect(() => {
    fetchPages();
    fetchPendingCollabs();
    fetchAdminInvites();
  }, []);

  // Se espera a que la persona deje de tipear: una consulta por tecla es una
  // consulta de más, y con muchas páginas se nota.
  useEffect(() => {
    const id = setTimeout(() => {
      setPagina(1);
      fetchPages(busqueda, 1);
    }, 300);

    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busqueda]);

  const irAPagina = (nueva) => {
    setPagina(nueva);
    fetchPages(busqueda, nueva);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const fetchAdminInvites = async () => {
    try {
      const response = await fetch(`${apiUrl}/admins/index.php?type=pending`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (response.ok) {
        const data = await response.json();
        setAdminInvites(data.invitations || []);
      }
    } catch (err) {
      console.error('Error fetching admin invites:', err);
    }
  };

  const respondAdminInvite = async (inviteId, status) => {
    try {
      const response = await fetch(`${apiUrl}/admins/detail.php?id=${inviteId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ status })
      });
      if (response.ok) {
        fetchAdminInvites();
        if (status === 'accepted') fetchPages();
      }
    } catch (err) {
      console.error('Error responding to invite:', err);
    }
  };

  const leavePage = async (id) => {
    if (!confirm('¿Dejar de administrar esta página?')) return;
    try {
      await fetch(`${apiUrl}/admins/detail.php?page_id=${id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      fetchPages();
    } catch (err) {
      console.error('Error leaving page:', err);
    }
  };

  const fetchPendingCollabs = async () => {
    try {
      const response = await fetch(`${apiUrl}/collaborations/index.php?type=pending`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (response.ok) {
        const data = await response.json();
        const ids = new Set((data.pending || []).map(c => c.collaborator_page_id));
        setPendingCollabPageIds(ids);
      }
    } catch (err) {
      console.error('Error fetching pending collabs:', err);
    }
  };

  const fetchPages = async (termino = busqueda, nroPagina = pagina) => {
    try {
      const query = new URLSearchParams({ pagina: nroPagina });

      if (termino.trim() !== '') {
        query.set('q', termino.trim());
      }

      const response = await fetch(`${apiUrl}/pages/index.php?${query}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();

      setPages(data.pages || []);

      if (data.paginacion) {
        setPaginacion(data.paginacion);
        // El servidor acota la página pedida a la última con resultados.
        setPagina(data.paginacion.pagina);
      }
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

        {adminInvites.length > 0 && (
          <div className="mb-10 border border-emerald-800 bg-emerald-950/40 p-6">
            <h2 className="text-lg font-bold mb-4 text-emerald-300">INVITACIONES PARA ADMINISTRAR</h2>
            <div className="space-y-3">
              {adminInvites.map((inv) => (
                <div key={inv.id} className="flex flex-wrap items-center justify-between gap-3 bg-black/40 px-4 py-3">
                  <div>
                    <span className="font-bold">{inv.page_title}</span>
                    <span className="text-gray-400 text-sm"> — te invitó {inv.owner_name || inv.owner_email}</span>
                  </div>
                  <div className="flex gap-2">
                    <button
                      onClick={() => respondAdminInvite(inv.id, 'accepted')}
                      className="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 text-sm font-bold transition"
                    >
                      ACEPTAR
                    </button>
                    <button
                      onClick={() => respondAdminInvite(inv.id, 'rejected')}
                      className="border border-gray-700 hover:border-gray-500 text-gray-300 px-4 py-2 text-sm font-bold transition"
                    >
                      RECHAZAR
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* El buscador se muestra siempre que haya algo que buscar, aunque la
            búsqueda actual no traiga resultados: si no, quedaría atrapado sin
            forma de borrar el término. */}
        {(paginacion.total > 0 || busqueda !== '') && (
          <div className="mb-8 flex items-center gap-4 flex-wrap">
            <div className="relative flex-1 min-w-[240px]">
              <Search className="w-4 h-4 text-gray-600 absolute left-4 top-1/2 -translate-y-1/2" />
              <input
                type="search"
                value={busqueda}
                onChange={(e) => setBusqueda(e.target.value)}
                placeholder="Buscar por nombre o dirección"
                aria-label="Buscar páginas"
                className="w-full pl-11 pr-4 py-3 bg-gray-900 border border-gray-800 text-white focus:border-white transition"
              />
            </div>

            <span className="text-sm text-gray-500">
              {paginacion.total} {paginacion.total === 1 ? 'página' : 'páginas'}
              {busqueda !== '' && ' encontradas'}
            </span>
          </div>
        )}

        {loading ? (
          <div className="text-center py-24">
            <div className="text-gray-500 text-xl font-medium">Cargando...</div>
          </div>
        ) : pages.length === 0 ? (
          busqueda !== '' ? (
            <div className="text-center py-24 space-y-4">
              <p className="text-gray-400 text-2xl font-light">
                Ninguna página coincide con "{busqueda}"
              </p>
              <button
                onClick={() => setBusqueda('')}
                className="text-gray-500 hover:text-white underline"
              >
                Limpiar la búsqueda
              </button>
            </div>
          ) : (
          <div className="text-center py-24 space-y-6">
            <div className="w-32 h-32 bg-gray-900 mx-auto"></div>
            <p className="text-gray-400 text-2xl font-light">No tienes páginas todavía</p>
            <p className="text-gray-600 text-lg">Crea tu primera página personal</p>
          </div>
          )
        ) : (
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            {pages.map((page) => (
              <div key={page.id} className="bg-gray-900 border border-gray-800 p-8 hover:border-gray-700 transition group relative">
                <div className="absolute top-4 right-4 flex items-center gap-2">
                  <PageQRDownload page={page} />
                  {pendingCollabPageIds.has(page.id) && (
                    <span className="w-3 h-3 bg-red-500 rounded-full" title="Tenés colaboraciones pendientes para aprobar" />
                  )}
                </div>
                {Number(page.is_owner) !== 1 && (
                  <span className="inline-block mb-3 px-2 py-1 text-xs font-bold rounded-full bg-pink-900 text-pink-200">
                    ADMIN
                  </span>
                )}
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
                  {Number(page.is_owner) === 1 ? (
                    <button
                      onClick={() => deletePage(page.id)}
                      className="w-full text-center text-red-400 hover:text-red-300 px-6 py-3 font-bold transition"
                    >
                      ELIMINAR
                    </button>
                  ) : (
                    <button
                      onClick={() => leavePage(page.id)}
                      className="w-full text-center text-gray-400 hover:text-gray-200 px-6 py-3 font-bold transition"
                    >
                      DEJAR DE ADMINISTRAR
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}

        {paginacion.paginas > 1 && (
          <nav className="mt-12 flex items-center justify-center gap-2" aria-label="Paginación">
            <button
              type="button"
              onClick={() => irAPagina(pagina - 1)}
              disabled={pagina <= 1}
              aria-label="Página anterior"
              className="px-4 py-3 border border-gray-800 text-white hover:bg-gray-900 transition disabled:opacity-30 disabled:hover:bg-transparent"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>

            <span className="px-4 text-gray-500 text-sm">
              Página {pagina} de {paginacion.paginas}
            </span>

            <button
              type="button"
              onClick={() => irAPagina(pagina + 1)}
              disabled={pagina >= paginacion.paginas}
              aria-label="Página siguiente"
              className="px-4 py-3 border border-gray-800 text-white hover:bg-gray-900 transition disabled:opacity-30 disabled:hover:bg-transparent"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </nav>
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
                <label htmlFor="nueva-pagina-titulo" className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  TÍTULO
                </label>
                <input
                  id="nueva-pagina-titulo"
                  type="text"
                  value={newPage.title}
                  onChange={(e) => setNewPage({ ...newPage, title: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  required
                />
              </div>

              <div>
                <label htmlFor="nueva-pagina-descripcion" className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  DESCRIPCIÓN
                </label>
                <textarea
                  id="nueva-pagina-descripcion"
                  value={newPage.description}
                  onChange={(e) => setNewPage({ ...newPage, description: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  rows="3"
                />
              </div>

              <div>
                <label htmlFor="nueva-pagina-url" className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  URL
                </label>
                <input
                  id="nueva-pagina-url"
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
