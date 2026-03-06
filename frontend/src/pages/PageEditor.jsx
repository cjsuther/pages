import React, { useState, useEffect, useContext, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { AuthContext } from '../App';
import LoadingSpinner from '../components/LoadingSpinner';
import GooglePlacesAutocomplete from '../components/GooglePlacesAutocomplete';
import { MoreVertical } from 'lucide-react';

function PageEditor() {
  const { id } = useParams();
  const { token, apiUrl } = useContext(AuthContext);
  const navigate = useNavigate();
  const [page, setPage] = useState(null);
  const [loading, setLoading] = useState(true);
  const [globalLoading, setGlobalLoading] = useState(false);
  const [showGroupModal, setShowGroupModal] = useState(false);
  const [showEditGroupModal, setShowEditGroupModal] = useState(false);
  const [showLinkModal, setShowLinkModal] = useState(false);
  const [showEditLinkModal, setShowEditLinkModal] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState(null);
  const [editingGroup, setEditingGroup] = useState(null);
  const [editingLink, setEditingLink] = useState(null);
  const [newGroup, setNewGroup] = useState({ title: '', type: 'links' });
  const [newLink, setNewLink] = useState({
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
  const [uploadingLinkImage, setUploadingLinkImage] = useState(false);
  const [openGroupMenu, setOpenGroupMenu] = useState(null);
  const [openLinkMenu, setOpenLinkMenu] = useState(null);
  const groupMenuRef = useRef(null);
  const linkMenuRef = useRef(null);

  useEffect(() => {
    fetchPage();
  }, [id]);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (groupMenuRef.current && !groupMenuRef.current.contains(event.target)) {
        setOpenGroupMenu(null);
      }
      if (linkMenuRef.current && !linkMenuRef.current.contains(event.target)) {
        setOpenLinkMenu(null);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const fetchPage = async () => {
    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      if (response.ok) {
        setPage(data.page);
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
        setPage({ ...page, ...data.page });
      }
    } catch (err) {
      console.error('Error updating page:', err);
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

  const handleLinkImageUpload = async (e, isEditing = false) => {
    const file = e.target.files[0];
    if (!file) return;

    setUploadingLinkImage(true);
    const url = await uploadImage(file);
    setUploadingLinkImage(false);

    if (url) {
      if (isEditing && editingLink) {
        setEditingLink({ ...editingLink, image_url: url });
      } else {
        setNewLink({ ...newLink, image_url: url });
      }
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

  const updateLink = async (e) => {
    e.preventDefault();

    if (selectedGroup.type === 'eventos') {
      if (!editingLink.event_latitude || !editingLink.event_longitude) {
        alert('Debes seleccionar una dirección válida de Google Maps para el evento');
        return;
      }
    }

    try {
      setGlobalLoading(true);
      const response = await fetch(`${apiUrl}/links/detail.php?id=${editingLink.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(editingLink)
      });
      if (response.ok) {
        setShowEditLinkModal(false);
        setEditingLink(null);
        fetchPage();
      }
    } catch (err) {
      console.error('Error updating link:', err);
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

  const openEditLinkModal = (link, group) => {
    setEditingLink({ ...link });
    setSelectedGroup(group);
    setShowEditLinkModal(true);
  };

  const handlePlaceSelect = (placeData, isEditing = false) => {
    if (isEditing && editingLink) {
      setEditingLink({
        ...editingLink,
        event_address: placeData.address,
        event_latitude: placeData.latitude,
        event_longitude: placeData.longitude,
        event_maps_url: placeData.mapsUrl
      });
    } else {
      setNewLink({
        ...newLink,
        event_address: placeData.address,
        event_latitude: placeData.latitude,
        event_longitude: placeData.longitude,
        event_maps_url: placeData.mapsUrl
      });
    }
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
    <div className="min-h-screen bg-black text-white">
      {globalLoading && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50">
          <div className="bg-gray-900 border border-gray-800 p-6">
            <LoadingSpinner />
          </div>
        </div>
      )}

      <nav className="border-b border-gray-800">
        <div className="max-w-7xl mx-auto px-6 py-6">
          <div className="flex justify-between items-center">
            <div>
              <h1 className="text-3xl font-black tracking-tight">EDITOR</h1>
              <p className="text-gray-500 mt-1 font-medium">{page.title}</p>
            </div>
            <div className="flex items-center gap-4">
              <button
                onClick={() => navigate('/my-pages')}
                className="text-gray-400 hover:text-white transition font-medium"
              >
                ← Volver
              </button>
              <a
                href={`/${page.url_slug}`}
                target="_blank"
                rel="noopener noreferrer"
                className="bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition"
              >
                VER PÁGINA
              </a>
            </div>
          </div>
        </div>
      </nav>

      <div className="max-w-7xl mx-auto px-6 py-16">
        <div className="bg-gray-900 border border-gray-800 p-8 mb-8">
          <h2 className="text-2xl font-black mb-8 tracking-tight">CONFIGURACIÓN</h2>

          <div className="grid md:grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TÍTULO</label>
              <input
                type="text"
                value={page.title}
                onChange={(e) => setPage({ ...page, title: e.target.value })}
                onBlur={() => updatePage({ title: page.title })}
                className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
              />
            </div>

            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">URL</label>
              <input
                type="text"
                value={page.url_slug}
                disabled
                className="w-full px-4 py-3 bg-gray-800 border border-gray-700 text-gray-500"
              />
            </div>
          </div>

          <div className="mb-6">
            <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">DESCRIPCIÓN</label>
            <textarea
              value={page.description}
              onChange={(e) => setPage({ ...page, description: e.target.value })}
              onBlur={() => updatePage({ description: page.description })}
              className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
              rows="3"
            />
          </div>

          <div className="grid md:grid-cols-2 gap-6 mb-6">
            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">IMAGEN DE PERFIL</label>
              <div className="flex items-center gap-4">
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
                  className="text-sm text-gray-400"
                />
              </div>
              <p className="text-xs text-gray-600 mt-1">JPG, PNG, GIF o WebP. Máximo 5MB</p>
            </div>

            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">IMAGEN DE FONDO</label>
              <div className="flex items-center gap-4">
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
                  className="text-sm text-gray-400"
                />
              </div>
              <p className="text-xs text-gray-600 mt-1">JPG, PNG, GIF o WebP. Máximo 5MB</p>
            </div>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 gap-6 mb-6">
            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">COLOR PRIMARIO</label>
              <input
                type="color"
                value={page.primary_color}
                onChange={(e) => {
                  setPage({ ...page, primary_color: e.target.value });
                  updatePage({ primary_color: e.target.value });
                }}
                className="w-full h-10 rounded-lg cursor-pointer"
              />
            </div>

            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">COLOR SECUNDARIO</label>
              <input
                type="color"
                value={page.secondary_color}
                onChange={(e) => {
                  setPage({ ...page, secondary_color: e.target.value });
                  updatePage({ secondary_color: e.target.value });
                }}
                className="w-full h-10 rounded-lg cursor-pointer"
              />
            </div>

            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">COLOR DE FONDO</label>
              <input
                type="color"
                value={page.background_color}
                onChange={(e) => {
                  setPage({ ...page, background_color: e.target.value });
                  updatePage({ background_color: e.target.value });
                }}
                className="w-full h-10 rounded-lg cursor-pointer"
              />
            </div>

            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">COLOR DE TEXTO</label>
              <input
                type="color"
                value={page.text_color}
                onChange={(e) => {
                  setPage({ ...page, text_color: e.target.value });
                  updatePage({ text_color: e.target.value });
                }}
                className="w-full h-10 rounded-lg cursor-pointer"
              />
            </div>
          </div>

          <div className="mt-8 pt-8 border-t border-gray-800">
            <label className="block text-sm font-bold text-gray-400 mb-4 tracking-wide">TEMPLATE DE DISEÑO</label>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <button
                onClick={() => {
                  setPage({ ...page, template: 'minimal' });
                  updatePage({ template: 'minimal' });
                }}
                className={`p-4 border-2 transition ${(page.template || 'minimal') === 'minimal'
                    ? 'border-white bg-gray-800'
                    : 'border-gray-700 hover:border-gray-600'
                  }`}
              >
                <div className="font-bold mb-2 text-white">Minimal</div>
                <div className="text-xs text-gray-500">Diseño limpio y centrado</div>
              </button>

              <button
                onClick={() => {
                  setPage({ ...page, template: 'cards' });
                  updatePage({ template: 'cards' });
                }}
                className={`p-4 border-2 transition ${page.template === 'cards'
                    ? 'border-white bg-gray-800'
                    : 'border-gray-700 hover:border-gray-600'
                  }`}
              >
                <div className="font-bold mb-2 text-white">Cards</div>
                <div className="text-xs text-gray-500">Tarjetas con sombras</div>
              </button>

              <button
                onClick={() => {
                  setPage({ ...page, template: 'modern' });
                  updatePage({ template: 'modern' });
                }}
                className={`p-4 border-2 transition ${page.template === 'modern'
                    ? 'border-white bg-gray-800'
                    : 'border-gray-700 hover:border-gray-600'
                  }`}
              >
                <div className="font-bold mb-2 text-white">Modern</div>
                <div className="text-xs text-gray-500">Estilo audaz y oscuro</div>
              </button>

              <button
                onClick={() => {
                  setPage({ ...page, template: 'condensed' });
                  updatePage({ template: 'condensed' });
                }}
                className={`p-4 border-2 transition ${page.template === 'condensed'
                    ? 'border-white bg-gray-800'
                    : 'border-gray-700 hover:border-gray-600'
                  }`}
              >
                <div className="font-bold mb-2 text-white">Condensado</div>
                <div className="text-xs text-gray-500">Lista compacta de 2 líneas</div>
              </button>
            </div>
          </div>
        </div>

        <div className="bg-gray-900 border border-gray-800 p-8 mb-8">
          <div className="flex justify-between items-center mb-8">
            <h2 className="text-2xl font-black tracking-tight">GRUPOS DE LINKS</h2>
            <button
              onClick={() => setShowGroupModal(true)}
              className="bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition"
            >
              + NUEVO GRUPO
            </button>
          </div>

          {page.groups && page.groups.length === 0 ? (
            <p className="text-gray-500 text-center py-8">No hay grupos todavía</p>
          ) : (
            <div className="space-y-6">
              {page.groups?.map((group, index) => (
                <div key={group.id} className="bg-black rounded-lg shadow-md p-6">
                  <div className="flex justify-between items-center mb-4">
                    <div className="flex items-center gap-3">
                      <h3 className="text-xl font-semibold">{group.title}</h3>
                      <span className={`px-2 py-1 text-xs rounded-full ${group.type === 'galeria' ? 'bg-purple-100 text-purple-700' :
                          group.type === 'eventos' ? 'bg-orange-100 text-orange-700' :
                            'bg-blue-100 text-blue-700'
                        }`}>
                        {group.type === 'galeria' ? 'Galería' :
                          group.type === 'eventos' ? 'Eventos' :
                            'Links'}
                      </span>
                    </div>
                    <div className="flex gap-2 items-center">
                      <button
                        onClick={() => moveGroup(group.id, 'up')}
                        disabled={index === 0}
                        className="px-3 py-1 rounded-lg hover:bg-gray-100 transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                        title="Mover arriba"
                      >
                        ↑
                      </button>
                      <button
                        onClick={() => moveGroup(group.id, 'down')}
                        disabled={index === page.groups.length - 1}
                        className="px-3 py-1 rounded-lg hover:bg-gray-100 transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                        title="Mover abajo"
                      >
                        ↓
                      </button>

                      <div className="hidden md:flex gap-2">
                        <button
                          onClick={() => openEditGroupModal(group)}
                          className="text-blue-600 hover:bg-blue-50 px-3 py-1 rounded-lg transition text-sm"
                        >
                          Editar Título
                        </button>
                        <button
                          onClick={() => {
                            setSelectedGroup(group);
                            setShowLinkModal(true);
                          }}
                          className="bg-green-600 text-white px-3 py-1 rounded-lg hover:bg-green-700 transition text-sm"
                        >
                          {group.type === 'galeria' ? '+ Imagen' :
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

                      <div className="relative md:hidden" ref={openGroupMenu === group.id ? groupMenuRef : null}>
                        <button
                          onClick={() => setOpenGroupMenu(openGroupMenu === group.id ? null : group.id)}
                          className="p-2 hover:bg-gray-200 rounded-lg transition text-gray-900"
                        >
                          <MoreVertical className="w-5 h-5" />
                        </button>

                        {openGroupMenu === group.id && (
                          <div className="absolute right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-50 w-48">
                            <button
                              onClick={() => {
                                openEditGroupModal(group);
                                setOpenGroupMenu(null);
                              }}
                              className="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-blue-600"
                            >
                              Editar Título
                            </button>
                            <button
                              onClick={() => {
                                setSelectedGroup(group);
                                setShowLinkModal(true);
                                setOpenGroupMenu(null);
                              }}
                              className="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-green-600"
                            >
                              {group.type === 'galeria' ? '+ Imagen' :
                                group.type === 'eventos' ? '+ Evento' :
                                  '+ Link'}
                            </button>
                            <button
                              onClick={() => {
                                deleteGroup(group.id);
                                setOpenGroupMenu(null);
                              }}
                              className="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600"
                            >
                              Eliminar
                            </button>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>

                  {group.links && group.links.length === 0 ? (
                    <p className="text-gray-400 text-sm">No hay links en este grupo</p>
                  ) : (
                    <div className="space-y-2">
                      {(group.type === 'eventos'
                        ? [...group.links].sort((a, b) => {
                          const dateA = new Date(a.event_date + ' ' + (a.event_time || '00:00'));
                          const dateB = new Date(b.event_date + ' ' + (b.event_time || '00:00'));
                          return dateA - dateB;
                        })
                        : group.links
                      )?.map((link, linkIndex) => (
                        <div
                          key={link.id}
                          className="flex items-center gap-4 p-3 bg-gray-50 rounded-lg hover:bg-gray-100"
                        >
                          {link.image_url && (
                            <img
                              src={link.image_url}
                              alt={link.text}
                              className="w-12 h-12 object-cover rounded"
                            />
                          )}
                          <div className="flex-1">
                            <a
                              href={link.url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-blue-600 hover:underline font-medium"
                            >
                              {link.text}
                            </a>
                            {link.description && (
                              <p className="text-sm text-gray-600">{link.description}</p>
                            )}
                            {link.event_due == '1' && (
                              <p className="text-sm text-red-600 font-semibold mt-1">¡Evento vencido!</p>
                            )}
                          </div>
                          <div className="flex gap-1 items-center">
                            {group.type !== 'eventos' && (
                              <>
                                <button
                                  onClick={() => moveLink(link.id, group.id, 'up')}
                                  disabled={linkIndex === 0}
                                  className="px-2 py-1 rounded bg-black transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                                  title="Mover arriba"
                                >
                                  ↑
                                </button>
                                <button
                                  onClick={() => moveLink(link.id, group.id, 'down')}
                                  disabled={linkIndex === group.links.length - 1}
                                  className="px-2 py-1 rounded bg-black transition text-sm disabled:opacity-30 disabled:cursor-not-allowed"
                                  title="Mover abajo"
                                >
                                  ↓
                                </button>
                              </>
                            )}

                            <div className="hidden md:flex gap-1">
                              <button
                                onClick={() => openEditLinkModal(link, group)}
                                className="text-blue-600 hover:bg-blue-50 px-3 py-1 rounded transition text-sm"
                              >
                                Editar
                              </button>
                              <button
                                onClick={() => deleteLink(link.id)}
                                className="text-red-600 hover:bg-red-50 px-3 py-1 rounded transition text-sm"
                              >
                                Eliminar
                              </button>
                            </div>

                            <div className="relative md:hidden" ref={openLinkMenu === link.id ? linkMenuRef : null}>
                              <button
                                onClick={() => setOpenLinkMenu(openLinkMenu === link.id ? null : link.id)}
                                className="p-2 hover:bg-gray-300 rounded transition text-gray-900"
                              >
                                <MoreVertical className="w-4 h-4" />
                              </button>

                              {openLinkMenu === link.id && (
                                <div className="absolute right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg z-50 w-40">
                                  <button
                                    onClick={() => {
                                      openEditLinkModal(link, group);
                                      setOpenLinkMenu(null);
                                    }}
                                    className="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-blue-600"
                                  >
                                    Editar
                                  </button>
                                  <button
                                    onClick={() => {
                                      deleteLink(link.id);
                                      setOpenLinkMenu(null);
                                    }}
                                    className="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600"
                                  >
                                    Eliminar
                                  </button>
                                </div>
                              )}
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {showGroupModal && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center p-4 z-50">
          <div className="bg-gray-900 border border-gray-800 max-w-lg w-full p-10">
            <h2 className="text-3xl font-black mb-8 text-white">NUEVO GRUPO</h2>
            <form onSubmit={createGroup} className="space-y-6">
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TÍTULO DEL GRUPO</label>
                <input
                  type="text"
                  value={newGroup.title}
                  onChange={(e) => setNewGroup({ ...newGroup, title: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TIPO DE GRUPO</label>
                <select
                  value={newGroup.type}
                  onChange={(e) => setNewGroup({ ...newGroup, type: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  required
                >
                  <option value="links">Links</option>
                  <option value="galeria">Galería</option>
                  <option value="eventos">Eventos</option>
                </select>
                <p className="text-xs text-gray-600 mt-2">
                  {newGroup.type === 'links' && 'Lista de enlaces con descripción'}
                  {newGroup.type === 'galeria' && 'Cuadrícula de imágenes'}
                  {newGroup.type === 'eventos' && 'Eventos con fecha, hora y ubicación'}
                </p>
              </div>
              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => setShowGroupModal(false)}
                  className="flex-1 px-4 py-3 border border-gray-700 text-white hover:bg-gray-800 transition font-bold"
                >
                  CANCELAR
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-white text-black px-4 py-3 font-bold hover:bg-gray-200 transition"
                >
                  CREAR
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showEditGroupModal && editingGroup && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center p-4 z-50">
          <div className="bg-gray-900 border border-gray-800 max-w-lg w-full p-10">
            <h2 className="text-3xl font-black mb-8 text-white">EDITAR GRUPO</h2>
            <form onSubmit={updateGroup} className="space-y-6">
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TÍTULO DEL GRUPO</label>
                <input
                  type="text"
                  value={editingGroup.title}
                  onChange={(e) => setEditingGroup({ ...editingGroup, title: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
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
                  className="flex-1 px-4 py-3 border border-gray-700 text-white hover:bg-gray-800 transition font-bold"
                >
                  CANCELAR
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-white text-black px-4 py-3 font-bold hover:bg-gray-200 transition"
                >
                  GUARDAR
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showLinkModal && selectedGroup && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center p-4 z-50 overflow-y-auto">
          <div className="bg-gray-900 border border-gray-800 max-w-lg w-full p-10 my-8">
            <h2 className="text-3xl font-black mb-8 text-white">
              {selectedGroup.type === 'galeria' ? 'NUEVA IMAGEN' : selectedGroup.type === 'eventos' ? 'NUEVO EVENTO' : 'NUEVO LINK'}
            </h2>
            <form onSubmit={createLink} className="space-y-6">
              {selectedGroup.type !== 'galeria' && (
                <>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                      {selectedGroup.type === 'eventos' ? 'NOMBRE DEL EVENTO' : 'TEXTO'}
                    </label>
                    <input
                      type="text"
                      value={newLink.text}
                      onChange={(e) => setNewLink({ ...newLink, text: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                      {selectedGroup.type === 'eventos' ? 'URL (OPCIONAL)' : 'URL'}
                    </label>
                    <input
                      type="url"
                      value={newLink.url}
                      onChange={(e) => setNewLink({ ...newLink, url: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                      required={selectedGroup.type !== 'eventos'}
                    />
                  </div>
                </>
              )}

              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  IMAGEN {selectedGroup.type === 'galeria' ? '' : '(OPCIONAL)'}
                </label>
                <div className="flex items-center gap-4">
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
                    onChange={(e) => handleLinkImageUpload(e, false)}
                    disabled={uploadingLinkImage}
                    className="text-sm text-gray-400"
                    required={selectedGroup.type === 'galeria' && !newLink.image_url}
                  />
                  {uploadingLinkImage && <span className="text-sm text-gray-500">Subiendo...</span>}
                </div>
                <p className="text-xs text-gray-600 mt-1">Sube una imagen (máx 5MB)</p>
              </div>

              {selectedGroup.type === 'galeria' && (
                <>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TÍTULO (OPCIONAL)</label>
                    <input
                      type="text"
                      value={newLink.text}
                      onChange={(e) => setNewLink({ ...newLink, text: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">LINK (OPCIONAL)</label>
                    <input
                      type="url"
                      value={newLink.url}
                      onChange={(e) => setNewLink({ ...newLink, url: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    />
                  </div>
                </>
              )}

              {selectedGroup.type === 'eventos' && (
                <>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">FECHA</label>
                      <input
                        type="date"
                        value={newLink.event_date}
                        onChange={(e) => setNewLink({ ...newLink, event_date: e.target.value })}
                        className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">HORA</label>
                      <input
                        type="time"
                        value={newLink.event_time}
                        onChange={(e) => setNewLink({ ...newLink, event_time: e.target.value })}
                        className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                      DIRECCIÓN <span className="text-red-500">*</span>
                    </label>
                    <GooglePlacesAutocomplete
                      value={newLink.event_address}
                      onChange={(e) => setNewLink({ ...newLink, event_address: e.target.value })}
                      onPlaceSelect={(placeData) => handlePlaceSelect(placeData, false)}
                      placeholder="Buscar dirección en Google Maps..."
                      required={true}
                    />
                    <p className="text-xs text-gray-600 mt-1">
                      Selecciona una dirección de las sugerencias para capturar las coordenadas
                    </p>
                    {newLink.event_latitude && newLink.event_longitude && (
                      <p className="text-xs text-green-500 mt-1">
                        ✓ Coordenadas capturadas correctamente
                      </p>
                    )}
                  </div>
                </>
              )}

              {selectedGroup.type !== 'galeria' && (
                <div>
                  <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                    {selectedGroup.type === 'eventos' ? 'DESCRIPCIÓN DEL EVENTO' : 'DESCRIPCIÓN (OPCIONAL)'}
                  </label>
                  <textarea
                    value={newLink.description}
                    onChange={(e) => setNewLink({ ...newLink, description: e.target.value })}
                    className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
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
                  className="flex-1 px-4 py-3 border border-gray-700 text-white hover:bg-gray-800 transition font-bold"
                >
                  CANCELAR
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-white text-black px-4 py-3 font-bold hover:bg-gray-200 transition"
                >
                  CREAR
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showEditLinkModal && editingLink && selectedGroup && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center p-4 z-50 overflow-y-auto">
          <div className="bg-gray-900 border border-gray-800 max-w-lg w-full p-10 my-8">
            <h2 className="text-3xl font-black mb-8 text-white">
              EDITAR {selectedGroup.type === 'galeria' ? 'IMAGEN' : selectedGroup.type === 'eventos' ? 'EVENTO' : 'LINK'}
            </h2>
            <form onSubmit={updateLink} className="space-y-6">
              {selectedGroup.type !== 'galeria' && (
                <>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                      {selectedGroup.type === 'eventos' ? 'NOMBRE DEL EVENTO' : 'TEXTO'}
                    </label>
                    <input
                      type="text"
                      value={editingLink.text}
                      onChange={(e) => setEditingLink({ ...editingLink, text: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                      {selectedGroup.type === 'eventos' ? 'URL (OPCIONAL)' : 'URL'}
                    </label>
                    <input
                      type="url"
                      value={editingLink.url}
                      onChange={(e) => setEditingLink({ ...editingLink, url: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                      required={selectedGroup.type !== 'eventos'}
                    />
                  </div>
                </>
              )}

              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  IMAGEN {selectedGroup.type === 'galeria' ? '' : '(OPCIONAL)'}
                </label>
                <div className="flex items-center gap-4">
                  {editingLink.image_url && (
                    <div className="relative">
                      <img src={editingLink.image_url} alt="Vista previa" className="w-16 h-16 object-cover rounded" />
                      <button
                        onClick={() => setEditingLink({ ...editingLink, image_url: null })}
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
                    onChange={(e) => handleLinkImageUpload(e, true)}
                    disabled={uploadingLinkImage}
                    className="text-sm text-gray-400"
                  />
                  {uploadingLinkImage && <span className="text-sm text-gray-500">Subiendo...</span>}
                </div>
                <p className="text-xs text-gray-600 mt-1">Sube una nueva imagen para reemplazar (máx 5MB)</p>
              </div>

              {selectedGroup.type === 'galeria' && (
                <>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TÍTULO (OPCIONAL)</label>
                    <input
                      type="text"
                      value={editingLink.text}
                      onChange={(e) => setEditingLink({ ...editingLink, text: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">LINK (OPCIONAL)</label>
                    <input
                      type="url"
                      value={editingLink.url}
                      onChange={(e) => setEditingLink({ ...editingLink, url: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    />
                  </div>
                </>
              )}

              {selectedGroup.type === 'eventos' && (
                <>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">FECHA</label>
                      <input
                        type="date"
                        value={editingLink.event_date}
                        onChange={(e) => setEditingLink({ ...editingLink, event_date: e.target.value })}
                        className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">HORA</label>
                      <input
                        type="time"
                        value={editingLink.event_time || ''}
                        onChange={(e) => setEditingLink({ ...editingLink, event_time: e.target.value })}
                        className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                      DIRECCIÓN <span className="text-red-500">*</span>
                    </label>
                    <GooglePlacesAutocomplete
                      value={editingLink.event_address || ''}
                      onChange={(e) => setEditingLink({ ...editingLink, event_address: e.target.value })}
                      onPlaceSelect={(placeData) => handlePlaceSelect(placeData, true)}
                      placeholder="Buscar dirección en Google Maps..."
                      required={true}
                    />
                    <p className="text-xs text-gray-600 mt-1">
                      Selecciona una dirección de las sugerencias para capturar las coordenadas
                    </p>
                    {editingLink.event_latitude && editingLink.event_longitude && (
                      <p className="text-xs text-green-500 mt-1">
                        ✓ Coordenadas capturadas correctamente
                      </p>
                    )}
                  </div>
                </>
              )}

              {selectedGroup.type !== 'galeria' && (
                <div>
                  <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                    {selectedGroup.type === 'eventos' ? 'DESCRIPCIÓN DEL EVENTO' : 'DESCRIPCIÓN (OPCIONAL)'}
                  </label>
                  <textarea
                    value={editingLink.description}
                    onChange={(e) => setEditingLink({ ...editingLink, description: e.target.value })}
                    className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    rows="3"
                  />
                </div>
              )}
              <div className="flex gap-3 pt-4">
                <button
                  type="button"
                  onClick={() => {
                    setShowEditLinkModal(false);
                    setEditingLink(null);
                    setSelectedGroup(null);
                  }}
                  className="flex-1 px-4 py-3 border border-gray-700 text-white hover:bg-gray-800 transition font-bold"
                >
                  CANCELAR
                </button>
                <button
                  type="submit"
                  className="flex-1 bg-white text-black px-4 py-3 font-bold hover:bg-gray-200 transition"
                >
                  GUARDAR
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

export default PageEditor;
