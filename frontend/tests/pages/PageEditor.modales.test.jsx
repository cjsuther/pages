import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, act } from '@testing-library/react';
import PageEditor from '../../src/pages/PageEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

/**
 * Modal de alta de links: subida de imagen, campos según el tipo de grupo y
 * dirección de Google Maps. La edición vive en ItemEditor.test.jsx.
 */

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const link = (overrides = {}) => ({
  id: 100,
  url: 'https://ejemplo.com',
  url_text: null,
  text: 'Un link',
  description: null,
  image_url: null,
  position: 0,
  collaborations: [],
  ...overrides,
});

const grupo = (overrides = {}) => ({
  id: 10,
  title: 'Mis Links',
  type: 'links',
  position: 0,
  links: [],
  collaborated_events: [],
  ...overrides,
});

const pagina = (overrides = {}) => ({
  id: 5,
  user_id: 9,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  profile_image: null,
  background_image: null,
  primary_color: '#3B82F6',
  secondary_color: '#1E40AF',
  background_color: '#FFFFFF',
  text_color: '#000000',
  template: 'minimal',
  groups: [],
  ...overrides,
});

function mockearEditor({ page = pagina(), results = [] } = {}) {
  return mockFetch({
    'pages/detail.php': { page },
    'admins/index.php': { admins: [] },
    'admins/detail.php': { message: 'ok' },
    'collaborations/index.php': { pending: [], collaborations: [], collaboration_id: 40 },
    'collaborations/detail.php': { message: 'ok' },
    'groups/index.php': { group: grupo() },
    'groups/detail.php': { group: grupo() },
    'links/index.php': { link: link() },
    'links/detail.php': { link: link() },
    'public/search.php': { results },
    'upload/image.php': { url: 'https://img/subida.png' },
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'users/location.php': { latitude: null, longitude: null },
  });
}

async function render(datos = {}) {
  const mock = mockearEditor(datos);
  const resultado = renderConProviders(<PageEditor />, {
    auth: autenticado(),
    route: '/page/5',
    path: '/page/:id',
  });
  await screen.findByRole('heading', { name: 'EDITOR' });

  // Todo lo que prueba este archivo vive en la solapa de contenido.
  fireEvent.click(screen.getByRole('button', { name: /^CONTENIDO/ }));
  await screen.findByRole('heading', { name: 'GRUPOS DE LINKS' });

  return { ...resultado, ...mock };
}

function archivo({ nombre = 'foto.jpg', tipo = 'image/jpeg', bytes = 1024 } = {}) {
  const f = new File(['x'], nombre, { type: tipo });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

/** Simula Google Places y devuelve el listener del autocompletado. */
function instalarGoogleMaps() {
  const listeners = [];
  const crearAutocomplete = () => ({
    addListener: vi.fn((evento, cb) => listeners.push(cb)),
    getPlace: vi.fn(() => ({
      formatted_address: 'Av. Corrientes 1234',
      url: 'https://maps.google.com/x',
      geometry: { location: { lat: () => -34.6037, lng: () => -58.3816 } },
    })),
  });

  window.google = {
    maps: {
      places: { Autocomplete: vi.fn(crearAutocomplete) },
      event: { clearInstanceListeners: vi.fn() },
    },
  };

  return listeners;
}

/** El input de archivo del modal abierto (el último del documento). */
const inputDeArchivoDelModal = () => {
  const inputs = document.querySelectorAll('form input[type="file"]');
  return inputs[inputs.length - 1];
};

describe('PageEditor — modales de link', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    window.gtag = vi.fn();
  });

  afterEach(() => {
    window.google = undefined;
  });

  // ================================================== alta: imagen del link

  describe('imagen al crear un link', () => {
    async function abrirAlta(tipo = 'links') {
      const mock = await render({ page: pagina({ groups: [grupo({ id: 10, type: tipo })] }) });
      const etiqueta = tipo === 'galeria' ? '+ Contenido' : tipo === 'eventos' ? '+ Evento' : '+ Link';
      fireEvent.click(screen.getByRole('button', { name: etiqueta }));
      await screen.findByRole('button', { name: 'CREAR' });
      return mock;
    }

    it('sube la imagen y muestra la vista previa', async () => {
      const { llamadas } = await abrirAlta();

      fireEvent.change(inputDeArchivoDelModal(), { target: { files: [archivo()] } });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'upload/image.php')).not.toBeNull();
      });
      expect(await screen.findByAltText('Vista previa')).toHaveAttribute(
        'src',
        'https://img/subida.png'
      );
    });

    it('permite quitar la imagen elegida', async () => {
      await abrirAlta();

      fireEvent.change(inputDeArchivoDelModal(), { target: { files: [archivo()] } });
      const preview = await screen.findByAltText('Vista previa');

      fireEvent.click(preview.closest('div').querySelector('button'));

      await waitFor(() => {
        expect(screen.queryByAltText('Vista previa')).not.toBeInTheDocument();
      });
    });

    it('rechaza una imagen demasiado grande', async () => {
      const { llamadas } = await abrirAlta();

      fireEvent.change(inputDeArchivoDelModal(), {
        target: { files: [archivo({ bytes: 6 * 1024 * 1024 })] },
      });

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('La imagen es muy grande. Máximo 5MB');
      });
      expect(llamadaA(llamadas, 'upload/image.php')).toBeNull();
    });

    it('en un grupo de galería la imagen es obligatoria', async () => {
      await abrirAlta('galeria');

      expect(inputDeArchivoDelModal()).toBeRequired();
    });

    it('en un grupo de links la imagen es opcional', async () => {
      await abrirAlta('links');

      expect(inputDeArchivoDelModal()).not.toBeRequired();
    });

    it('la galería ofrece título y link opcionales', async () => {
      await abrirAlta('galeria');

      expect(screen.getByText('TÍTULO (OPCIONAL)')).toBeInTheDocument();
      expect(screen.getByText('LINK (OPCIONAL)')).toBeInTheDocument();
    });
  });

  // ============================================ galería con videos

  describe('alta de un video o un contenido de Instagram', () => {
    const VIDEO = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    async function abrirAltaGaleria() {
      const mock = await render({ page: pagina({ groups: [grupo({ id: 10, type: 'galeria' })] }) });
      fireEvent.click(screen.getByRole('button', { name: '+ Contenido' }));
      await screen.findByRole('button', { name: 'CREAR' });
      return mock;
    }

    it('la galería arranca en imagen', async () => {
      await abrirAltaGaleria();

      expect(screen.getByLabelText('TIPO DE CONTENIDO')).toHaveValue('imagen');
      expect(screen.queryByLabelText('URL DEL VIDEO')).not.toBeInTheDocument();
    });

    it('crea un video de YouTube', async () => {
      const { llamadas } = await abrirAltaGaleria();

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'youtube' } });
      fireEvent.change(screen.getByLabelText('URL DEL VIDEO'), { target: { value: VIDEO } });
      fireEvent.submit(screen.getByRole('button', { name: 'CREAR' }).closest('form'));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('links/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toMatchObject({ embed_url: VIDEO, group_id: 10 });
      });
    });

    // La imagen es obligatoria porque sin ella no hay nada que mostrar; con un
    // video, la miniatura la pone YouTube.
    it('con un video la portada deja de ser obligatoria', async () => {
      await abrirAltaGaleria();
      expect(inputDeArchivoDelModal()).toBeRequired();

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'youtube' } });

      expect(inputDeArchivoDelModal()).not.toBeRequired();
    });

    it('no crea nada si el link no es del servicio elegido', async () => {
      const { llamadas } = await abrirAltaGaleria();

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'youtube' } });
      fireEvent.change(screen.getByLabelText('URL DEL VIDEO'), {
        target: { value: 'https://www.instagram.com/p/CxAbC123_-x/' },
      });
      fireEvent.submit(screen.getByRole('button', { name: 'CREAR' }).closest('form'));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Ese link no parece un video de YouTube');
      });
      expect(llamadaA(llamadas, 'links/index.php')).toBeNull();
    });
  });

  // ================================================= alta: evento con mapa

  describe('dirección del evento al crear', () => {
    async function abrirAltaEvento() {
      const listeners = instalarGoogleMaps();
      const mock = await render({ page: pagina({ groups: [grupo({ id: 20, type: 'eventos' })] }) });
      fireEvent.click(screen.getByRole('button', { name: '+ Evento' }));
      await screen.findByRole('button', { name: 'CREAR' });
      return { ...mock, listeners };
    }

    it('el formulario pide fecha, hora y dirección', async () => {
      await abrirAltaEvento();

      expect(screen.getByText('FECHA')).toBeInTheDocument();
      expect(screen.getByText('HORA')).toBeInTheDocument();
      expect(screen.getByPlaceholderText('Buscar dirección en Google Maps...')).toBeInTheDocument();
    });

    it('al elegir una dirección guarda las coordenadas y deja crear', async () => {
      const { listeners, llamadas } = await abrirAltaEvento();

      const crear = screen.getByRole('button', { name: 'CREAR' });
      const form = crear.closest('form');

      // Primero los campos obligatorios y recién después la dirección: cada
      // cambio hace setNewLink({ ...newLink, campo }), así que tocar otro
      // campo después de elegir el lugar pisaría las coordenadas.
      form.querySelectorAll('input[required], textarea[required]').forEach((campo) => {
        if (campo.type !== 'file') fireEvent.change(campo, { target: { value: 'x' } });
      });

      act(() => listeners.forEach((cb) => cb()));

      fireEvent.submit(form);

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('links/index.php') && l.options.method === 'POST'
        );
        expect(post).toBeDefined();
        expect(cuerpoDe(post)).toMatchObject({
          event_latitude: -34.6037,
          event_longitude: -58.3816,
          event_address: 'Av. Corrientes 1234',
          event_maps_url: 'https://maps.google.com/x',
        });
      });
      expect(window.alert).not.toHaveBeenCalled();
    });
  });

  // =============================================== edición: imagen y campos

  // La edición de un item ya no es un modal: vive en ItemEditor y sus tests
  // están en ItemEditor.test.jsx. Acá queda sólo el alta.

  // ============================================== eventos colaborados

  describe('eventos colaborados de otras páginas', () => {
    it('se muestran dentro del grupo', async () => {
      await render({
        page: pagina({
          groups: [
            grupo({
              id: 20,
              type: 'eventos',
              links: [],
              collaborated_events: [
                {
                  id: 300,
                  text: 'Evento Ajeno',
                  collaboration_id: 5,
                  source_page_title: 'Otra Página',
                  event_date: '2026-12-01',
                  event_time: '20:00:00',
                },
              ],
            }),
          ],
        }),
      });

      expect(await screen.findByText(/Evento Ajeno/)).toBeInTheDocument();
    });
  });
});
