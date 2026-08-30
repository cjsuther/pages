import React, { useState, useEffect, useContext } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { AuthContext } from '../App';
import LoadingSpinner from '../components/LoadingSpinner';
import GooglePlacesAutocomplete from '../components/GooglePlacesAutocomplete';
import PanelEntradas from '../components/PanelEntradas';
import PanelVentas from '../components/PanelVentas';

/**
 * Edición de un item (link, imagen o evento) en pantalla completa.
 *
 * Antes esto era un modal dentro del editor de páginas: un evento con sus
 * entradas y sus ventas no entra en una ventanita, y al cerrarla se perdía
 * dónde estabas. Ahora es una ruta propia, así que el botón "atrás" del
 * navegador y el link "Volver" hacen lo mismo: te devuelven al contenido de
 * la página.
 */
function ItemEditor() {
  const { id, linkId } = useParams();
  const { token, apiUrl } = useContext(AuthContext);
  const navigate = useNavigate();

  const [page, setPage] = useState(null);
  const [group, setGroup] = useState(null);
  const [item, setItem] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [uploadingImage, setUploadingImage] = useState(false);
  // Tab activo cuando el item es un evento: datos | entradas | ventas.
  const [tab, setTab] = useState('datos');
  const [pageSearchQuery, setPageSearchQuery] = useState('');
  const [pageSearchResults, setPageSearchResults] = useState([]);
  const [searchingPages, setSearchingPages] = useState(false);

  // A dónde vuelve todo: al editor, en la solapa donde estaba el item.
  const volverA = `/page/${id}?s=contenido`;

  useEffect(() => {
    cargar();
  }, [id, linkId]);

  const cargar = async () => {
    try {
      const response = await fetch(`${apiUrl}/pages/detail.php?id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      if (response.ok && data.page) {
        setPage(data.page);
        // El link no tiene endpoint propio de lectura, así que se busca en la
        // página, que ya viene con sus grupos, links y colaboraciones.
        const grupo = (data.page.groups || []).find(g =>
          (g.links || []).some(l => String(l.id) === String(linkId))
        );
        if (grupo) {
          setGroup(grupo);
          setItem({ ...grupo.links.find(l => String(l.id) === String(linkId)) });
        }
      }
    } catch (err) {
      console.error('Error cargando el item:', err);
    } finally {
      setLoading(false);
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
        headers: { 'Authorization': `Bearer ${token}` },
        body: formData
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Error al subir imagen');
      return data.url;
    } catch (err) {
      console.error('Error uploading image:', err);
      alert('Error al subir imagen: ' + err.message);
      return null;
    }
  };

  const handleImageUpload = async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    setUploadingImage(true);
    const url = await uploadImage(file);
    setUploadingImage(false);

    if (url) setItem({ ...item, image_url: url });
  };

  const handlePlaceSelect = (placeData) => {
    setItem({
      ...item,
      event_address: placeData.address,
      event_latitude: placeData.latitude,
      event_longitude: placeData.longitude,
      event_maps_url: placeData.mapsUrl
    });
  };

  const guardar = async (e) => {
    e.preventDefault();

    if (group.type === 'eventos') {
      if (!item.event_latitude || !item.event_longitude) {
        alert('Debes seleccionar una dirección válida de Google Maps para el evento');
        return;
      }
    }

    try {
      setSaving(true);
      const response = await fetch(`${apiUrl}/links/detail.php?id=${item.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(item)
      });
      if (response.ok) {
        navigate(volverA);
      } else {
        const data = await response.json();
        alert(data.error || 'Error al guardar');
      }
    } catch (err) {
      console.error('Error updating link:', err);
    } finally {
      setSaving(false);
    }
  };

  const searchPagesForCollaboration = async (query) => {
    if (!query.trim()) { setPageSearchResults([]); return; }
    setSearchingPages(true);
    try {
      const response = await fetch(`${apiUrl}/public/search.php?q=${encodeURIComponent(query)}`);
      const data = await response.json();
      setPageSearchResults((data.results || []).filter(r => r.type === 'page'));
    } catch (err) {
      console.error('Error searching pages:', err);
    } finally {
      setSearchingPages(false);
    }
  };

  const addCollaborator = async (colaboradora) => {
    try {
      const response = await fetch(`${apiUrl}/collaborations/index.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({ link_id: item.id, collaborator_page_id: colaboradora.id })
      });
      const data = await response.json();
      if (response.ok) {
        setPageSearchQuery('');
        setPageSearchResults([]);
        setItem(prev => prev ? {
          ...prev,
          collaborations: [...(prev.collaborations || []), {
            id: data.collaboration_id,
            status: 'pending',
            collaborator_page_id: colaboradora.id,
            page_title: colaboradora.title,
            page_slug: colaboradora.slug,
            page_image: colaboradora.profile_image || null,
          }]
        } : prev);
      } else {
        alert(data.error || 'Error al invitar colaborador');
      }
    } catch (err) {
      console.error('Error adding collaborator:', err);
    }
  };

  const removeCollaboration = async (collabId) => {
    if (!confirm('¿Quitar esta colaboración?')) return;
    try {
      await fetch(`${apiUrl}/collaborations/detail.php?id=${collabId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      setItem(prev => prev ? {
        ...prev,
        collaborations: (prev.collaborations || []).filter(c => c.id !== collabId)
      } : prev);
    } catch (err) {
      console.error('Error removing collaboration:', err);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-black">
        <LoadingSpinner message="Cargando..." />
      </div>
    );
  }

  if (!item || !group) {
    return (
      <div className="min-h-screen bg-black text-white flex flex-col items-center justify-center gap-4">
        <p className="text-gray-400">No encontramos este item.</p>
        <Link to={volverA} className="bg-white text-black px-6 py-3 font-bold hover:bg-gray-200 transition">
          VOLVER AL EDITOR
        </Link>
      </div>
    );
  }

  const queEs = group.type === 'galeria' ? 'IMAGEN' : group.type === 'eventos' ? 'EVENTO' : 'LINK';

  return (
    <div className="min-h-screen bg-black text-white">
      {saving && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50">
          <div className="bg-gray-900 border border-gray-800 p-6">
            <LoadingSpinner />
          </div>
        </div>
      )}

      <nav className="border-b border-gray-800">
        <div className="max-w-3xl mx-auto px-6 py-6">
          <div className="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
            <div className="min-w-0">
              <h1 className="text-3xl font-black tracking-tight">EDITAR {queEs}</h1>
              <p className="text-gray-500 mt-1 font-medium truncate">
                {page?.title} · {group.title}
              </p>
            </div>
            <Link
              to={volverA}
              className="text-gray-400 hover:text-white transition font-medium shrink-0"
            >
              ← Volver al editor
            </Link>
          </div>
        </div>
      </nav>

      <div className="max-w-3xl mx-auto px-6 py-10">
        {group.type === 'eventos' && (
          <nav className="flex gap-1 border-b border-gray-800 mb-8" aria-label="Secciones del evento">
            {[
              { clave: 'datos', etiqueta: 'DATOS' },
              { clave: 'entradas', etiqueta: 'ENTRADAS' },
              { clave: 'ventas', etiqueta: 'VENTAS' },
            ].map((t) => (
              <button
                key={t.clave}
                type="button"
                onClick={() => setTab(t.clave)}
                className={`px-4 py-3 text-sm font-bold tracking-wide transition border-b-2 -mb-px ${
                  tab === t.clave
                    ? 'border-white text-white'
                    : 'border-transparent text-gray-500 hover:text-gray-300'
                }`}
              >
                {t.etiqueta}
              </button>
            ))}
          </nav>
        )}

        {tab === 'entradas' && group.type === 'eventos' && (
          <PanelEntradas linkId={item.id} apiUrl={apiUrl} token={token} />
        )}

        {tab === 'ventas' && group.type === 'eventos' && (
          <PanelVentas linkId={item.id} apiUrl={apiUrl} token={token} />
        )}

        {(tab === 'datos' || group.type !== 'eventos') && (
        <form onSubmit={guardar} className="space-y-6">
          {group.type !== 'galeria' && (
            <>
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  {group.type === 'eventos' ? 'NOMBRE DEL EVENTO' : 'TEXTO'}
                </label>
                <input
                  type="text"
                  value={item.text || ''}
                  onChange={(e) => setItem({ ...item, text: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  required
                />
              </div>
              {group.type === 'eventos' ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">URL (OPCIONAL)</label>
                    <input
                      type="url"
                      value={item.url || ''}
                      onChange={(e) => setItem({ ...item, url: e.target.value })}
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TEXTO DEL BOTÓN (OPCIONAL)</label>
                    <input
                      type="text"
                      value={item.url_text || ''}
                      onChange={(e) => setItem({ ...item, url_text: e.target.value })}
                      placeholder="Más información"
                      className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    />
                  </div>
                </div>
              ) : (
                <div>
                  <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">URL</label>
                  <input
                    type="url"
                    value={item.url || ''}
                    onChange={(e) => setItem({ ...item, url: e.target.value })}
                    className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    required
                  />
                </div>
              )}
            </>
          )}

          <div>
            <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
              IMAGEN {group.type === 'galeria' ? '' : '(OPCIONAL)'}
            </label>
            <div className="flex items-center gap-4">
              {item.image_url && (
                <div className="relative">
                  <img src={item.image_url} alt="Vista previa" className="w-16 h-16 object-cover rounded" />
                  <button
                    onClick={() => setItem({ ...item, image_url: null })}
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
                onChange={handleImageUpload}
                disabled={uploadingImage}
                className="text-sm text-gray-400"
              />
              {uploadingImage && <span className="text-sm text-gray-500">Subiendo...</span>}
            </div>
            <p className="text-xs text-gray-600 mt-1">Sube una nueva imagen para reemplazar (máx 5MB)</p>
          </div>

          {group.type === 'galeria' && (
            <>
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">TÍTULO (OPCIONAL)</label>
                <input
                  type="text"
                  value={item.text || ''}
                  onChange={(e) => setItem({ ...item, text: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                />
              </div>
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">LINK (OPCIONAL)</label>
                <input
                  type="url"
                  value={item.url || ''}
                  onChange={(e) => setItem({ ...item, url: e.target.value })}
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                />
              </div>
            </>
          )}

          {group.type === 'eventos' && (
            <>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">FECHA</label>
                  <input
                    type="date"
                    value={item.event_date || ''}
                    onChange={(e) => setItem({ ...item, event_date: e.target.value })}
                    className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">HORA</label>
                  <input
                    type="time"
                    value={item.event_time || ''}
                    onChange={(e) => setItem({ ...item, event_time: e.target.value })}
                    className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                  />
                </div>
              </div>
              <div>
                <label htmlFor="editar-precio-desde" className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  PRECIO DESDE (OPCIONAL)
                </label>
                <input
                  id="editar-precio-desde"
                  type="number"
                  min="0"
                  step="0.01"
                  value={item.precio_desde ?? ''}
                  onChange={(e) => setItem({ ...item, precio_desde: e.target.value })}
                  placeholder="Vacío si no se sabe"
                  className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                />
                <p className="text-xs text-gray-600 mt-1">
                  En 0 el evento se anuncia como gratis; vacío no muestra nada.
                </p>
              </div>
              <div>
                <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                  DIRECCIÓN <span className="text-red-500">*</span>
                </label>
                <GooglePlacesAutocomplete
                  value={item.event_address || ''}
                  onChange={(e) => setItem({ ...item, event_address: e.target.value })}
                  onPlaceSelect={handlePlaceSelect}
                  placeholder="Buscar dirección en Google Maps..."
                  required={true}
                />
                <p className="text-xs text-gray-600 mt-1">
                  Selecciona una dirección de las sugerencias para capturar las coordenadas
                </p>
                {item.event_latitude && item.event_longitude && (
                  <p className="text-xs text-green-500 mt-1">
                    ✓ Coordenadas capturadas correctamente
                  </p>
                )}
              </div>
            </>
          )}

          {group.type !== 'galeria' && (
            <div>
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">
                {group.type === 'eventos' ? 'DESCRIPCIÓN DEL EVENTO' : 'DESCRIPCIÓN (OPCIONAL)'}
              </label>
              <textarea
                value={item.description || ''}
                onChange={(e) => setItem({ ...item, description: e.target.value })}
                className="w-full px-4 py-3 bg-black border border-gray-700 text-white focus:border-white transition"
                rows="4"
              />
            </div>
          )}

          {group.type === 'eventos' && (
            <div className="border-t border-gray-700 pt-6">
              <label className="block text-sm font-bold text-gray-400 mb-3 tracking-wide">COLABORADORES</label>

              {item.collaborations && item.collaborations.length > 0 && (
                <div className="space-y-2 mb-4">
                  {item.collaborations.map(c => (
                    <div key={c.id} className="flex items-center justify-between bg-gray-900 px-3 py-2 rounded">
                      <div className="flex items-center gap-2">
                        {c.page_image && <img src={c.page_image} alt="" className="w-6 h-6 rounded-full object-cover" />}
                        <span className="text-sm text-white">{c.page_title}</span>
                        <span className={`text-xs px-2 py-0.5 rounded ${
                          c.status === 'accepted' ? 'bg-green-900 text-green-300' :
                          c.status === 'rejected' ? 'bg-red-900 text-red-300' :
                          'bg-yellow-900 text-yellow-300'
                        }`}>
                          {c.status === 'accepted' ? 'Aceptó' : c.status === 'rejected' ? 'Rechazó' : 'Pendiente'}
                        </span>
                      </div>
                      <button
                        type="button"
                        onClick={() => removeCollaboration(c.id)}
                        className="text-red-400 hover:text-red-300 text-sm"
                      >
                        Quitar
                      </button>
                    </div>
                  ))}
                </div>
              )}

              <div className="flex gap-2">
                <input
                  type="text"
                  value={pageSearchQuery}
                  onChange={(e) => {
                    setPageSearchQuery(e.target.value);
                    searchPagesForCollaboration(e.target.value);
                  }}
                  placeholder="Buscar página para invitar..."
                  className="flex-1 px-3 py-2 bg-black border border-gray-700 text-white text-sm focus:border-white transition"
                />
                {searchingPages && <span className="text-gray-500 text-sm self-center">...</span>}
              </div>

              {pageSearchResults.length > 0 && (
                <div className="mt-2 bg-black border border-gray-700 rounded max-h-40 overflow-y-auto">
                  {pageSearchResults
                    .filter(p => !item.collaborations?.some(c => c.collaborator_page_id == p.id))
                    .map(p => (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => addCollaborator(p)}
                        className="w-full text-left px-3 py-2 hover:bg-gray-800 text-sm text-white flex items-center gap-2"
                      >
                        <span className="font-medium">{p.title}</span>
                        <span className="text-gray-500 text-xs">/{p.slug}</span>
                      </button>
                    ))}
                </div>
              )}
              <p className="text-xs text-gray-600 mt-2">
                Las páginas invitadas recibirán una notificación para aceptar o rechazar la colaboración
              </p>
            </div>
          )}

          <div className="flex flex-col sm:flex-row gap-3 pt-4">
            <button
              type="button"
              onClick={() => navigate(volverA)}
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
        )}

        {tab !== 'datos' && group.type === 'eventos' && (
          <div className="flex gap-3 pt-8">
            <button
              type="button"
              onClick={() => navigate(volverA)}
              className="flex-1 px-4 py-3 border border-gray-700 text-white hover:bg-gray-800 transition font-bold"
            >
              VOLVER AL EDITOR
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default ItemEditor;
