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
  event_address: 'Av. Corrientes 1234, Palermo, CABA, Argentina',
  event_latitude: '-34.6037',
  event_longitude: '-58.3816',
  page_slug: 'rock-del-sur',
  page_title: 'Rock del Sur',
  page_image: null,
  ...overrides,
});

function mockearHome({
  pages = [],
  events = [],
  results = [],
  siguiendo = [],
  ubicacion = { latitude: null, longitude: null },
} = {}) {
  return mockFetch({
    'public/recent-pages.php': { pages },
    'public/recent-events.php': { events },
    'public/search.php': { results },
    'pages/feed-events.php': { events, total: events.length },
    'pages/following.php': { following: siguiendo, total: siguiendo.length },
    'users/location.php': ubicacion,
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'pages/follow.php': { is_following: false },
    'public/followers.php': { followers: [] },
  });
}

/** El navegador de prueba deniega la ubicación salvo que el test diga otra cosa. */
function geolocalizacion(implementacion) {
  Object.defineProperty(navigator, 'geolocation', {
    value: { getCurrentPosition: vi.fn(implementacion || ((_, onError) => onError({ code: 1 }))) },
    writable: true,
    configurable: true,
  });
}

async function render(datos = {}, auth = crearAuth()) {
  const mock = mockearHome(datos);
  const resultado = renderConProviders(<Home />, { auth });
  await screen.findByRole('heading', { name: 'Qué se viene' });
  return { ...resultado, ...mock };
}

const buscador = () => screen.getByPlaceholderText('Buscá un artista, un ciclo, una sala...');
const filtro = (nombre) => screen.getByRole('button', { name: new RegExp(`^${nombre}`) });

describe('Home', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.gtag = vi.fn();
    geolocalizacion();
  });

  afterEach(() => {
    delete navigator.geolocation;
    window.google = undefined;
  });

  describe('visitante sin sesión', () => {
    it('dice de qué se trata el sitio', async () => {
      await render();

      expect(
        screen.getByRole('heading', { name: /Seguí a los artistas que te gustan/ })
      ).toBeInTheDocument();
    });

    it('ofrece entrar y crear una página', async () => {
      await render();

      expect(screen.getAllByRole('link', { name: 'Entrar' })[0]).toHaveAttribute('href', '/login');
      expect(screen.getAllByRole('link', { name: 'Crear mi página' })[0]).toHaveAttribute(
        'href',
        '/register'
      );
    });

    it('enlaza a la página para artistas', async () => {
      await render();

      expect(screen.getAllByRole('link', { name: /artistas|Armá tu página gratis/ }).length)
        .toBeGreaterThan(0);
    });

    /**
     * Es lo que el sitio hace y no se explicaba en ninguna parte: quien entra
     * por primera vez no sabe que puede seguir a un artista ni para qué.
     */
    it('explica cómo se sigue a un artista', async () => {
      await render();

      expect(screen.getByRole('heading', { name: 'Cómo seguir a un artista' })).toBeInTheDocument();
      expect(screen.getByText('Buscá al artista')).toBeInTheDocument();
      expect(screen.getByText('Tocá Seguir')).toBeInTheDocument();
      expect(screen.getByText('Te avisamos')).toBeInTheDocument();
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

    /**
     * El permiso de geolocalización se concede una vez: pedirlo al entrar,
     * antes de haber explicado para qué sirve, es la forma más rápida de
     * perderlo para siempre.
     */
    it('no pide la ubicación al cargar', async () => {
      await render();

      expect(navigator.geolocation.getCurrentPosition).not.toHaveBeenCalled();
    });

    it('no ofrece filtrar por páginas seguidas', async () => {
      await render();

      expect(screen.queryByRole('button', { name: /^De quienes sigo/ })).not.toBeInTheDocument();
    });
  });

  describe('usuario con sesión', () => {
    it('saluda por su nombre', async () => {
      await render({}, autenticado());

      expect(screen.getByRole('heading', { name: /Hola/ })).toBeInTheDocument();
    });

    it('muestra la navegación privada', async () => {
      await render({}, autenticado());

      expect(screen.getAllByRole('link', { name: 'Mis páginas' })[0]).toBeInTheDocument();
    });

    it('consulta la ubicación guardada en vez de pedirla al navegador', async () => {
      const { llamadas } = await render({}, autenticado());

      await waitFor(() => {
        expect(llamadaA(llamadas, 'users/location.php')).not.toBeNull();
      });
      expect(navigator.geolocation.getCurrentPosition).not.toHaveBeenCalled();
    });

    it('cuenta cuántas páginas sigue', async () => {
      await render({ siguiendo: [{ id: 1 }, { id: 2 }] }, autenticado());

      expect(await screen.findByText('Seguís 2 páginas.')).toBeInTheDocument();
    });

    /** A quien ya sigue páginas la explicación le sobra: ocuparía el lugar de su agenda. */
    it('no repite cómo seguir si ya sigue a alguien', async () => {
      await render({ siguiendo: [{ id: 1 }] }, autenticado());

      await waitFor(() => {
        expect(
          screen.queryByRole('heading', { name: 'Cómo seguir a un artista' })
        ).not.toBeInTheDocument();
      });
    });

    it('ofrece filtrar por las páginas que sigue', async () => {
      await render({}, autenticado());

      expect(filtro('De quienes sigo')).toBeInTheDocument();
    });

    it('al filtrar consulta el feed de seguidas', async () => {
      const { llamadas } = await render({}, autenticado());

      fireEvent.click(filtro('De quienes sigo'));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'feed-events.php')).not.toBeNull();
      });
    });
  });

  describe('buscador', () => {
    it('no deja buscar con el campo vacío', async () => {
      await render();

      expect(screen.getByRole('button', { name: 'Buscar' })).toBeDisabled();
    });

    it('no deja buscar con una sola letra', async () => {
      await render();

      fireEvent.change(buscador(), { target: { value: 'a' } });

      expect(screen.getByRole('button', { name: 'Buscar' })).toBeDisabled();
    });

    it('consulta la API con el término escrito', async () => {
      const { llamadas } = await render();

      fireEvent.change(buscador(), { target: { value: 'rock nacional' } });
      fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php').url).toContain('q=rock%20nacional');
      });
    });

    it('muestra los resultados de tipo página', async () => {
      await render({
        results: [{ id: 5, type: 'page', title: 'Rock del Sur', slug: 'rock-del-sur', follower_count: 3 }],
      });

      fireEvent.change(buscador(), { target: { value: 'rock' } });
      fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

      expect(await screen.findByText('Páginas para seguir')).toBeInTheDocument();
      expect(screen.getAllByText('Rock del Sur').length).toBeGreaterThan(0);
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

      fireEvent.change(buscador(), { target: { value: 'rock' } });
      fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

      expect(await screen.findByText('Recital de Rock')).toBeInTheDocument();
    });

    it('avisa cuando no encuentra nada', async () => {
      await render({ results: [] });

      fireEvent.change(buscador(), { target: { value: 'inexistente' } });
      fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

      expect(await screen.findByText('No encontramos nada con ese nombre.')).toBeInTheDocument();
    });

    it('no rompe si la búsqueda falla', async () => {
      await render();

      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));
      fireEvent.change(buscador(), { target: { value: 'rock' } });
      fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('páginas para seguir', () => {
    it('muestra la sección', async () => {
      await render();

      expect(screen.getByRole('heading', { name: 'Páginas para seguir' })).toBeInTheDocument();
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

  describe('agenda', () => {
    /** Arranca en lista: el mapa no dice qué pasa primero. */
    it('arranca en lista y muestra los eventos', async () => {
      await render({ events: [eventoReciente({ text: 'Recital de Rock' })] });

      expect(await screen.findByText('Recital de Rock')).toBeInTheDocument();
    });

    it('alterna al mapa', async () => {
      await render({ events: [eventoReciente()] });

      fireEvent.click(screen.getByRole('button', { name: 'Mapa' }));

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Mapa' })).toHaveAttribute('aria-pressed', 'true');
      });
    });

    it('avisa cuando no hay fechas publicadas', async () => {
      await render({ events: [] });

      expect(
        await screen.findByText('Todavía no hay fechas publicadas')
      ).toBeInTheDocument();
    });
  });

  describe('cerca mío', () => {
    it('pide la ubicación recién cuando se elige el filtro', async () => {
      await render({ events: [eventoReciente()] });

      fireEvent.click(filtro('Cerca mío'));

      expect(
        await screen.findByText('Necesitamos saber dónde estás')
      ).toBeInTheDocument();
      expect(navigator.geolocation.getCurrentPosition).not.toHaveBeenCalled();

      fireEvent.click(screen.getByRole('button', { name: 'Usar mi ubicación' }));
      expect(navigator.geolocation.getCurrentPosition).toHaveBeenCalled();
    });

    it('con la ubicación concedida deja sólo lo que está cerca', async () => {
      geolocalizacion((onOk) => onOk({ coords: { latitude: -34.6037, longitude: -58.3816 } }));

      await render({
        events: [
          eventoReciente({ id: 1, text: 'Al lado' }),
          // Córdoba: más de 600 km de Buenos Aires.
          eventoReciente({ id: 2, text: 'Lejísimos', event_latitude: '-31.42', event_longitude: '-64.18' }),
        ],
      });

      fireEvent.click(filtro('Cerca mío'));
      fireEvent.click(await screen.findByRole('button', { name: 'Usar mi ubicación' }));

      expect(await screen.findByText('Al lado')).toBeInTheDocument();
      expect(screen.queryByText('Lejísimos')).not.toBeInTheDocument();
    });

    it('avisa si no queda nada cerca', async () => {
      geolocalizacion((onOk) => onOk({ coords: { latitude: -34.6037, longitude: -58.3816 } }));

      await render({
        events: [
          eventoReciente({ id: 2, text: 'Lejísimos', event_latitude: '-31.42', event_longitude: '-64.18' }),
        ],
      });

      fireEvent.click(filtro('Cerca mío'));
      fireEvent.click(await screen.findByRole('button', { name: 'Usar mi ubicación' }));

      expect(await screen.findByText('No hay nada a menos de 30 km')).toBeInTheDocument();
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
