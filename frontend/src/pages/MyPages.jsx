import React, { useState, useEffect, useContext } from 'react';
import { useNavigate } from 'react-router-dom';
import { Helmet } from 'react-helmet-async';
import { AuthContext } from '../App';
import Marco from '../components/Marco';
import PageQRDownload from '../components/PageQRDownload';
import {
  Search, ChevronLeft, ChevronRight, Plus, ExternalLink, Pencil,
  LayoutTemplate, UserCheck,
} from 'lucide-react';
import {
  Aviso, Boton, Campo, Cargando, Chip, Etiqueta, Modal, AreaTexto, Rotulo,
  Tarjeta, Vacio,
} from '../components/ui';

/** El servidor manda booleanos; MySQL supo mandar 0/1 y no cuesta tolerarlo. */
const esDuenia = (page) => page.is_owner === true || Number(page.is_owner) === 1;
const esAdministradora = (page) => page.is_admin === true || Number(page.is_admin) === 1;

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

    // Espejo de PagesHandler::$reservedSlugs. El servidor vuelve a validar:
    // esto es para avisar antes de mandar, no para reemplazar aquel control.
    const reservedSlugs = [
      'login', 'register', 'dashboard', 'page', 'api', 'admin', 'auth',
      'public', 'pages', 'groups', 'links', 'user', 'users', 'config',
      'settings', 'logout', 'profile', 'account', 'artistas', 'feed', 'my-pages',
    ];
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
    <Marco ancho="ancho">
      <Helmet>
        <title>Mis páginas — Rezonar</title>
      </Helmet>

      <header className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <Rotulo>Tu administración</Rotulo>
          <h1 className="mt-3 text-3xl sm:text-4xl font-bold tracking-tight">Mis páginas</h1>
          <p className="mt-2 text-tinta-media">
            Cada página es un link propio con tus fechas, tus redes y tus entradas.
          </p>
        </div>
        <Boton onClick={() => setShowCreateModal(true)} tamano="lg">
          <Plus className="w-4 h-4" /> Nueva página
        </Boton>
      </header>

      {adminInvites.length > 0 && (
        <Tarjeta destacada className="p-6 mb-8">
          <div className="flex items-center gap-2 mb-4">
            <UserCheck className="w-4 h-4 text-verde-oscuro" />
            <Rotulo className="!text-verde-oscuro">Te invitaron a administrar</Rotulo>
          </div>
          <ul className="space-y-3">
            {adminInvites.map((inv) => (
              <li
                key={inv.id}
                className="flex flex-wrap items-center justify-between gap-3 bg-white border border-verde-medio rounded-xl px-4 py-3"
              >
                <p className="min-w-0">
                  <span className="font-bold text-tinta">{inv.page_title}</span>
                  <span className="text-tinta-media text-sm"> — te invitó {inv.owner_name || inv.owner_email}</span>
                </p>
                <div className="flex gap-2">
                  <Boton tamano="sm" onClick={() => respondAdminInvite(inv.id, 'accepted')}>
                    Aceptar
                  </Boton>
                  <Boton variante="fantasma" tamano="sm" onClick={() => respondAdminInvite(inv.id, 'rejected')}>
                    Rechazar
                  </Boton>
                </div>
              </li>
            ))}
          </ul>
        </Tarjeta>
      )}

      {/* El buscador se muestra siempre que haya algo que buscar, aunque la
          búsqueda actual no traiga resultados: si no, quedaría atrapado sin
          forma de borrar el término. */}
      {(paginacion.total > 0 || busqueda !== '') && (
        <div className="mb-8 flex items-center gap-4 flex-wrap">
          <div className="relative flex-1 min-w-[240px]">
            <Search className="w-4 h-4 text-tinta-suave absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none" />
            <Campo
              type="search"
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
              placeholder="Buscar por nombre o dirección"
              aria-label="Buscar páginas"
              className="pl-11"
            />
          </div>

          <span className="text-sm text-tinta-suave tabular-nums">
            {paginacion.total} {paginacion.total === 1 ? 'página' : 'páginas'}
            {busqueda !== '' && ' encontradas'}
          </span>
        </div>
      )}

      {loading ? (
        <Cargando texto="Buscando tus páginas..." />
      ) : pages.length === 0 ? (
        <Tarjeta>
          {busqueda !== '' ? (
            <Vacio
              icono={Search}
              titulo={`Ninguna página coincide con "${busqueda}"`}
              accion={
                <Boton variante="secundario" onClick={() => setBusqueda('')}>
                  Limpiar la búsqueda
                </Boton>
              }
            />
          ) : (
            <Vacio
              icono={LayoutTemplate}
              titulo="Todavía no tenés ninguna página"
              detalle="Armá la primera, cargale tus fechas y pegá el link en tu Instagram."
              accion={
                <Boton onClick={() => setShowCreateModal(true)}>
                  <Plus className="w-4 h-4" /> Crear mi primera página
                </Boton>
              }
            />
          )}
        </Tarjeta>
      ) : (
        <ul className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
          {pages.map((page) => (
            <li key={page.id}>
              <Tarjeta className="p-5 h-full flex flex-col relative">
                <div className="absolute top-4 right-4 flex items-center gap-2">
                  <PageQRDownload page={page} />
                  {pendingCollabPageIds.has(page.id) && (
                    <span
                      className="w-2.5 h-2.5 bg-amber-500 rounded-full"
                      title="Tenés colaboraciones pendientes para aprobar"
                    />
                  )}
                </div>

                {!esDuenia(page) && (
                  <Chip
                    tono={esAdministradora(page) ? 'neutro' : 'verde'}
                    className="self-start mb-3"
                  >
                    {esAdministradora(page) ? 'Administrás esta página' : 'Acceso de plataforma'}
                  </Chip>
                )}

                <h2 className="text-xl font-bold text-tinta leading-snug pr-16 text-balance">
                  {page.title}
                </h2>

                <a
                  href={`/${page.url_slug}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 text-sm text-tinta-suave hover:text-verde-oscuro transition-colors mt-1 w-fit"
                >
                  rezon.ar/{page.url_slug}
                  <ExternalLink className="w-3 h-3" />
                </a>

                {page.description && (
                  <p className="text-sm text-tinta-media mt-3 line-clamp-3 leading-relaxed">
                    {page.description}
                  </p>
                )}

                <div className="mt-auto pt-5 flex items-center gap-2">
                  <Boton a={`/page/${page.id}`} tamano="sm">
                    <Pencil className="w-3.5 h-3.5" /> Editar
                  </Boton>
                  {esDuenia(page) && (
                    <Boton variante="fantasma" tamano="sm" onClick={() => deletePage(page.id)}>
                      Eliminar
                    </Boton>
                  )}
                  {/* A la página ajena que se ve por acceso de plataforma no se
                      le ofrece "dejar de administrar": no se la administra, se
                      entra a darle soporte, y el botón no tendría qué soltar. */}
                  {!esDuenia(page) && esAdministradora(page) && (
                    <Boton variante="fantasma" tamano="sm" onClick={() => leavePage(page.id)}>
                      Dejar de administrar
                    </Boton>
                  )}
                </div>
              </Tarjeta>
            </li>
          ))}
        </ul>
      )}

      {paginacion.paginas > 1 && (
        <nav className="mt-10 flex items-center justify-center gap-3" aria-label="Paginación">
          <Boton
            variante="secundario"
            tamano="sm"
            onClick={() => irAPagina(pagina - 1)}
            disabled={pagina <= 1}
            aria-label="Página anterior"
          >
            <ChevronLeft className="w-4 h-4" />
          </Boton>

          <span className="text-sm text-tinta-suave tabular-nums">
            Página {pagina} de {paginacion.paginas}
          </span>

          <Boton
            variante="secundario"
            tamano="sm"
            onClick={() => irAPagina(pagina + 1)}
            disabled={pagina >= paginacion.paginas}
            aria-label="Página siguiente"
          >
            <ChevronRight className="w-4 h-4" />
          </Boton>
        </nav>
      )}

      <Modal
        abierto={showCreateModal}
        alCerrar={() => { setShowCreateModal(false); setError(''); }}
        titulo="Nueva página"
      >
        {error && <Aviso tipo="error" className="mb-5">{error}</Aviso>}

        <form onSubmit={createPage} className="space-y-5">
          <div>
            <Etiqueta htmlFor="nueva-pagina-titulo">Título</Etiqueta>
            <Campo
              id="nueva-pagina-titulo"
              type="text"
              value={newPage.title}
              onChange={(e) => setNewPage({ ...newPage, title: e.target.value })}
              placeholder="Tu nombre artístico, el de tu banda o el del ciclo"
              required
            />
          </div>

          <div>
            <Etiqueta htmlFor="nueva-pagina-descripcion">Descripción</Etiqueta>
            <AreaTexto
              id="nueva-pagina-descripcion"
              value={newPage.description}
              onChange={(e) => setNewPage({ ...newPage, description: e.target.value })}
              placeholder="Una línea sobre qué van a encontrar acá"
              rows="3"
            />
          </div>

          <div>
            <Etiqueta htmlFor="nueva-pagina-url">Tu dirección</Etiqueta>
            <div className="flex items-center gap-0 rounded-xl border border-borde-fuerte overflow-hidden focus-within:border-verde-oscuro transition-colors">
              <span className="pl-4 pr-0.5 text-tinta-suave text-sm select-none">rezon.ar/</span>
              <Campo
                id="nueva-pagina-url"
                type="text"
                value={newPage.url_slug}
                onChange={(e) => setNewPage({ ...newPage, url_slug: e.target.value.toLowerCase() })}
                pattern="[a-z0-9-]+"
                placeholder="tunombre"
                required
                className="!border-0 !rounded-none !pl-0 focus:!border-0"
              />
            </div>
            <p className="text-xs text-tinta-suave mt-1.5">
              Solo letras, números y guiones. Es el link que va en tu Instagram, así que
              conviene que sea corto.
            </p>
          </div>

          <div className="flex gap-3 pt-2">
            <Boton
              type="button"
              variante="secundario"
              className="flex-1"
              onClick={() => { setShowCreateModal(false); setError(''); }}
            >
              Cancelar
            </Boton>
            <Boton type="submit" className="flex-1">Crear página</Boton>
          </div>
        </form>
      </Modal>
    </Marco>
  );
}

export default MyPages;
