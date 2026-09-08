import React, { useState, useEffect, useContext, useRef } from 'react';
import { useParams, useNavigate, useSearchParams, Link } from 'react-router-dom';
import { AuthContext } from '../App';
import LoadingSpinner from '../components/LoadingSpinner';
import GooglePlacesAutocomplete from '../components/GooglePlacesAutocomplete';
import SeccionRedes from '../components/SeccionRedes';
import SeccionEntradas from '../components/SeccionEntradas';
import { analizarEmbed, portadaDe } from '../utils/embeds';
import { paleta } from '../utils/colores';
import MiniaturaPlantilla from '../components/MiniaturaPlantilla';
import Navigation from '../components/Navigation';
import { ChevronLeft, ExternalLink } from 'lucide-react';

/**
 * Los colores que se manejan desde el administrador, y qué pinta cada uno.
 *
 * Los tres primeros siempre tienen valor. Los tres últimos son opcionales:
 * vacíos se derivan de los anteriores, que es como se comportaba la página
 * antes de que se pudieran elegir. `rol` es la clave con la que paleta() los
 * resuelve, y sirve para mostrar en el selector el color que está rigiendo.
 */
const COLORES = [
  { campo: 'text_color',       etiqueta: 'Texto',    ayuda: 'Toda la tipografía' },
  { campo: 'background_color', etiqueta: 'Fondo',    ayuda: 'El fondo de la página' },
  { campo: 'primary_color',    etiqueta: 'Acento',   ayuda: 'La barra debajo de cada grupo y los detalles' },
  { campo: 'title_color',      etiqueta: 'Títulos',  rol: 'titulo',  automatico: 'igual al texto' },
  { campo: 'secondary_color',  etiqueta: 'Botones',  rol: 'boton',   automatico: 'igual al acento' },
  { campo: 'card_color',       etiqueta: 'Tarjetas', rol: 'tarjeta', automatico: 'se calcula con el fondo' },
];

/** Plantillas disponibles, en el orden en que se ofrecen. */
const PLANTILLAS = [
  { clave: 'minimal',   nombre: 'Minimal',    descripcion: 'Limpio y centrado, un bloque por link' },
  { clave: 'cards',     nombre: 'Cards',      descripcion: 'Tarjetas con sombra y miniatura' },
  { clave: 'modern',    nombre: 'Modern',     descripcion: 'Portada ancha arriba, texto a la izquierda' },
  { clave: 'condensed', nombre: 'Condensado', descripcion: 'Lista compacta de 2 líneas' },
  { clave: 'afiches',   nombre: 'Afiches',    descripcion: 'La imagen es la ficha, con marco de color' },
];

/** Secciones del editor, en el orden en que se muestran. */
const SECCIONES = [
  { clave: 'general',   etiqueta: 'Configuración' },
  { clave: 'contenido', etiqueta: 'Contenido' },
  { clave: 'redes',     etiqueta: 'Redes sociales' },
  { clave: 'entradas',  etiqueta: 'Entradas' },
  { clave: 'admins',    etiqueta: 'Administradores', soloDueno: true },
];

function PageEditor() {
  const { id } = useParams();
  const { token, apiUrl, user } = useContext(AuthContext);
  const navigate = useNavigate();
  // La solapa abierta va en la URL (?s=). Así, al volver de editar un item
  // —con el botón del editor o con el "atrás" del navegador— se cae en la
  // misma sección y no en CONFIGURACIÓN.
  const [searchParams, setSearchParams] = useSearchParams();
  const seccionEnUrl = searchParams.get('s');
  const seccion = SECCIONES.some(x => x.clave === seccionEnUrl) ? seccionEnUrl : 'general';
  const setSeccion = (clave) => setSearchParams(clave === 'general' ? {} : { s: clave });
  const [page, setPage] = useState(null);
  const [loading, setLoading] = useState(true);
  const [globalLoading, setGlobalLoading] = useState(false);
  const [showGroupModal, setShowGroupModal] = useState(false);
  const [showEditGroupModal, setShowEditGroupModal] = useState(false);
  const [showLinkModal, setShowLinkModal] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState(null);
  const [editingGroup, setEditingGroup] = useState(null);
  const [newGroup, setNewGroup] = useState({ title: '', type: 'links' });
  // Qué se está cargando en un grupo de galería: imagen, youtube o instagram.
  const [tipoMedia, setTipoMedia] = useState('imagen');
  const [newLink, setNewLink] = useState({
    url: '',
    url_text: '',
    embed_url: '',
    text: '',
    image_url: '',
    description: '',
    event_date: '',
    event_time: '',
    event_address: '',
    event_latitude: null,
    event_longitude: null,
    event_maps_url: ''
  });
  const [uploadingLinkImage, setUploadingLinkImage] = useState(false);

  // Collaborations state
  const [pendingCollaborations, setPendingCollaborations] = useState([]);
  const [showCollabAcceptModal, setShowCollabAcceptModal] = useState(false);
  const [acceptingCollab, setAcceptingCollab] = useState(null);
  const [collabAcceptGroupId, setCollabAcceptGroupId] = useState('');

  // Page admins state
  const [admins, setAdmins] = useState([]);
  const [inviteEmail, setInviteEmail] = useState('');
  const [adminError, setAdminError] = useState('');
  const [adminMsg, setAdminMsg] = useState('');
  const [invitingAdmin, setInvitingAdmin] = useState(false);

  // El dominio propio se guarda aparte: la API lo puede rechazar —mal escrito,
  // o ya tomado por otra página— y updatePage descarta los errores en silencio.
  const [dominioError, setDominioError] = useState('');
  // Cambiar el usuario de una página rompe todo lo que apunte a la dirección
  // anterior, así que es una operación de soporte: el servidor sólo la acepta
  // de quien administra la plataforma y acá el campo se abre para esa persona.
  const [esPlataforma, setEsPlataforma] = useState(false);
  const [usuarioError, setUsuarioError] = useState('');
  // El usuario tal como quedó guardado. Sirve para dos cosas: no mandar un
  // cambio cuando no lo hubo —el campo guarda al salir— y volver a él si el
  // servidor rechaza el nuevo.
  const usuarioGuardado = useRef('');

  // ¿El usuario actual es el dueño de la página? (gestionar admins es solo del dueño)
  const isOwner = page && user && Number(page.user_id) === Number(user.id);

  // Los colores ya resueltos, para poder mostrar en el selector el que rige
  // cuando el campo está en automático.
  const colores = paleta(page);

  // Se muestra en el submenú para que las colaboraciones pendientes no queden
  // escondidas dentro de una solapa que el usuario no abrió.
  const pendientesDeColaborar = pendingCollaborations.filter(c => c.collaborator_page_id == id).length;

  useEffect(() => {
    fetchPage();
  }, [id]);

  useEffect(() => {
    if (page) loadPendingCollaborations();
  }, [page?.id]);

  useEffect(() => {
    if (isOwner) loadAdmins();
  }, [isOwner, page?.id]);

  const loadAdmins = async () => {
    try {
      const response = await fetch(`${apiUrl}/admins/index.php?page_id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (response.ok) {
        const data = await response.json();
        setAdmins(data.admins || []);
      }
    } catch (err) {
      console.error('Error loading admins:', err);
    }
  };

  const inviteAdmin = async (e) => {
    e.preventDefault();
    setAdminError('');
    setAdminMsg('');
    if (!inviteEmail.trim()) return;
    setInvitingAdmin(true);
    try {
      const response = await fetch(`${apiUrl}/admins/index.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ page_id: Number(id), email: inviteEmail.trim() })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Error al invitar');
      setInviteEmail('');
      setAdminMsg('Invitación enviada a ' + (data.admin?.user_email || ''));
      loadAdmins();
    } catch (err) {
      setAdminError(err.message);
    } finally {
      setInvitingAdmin(false);
    }
  };

  const removeAdmin = async (adminId) => {
    if (!confirm('¿Quitar a este administrador?')) return;
    try {
      await fetch(`${apiUrl}/admins/detail.php?id=${adminId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      loadAdmins();
    } catch (err) {
      console.error('Error removing admin:', err);
    }
  };

  const fetchPage = async () => {
    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      if (response.ok) {
        setPage(data.page);
        setEsPlataforma(Boolean(data.es_plataforma));
        usuarioGuardado.current = data.page.url_slug;
      }
    } catch (err) {
      console.error('Error fetching page:', err);
    } finally {
      setLoading(false);
      setGlobalLoading(false);
    }
  };

  const updatePage = async (updates) => {
    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(updates)
      });
      const data = await response.json();
      if (response.ok) {
        // Sobre el estado actual y no sobre la copia que cerró esta función:
        // si no, dos guardados seguidos se pisan y el segundo revierte al
        // primero. Se veía al cambiar un color: no se refrescaba.
        setPage((actual) => ({ ...actual, ...data.page }));
      }
    } catch (err) {
      console.error('Error updating page:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  /**
   * Guarda el dominio propio y muestra lo que conteste la API.
   *
   * Se guarda lo normalizado que devuelve el servidor: si el campo quedara con
   * lo que se escribió —con https:// o con www— parecería que se guardó otra
   * cosa de la que efectivamente se va a comparar contra cada visita.
   */
  /** Guarda un color. null vuelve al valor derivado. */
  const guardarColor = (campo, valor) => {
    setPage({ ...page, [campo]: valor });
    updatePage({ [campo]: valor });
  };

  const guardarUsuario = async (valor) => {
    setUsuarioError('');

    if (valor === usuarioGuardado.current) return;

    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ url_slug: valor })
      });
      const data = await response.json();

      if (!response.ok) {
        setUsuarioError(data.error || 'No pudimos guardar el usuario');
        // Se vuelve al que está guardado: dejar en pantalla uno que no se
        // aceptó haría creer que la dirección cambió.
        setPage((actual) => ({ ...actual, url_slug: usuarioGuardado.current }));
        return;
      }

      usuarioGuardado.current = data.page.url_slug;
      setPage((actual) => ({ ...actual, url_slug: data.page.url_slug }));
    } catch (err) {
      setUsuarioError('No pudimos guardar el usuario');
    } finally {
      setGlobalLoading(false);
    }
  };

  const guardarDominio = async (valor) => {
    setDominioError('');

    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ dominio: valor || '' })
      });
      const data = await response.json();

      if (!response.ok) {
        setDominioError(data.error || 'No pudimos guardar el dominio');
        return;
      }

      setPage((actual) => ({ ...actual, dominio: data.page ? data.page.dominio : null }));
    } catch (err) {
      setDominioError('No pudimos guardar el dominio');
    } finally {
      setGlobalLoading(false);
    }
  };

  const uploadImage = async (file) => {
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
      alert('La imagen es muy grande. Máximo 5MB');
      return null;
    }

    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
      alert('Tipo de archivo no válido. Solo JPG, PNG, GIF y WebP');
      return null;
    }

    const formData = new FormData();
    formData.append('image', file);

    try {
      const response = await fetch(`${apiUrl}/upload/image.php`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`
        },
        body: formData
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Error al subir imagen');
      }

      return data.url;
    } catch (err) {
      console.error('Error uploading image:', err);
      alert('Error al subir imagen: ' + err.message);
      return null;
    }
  };

  const handleProfileImageUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const url = await uploadImage(file);
    if (url) {
      updatePage({ profile_image: url });
    }
  };

  const handleBackgroundImageUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const url = await uploadImage(file);
    if (url) {
      updatePage({ background_image: url });
    }
  };

  const handleLinkImageUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    setUploadingLinkImage(true);
    const url = await uploadImage(file);
    setUploadingLinkImage(false);

    if (url) {
      setNewLink({ ...newLink, image_url: url });
    }
  };

  const createGroup = async (e) => {
    e.preventDefault();
    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/groups/index.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ ...newGroup, page_id: id })
      });
      if (response.ok) {
        setShowGroupModal(false);
        setNewGroup({ title: '', type: 'links' });
        fetchPage();
      }
    } catch (err) {
      console.error('Error creating group:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const updateGroup = async (e) => {
    e.preventDefault();
    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/groups/detail.php?id=${editingGroup.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ title: editingGroup.title })
      });
      if (response.ok) {
        setShowEditGroupModal(false);
        setEditingGroup(null);
        fetchPage();
      }
    } catch (err) {
      console.error('Error updating group:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const deleteGroup = async (groupId) => {
    if (!confirm('¿Eliminar este grupo?')) return;
    try {
      setGlobalLoading(true);
      await fetch(`${apiUrl}/groups/detail.php?id=${groupId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      fetchPage();
    } catch (err) {
      console.error('Error deleting group:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const createLink = async (e) => {
    e.preventDefault();

    if (selectedGroup.type === 'galeria' && tipoMedia !== 'imagen') {
      const embed = analizarEmbed(newLink.embed_url);
      if (!embed || embed.tipo !== tipoMedia) {
        alert(tipoMedia === 'youtube'
          ? 'Ese link no parece un video de YouTube'
          : 'Ese link no parece un post, reel o carrusel de Instagram');
        return;
      }
    }

    if (selectedGroup.type === 'eventos') {
      if (!newLink.event_latitude || !newLink.event_longitude) {
        alert('Debes seleccionar una dirección válida de Google Maps para el evento');
        return;
      }
    }

    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/links/index.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ ...newLink, group_id: selectedGroup.id })
      });
      if (response.ok) {
        setShowLinkModal(false);
        setNewLink({
          url: '',
          url_text: '',
          embed_url: '',
          text: '',
          image_url: '',
          description: '',
          event_date: '',
          event_time: '',
          event_address: '',
          event_latitude: null,
          event_longitude: null,
          event_maps_url: ''
        });
        setSelectedGroup(null);
        fetchPage();
      }
    } catch (err) {
      console.error('Error creating link:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const deleteLink = async (linkId) => {
    if (!confirm('¿Eliminar este link?')) return;
    try {
      setGlobalLoading(true);
      await fetch(`${apiUrl}/links/detail.php?id=${linkId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      fetchPage();
    } catch (err) {
      console.error('Error deleting link:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const moveGroup = async (groupId, direction) => {
    const groups = [...page.groups];
    const index = groups.findIndex(g => g.id === groupId);

    if (direction === 'up' && index > 0) {
      [groups[index], groups[index - 1]] = [groups[index - 1], groups[index]];
    } else if (direction === 'down' && index < groups.length - 1) {
      [groups[index], groups[index + 1]] = [groups[index + 1], groups[index]];
    } else {
      return;
    }

    try {
      setGlobalLoading(true);
      for (let i = 0; i < groups.length; i++) {
        await fetch(`${apiUrl}/groups/detail.php?id=${groups[i].id}`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({ position: i })
        });
      }
      fetchPage();
    } catch (err) {
      console.error('Error reordering groups:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const moveLink = async (linkId, groupId, direction) => {
    const group = page.groups.find(g => g.id === groupId);
    const links = [...group.links];
    const index = links.findIndex(l => l.id === linkId);

    if (direction === 'up' && index > 0) {
      [links[index], links[index - 1]] = [links[index - 1], links[index]];
    } else if (direction === 'down' && index < links.length - 1) {
      [links[index], links[index + 1]] = [links[index + 1], links[index]];
    } else {
      return;
    }

    try {
      setGlobalLoading(true);
      for (let i = 0; i < links.length; i++) {
        await fetch(`${apiUrl}/links/detail.php?id=${links[i].id}`, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
          },
          body: JSON.stringify({ position: i })
        });
      }
      fetchPage();
    } catch (err) {
      console.error('Error reordering links:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const openEditGroupModal = (group) => {
    setEditingGroup({ ...group });
    setShowEditGroupModal(true);
  };

  const loadPendingCollaborations = async () => {
    try {
      const response = await fetch(`${apiUrl}/collaborations/index.php?type=pending`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (response.ok) {
        const data = await response.json();
        setPendingCollaborations(data.pending || []);
      }
    } catch (err) {
      console.error('Error loading collaborations:', err);
    }
  };

  const removeCollaboration = async (collabId) => {
    if (!confirm('¿Quitar esta colaboración?')) return;
    try {
      setGlobalLoading(true);
      await fetch(`${apiUrl}/collaborations/detail.php?id=${collabId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      fetchPage();
      loadPendingCollaborations();
    } catch (err) {
      console.error('Error removing collaboration:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const openAcceptCollabModal = (collab) => {
    setAcceptingCollab(collab);
    const eventGroups = page?.groups?.filter(g => g.type === 'eventos') || [];
    setCollabAcceptGroupId(eventGroups.length === 1 ? String(eventGroups[0].id) : '');
    setShowCollabAcceptModal(true);
  };

  const acceptCollaboration = async (e) => {
    e.preventDefault();
    if (!collabAcceptGroupId) { alert('Debes seleccionar un grupo de eventos'); return; }
    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/collaborations/detail.php?id=${acceptingCollab.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ status: 'accepted', group_id: parseInt(collabAcceptGroupId) })
      });
      const data = await response.json();
      if (response.ok) {
        setShowCollabAcceptModal(false);
        setAcceptingCollab(null);
        setCollabAcceptGroupId('');
        loadPendingCollaborations();
        fetchPage();
      } else {
        alert(data.error || 'Error al aceptar');
      }
    } catch (err) {
      console.error('Error accepting collaboration:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const rejectCollaboration = async (collab) => {
    if (!confirm('¿Rechazar esta invitación?')) return;
    try {
      setGlobalLoading(true);
      await fetch(`${apiUrl}/collaborations/detail.php?id=${collab.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ status: 'rejected' })
      });
      loadPendingCollaborations();
    } catch (err) {
      console.error('Error rejecting collaboration:', err);
    } finally {
      setGlobalLoading(false);
    }
  };

  const handlePlaceSelect = (placeData) => {
    setNewLink({
      ...newLink,
      event_address: placeData.address,
      event_latitude: placeData.latitude,
      event_longitude: placeData.longitude,
      event_maps_url: placeData.mapsUrl
    });
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <LoadingSpinner message="Cargando página..." />
      </div>
    );
  }

  if (!page) {
    return <div className="flex items-center justify-center min-h-screen">Página no encontrada</div>;
  }

  return (
    <div className="min-h-screen bg-white text-tinta">
      {globalLoading && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center z-50">
          <div className="bg-white border border-borde rounded-2xl p-6">
            <LoadingSpinner />
          </div>
        </div>
      )}

      <Navigation />

      <div className="max-w-7xl mx-auto px-5 sm:px-6 py-10 sm:py-14">
        <header className="flex flex-wrap items-end justify-between gap-4 mb-8">
          <div className="min-w-0">
            <Link
              to="/my-pages"
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-tinta-media hover:text-tinta transition-colors"
            >
              <ChevronLeft className="w-4 h-4" /> Mis páginas
            </Link>
            <h1 className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-balance">
              {page.title}
            </h1>
            <p className="text-tinta-suave mt-1">rezon.ar/{page.url_slug}</p>
          </div>

          <a
            href={`/${page.url_slug}`}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
          >
            Ver mi página <ExternalLink className="w-4 h-4" />
          </a>
        </header>

        {/* Secciones del editor. Antes todo se apilaba en una sola columna y
            había que bajar mucho para llegar a los administradores. */}
        <nav className="flex flex-wrap gap-2 mb-8 border-b border-borde">
          {SECCIONES.filter(s => !s.soloDueno || isOwner).map((s) => (
            <button
              key={s.clave}
              onClick={() => setSeccion(s.clave)}
              className={`px-5 py-3 font-bold text-sm tracking-wide transition border-b-2 -mb-px flex items-center gap-2 ${
                seccion === s.clave
                  ? 'border-verde text-tinta'
                  : 'border-transparent text-tinta-suave hover:text-tinta-media'
              }`}
            >
              {s.etiqueta}
              {s.clave === 'contenido' && pendientesDeColaborar > 0 && (
                <span className="bg-amber-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                  {pendientesDeColaborar}
                </span>
              )}
            </button>
          ))}
        </nav>

        {seccion === 'general' && (
          <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
            <h2 className="text-2xl font-bold mb-8 tracking-tight">Configuración</h2>

            <div className="grid md:grid-cols-2 gap-6 mb-6">
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Título</label>
                <input
                  type="text"
                  value={page.title}
                  onChange={(e) => setPage({ ...page, title: e.target.value })}
                  onBlur={() => updatePage({ title: page.title })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                />
              </div>

              <div>
                <label htmlFor="usuario-pagina" className="block text-sm font-semibold text-tinta mb-1.5">
                  Usuario
                </label>
                <div className={`flex items-center rounded-xl border overflow-hidden transition-colors ${
                  esPlataforma
                    ? 'bg-white border-borde-fuerte focus-within:border-verde-oscuro'
                    : 'bg-papel-hueso border-borde'
                }`}>
                  <span className="pl-4 text-sm text-tinta-suave select-none">rezon.ar/</span>
                  <input
                    id="usuario-pagina"
                    type="text"
                    value={page.url_slug}
                    disabled={!esPlataforma}
                    onChange={(e) => setPage({ ...page, url_slug: e.target.value.toLowerCase() })}
                    onBlur={(e) => guardarUsuario(e.target.value)}
                    className={`flex-1 min-w-0 px-1 py-3 bg-transparent border-0 focus:outline-none ${
                      esPlataforma ? 'text-tinta' : 'text-tinta-suave'
                    }`}
                  />
                </div>
                {usuarioError ? (
                  <p className="text-xs text-red-700 mt-2">{usuarioError}</p>
                ) : esPlataforma ? (
                  <p className="text-xs text-tinta-suave mt-2">
                    Cambiarlo rompe los links que ya circulan: el de la bio de Instagram, los QR
                    impresos, lo que se haya compartido. Avisale a quien maneja la página.
                  </p>
                ) : (
                  <p className="text-xs text-tinta-suave mt-2">
                    La dirección de tu página. No se puede cambiar desde acá: escribinos y la movemos.
                  </p>
                )}
              </div>
            </div>

            <div className="mb-6">
              <label htmlFor="dominio-propio" className="block text-sm font-semibold text-tinta mb-1.5">
                Dominio propio (opcional)
              </label>
              <input
                id="dominio-propio"
                type="text"
                value={page.dominio || ''}
                onChange={(e) => setPage({ ...page, dominio: e.target.value })}
                onBlur={() => guardarDominio(page.dominio)}
                placeholder="maxipeque.com"
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
              />
              {dominioError ? (
                <p className="text-xs text-red-700 mt-2">{dominioError}</p>
              ) : (
                <p className="text-xs text-tinta-suave mt-2">
                  Puedes cargar tu dominio aca si quieres que tu pagina de rezonar
                  tenga tu dominio propio. Para esto te debes comunicar antes con
                  cjsuther@gmail.com y contarle que lo quieres hacer
                </p>
              )}
            </div>

            <div className="mb-6">
              <label className="block text-sm font-semibold text-tinta mb-1.5">Descripción</label>
              <textarea
                value={page.description}
                onChange={(e) => setPage({ ...page, description: e.target.value })}
                onBlur={() => updatePage({ description: page.description })}
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                rows="3"
              />
            </div>

            <div className="grid md:grid-cols-2 gap-6 mb-6">
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Imagen de perfil</label>
                <div className="flex flex-wrap items-center gap-4">
                  {page.profile_image && (
                    <div className="relative">
                      <img src={page.profile_image} alt="Perfil" className="w-20 h-20 object-cover rounded-full" />
                      <button
                        onClick={() => {
                          setPage({ ...page, profile_image: null });
                          updatePage({ profile_image: null });
                        }}
                        className="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-700"
                      >
                        ×
                      </button>
                    </div>
                  )}
                  <input
                    type="file"
                    accept="image/*"
                    onChange={handleProfileImageUpload}
                    className="text-sm text-tinta-media max-w-full min-w-0"
                  />
                </div>
                <p className="text-xs text-tinta-suave mt-1">JPG, PNG, GIF o WebP. Máximo 5MB</p>
              </div>

              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Imagen de fondo</label>
                <div className="flex flex-wrap items-center gap-4">
                  {page.background_image && (
                    <div className="relative">
                      <img src={page.background_image} alt="Fondo" className="w-20 h-20 object-cover rounded" />
                      <button
                        onClick={() => {
                          setPage({ ...page, background_image: null });
                          updatePage({ background_image: null });
                        }}
                        className="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-700"
                      >
                        ×
                      </button>
                    </div>
                  )}
                  <input
                    type="file"
                    accept="image/*"
                    onChange={handleBackgroundImageUpload}
                    className="text-sm text-tinta-media max-w-full min-w-0"
                  />
                </div>
                <p className="text-xs text-tinta-suave mt-1">JPG, PNG, GIF o WebP. Máximo 5MB</p>
              </div>
            </div>

            <div className="grid grid-cols-2 md:grid-cols-3 gap-6 mb-6">
              {COLORES.map((c) => {
                const elegido = page[c.campo];
                // El selector muestra el color que rige: si no se eligió, el
                // derivado. Un selector en negro cuando la página se ve azul
                // sería mentir sobre lo que está pasando.
                const vigente = elegido || (c.rol ? colores[c.rol] : '#000000');
                // En automático el selector queda deshabilitado: si se pudiera
                // arrastrar, moverlo y mover el acento se verían igual y no
                // habría forma de saber si están atados.
                const enAutomatico = Boolean(c.rol) && !elegido;

                return (
                  <div key={c.campo}>
                    <label htmlFor={`color-${c.campo}`} className="block text-sm font-semibold text-tinta mb-1.5">
                      Color de {c.etiqueta.toLowerCase()}
                    </label>
                    {/* Nunca deshabilitado: un input de color deshabilitado no
                        abre el selector del sistema, y el control queda muerto.
                        Que esté en automático se dice con el borde punteado y
                        el texto de abajo; elegir un color lo separa. */}
                    <input
                      id={`color-${c.campo}`}
                      type="color"
                      value={vigente}
                      onChange={(e) => guardarColor(c.campo, e.target.value)}
                      className={`w-full h-10 rounded-lg cursor-pointer ${
                        enAutomatico ? 'ring-2 ring-borde-fuerte' : ''
                      }`}
                    />
                    {c.rol ? (
                      enAutomatico ? (
                        <p className="text-xs text-tinta-suave mt-2">
                          Automático: {c.automatico}. Elegí uno para separarlo.
                        </p>
                      ) : (
                        <button
                          type="button"
                          onClick={() => guardarColor(c.campo, null)}
                          className="text-xs text-tinta-suave hover:text-tinta transition mt-2"
                        >
                          Volver al automático
                        </button>
                      )
                    ) : (
                      <p className="text-xs text-tinta-suave mt-2">{c.ayuda}</p>
                    )}
                  </div>
                );
              })}
            </div>

            <div className="mt-8 pt-8 border-t border-borde">
              <label className="block text-sm font-bold text-tinta-media mb-4 tracking-wide">Template de diseño</label>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {PLANTILLAS.map((p) => {
                  const elegida = (page.template || 'minimal') === p.clave;
                  return (
                    <button
                      key={p.clave}
                      onClick={() => {
                        setPage({ ...page, template: p.clave });
                        updatePage({ template: p.clave });
                      }}
                      aria-pressed={elegida}
                      className={`p-3 border-2 text-left transition ${
                        elegida ? 'border-white bg-papel-hueso' : 'border-borde-fuerte hover:border-borde-fuerte'
                      }`}
                    >
                      <MiniaturaPlantilla plantilla={p.clave} page={page} />
                      <div className="font-bold mt-3 text-tinta">{p.nombre}</div>
                      <div className="text-xs text-tinta-suave">{p.descripcion}</div>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>
        )}

        {seccion === 'redes' && (
          <SeccionRedes
            socials={page.socials || []}
            guardando={globalLoading}
            onGuardar={(socials) => updatePage({ socials })}
          />
        )}

        {seccion === 'entradas' && (
          <SeccionEntradas
            pageId={id}
            apiUrl={apiUrl}
            token={token}
            emailContacto={page.email_contacto || ''}
            onGuardarContacto={(email) => {
              setPage({ ...page, email_contacto: email });
              updatePage({ email_contacto: email });
            }}
          />
        )}

        {seccion === 'admins' && isOwner && (
            <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
              <h2 className="text-2xl font-bold mb-2 tracking-tight">Administradores</h2>
              <p className="text-sm text-tinta-suave mb-6">
                Invitá a otros usuarios (por email) a administrar esta página. Pueden editar contenido y ajustes, pero no borrar la página ni gestionar administradores.
              </p>

              <form onSubmit={inviteAdmin} className="flex flex-col sm:flex-row gap-3 mb-4">
                <input
                  type="email"
                  value={inviteEmail}
                  onChange={(e) => setInviteEmail(e.target.value)}
                  placeholder="email@ejemplo.com"
                  className="flex-1 px-4 py-3 bg-white border border-borde-fuerte text-tinta focus:border-verde-oscuro transition"
                  required
                />
                <button
                  type="submit"
                  disabled={invitingAdmin}
                  className="inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors disabled:opacity-50"
                >
                  {invitingAdmin ? 'Invitando...' : 'Invitar'}
                </button>
              </form>

              {adminError && <p className="text-red-700 text-sm mb-4">{adminError}</p>}
              {adminMsg && <p className="text-verde-oscuro text-sm mb-4">{adminMsg}</p>}

              {admins.length === 0 ? (
                <p className="text-tinta-suave text-sm">Todavía no invitaste a nadie.</p>
              ) : (
                <div className="space-y-2">
                  {admins.map((a) => (
                    <div key={a.id} className="flex items-center justify-between bg-white border border-borde px-4 py-3">
                      <div>
                        <span className="font-medium text-tinta">{a.user_name || a.user_email}</span>
                        {a.user_name && <span className="text-tinta-suave text-sm"> · {a.user_email}</span>}
                        <span className={`ml-3 px-2 py-0.5 text-xs rounded-full ${a.status === 'accepted' ? 'bg-verde-claro text-verde-oscuro border border-verde-medio' : 'bg-amber-50 text-amber-800 border border-amber-200'}`}>
                          {a.status === 'accepted' ? 'Administrador' : 'Pendiente'}
                        </span>
                      </div>
                      <button
                        onClick={() => removeAdmin(a.id)}
                        className="text-red-700 hover:text-red-700 text-sm font-bold transition"
                      >
                        {a.status === 'accepted' ? 'Quitar' : 'Cancelar'}
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
        )}

        {seccion === 'contenido' && (
          <>
            {pendingCollaborations.filter(c => c.collaborator_page_id == id).length > 0 && (
              <div className="bg-white border border-amber-200 p-8 mb-8">
                <h2 className="text-2xl font-bold tracking-tight mb-6 text-amber-700">Colaboraciones pendientes</h2>
                <div className="space-y-4">
                  {pendingCollaborations.filter(c => c.collaborator_page_id == id).map((collab) => (
                    <div key={collab.id} className="bg-white border border-borde rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-4">
                      <div className="flex items-center gap-3 flex-1">
                        {collab.requester_page_image && (
                          <img src={collab.requester_page_image} alt={collab.requester_page_title} className="w-10 h-10 rounded-full object-cover flex-shrink-0" />
                        )}
                        <div>
                          <p className="font-bold text-tinta">{collab.requester_page_title}</p>
                          <p className="text-sm text-tinta-media">te invita a colaborar en <span className="text-tinta">{collab.event_title}</span></p>
                          {collab.event_date && (
                            <p className="text-xs text-tinta-suave mt-1">
                              {new Date(collab.event_date + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })}
                              {collab.event_time && ' · ' + collab.event_time}
                            </p>
                          )}
                          <p className="text-xs text-tinta-suave mt-1">Para tu página: <span className="text-tinta-media">{collab.collaborator_page_title}</span></p>
                        </div>
                      </div>
                      <div className="flex gap-2 flex-shrink-0">
                        <button
                          onClick={() => openAcceptCollabModal(collab)}
                          className="rounded-full px-4 py-2 bg-verde text-verde-tinta text-sm font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
                        >
                          Aceptar
                        </button>
                        <button
                          onClick={() => rejectCollaboration(collab)}
                          className="px-4 py-2 bg-papel-hueso text-red-700 text-sm font-bold hover:bg-papel-hueso transition"
                        >
                          Rechazar
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            <div className="bg-white border border-borde rounded-2xl p-6 sm:p-8 mb-8">
              <div className="flex justify-between items-center mb-4">
                <h2 className="text-2xl font-bold tracking-tight">Grupos de links</h2>
                <button
                  onClick={() => setShowGroupModal(true)}
                  className="inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
                >
                  + NUEVO GRUPO
                </button>
              </div>

              {/* El contenido de una página vive dentro de un grupo, y el tipo
                  del grupo decide cómo se ve. Es lo primero que hay que
                  entender para cargar algo, y no estaba dicho en ninguna
                  parte: se creaba un grupo de links y después no se podía
                  poner una fecha. */}
              <div className="border border-borde bg-papel-hueso rounded-xl p-5 mb-8">
                <p className="text-sm text-tinta-media mb-3">
                  Todo lo que publicás va adentro de un grupo, y el tipo que elijas define
                  cómo se muestra. Creá uno por cada clase de contenido:
                </p>
                <ul className="text-sm text-tinta-suave space-y-1.5">
                  <li>
                    <strong className="text-tinta-media">Links —</strong> una lista de enlaces
                    con su descripción e imagen.
                  </li>
                  <li>
                    <strong className="text-tinta-media">Eventos —</strong> con fecha, hora y
                    dirección. Es el único que aparece en la agenda, en el mapa y en el
                    buscador, y el único que puede vender entradas.
                  </li>
                  <li>
                    <strong className="text-tinta-media">Galería —</strong> una cuadrícula de
                    imágenes, videos de YouTube y contenido de Instagram, que se abren
                    en grande.
                  </li>
                </ul>
                <p className="text-xs text-tinta-suave mt-3">
                  El tipo se elige al crear el grupo y conviene acertarlo: un evento cargado
                  como link no tiene dónde poner la fecha. Tus redes sociales no van acá,
                  se cargan en la sección Redes Sociales.
                </p>
              </div>

              {page.groups && page.groups.length === 0 ? (
                <p className="text-tinta-suave text-center py-8">No hay grupos todavía</p>
              ) : (
                <div className="space-y-6">
                  {page.groups?.map((group, index) => (
                    <div key={group.id} className="bg-white rounded-lg shadow-md p-6">
                      {/* En mobile las acciones van debajo del título. Antes
                          compartían fila: un título largo empujaba los botones
                          fuera de la pantalla y no había forma de llegar a
                          ellos. min-w-0 es lo que permite que el título se
                          recorte en vez de estirar la fila. */}
                      <div className="flex flex-col gap-3 md:flex-row md:justify-between md:items-center mb-4">
                        <div className="flex items-center gap-3 min-w-0">
                          <h3 className="text-xl font-semibold truncate">{group.title}</h3>
                          <span className={`px-2 py-1 text-xs rounded-full ${group.type === 'galeria' ? 'bg-papel-hueso text-tinta-media border border-borde' :
                              group.type === 'eventos' ? 'bg-orange-100 text-orange-700' :
                                  'bg-papel-hueso text-tinta-media border border-borde'
                            }`}>
                            {group.type === 'galeria' ? 'Galería' :
                              group.type === 'eventos' ? 'Eventos' :
                                  'Links'}
                          </span>
                        </div>
                        <div className="flex flex-wrap gap-2 items-center shrink-0">
                          <button
                            onClick={() => moveGroup(group.id, 'up')}
                            disabled={index === 0}
                            className="px-3 py-1 rounded-lg hover:bg-papel-hueso transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                            title="Mover arriba"
                          >
                            ↑
                          </button>
                          <button
                            onClick={() => moveGroup(group.id, 'down')}
                            disabled={index === page.groups.length - 1}
                            className="px-3 py-1 rounded-lg hover:bg-papel-hueso transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                            title="Mover abajo"
                          >
                            ↓
                          </button>

                          <div className="flex flex-wrap gap-2">
                            <button
                              onClick={() => openEditGroupModal(group)}
                              className="text-verde-oscuro hover:bg-verde-claro px-3 py-1 rounded-lg transition text-sm"
                            >
                              Editar Título
                            </button>
                            <button
                              onClick={() => {
                                setSelectedGroup(group);
                                setTipoMedia('imagen');
                                setShowLinkModal(true);
                              }}
                              className="rounded-full bg-verde text-verde-tinta px-3 py-1 hover:bg-verde-oscuro hover:text-white transition-colors text-sm"
                            >
                              {group.type === 'galeria' ? '+ Contenido' :
                                group.type === 'eventos' ? '+ Evento' :
                                  '+ Link'}
                            </button>
                            <button
                              onClick={() => deleteGroup(group.id)}
                              className="bg-red-50 text-red-600 px-3 py-1 rounded-lg hover:bg-red-100 transition text-sm"
                            >
                              Eliminar
                            </button>
                          </div>

                        </div>
                      </div>

                      {group.links && group.links.length === 0 && (!group.collaborated_events || group.collaborated_events.length === 0) ? (
                        <p className="text-tinta-media text-sm">No hay links en este grupo</p>
                      ) : (
                        <div className="space-y-2">
                          {(group.type === 'eventos'
                            ? [
                                ...group.links.map(l => ({ ...l, _isCollaboration: false })),
                                ...(group.collaborated_events || []).map(e => ({ ...e, _isCollaboration: true }))
                              ].sort((a, b) => {
                                const dateA = new Date((a.event_date || '9999-12-31') + ' ' + (a.event_time || '00:00'));
                                const dateB = new Date((b.event_date || '9999-12-31') + ' ' + (b.event_time || '00:00'));
                                return dateA - dateB;
                              })
                            : group.links
                          )?.map((link, linkIndex) => (
                            link._isCollaboration ? (
                            <div key={`collab-${link.collaboration_id}`} className="flex items-center gap-4 p-3 bg-papel-hueso rounded-lg border border-amber-200">
                              {link.image_url && (
                                <img src={link.image_url} alt={link.text} className="w-12 h-12 object-cover rounded" />
                              )}
                              <div className="flex-1">
                                <div className="flex items-center gap-2 mb-1">
                                  <span className="text-xs bg-amber-50 text-orange-300 px-2 py-0.5 rounded font-medium">Colaboración</span>
                                  {link.source_page_image && <img src={link.source_page_image} alt={link.source_page_title} className="w-4 h-4 rounded-full object-cover" />}
                                  <span className="text-xs text-tinta-media">{link.source_page_title}</span>
                                </div>
                                <p className="text-tinta font-medium">{link.text}</p>
                                {link.event_date && (
                                  <p className="text-xs text-tinta-suave mt-0.5">
                                    {new Date(link.event_date + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })}
                                    {link.event_time && ' · ' + link.event_time}
                                  </p>
                                )}
                                {link.event_due == '1' && (
                                  <p className="text-sm text-red-700 font-semibold mt-1">¡Evento vencido!</p>
                                )}
                              </div>
                              <button
                                onClick={() => removeCollaboration(link.collaboration_id)}
                                className="text-red-700 hover:bg-red-50 px-3 py-1 rounded transition text-sm flex-shrink-0"
                              >
                                Quitar
                              </button>
                            </div>
                            ) : (
                            <div
                              key={link.id}
                              className="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4 p-3 bg-papel-hueso rounded-xl border border-borde hover:bg-papel-hueso"
                            >
                              {portadaDe(link) && (
                                <img
                                  src={portadaDe(link)}
                                  alt={link.text}
                                  className="w-12 h-12 object-cover rounded"
                                />
                              )}
                              <div className="flex-1 min-w-0">
                                <Link
                                  to={`/page/${id}/item/${link.id}`}
                                  className="text-verde-oscuro hover:underline font-medium text-left"
                                >
                                  {link.text || (group.type === 'galeria' ? 'Sin título' : link.url)}
                                </Link>
                                {analizarEmbed(link.embed_url) && (
                                  <span className="ml-2 text-xs px-2 py-0.5 rounded bg-papel-hueso text-tinta-media">
                                    {analizarEmbed(link.embed_url).tipo === 'youtube' ? 'YouTube' : 'Instagram'}
                                  </span>
                                )}
                                {link.description && (
                                  <p className="text-sm text-tinta-suave">{link.description}</p>
                                )}
                                {link.event_due == '1' && (
                                  <p className="text-sm text-red-600 font-semibold mt-1">¡Evento vencido!</p>
                                )}
                                {group.type === 'eventos' && link.collaborations && link.collaborations.length > 0 && (
                                  <div className="flex flex-wrap gap-1 mt-2">
                                    {link.collaborations.map(c => (
                                      <span
                                        key={c.id}
                                        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${
                                          c.status === 'accepted' ? 'bg-verde-claro text-verde-oscuro border border-verde-medio' :
                                          c.status === 'rejected' ? 'bg-red-100 text-red-600' :
                                          'bg-yellow-100 text-yellow-700'
                                        }`}
                                      >
                                        {c.page_image && <img src={c.page_image} alt="" className="w-3 h-3 rounded-full object-cover" />}
                                        {c.page_title}
                                        {' · '}
                                        {c.status === 'accepted' ? 'aceptó' : c.status === 'rejected' ? 'rechazó' : 'pendiente'}
                                        <button
                                          onClick={() => removeCollaboration(c.id)}
                                          className="ml-1 opacity-60 hover:opacity-100"
                                          title="Quitar colaborador"
                                        >×</button>
                                      </span>
                                    ))}
                                  </div>
                                )}
                              </div>
                              {/* Se envuelven y se apilan: con el título y la
                                  imagen en la misma fila, en un teléfono los
                                  botones quedaban fuera de la pantalla. */}
                              <div className="flex flex-wrap gap-1 items-center shrink-0">
                                {group.type !== 'eventos' && (
                                  <>
                                    <button
                                      onClick={() => moveLink(link.id, group.id, 'up')}
                                      disabled={linkIndex === 0}
                                      className="px-2 py-1 rounded bg-white transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                                      title="Mover arriba"
                                    >
                                      ↑
                                    </button>
                                    <button
                                      onClick={() => moveLink(link.id, group.id, 'down')}
                                      disabled={linkIndex === group.links.length - 1}
                                      className="px-2 py-1 rounded bg-white transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                                      title="Mover abajo"
                                    >
                                      ↓
                                    </button>
                                  </>
                                )}

                                <div className="flex flex-wrap gap-1">
                                  <Link
                                    to={`/page/${id}/item/${link.id}`}
                                    className="text-verde-oscuro hover:bg-verde-claro px-3 py-1 rounded transition text-sm"
                                  >
                                    Editar
                                  </Link>
                                  <button
                                    onClick={() => deleteLink(link.id)}
                                    className="text-red-600 hover:bg-red-50 px-3 py-1 rounded transition text-sm"
                                  >
                                    Eliminar
                                  </button>
                                </div>

                              </div>
                            </div>
                            )
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </div>

      {showGroupModal && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-borde max-w-lg w-full p-10">
            <h2 className="text-3xl font-bold mb-8 text-tinta">Nuevo grupo</h2>
            <form onSubmit={createGroup} className="space-y-6">
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Título del grupo</label>
                <input
                  type="text"
                  value={newGroup.title}
                  onChange={(e) => setNewGroup({ ...newGroup, title: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Tipo de grupo</label>
                <select
                  value={newGroup.type}
                  onChange={(e) => setNewGroup({ ...newGroup, type: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  required
                >
                  <option value="links">Links</option>
                  <option value="galeria">Galería</option>
                  <option value="eventos">Eventos</option>
                </select>
                <p className="text-xs text-tinta-suave mt-2">
                  {newGroup.type === 'links' && 'Lista de enlaces con descripción'}
                  {newGroup.type === 'galeria' && 'Cuadrícula de imágenes'}
                  {newGroup.type === 'eventos' && 'Eventos con fecha, hora y ubicación'}
                </p>
              </div>
              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setShowGroupModal(false)}
                  className="flex-1 px-4 py-3 border border-borde-fuerte text-tinta hover:bg-papel-hueso transition font-bold"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-verde text-verde-tinta px-4 py-3 font-bold hover:bg-verde-oscuro hover:text-tinta transition"
                >
                  Crear
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showEditGroupModal && editingGroup && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-borde max-w-lg w-full p-10">
            <h2 className="text-3xl font-bold mb-8 text-tinta">Editar grupo</h2>
            <form onSubmit={updateGroup} className="space-y-6">
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Título del grupo</label>
                <input
                  type="text"
                  value={editingGroup.title}
                  onChange={(e) => setEditingGroup({ ...editingGroup, title: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  required
                />
              </div>
              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => {
                    setShowEditGroupModal(false);
                    setEditingGroup(null);
                  }}
                  className="flex-1 px-4 py-3 border border-borde-fuerte text-tinta hover:bg-papel-hueso transition font-bold"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-verde text-verde-tinta px-4 py-3 font-bold hover:bg-verde-oscuro hover:text-tinta transition"
                >
                  Guardar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showLinkModal && selectedGroup && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-start justify-center p-4 z-50 overflow-y-auto">
          <div className="bg-white border border-borde max-w-lg w-full p-10 my-8">
            <h2 className="text-3xl font-bold mb-8 text-tinta">
              {selectedGroup.type === 'galeria' ? 'Nuevo contenido' : selectedGroup.type === 'eventos' ? 'Nuevo evento' : 'Nuevo link'}
            </h2>
            <form onSubmit={createLink} className="space-y-6">
              {selectedGroup.type !== 'galeria' && (
                <>
                  <div>
                    <label className="block text-sm font-semibold text-tinta mb-1.5">
                      {selectedGroup.type === 'eventos' ? 'Nombre del evento' : 'Texto'}
                    </label>
                    <input
                      type="text"
                      value={newLink.text}
                      onChange={(e) => setNewLink({ ...newLink, text: e.target.value })}
                      className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                      required
                    />
                  </div>
                  {selectedGroup.type === 'eventos' ? (
                    <div className="grid grid-cols-2 gap-4 items-end">
                      <div>
                        <label className="block text-sm font-semibold text-tinta mb-1.5">URL (opcional)</label>
                        <input
                          type="url"
                          value={newLink.url}
                          onChange={(e) => setNewLink({ ...newLink, url: e.target.value })}
                          className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-semibold text-tinta mb-1.5">Texto del botón (opcional)</label>
                        <input
                          type="text"
                          value={newLink.url_text}
                          onChange={(e) => setNewLink({ ...newLink, url_text: e.target.value })}
                          placeholder="Más información"
                          className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                        />
                      </div>
                    </div>
                  ) : (
                    <div>
                      <label className="block text-sm font-semibold text-tinta mb-1.5">URL</label>
                      <input
                        type="url"
                        value={newLink.url}
                        onChange={(e) => setNewLink({ ...newLink, url: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                        required
                      />
                    </div>
                  )}
                </>
              )}

              {selectedGroup.type === 'galeria' && (
                <>
                  <div>
                    <label htmlFor="nuevo-tipo-de-contenido" className="block text-sm font-semibold text-tinta mb-1.5">
                      Tipo de contenido
                    </label>
                    <select
                      id="nuevo-tipo-de-contenido"
                      value={tipoMedia}
                      onChange={(e) => {
                        setTipoMedia(e.target.value);
                        if (e.target.value === 'imagen') setNewLink({ ...newLink, embed_url: '' });
                      }}
                      className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    >
                      <option value="imagen">Imagen</option>
                      <option value="youtube">Video de YouTube</option>
                      <option value="instagram">Instagram</option>
                    </select>
                  </div>

                  {tipoMedia !== 'imagen' && (
                    <div>
                      <label htmlFor="nueva-url-del-contenido" className="block text-sm font-semibold text-tinta mb-1.5">
                        {tipoMedia === 'youtube' ? 'URL del video' : 'URL del contenido'}
                      </label>
                      <input
                        id="nueva-url-del-contenido"
                        type="url"
                        value={newLink.embed_url}
                        onChange={(e) => setNewLink({ ...newLink, embed_url: e.target.value })}
                        placeholder={tipoMedia === 'youtube'
                          ? 'https://www.youtube.com/watch?v=...'
                          : 'https://www.instagram.com/p/...'}
                        className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                        required
                      />
                      <p className="text-xs text-tinta-suave mt-1">
                        {tipoMedia === 'youtube'
                          ? 'Sirve el link del video, el de compartir o el de un short'
                          : 'De Instagram se muestra sólo la foto, el carrusel o el video. La cuenta tiene que ser pública.'}
                      </p>
                    </div>
                  )}
                </>
              )}

              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">
                  {selectedGroup.type !== 'galeria' ? 'IMAGEN (OPCIONAL)'
                    : tipoMedia === 'imagen' ? 'Imagen'
                    : 'PORTADA (OPCIONAL)'}
                </label>
                <div className="flex flex-wrap items-center gap-4">
                  {newLink.image_url && (
                    <div className="relative">
                      <img src={newLink.image_url} alt="Vista previa" className="w-16 h-16 object-cover rounded" />
                      <button
                        onClick={() => setNewLink({ ...newLink, image_url: null })}
                        className="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-700 text-lg font-bold"
                        type="button"
                      >
                        ×
                      </button>
                    </div>
                  )}
                  <input
                    type="file"
                    accept="image/*"
                    onChange={(e) => handleLinkImageUpload(e)}
                    disabled={uploadingLinkImage}
                    className="text-sm text-tinta-media max-w-full min-w-0"
                    required={selectedGroup.type === 'galeria' && tipoMedia === 'imagen' && !newLink.image_url}
                  />
                  {uploadingLinkImage && <span className="text-sm text-tinta-suave">Subiendo...</span>}
                </div>
                <p className="text-xs text-tinta-suave mt-1">
                  {selectedGroup.type === 'galeria' && tipoMedia === 'instagram'
                    ? 'Sin portada, en la grilla se muestra el contenido tal como lo publica Instagram (máx 5MB)'
                    : selectedGroup.type === 'galeria' && tipoMedia === 'youtube'
                    ? 'Opcional: sin portada se usa la miniatura del video (máx 5MB)'
                    : 'Sube una imagen (máx 5MB)'}
                </p>
              </div>

              {selectedGroup.type === 'galeria' && (
                <>
                  <div>
                    <label className="block text-sm font-semibold text-tinta mb-1.5">Título (opcional)</label>
                    <input
                      type="text"
                      value={newLink.text}
                      onChange={(e) => setNewLink({ ...newLink, text: e.target.value })}
                      className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-semibold text-tinta mb-1.5">Link (opcional)</label>
                    <input
                      type="url"
                      value={newLink.url}
                      onChange={(e) => setNewLink({ ...newLink, url: e.target.value })}
                      className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    />
                  </div>
                </>
              )}

              {selectedGroup.type === 'eventos' && (
                <>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-semibold text-tinta mb-1.5">Fecha</label>
                      <input
                        type="date"
                        value={newLink.event_date}
                        onChange={(e) => setNewLink({ ...newLink, event_date: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-semibold text-tinta mb-1.5">Hora</label>
                      <input
                        type="time"
                        value={newLink.event_time}
                        onChange={(e) => setNewLink({ ...newLink, event_time: e.target.value })}
                        className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                      />
                    </div>
                  </div>
                  <div>
                    <label htmlFor="nuevo-precio-desde" className="block text-sm font-semibold text-tinta mb-1.5">
                      Precio desde (opcional)
                    </label>
                    <input
                      id="nuevo-precio-desde"
                      type="number"
                      min="0"
                      step="0.01"
                      value={newLink.precio_desde ?? ''}
                      onChange={(e) => setNewLink({ ...newLink, precio_desde: e.target.value })}
                      placeholder="Vacío si no se sabe"
                      className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    />
                    <p className="text-xs text-tinta-suave mt-1">
                      En 0 el evento se anuncia como gratis; vacío no muestra nada.
                    </p>
                  </div>
                  <div>
                    <label className="block text-sm font-semibold text-tinta mb-1.5">
                      Dirección<span className="text-red-500">*</span>
                    </label>
                    <GooglePlacesAutocomplete
                      value={newLink.event_address}
                      onChange={(e) => setNewLink({ ...newLink, event_address: e.target.value })}
                      onPlaceSelect={handlePlaceSelect}
                      placeholder="Buscar dirección en Google Maps..."
                      required={true}
                    />
                    <p className="text-xs text-tinta-suave mt-1">
                      Selecciona una dirección de las sugerencias para capturar las coordenadas
                    </p>
                    {newLink.event_latitude && newLink.event_longitude && (
                      <p className="text-xs text-verde-oscuro mt-1">
                        ✓ Coordenadas capturadas correctamente
                      </p>
                    )}
                  </div>
                </>
              )}

              {selectedGroup.type !== 'galeria' && (
                <div>
                  <label className="block text-sm font-semibold text-tinta mb-1.5">
                    {selectedGroup.type === 'eventos' ? 'Descripción del evento' : 'Descripción (opcional)'}
                  </label>
                  <textarea
                    value={newLink.description}
                    onChange={(e) => setNewLink({ ...newLink, description: e.target.value })}
                    className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    rows="3"
                  />
                </div>
              )}
              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => {
                    setShowLinkModal(false);
                    setSelectedGroup(null);
                    setNewLink({
                      url: '',
                      text: '',
                      image_url: '',
                      description: '',
                      event_date: '',
                      event_time: '',
                      event_address: '',
                      event_latitude: null,
                      event_longitude: null,
                      event_maps_url: ''
                    });
                  }}
                  className="flex-1 px-4 py-3 border border-borde-fuerte text-tinta hover:bg-papel-hueso transition font-bold"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-verde text-verde-tinta px-4 py-3 font-bold hover:bg-verde-oscuro hover:text-tinta transition"
                >
                  Crear
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showCollabAcceptModal && acceptingCollab && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center p-4 z-50">
          <div className="bg-white border border-borde max-w-lg w-full p-10">
            <h2 className="text-2xl font-bold mb-2 text-tinta">Aceptar colaboración</h2>
            <p className="text-tinta-media mb-6 text-sm">
              <span className="text-tinta font-medium">{acceptingCollab.requester_page_title}</span> te invita a colaborar en el evento <span className="text-tinta font-medium">&ldquo;{acceptingCollab.event_title}&rdquo;</span>.
              {acceptingCollab.event_date && (
                <span className="block mt-1 text-tinta-suave">
                  {new Date(acceptingCollab.event_date + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })}
                  {acceptingCollab.event_time && ' · ' + acceptingCollab.event_time}
                </span>
              )}
            </p>
            <form onSubmit={acceptCollaboration} className="space-y-6">
              <div>
                {(() => {
                  const eventGroups = page?.groups?.filter(g => g.type === 'eventos') || [];
                  if (eventGroups.length === 0) {
                    return <p className="text-red-700 text-sm">No tenés grupos de eventos en tu página &ldquo;{acceptingCollab.collaborator_page_title}&rdquo;. Creá uno primero.</p>;
                  }
                  if (eventGroups.length === 1) {
                    return (
                      <p className="text-sm text-tinta-media">
                        El evento se agregará al grupo <span className="font-bold text-tinta">&ldquo;{eventGroups[0].title}&rdquo;</span>.
                      </p>
                    );
                  }
                  return (
                    <>
                      <label className="block text-sm font-semibold text-tinta mb-1.5">
                        ¿EN QUÉ GRUPO AGREGAR EL EVENTO?
                      </label>
                      <select
                        value={collabAcceptGroupId}
                        onChange={(e) => setCollabAcceptGroupId(e.target.value)}
                        className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                        required
                      >
                        <option value="">Seleccionar grupo...</option>
                        {eventGroups.map(g => (
                          <option key={g.id} value={g.id}>{g.title}</option>
                        ))}
                      </select>
                    </>
                  );
                })()}
                <p className="text-xs text-tinta-suave mt-2">
                  El evento aparecerá en ese grupo con un indicador de colaboración. También se agregará el link a tu página en el evento original.
                </p>
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setShowCollabAcceptModal(false);
                    setAcceptingCollab(null);
                    setCollabAcceptGroupId('');
                  }}
                  className="flex-1 px-4 py-3 border border-borde-fuerte text-tinta hover:bg-papel-hueso transition font-bold"
                >
                  Cancelar
                </button>
                {page?.groups?.filter(g => g.type === 'eventos').length > 0 && (
                  <button
                    type="submit"
                    className="flex-1 rounded-full bg-verde text-verde-tinta px-4 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors"
                  >
                    Aceptar colaboración
                  </button>
                )}
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

export default PageEditor;
