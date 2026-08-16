import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import PageEditor from '../../src/pages/PageEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

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

const evento = (overrides = {}) =>
  link({
    id: 200,
    text: 'Mi Evento',
    event_date: '2026-12-01',
    event_time: '20:00:00',
    event_address: 'Av. Corrientes 1234',
    event_latitude: '-34.6037',
    event_longitude: '-58.3816',
    event_maps_url: 'https://maps.google.com/x',
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
  // usuarioDePrueba() tiene id 9: así el editor reconoce al dueño y muestra
  // la sección de administradores.
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

function mockearEditor({ page = pagina(), admins = [], pending = [], results = [] } = {}) {
  return mockFetch({
    'pages/detail.php': { page },
    'admins/index.php': { admins },
    'admins/detail.php': { message: 'ok' },
    'collaborations/index.php': { pending, collaborations: [] },
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

describe('PageEditor', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    window.gtag = vi.fn();
  });

  afterEach(() => {
    window.google = undefined;
  });

  describe('carga', () => {
    it('pide la página por el id de la ruta', async () => {
      const { llamadas } = await render();

      expect(llamadaA(llamadas, 'pages/detail.php').url).toContain('id=5');
    });

    it('muestra las secciones principales', async () => {
      await render();

      expect(screen.getByRole('heading', { name: 'CONFIGURACIÓN' })).toBeInTheDocument();
      expect(screen.getByRole('heading', { name: 'GRUPOS DE LINKS' })).toBeInTheDocument();
      expect(screen.getByRole('heading', { name: 'ADMINISTRADORES' })).toBeInTheDocument();
    });

    it('avisa si la página no tiene grupos', async () => {
      await render({ page: pagina({ groups: [] }) });

      expect(screen.getByText('No hay grupos todavía')).toBeInTheDocument();
    });

    it('no rompe si falla la carga', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<PageEditor />, {
        auth: autenticado(), route: '/page/5', path: '/page/:id',
      });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('grupos', () => {
    it('lista los grupos con su tipo', async () => {
      await render({
        page: pagina({
          groups: [
            grupo({ id: 1, title: 'Mis Links', type: 'links' }),
            grupo({ id: 2, title: 'Agenda', type: 'eventos' }),
            grupo({ id: 3, title: 'Fotos', type: 'galeria' }),
            grupo({ id: 4, title: 'Redes', type: 'redes' }),
          ],
        }),
      });

      expect(screen.getByText('Mis Links')).toBeInTheDocument();
      expect(screen.getByText('Links')).toBeInTheDocument();
      expect(screen.getByText('Eventos')).toBeInTheDocument();
      expect(screen.getByText('Galería')).toBeInTheDocument();
      expect(screen.getByText('Redes Sociales')).toBeInTheDocument();
    });

    it('abre el modal de nuevo grupo', async () => {
      await render();

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));

      expect(await screen.findByRole('heading', { name: 'NUEVO GRUPO' })).toBeInTheDocument();
    });

    it('crea el grupo con el page_id de la ruta', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'NUEVO GRUPO' });

      const modal = screen.getByRole('heading', { name: 'NUEVO GRUPO' }).closest('div');
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'Nuevo Grupo' } });
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('groups/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toMatchObject({ title: 'Nuevo Grupo', page_id: '5' });
      });
    });

    it('el tipo por defecto es links', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'NUEVO GRUPO' });

      const modal = screen.getByRole('heading', { name: 'NUEVO GRUPO' }).closest('div');
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'G' } });
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('groups/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post).type).toBe('links');
      });
    });

    it('se puede cancelar la creación', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'NUEVO GRUPO' });
      fireEvent.click(screen.getAllByRole('button', { name: 'CANCELAR' })[0]);

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'NUEVO GRUPO' })).not.toBeInTheDocument();
      });
      expect(
        llamadas.find((l) => l.url.includes('groups/index.php') && l.options.method === 'POST')
      ).toBeUndefined();
    });

    it('recarga la página tras crear el grupo', async () => {
      const { llamadas } = await render();
      const antes = llamadas.filter((l) => l.url.includes('pages/detail.php')).length;

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'NUEVO GRUPO' });
      const modal = screen.getByRole('heading', { name: 'NUEVO GRUPO' }).closest('div');
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'G' } });
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      await waitFor(() => {
        const despues = llamadas.filter((l) => l.url.includes('pages/detail.php')).length;
        expect(despues).toBeGreaterThan(antes);
      });
    });
  });

  describe('reordenar grupos', () => {
    const dosGrupos = () =>
      pagina({
        groups: [
          grupo({ id: 1, title: 'Primero', position: 0 }),
          grupo({ id: 2, title: 'Segundo', position: 1 }),
        ],
      });

    it('el primer grupo no puede subir', async () => {
      await render({ page: dosGrupos() });

      const subir = screen.getAllByTitle('Mover arriba');
      expect(subir[0]).toBeDisabled();
    });

    it('el último grupo no puede bajar', async () => {
      await render({ page: dosGrupos() });

      const bajar = screen.getAllByTitle('Mover abajo');
      expect(bajar[bajar.length - 1]).toBeDisabled();
    });

    it('mover un grupo actualiza las posiciones', async () => {
      const { llamadas } = await render({ page: dosGrupos() });

      fireEvent.click(screen.getAllByTitle('Mover abajo')[0]);

      await waitFor(() => {
        const puts = llamadas.filter(
          (l) => l.url.includes('groups/detail.php') && l.options.method === 'PUT'
        );
        expect(puts.length).toBeGreaterThan(0);
        expect(cuerpoDe(puts[0])).toHaveProperty('position');
      });
    });
  });

  describe('links dentro de un grupo', () => {
    const conLinks = () =>
      pagina({
        groups: [grupo({ id: 10, type: 'links', links: [link({ id: 100, text: 'Instagram' })] })],
      });

    it('lista los links del grupo', async () => {
      await render({ page: conLinks() });

      expect(screen.getByText('Instagram')).toBeInTheDocument();
    });

    it('ofrece agregar un link al grupo', async () => {
      await render({ page: conLinks() });

      expect(screen.getByRole('button', { name: '+ Link' })).toBeInTheDocument();
    });

    it('la etiqueta del botón depende del tipo de grupo', async () => {
      await render({
        page: pagina({
          groups: [
            grupo({ id: 1, type: 'links' }),
            grupo({ id: 2, type: 'eventos' }),
            grupo({ id: 3, type: 'galeria' }),
          ],
        }),
      });

      expect(screen.getByRole('button', { name: '+ Link' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: '+ Evento' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: '+ Imagen' })).toBeInTheDocument();
    });
  });

  describe('eventos: validación de coordenadas', () => {
    const grupoEventos = () =>
      pagina({ groups: [grupo({ id: 20, title: 'Agenda', type: 'eventos', links: [] })] });

    it('no deja crear un evento sin dirección de Google Maps', async () => {
      const { llamadas } = await render({ page: grupoEventos() });

      fireEvent.click(screen.getByRole('button', { name: '+ Evento' }));
      const crear = await screen.findByRole('button', { name: 'CREAR' });

      // Se completan los campos obligatorios para que el navegador deje
      // enviar el formulario y se llegue a la validación de coordenadas.
      const modal = crear.closest('form');
      modal.querySelectorAll('input[required], textarea[required]').forEach((campo) => {
        fireEvent.change(campo, { target: { value: 'x' } });
      });

      fireEvent.submit(modal);

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'Debes seleccionar una dirección válida de Google Maps para el evento'
        );
      });
      expect(
        llamadas.find((l) => l.url.includes('links/index.php') && l.options.method === 'POST')
      ).toBeUndefined();
    });
  });

  describe('administradores', () => {
    it('carga la lista al montar', async () => {
      const { llamadas } = await render();

      expect(llamadaA(llamadas, 'admins/index.php').url).toContain('page_id=5');
    });

    it('lista los administradores', async () => {
      await render({
        admins: [
          { id: 1, user_id: 11, user_name: 'Beto', user_email: 'beto@test.local', status: 'accepted' },
        ],
      });

      const encontrados = await screen.findAllByText(/beto@test.local|Beto/);
      expect(encontrados.length).toBeGreaterThan(0);
    });

    it('invita por email', async () => {
      const { llamadas } = await render();

      fireEvent.change(screen.getByPlaceholderText('email@ejemplo.com'), {
        target: { value: 'nuevo@test.local' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'INVITAR' }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('admins/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toMatchObject({ email: 'nuevo@test.local' });
      });
    });

    it('muestra el error que devuelve la API al invitar', async () => {
      await render();

      mockFetch({
        'admins/index.php': {
          status: 404,
          body: { error: 'No hay ningún usuario registrado con ese email' },
        },
      });

      fireEvent.change(screen.getByPlaceholderText('email@ejemplo.com'), {
        target: { value: 'nadie@test.local' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'INVITAR' }));

      expect(
        await screen.findByText('No hay ningún usuario registrado con ese email')
      ).toBeInTheDocument();
    });
  });

  describe('colaboraciones pendientes', () => {
    it('no muestra la sección si no hay', async () => {
      await render({ pending: [] });

      expect(screen.queryByText('COLABORACIONES PENDIENTES')).not.toBeInTheDocument();
    });

    it('muestra las invitaciones recibidas', async () => {
      await render({
        pending: [
          {
            id: 1,
            link_id: 100,
            event_title: 'Recital Compartido',
            requester_page_title: 'Otra Página',
            collaborator_page_id: 5,
            status: 'pending',
          },
        ],
      });

      expect(await screen.findByText('COLABORACIONES PENDIENTES')).toBeInTheDocument();
      expect(screen.getByText(/Recital Compartido/)).toBeInTheDocument();
    });
  });

  describe('configuración de la página', () => {
    it('muestra los datos actuales', async () => {
      await render({ page: pagina({ title: 'Mi Página', description: 'Una descripción' }) });

      expect(screen.getByDisplayValue('Mi Página')).toBeInTheDocument();
      expect(screen.getByDisplayValue('Una descripción')).toBeInTheDocument();
    });

    it('ofrece elegir plantilla', async () => {
      await render();

      expect(screen.getByText('TEMPLATE DE DISEÑO')).toBeInTheDocument();
    });

    it('enlaza a la página pública', async () => {
      await render({ page: pagina({ url_slug: 'mi-pagina' }) });

      const enlaces = screen.getAllByRole('link').filter(
        (a) => a.getAttribute('href') === '/mi-pagina'
      );
      expect(enlaces.length).toBeGreaterThan(0);
    });
  });
});
