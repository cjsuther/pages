import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, act } from '@testing-library/react';
import PageEditor from '../../src/pages/PageEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

/**
 * Modales de alta y edición de links: subida de imagen, campos según el tipo
 * de grupo, dirección de Google Maps y colaboradores.
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
      const etiqueta = tipo === 'galeria' ? '+ Imagen' : tipo === 'eventos' ? '+ Evento' : '+ Link';
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

  describe('edición de un link', () => {
    async function abrirEdicion(overrides = {}, tipo = 'links') {
      const mock = await render({
        page: pagina({
          groups: [grupo({ id: 10, type: tipo, links: [link({ id: 100, ...overrides })] })],
        }),
      });
      fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
      await screen.findByRole('button', { name: 'GUARDAR' });
      return mock;
    }

    it('sube una imagen nueva', async () => {
      const { llamadas } = await abrirEdicion();

      fireEvent.change(inputDeArchivoDelModal(), { target: { files: [archivo()] } });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'upload/image.php')).not.toBeNull();
      });

      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR' }));

      await waitFor(() => {
        const put = llamadas.find(
          (l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'
        );
        expect(cuerpoDe(put)).toMatchObject({ image_url: 'https://img/subida.png' });
      });
    });

    // El texto del botón sólo se ofrece en los grupos de eventos.
    it('permite editar el texto del botón de la URL de un evento', async () => {
      const { llamadas } = await abrirEdicion(
        { url: 'https://x.com', event_latitude: '-34.6', event_longitude: '-58.4' },
        'eventos'
      );

      const boton = screen.getByPlaceholderText('Más información');
      fireEvent.change(boton, { target: { value: 'Comprar' } });

      // El formulario del evento tiene campos obligatorios que el fixture deja
      // vacíos; se envía directamente para llegar al handler.
      fireEvent.submit(screen.getByRole('button', { name: 'GUARDAR' }).closest('form'));

      await waitFor(() => {
        const put = llamadas.find(
          (l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'
        );
        expect(cuerpoDe(put)).toMatchObject({ url_text: 'Comprar' });
      });
    });

    it('se puede cancelar sin guardar', async () => {
      const { llamadas } = await abrirEdicion();

      fireEvent.click(screen.getAllByRole('button', { name: 'CANCELAR' })[0]);

      await waitFor(() => {
        expect(screen.queryByRole('button', { name: 'GUARDAR' })).not.toBeInTheDocument();
      });
      expect(
        llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT')
      ).toBeUndefined();
    });

    it('un evento sin coordenadas no se puede guardar', async () => {
      const { llamadas } = await abrirEdicion(
        { text: 'Mi Evento', event_latitude: null, event_longitude: null },
        'eventos'
      );

      const form = screen.getByRole('button', { name: 'GUARDAR' }).closest('form');
      fireEvent.submit(form);

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'Debes seleccionar una dirección válida de Google Maps para el evento'
        );
      });
      expect(
        llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT')
      ).toBeUndefined();
    });
  });

  // ==================================================== colaboradores

  describe('colaboradores desde el modal de edición', () => {
    async function abrirEdicionEvento(collaborations = [], results = []) {
      const mock = await render({
        page: pagina({
          groups: [
            grupo({
              id: 20,
              type: 'eventos',
              links: [
                link({
                  id: 200,
                  text: 'Mi Evento',
                  event_latitude: '-34.6',
                  event_longitude: '-58.4',
                  collaborations,
                }),
              ],
            }),
          ],
        }),
        results,
      });
      fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
      await screen.findByRole('button', { name: 'GUARDAR' });
      return mock;
    }

    it('lista los colaboradores con su estado', async () => {
      await abrirEdicionEvento([
        { id: 1, status: 'accepted', page_title: 'Aceptó Página', collaborator_page_id: 7 },
        { id: 2, status: 'pending', page_title: 'Pendiente Página', collaborator_page_id: 8 },
        { id: 3, status: 'rejected', page_title: 'Rechazó Página', collaborator_page_id: 9 },
      ]);

      expect(screen.getByText('Aceptó')).toBeInTheDocument();
      expect(screen.getByText('Pendiente')).toBeInTheDocument();
      expect(screen.getByText('Rechazó')).toBeInTheDocument();
    });

    it('quita un colaborador desde el modal', async () => {
      const { llamadas } = await abrirEdicionEvento([
        { id: 1, status: 'accepted', page_title: 'Otra', collaborator_page_id: 7 },
      ]);

      fireEvent.click(screen.getByRole('button', { name: 'Quitar' }));

      await waitFor(() => {
        const del = llamadas.find(
          (l) => l.url.includes('collaborations/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=1');
      });
      expect(screen.queryByText('Otra')).not.toBeInTheDocument();
    });

    it('busca páginas e invita a colaborar', async () => {
      const { llamadas } = await abrirEdicionEvento(
        [],
        [{ id: 7, type: 'page', title: 'Otra Página', slug: 'otra' }]
      );

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'otra' },
      });

      const resultado = await screen.findByRole('button', { name: /Otra Página/ });
      fireEvent.click(resultado);

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('collaborations/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toEqual({ link_id: 200, collaborator_page_id: 7 });
      });
    });

    it('no ofrece invitar a una página que ya es colaboradora', async () => {
      await abrirEdicionEvento(
        [{ id: 1, status: 'pending', page_title: 'Otra Página', collaborator_page_id: 7 }],
        [{ id: 7, type: 'page', title: 'Otra Página', slug: 'otra' }]
      );

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'otra' },
      });

      await waitFor(() => {
        // Sólo queda la fila del colaborador ya invitado, sin botón para sumarlo.
        expect(screen.queryByRole('button', { name: /^Otra Página/ })).not.toBeInTheDocument();
      });
    });

    it('no busca si el campo queda en blanco', async () => {
      const { llamadas } = await abrirEdicionEvento();
      const buscador = screen.getByPlaceholderText('Buscar página para invitar...');

      fireEvent.change(buscador, { target: { value: '   ' } });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
      });
    });

    it('busca desde el primer caracter', async () => {
      const { llamadas } = await abrirEdicionEvento();

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'a' },
      });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php')).not.toBeNull();
      });
    });

    it('descarta los resultados que no son páginas', async () => {
      await abrirEdicionEvento([], [
        { id: 7, type: 'page', title: 'Una Página', slug: 'una' },
        { id: 8, type: 'event', title: 'Un Evento', slug: 'una' },
      ]);

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'un' },
      });

      expect(await screen.findByRole('button', { name: /Una Página/ })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Un Evento/ })).not.toBeInTheDocument();
    });
  });

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
