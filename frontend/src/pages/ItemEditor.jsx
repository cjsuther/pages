import React, { useState, useEffect, useContext, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router';
import { AuthContext } from '../App';
import LoadingSpinner from '../components/LoadingSpinner';
import GooglePlacesAutocomplete from '../components/GooglePlacesAutocomplete';
import PanelEntradas from '../components/PanelEntradas';
import PanelVentas from '../components/PanelVentas';
import PanelVentasProductos from '../components/PanelVentasProductos';
import ArchivoDeProducto from '../components/ArchivoDeProducto';
import LinkDeProducto from '../components/LinkDeProducto';
import AvisoMercadoPago from '../components/AvisoMercadoPago';
import EleccionDePrecio, { eleccionDesdePrecio, precioDeEleccion, problemaDeEleccion } from '../components/EleccionDePrecio';
import { analizarEmbed, EJEMPLOS_EMBED } from '../utils/embeds';
import { MAX_BYTES, excesoImagenEvento } from '../utils/imagenes';
import EditorCaracteristicas from '../components/EditorCaracteristicas';
import EditorFormasDeEntrega from '../components/EditorFormasDeEntrega';
import InterruptorActivo from '../components/InterruptorActivo';
import {
  subirArchivoDeProducto,
  esOculto,
  esFisico,
  caracteristicasParaEditar,
  caracteristicasParaGuardar,
  problemaDeCaracteristicas,
  problemaDeFormas,
  stockParaEditar,
  stockValido,
} from '../utils/productos';

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
  const [subiendoArchivo, setSubiendoArchivo] = useState(false);
  const [errorArchivo, setErrorArchivo] = useState('');
  // Tab activo de un evento (datos | entradas | ventas) o de un producto (datos | ventas).
  const [tab, setTab] = useState('datos');
  // Qué es este item de galería: imagen, youtube o instagram. Se deduce de la
  // URL guardada; el formulario lo usa para mostrar el campo que corresponde.
  const [tipoMedia, setTipoMedia] = useState('imagen');
  // El precio de un evento que no vende por Rezonar: "Gratis" o "Desde $X".
  // vendePorRezonar es null mientras no se sabe.
  const [eleccionPrecio, setEleccionPrecio] = useState(() => eleccionDesdePrecio(null));
  const [errorPrecio, setErrorPrecio] = useState(null);
  const [vendePorRezonar, setVendePorRezonar] = useState(null);
  const [pageSearchQuery, setPageSearchQuery] = useState('');
  const [pageSearchResults, setPageSearchResults] = useState([]);
  const [searchingPages, setSearchingPages] = useState(false);
  // Lo de un producto físico (#42): sus características y el stock de cada
  // combinación, como los edita EditorCaracteristicas.
  const [caracteristicas, setCaracteristicas] = useState([]);
  const [stockGrilla, setStockGrilla] = useState({});
  const [errorFisico, setErrorFisico] = useState(null);

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
          const encontrado = grupo.links.find(l => String(l.id) === String(linkId));
          setGroup(grupo);
          setItem({ ...encontrado });
          setTipoMedia(analizarEmbed(encontrado.embed_url)?.tipo || 'imagen');

          if (grupo.type === 'eventos') {
            setEleccionPrecio(eleccionDesdePrecio(encontrado.precio_desde));
            setVendePorRezonar(await vendeEntradasPorRezonar(encontrado.id));
          }

          if (grupo.type === 'productos' && esFisico(encontrado)) {
            setCaracteristicas(caracteristicasParaEditar(encontrado.caracteristicas));
            setStockGrilla(stockParaEditar(encontrado.variantes));
          }
        }
      }
    } catch (err) {
      console.error('Error cargando el item:', err);
    } finally {
      setLoading(false);
    }
  };

  /**
   * Si el evento vende entradas por acá: en ese caso el precio sale de la
   * venta y no se pide. Si no se puede saber, se pide: la API lo acepta igual.
   */
  const vendeEntradasPorRezonar = async (id) => {
    try {
      const r = await fetch(`${apiUrl}/entradas/evento.php?link_id=${id}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const cuerpo = await r.json();
      return !!(r.ok && cuerpo.entradas && Number(cuerpo.entradas.activo));
    } catch {
      return false;
    }
  };

  const uploadImage = async (file) => {
    const esEvento = group.type === 'eventos';

    if (file.size > MAX_BYTES) {
      alert('La imagen es muy grande. Máximo 5MB');
      return null;
    }

    // El afiche de un evento tiene un tope menor: más pesado, WhatsApp no lo
    // muestra al compartir. La API lo valida igual.
    const exceso = esEvento ? excesoImagenEvento(file) : null;
    if (exceso) {
      alert(exceso);
      return null;
    }

    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
      alert('Tipo de archivo no válido. Solo JPG, PNG, GIF y WebP');
      return null;
    }

    const formData = new FormData();
    formData.append('image', file);
    if (esEvento) formData.append('destino', 'evento');

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

  /**
   * El archivo de un producto se sube apenas se elige: el producto ya existe,
   * y así el guardar del formulario no queda esperando 50 MB. Al reemplazarlo,
   * la API borra el anterior.
   */
  const subirArchivo = async (file) => {
    setErrorArchivo('');
    setSubiendoArchivo(true);
    try {
      const actualizado = await subirArchivoDeProducto({ apiUrl, token, linkId: item.id, file });
      setItem((actual) => ({
        ...actual,
        archivo_nombre: actualizado.archivo_nombre,
        archivo_bytes: actualizado.archivo_bytes,
        archivo_tipo: actualizado.archivo_tipo,
      }));
    } catch (err) {
      setErrorArchivo(err.message);
    } finally {
      setSubiendoArchivo(false);
    }
  };

  // Las formas de entrega de un físico (#53): sólo lo que cambió.
  const cambiarEntrega = useCallback((cambios) => {
    setItem((actual) => ({ ...actual, ...cambios }));
    setErrorFisico(null);
  }, []);

  const handlePlaceSelect = (placeData) => {
    setItem({
      ...item,
      event_address: placeData.address,
      event_latitude: placeData.latitude,
      event_longitude: placeData.longitude,
      event_maps_url: placeData.mapsUrl
    });
  };

  const cambiarTipoMedia = (tipo) => {
    setTipoMedia(tipo);
    if (tipo === 'imagen') setItem({ ...item, embed_url: '' });
  };

  /**
   * Guarda el link del evento sin salir de la pantalla.
   *
   * El guardar de DATOS vuelve a la página al terminar, que es lo que se
   * espera de un formulario que se completa una vez. Acá no: se está eligiendo
   * cómo se venden las entradas y la pantalla tiene que seguir donde está.
   */
  const guardarEnlace = async ({ url, url_text }) => {
    const actualizado = { ...item, url, url_text };

    // Sólo el link: mandar el evento entero haría que la API pida el precio,
    // y elegir dónde se venden las entradas no es el lugar para eso.
    const r = await fetch(`${apiUrl}/links/detail.php?id=${item.id}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({ url, url_text })
    });

    if (!r.ok) {
      const cuerpo = await r.json().catch(() => ({}));
      throw new Error(cuerpo.error || 'No se pudo guardar el link');
    }

    setItem(actualizado);
  };

  const guardar = async (e) => {
    e.preventDefault();

    if (group.type === 'galeria' && tipoMedia !== 'imagen') {
      const embed = analizarEmbed(item.embed_url);
      if (!embed || embed.tipo !== tipoMedia) {
        alert(tipoMedia === 'youtube'
          ? 'Ese link no parece un video de YouTube'
          : 'Ese link no parece un post, reel o carrusel de Instagram');
        return;
      }
    }

    let cuerpo = item;

    if (group.type === 'eventos') {
      if (!item.event_latitude || !item.event_longitude) {
        alert('Debes seleccionar una dirección válida de Google Maps para el evento');
        return;
      }

      // Si vende por Rezonar el precio sale de la venta y no se pide. Si no,
      // tiene que decir si es gratis o desde cuánto, también un evento viejo
      // que nunca lo tuvo.
      if (!vendePorRezonar) {
        const problema = problemaDeEleccion(eleccionPrecio);
        if (problema) {
          setErrorPrecio(problema);
          return;
        }
        cuerpo = { ...item, precio_desde: precioDeEleccion(eleccionPrecio) };
      }
    }

    if (group.type === 'productos' && esFisico(item)) {
      const problema = problemaDeFormas(item)
        || problemaDeCaracteristicas(caracteristicas)
        || (Object.values(stockGrilla).every(stockValido) && stockValido(item.stock ?? '')
          ? null
          : 'El stock tiene que ser un número entero igual o mayor que 0, o quedar vacío');
      if (problema) {
        setErrorFisico(problema);
        return;
      }
      setErrorFisico(null);

      // Lo que calcula la API (agotado, variantes con su clave) no vuelve.
      const resto = { ...item };
      delete resto.agotado;
      delete resto.variantes;
      cuerpo = {
        ...resto,
        stock: caracteristicas.length ? '' : (item.stock ?? ''),
        ...caracteristicasParaGuardar(caracteristicas, stockGrilla),
      };
    }

    try {
      setSaving(true);
      const response = await fetch(`${apiUrl}/links/detail.php?id=${item.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(cuerpo)
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
      <div className="flex items-center justify-center min-h-screen bg-white">
        <LoadingSpinner message="Cargando..." />
      </div>
    );
  }

  if (!item || !group) {
    return (
      <div className="min-h-screen bg-white text-tinta flex flex-col items-center justify-center gap-4">
        <p className="text-tinta-media">No encontramos este item.</p>
        <Link to={volverA} className="inline-flex items-center justify-center gap-2 rounded-full bg-verde text-verde-tinta px-6 py-3 font-semibold hover:bg-verde-oscuro hover:text-white transition-colors">
          Volver al editor
        </Link>
      </div>
    );
  }

  const queEs = group.type === 'galeria' ? 'contenido'
    : group.type === 'eventos' ? 'evento'
    : group.type === 'productos' ? 'producto'
    : 'link';

  // Un evento y un producto tienen más que sus datos: las ventas (y las
  // entradas, el evento). El resto es un formulario solo.
  const pestanas = group.type === 'eventos'
    ? [
        { clave: 'datos', etiqueta: 'Datos' },
        { clave: 'entradas', etiqueta: 'Entradas' },
        { clave: 'ventas', etiqueta: 'Ventas' },
      ]
    : group.type === 'productos'
      ? [
          { clave: 'datos', etiqueta: 'Datos' },
          { clave: 'ventas', etiqueta: 'Ventas' },
        ]
      : null;

  return (
    <div className="min-h-screen bg-white text-tinta">
      {saving && (
        <div className="fixed inset-0 bg-tinta/40 backdrop-blur-sm flex items-center justify-center z-50">
          <div className="bg-white border border-borde rounded-2xl p-6">
            <LoadingSpinner />
          </div>
        </div>
      )}

      <nav className="border-b border-borde">
        <div className="max-w-3xl mx-auto px-6 py-6">
          <div className="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
            <div className="min-w-0">
              <h1 className="text-3xl font-bold tracking-tight">Editar {queEs}</h1>
              <p className="text-tinta-suave mt-1 font-medium truncate">
                {page?.title} · {group.title}
              </p>
            </div>
            <Link
              to={volverA}
              className="text-tinta-media hover:text-tinta transition font-medium shrink-0"
            >
              ← Volver al editor
            </Link>
          </div>
        </div>
      </nav>

      <div className="max-w-3xl mx-auto px-6 py-10">
        {pestanas && (
          <nav className="flex gap-1 border-b border-borde mb-8" aria-label={`Secciones del ${queEs}`}>
            {pestanas.map((t) => (
              <button
                key={t.clave}
                type="button"
                onClick={() => setTab(t.clave)}
                className={`px-4 py-3 text-sm font-bold tracking-wide transition border-b-2 -mb-px ${
                  tab === t.clave
                    ? 'border-verde text-tinta'
                    : 'border-transparent text-tinta-suave hover:text-tinta-media'
                }`}
              >
                {t.etiqueta}
              </button>
            ))}
          </nav>
        )}

        {tab === 'entradas' && group.type === 'eventos' && (
          <PanelEntradas
            linkId={item.id}
            apiUrl={apiUrl}
            token={token}
            onCambio={(entradas) => setVendePorRezonar(!!(entradas && Number(entradas.activo)))}
            enlace={{ url: item.url, url_text: item.url_text }}
            onGuardarEnlace={guardarEnlace}
          />
        )}

        {tab === 'ventas' && group.type === 'eventos' && (
          <PanelVentas linkId={item.id} apiUrl={apiUrl} token={token} nombreEvento={item.text} />
        )}

        {tab === 'ventas' && group.type === 'productos' && (
          <PanelVentasProductos
            linkId={item.id}
            apiUrl={apiUrl}
            token={token}
            nombre={item.text}
            tipo={esFisico(item) ? 'fisico' : 'digital'}
          />
        )}

        {(tab === 'datos' || !pestanas) && (
        <form onSubmit={guardar} className="space-y-6">
          {group.type !== 'galeria' && (
            <>
              <div>
                <label htmlFor="texto-del-item" className="block text-sm font-semibold text-tinta mb-1.5">
                  {group.type === 'eventos' ? 'Nombre del evento' : group.type === 'productos' ? 'Título' : 'Texto'}
                </label>
                <input
                  id="texto-del-item"
                  type="text"
                  value={item.text || ''}
                  onChange={(e) => setItem({ ...item, text: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  required
                />
              </div>
              {/* Un producto no lleva a ninguna parte: se compra. */}
              {group.type === 'eventos' || group.type === 'productos' ? null : (
                <div>
                  <label className="block text-sm font-semibold text-tinta mb-1.5">URL</label>
                  <input
                    type="url"
                    value={item.url || ''}
                    onChange={(e) => setItem({ ...item, url: e.target.value })}
                    className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    required
                  />
                </div>
              )}
            </>
          )}

          {group.type === 'galeria' && (
            <>
              <div>
                <label htmlFor="tipo-de-contenido" className="block text-sm font-semibold text-tinta mb-1.5">
                  Tipo de contenido
                </label>
                <select
                  id="tipo-de-contenido"
                  value={tipoMedia}
                  onChange={(e) => cambiarTipoMedia(e.target.value)}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                >
                  <option value="imagen">Imagen</option>
                  <option value="youtube">Video de YouTube</option>
                  <option value="instagram">Instagram</option>
                </select>
              </div>

              {tipoMedia !== 'imagen' && (
                <div>
                  <label htmlFor="url-del-contenido" className="block text-sm font-semibold text-tinta mb-1.5">
                    {tipoMedia === 'youtube' ? 'URL del video' : 'URL del contenido'}
                  </label>
                  <input
                    id="url-del-contenido"
                    type="url"
                    value={item.embed_url || ''}
                    onChange={(e) => setItem({ ...item, embed_url: e.target.value })}
                    placeholder={tipoMedia === 'youtube'
                      ? 'https://www.youtube.com/watch?v=...'
                      : 'https://www.instagram.com/p/...'}
                    className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    required
                  />
                  {/* Lo de "y que se pueda mostrar afuera" no sobra: hay
                      posteos de cuentas públicas que Instagram igual se niega
                      a servir. Desde acá no hay forma de saberlo de antemano,
                      así que al menos se avisa dónde mirar cuando pasa. */}
                  <p className="text-xs text-tinta-suave mt-1">
                    {tipoMedia === 'youtube'
                      ? 'Sirve el link del video, el de compartir o el de un short'
                      : 'De Instagram se muestra sólo la foto, el carrusel o el video. '
                        + 'La cuenta tiene que ser pública y el posteo tiene que poder mostrarse '
                        + 'afuera de Instagram: si no se ve, probá abrir el link en una ventana '
                        + 'de incógnito, y si ahí tampoco se ve, subí una portada.'}
                  </p>
                </div>
              )}
            </>
          )}

          <div>
            <label className="block text-sm font-semibold text-tinta mb-1.5">
              {group.type !== 'galeria' ? 'IMAGEN (OPCIONAL)'
                : tipoMedia === 'imagen' ? 'Imagen'
                : 'PORTADA (OPCIONAL)'}
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
                className="text-sm text-tinta-media"
              />
              {uploadingImage && <span className="text-sm text-tinta-suave">Subiendo...</span>}
            </div>
            <p className="text-xs text-tinta-suave mt-1">
              {group.type === 'galeria' && tipoMedia === 'instagram'
                ? 'Sin portada, en la grilla se muestra el contenido tal como lo publica Instagram (máx 5MB)'
                : group.type === 'galeria' && tipoMedia === 'youtube'
                ? 'Opcional: sin portada se usa la miniatura del video (máx 5MB)'
                : group.type === 'eventos'
                ? 'Sube una nueva imagen para reemplazar (máx 600 KB, para que se vea al compartir en WhatsApp)'
                : 'Sube una nueva imagen para reemplazar (máx 5MB)'}
            </p>
          </div>

          {group.type === 'galeria' && (
            <>
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Título (opcional)</label>
                <input
                  type="text"
                  value={item.text || ''}
                  onChange={(e) => setItem({ ...item, text: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                />
              </div>
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">Link (opcional)</label>
                <input
                  type="url"
                  value={item.url || ''}
                  onChange={(e) => setItem({ ...item, url: e.target.value })}
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                />
              </div>
            </>
          )}

          {group.type === 'eventos' && (
            <>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-semibold text-tinta mb-1.5">Fecha</label>
                  <input
                    type="date"
                    value={item.event_date || ''}
                    onChange={(e) => setItem({ ...item, event_date: e.target.value })}
                    className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-semibold text-tinta mb-1.5">Hora</label>
                  <input
                    type="time"
                    value={item.event_time || ''}
                    onChange={(e) => setItem({ ...item, event_time: e.target.value })}
                    className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                  />
                </div>
              </div>
              {vendePorRezonar ? (
                <p className="text-sm text-tinta-media">
                  El precio que se muestra sale de la venta de entradas por Rezonar.
                </p>
              ) : vendePorRezonar === false && (
                <EleccionDePrecio
                  id="editar-precio"
                  eleccion={eleccionPrecio}
                  onChange={(nueva) => { setEleccionPrecio(nueva); setErrorPrecio(null); }}
                  error={errorPrecio}
                  sinPrecioGuardado={item.precio_desde === null || item.precio_desde === undefined || item.precio_desde === ''}
                />
              )}
              <div>
                <label className="block text-sm font-semibold text-tinta mb-1.5">
                  Dirección<span className="text-red-500">*</span>
                </label>
                <GooglePlacesAutocomplete
                  value={item.event_address || ''}
                  onChange={(e) => setItem({ ...item, event_address: e.target.value })}
                  onPlaceSelect={handlePlaceSelect}
                  placeholder="Buscar dirección en Google Maps..."
                  required={true}
                />
                <p className="text-xs text-tinta-suave mt-1">
                  Selecciona una dirección de las sugerencias para capturar las coordenadas
                </p>
                {item.event_latitude && item.event_longitude && (
                  <p className="text-xs text-verde-oscuro mt-1">
                    ✓ Coordenadas capturadas correctamente
                  </p>
                )}
              </div>
            </>
          )}

          {group.type !== 'galeria' && (
            <div>
              <label className="block text-sm font-semibold text-tinta mb-1.5">
                {group.type === 'eventos' ? 'Descripción del evento' : 'Descripción (opcional)'}
              </label>
              <textarea
                value={item.description || ''}
                onChange={(e) => setItem({ ...item, description: e.target.value })}
                className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                rows="4"
              />
            </div>
          )}

          {group.type === 'productos' && (
            <>
              <div>
                <label htmlFor="precio-producto" className="block text-sm font-semibold text-tinta mb-1.5">
                  Precio
                </label>
                <input
                  id="precio-producto"
                  type="number"
                  min="0"
                  step="0.01"
                  value={item.precio ?? ''}
                  onChange={(e) => setItem({ ...item, precio: e.target.value })}
                  placeholder="0"
                  className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                />
                <p className="text-xs text-tinta-suave mt-1">En pesos. 0 = gratis.</p>
              </div>

              <AvisoMercadoPago pageId={id} apiUrl={apiUrl} token={token} precio={item.precio} />

              <div>
                <InterruptorActivo
                  activo={!esOculto(item.oculto)}
                  onChange={(activo) => setItem({ ...item, oculto: !activo })}
                  etiqueta="Activo"
                />
                <p className="text-xs text-tinta-suave mt-1">
                  {esFisico(item)
                    ? 'Inactivo: no se muestra en tu página ni en su link y no acepta pedidos nuevos. Los pedidos que ya existen siguen su curso.'
                    : 'Inactivo: no se muestra en tu página ni en su link y no acepta pedidos nuevos. Quienes ya compraron pueden seguir descargando.'}
                </p>
              </div>

              {esFisico(item) ? (
                <>
                  <p className="text-sm text-tinta-media">
                    <span className="font-semibold text-tinta">Producto físico.</span>{' '}
                    Se manda o se entrega: no lleva archivo. El tipo no se cambia después de crearlo.
                  </p>

                  <EditorFormasDeEntrega item={item} onChange={cambiarEntrega} />

                  <EditorCaracteristicas
                    caracteristicas={caracteristicas}
                    onChange={(nuevas) => { setCaracteristicas(nuevas); setErrorFisico(null); }}
                    stock={stockGrilla}
                    onStockChange={(nuevo) => { setStockGrilla(nuevo); setErrorFisico(null); }}
                  />

                  {caracteristicas.length === 0 && (
                    <div>
                      <label htmlFor="stock-producto" className="block text-sm font-semibold text-tinta mb-1.5">
                        Stock (opcional)
                      </label>
                      <input
                        id="stock-producto"
                        type="number"
                        min="0"
                        step="1"
                        inputMode="numeric"
                        value={item.stock ?? ''}
                        onChange={(e) => { setItem({ ...item, stock: e.target.value }); setErrorFisico(null); }}
                        placeholder="Sin límite"
                        className="w-full px-4 py-3 rounded-xl bg-white border border-borde-fuerte text-tinta placeholder-tinta-suave focus:border-verde-oscuro focus:outline-none transition-colors"
                      />
                      <p className="text-xs text-tinta-suave mt-1">Vacío es sin límite. Con 0 se muestra "Agotado".</p>
                    </div>
                  )}

                  {errorFisico && <p role="alert" className="text-sm text-red-700">{errorFisico}</p>}
                </>
              ) : (
                <ArchivoDeProducto
                  archivo={item.archivo_nombre ? { nombre: item.archivo_nombre, bytes: item.archivo_bytes } : null}
                  subiendo={subiendoArchivo}
                  onElegir={subirArchivo}
                  error={errorArchivo}
                />
              )}

              <LinkDeProducto
                linkId={item.id}
                oculto={esOculto(item.oculto)}
                conArchivo={esFisico(item) || Boolean(item.archivo_nombre)}
              />
            </>
          )}

          {group.type === 'eventos' && (
            <div className="border-t border-borde-fuerte pt-6">
              <label className="block text-sm font-semibold text-tinta mb-1.5">Colaboradores</label>

              {item.collaborations && item.collaborations.length > 0 && (
                <div className="space-y-2 mb-4">
                  {item.collaborations.map(c => (
                    <div key={c.id} className="flex items-center justify-between bg-papel-hueso border border-borde px-3 py-2 rounded-lg">
                      <div className="flex items-center gap-2">
                        {c.page_image && <img src={c.page_image} alt="" className="w-6 h-6 rounded-full object-cover" />}
                        <span className="text-sm text-tinta">{c.page_title}</span>
                        <span className={`text-xs px-2 py-0.5 rounded ${
                          c.status === 'accepted' ? 'bg-verde-claro text-verde-oscuro' :
                          c.status === 'rejected' ? 'bg-red-50 text-red-700' :
                          'bg-yellow-900 text-yellow-300'
                        }`}>
                          {c.status === 'accepted' ? 'Aceptó' : c.status === 'rejected' ? 'Rechazó' : 'Pendiente'}
                        </span>
                      </div>
                      <button
                        type="button"
                        onClick={() => removeCollaboration(c.id)}
                        className="text-red-700 hover:text-red-700 text-sm"
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
                  className="flex-1 px-3 py-2 bg-white border border-borde-fuerte text-tinta text-sm focus:border-verde-oscuro transition"
                />
                {searchingPages && <span className="text-tinta-suave text-sm self-center">...</span>}
              </div>

              {pageSearchResults.length > 0 && (
                <div className="mt-2 bg-white border border-borde-fuerte rounded max-h-40 overflow-y-auto">
                  {pageSearchResults
                    .filter(p => !item.collaborations?.some(c => c.collaborator_page_id == p.id))
                    .map(p => (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => addCollaborator(p)}
                        className="w-full text-left px-3 py-2 hover:bg-papel-hueso text-sm text-tinta flex items-center gap-2"
                      >
                        <span className="font-medium">{p.title}</span>
                        <span className="text-tinta-suave text-xs">/{p.slug}</span>
                      </button>
                    ))}
                </div>
              )}
              <p className="text-xs text-tinta-suave mt-2">
                Las páginas invitadas recibirán una notificación para aceptar o rechazar la colaboración
              </p>
            </div>
          )}

          <div className="flex flex-col sm:flex-row gap-3 pt-4">
            <button
              type="button"
              onClick={() => navigate(volverA)}
              className="flex-1 px-4 py-3 border border-borde-fuerte text-tinta hover:bg-papel-hueso transition font-bold"
            >
              Cancelar
            </button>
            {/* Salir mientras sube cortaría la subida a la mitad. */}
            <button
              type="submit"
              disabled={subiendoArchivo}
              className="flex-1 bg-verde text-verde-tinta px-4 py-3 font-bold hover:bg-verde-oscuro hover:text-tinta transition disabled:opacity-50"
            >
              Guardar
            </button>
          </div>
        </form>
        )}

        {tab !== 'datos' && pestanas && (
          <div className="flex gap-3 pt-8">
            <button
              type="button"
              onClick={() => navigate(volverA)}
              className="flex-1 px-4 py-3 border border-borde-fuerte text-tinta hover:bg-papel-hueso transition font-bold"
            >
              Volver al editor
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default ItemEditor;
