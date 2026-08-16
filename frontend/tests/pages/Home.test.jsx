import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Home from '../../src/pages/Home';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, llamadaA } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const paginaReciente = (overrides = {}) => ({
  id: 5,
  title: 'Rock del Sur',
  description: 'Agenda de recitales',
  url_slug: 'rock-del-sur',
  profile_image: null,
  follower_count: 12,
  owner_name: 'Ana',
  ...overrides,
});

const eventoReciente = (overrides = {}) => ({
  id: 100,
  text: 'Recital de Rock',
  image_url: null,
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  event_latitude: '-34.6037',
  event_longitude: '-58.3816',
  page_slug: 'rock-del-sur',
  page_title: 'Rock del Sur',
  page_image: null,
  ...overrides,
});

function mockearHome({ pages = [], events = [], results = [], ubicacion = { latitude: null, longitude: null } } = {}) {
  return mockFetch({
    'public/recent-pages.php': { pages },
    'public/recent-events.php': { events },
    'public/search.php': { results },
    'pages/feed-events.php': { events, total: events.length },
    'users/location.php': ubicacion,
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'pages/follow.php': { is_following: false },
    'public/followers.php': { followers: [] },
  });
}

/** Sin geolocalización, Home sólo registra el error y sigue. */
function silenciarGeolocalizacion() {
  Object.defineProperty(navigator, 'geolocation', {
    value: { getCurrentPosition: vi.fn((_, onError) => onError({ code: 1 })) },
    writable: true,
    configurable: true,
  });
}

async function render(datos = {}, auth = crearAuth()) {
  const mock = mockearHome(datos);
  const resultado = renderConProviders(<Home />, { auth });
  await screen.findByRole('heading', { name: 'BUSCAR' });
  return { ...resultado, ...mock };
}

describe('Home', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.gtag = vi.fn();
    silenciarGeolocalizacion();
  });

  afterEach(() => {
    delete navigator.geolocation;
    window.google = undefined;
  });

  describe('visitante sin sesión', () => {
    it('muestra el eslogan', async () => {
      await render();

      expect(screen.getByText('ENCONTRÁ. CONECTÁ. REZONÁ.')).toBeInTheDocument();
    });

    it('ofrece iniciar sesión', async () => {
      await render();

      expect(screen.getByRole('link', { name: 'Iniciar Sesión / Registrarse' })).toHaveAttribute(
        'href',
        '/login'
      );
    });

    it('titula la sección de eventos como "EVENTOS CERCA"', async () => {
      await render();

      expect(screen.getByRole('heading', { name: 'EVENTOS CERCA' })).toBeInTheDocument();
    });

    it('carga los eventos públicos', async () => {
      const { llamadas } = await render();

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/recent-events.php')).not.toBeNull();
      });
    });

    it('no consulta la ubicación guardada', async () => {
      const { llamadas } = await render();

      expect(llamadaA(llamadas, 'users/location.php')).toBeNull();
    });

    it('pide la ubicación al navegador', async () => {
      await render();

      expect(navigator.geolocation.getCurrentPosition).toHaveBeenCalled();
    });

    it('no ofrece filtrar por páginas seguidas', async () => {
      await render();

      expect(screen.queryByText('Todos los eventos')).not.toBeInTheDocument();
    });
  });

  describe('usuario con sesión', () => {
    it('titula la sección como "EVENTOS"', async () => {
      await render({}, autenticado());

      expect(screen.getByRole('heading', { name: 'EVENTOS' })).toBeInTheDocument();
    });

    it('muestra la navegación privada', async () => {
      await render({}, autenticado());

      expect(screen.getByRole('link', { name: 'MIS PÁGINAS' })).toBeInTheDocument();
    });

    it('consulta la ubicación guardada en vez de pedirla al navegador', async () => {
      const { llamadas } = await render({}, autenticado());

      await waitFor(() => {
        expect(llamadaA(llamadas, 'users/location.php')).not.toBeNull();
      });
      expect(navigator.geolocation.getCurrentPosition).not.toHaveBeenCalled();
    });

    it('ofrece filtrar por páginas seguidas', async () => {
      await render({}, autenticado());

      expect(screen.getByRole('button', { name: 'Todos los eventos' })).toBeInTheDocument();
    });

    it('al filtrar consulta el feed de seguidas', async () => {
      const { llamadas } = await render({}, autenticado());

      fireEvent.click(screen.getByRole('button', { name: 'Todos los eventos' }));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'feed-events.php')).not.toBeNull();
      });
    });

    it('el botón cambia de etiqueta al filtrar', async () => {
      await render({}, autenticado());

      fireEvent.click(screen.getByRole('button', { name: 'Todos los eventos' }));

      expect(await screen.findByRole('button', { name: 'Páginas seguidas' })).toBeInTheDocument();
    });
  });

  describe('buscador', () => {
    it('no busca con el campo vacío', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByRole('button', { name: 'BUSCAR' }));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
      });
    });

    it('no busca con sólo espacios', async () => {
      const { llamadas } = await render();

      fireEvent.change(screen.getByPlaceholderText('Buscar páginas, eventos...'), {
        target: { value: '   ' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'BUSCAR' }));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
      });
    });

    it('consulta la API con el término escrito', async () => {
      const { llamadas } = await render();

      fireEvent.change(screen.getByPlaceholderText('Buscar páginas, eventos...'), {
        target: { value: 'rock nacional' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'BUSCAR' }));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php').url).toContain('q=rock%20nacional');
      });
    });

    it('muestra los resultados de tipo página', async () => {
      await render({
        results: [{ id: 5, type: 'page', title: 'Rock del Sur', slug: 'rock-del-sur', follower_count: 3 }],
      });

      fireEvent.change(screen.getByPlaceholderText('Buscar páginas, eventos...'), {
        target: { value: 'rock' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'BUSCAR' }));

      expect(await screen.findByRole('heading', { name: 'PÁGINAS' })).toBeInTheDocument();
      expect(screen.getByText('Rock del Sur')).toBeInTheDocument();
    });

    it('muestra los resultados de tipo evento', async () => {
      await render({
        results: [
          {
            id: 100, type: 'event', title: 'Recital de Rock', slug: 'rock-del-sur',
            item_date: '2026-12-01', event_time: '20:00:00', event_address: 'Corrientes 1234',
          },
        ],
      });

      fireEvent.change(screen.getByPlaceholderText('Buscar páginas, eventos...'), {
        target: { value: 'rock' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'BUSCAR' }));

      expect(await screen.findByText('Recital de Rock')).toBeInTheDocument();
    });

    it('no rompe si la búsqueda falla', async () => {
      await render();

      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));
      fireEvent.change(screen.getByPlaceholderText('Buscar páginas, eventos...'), {
        target: { value: 'rock' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'BUSCAR' }));

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('páginas recientes', () => {
    it('muestra la sección', async () => {
      await render();

      expect(screen.getByRole('heading', { name: 'PÁGINAS RECIENTES' })).toBeInTheDocument();
    });

    it('lista las páginas que devuelve la API', async () => {
      await render({ pages: [paginaReciente({ title: 'Rock del Sur' })] });

      expect(await screen.findByText('Rock del Sur')).toBeInTheDocument();
    });

    it('no rompe si falla la carga', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<Home />, { auth: crearAuth() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('vista de eventos', () => {
    it('arranca en modo mapa', async () => {
      await render({ events: [eventoReciente()] });

      expect(screen.getByTitle('Ver grilla')).toBeInTheDocument();
    });

    it('alterna a modo grilla', async () => {
      await render({ events: [eventoReciente()] });

      fireEvent.click(screen.getByTitle('Ver grilla'));

      expect(await screen.findByTitle('Ver mapa')).toBeInTheDocument();
    });

    it('en grilla muestra los eventos', async () => {
      await render({ events: [eventoReciente({ text: 'Recital de Rock' })] });

      fireEvent.click(screen.getByTitle('Ver grilla'));

      expect(await screen.findByText('Recital de Rock')).toBeInTheDocument();
    });
  });

  describe('orden de eventos', () => {
    it('deshabilita ordenar por distancia sin ubicación', async () => {
      await render({}, autenticado());

      const opciones = screen.getByRole('combobox').querySelectorAll('option');
      expect(opciones[1]).toBeDisabled();
    });

    it('habilita ordenar por distancia con ubicación', async () => {
      await render({ ubicacion: { latitude: -34.6, longitude: -58.4 } }, autenticado());

      await waitFor(() => {
        const opciones = screen.getByRole('combobox').querySelectorAll('option');
        expect(opciones[1]).not.toBeDisabled();
      });
    });

    it('explica cómo habilitarlo', async () => {
      await render({}, autenticado());

      fireEvent.change(screen.getByRole('combobox'), { target: { value: 'distance' } });

      expect(
        await screen.findByText(/necesitas configurar tu ubicación en el perfil/)
      ).toBeInTheDocument();
    });

    it('alterna el sentido del orden', async () => {
      await render({}, autenticado());

      fireEvent.click(screen.getByTitle('Orden ascendente'));

      expect(await screen.findByTitle('Orden descendente')).toBeInTheDocument();
    });
  });

  describe('metadatos', () => {
    it('pone el título del sitio', async () => {
      await render();

      await waitFor(() => {
        expect(document.title).toContain('Rezonar');
      });
    });

    it('declara la descripción para redes', async () => {
      await render();

      await waitFor(() => {
        const meta = document.querySelector('meta[property="og:title"]');
        expect(meta.getAttribute('content')).toContain('Rezonar');
      });
    });
  });
});
