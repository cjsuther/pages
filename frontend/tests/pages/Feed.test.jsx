import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Feed from '../../src/pages/Feed';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, llamadaA } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const evento = (overrides = {}) => ({
  id: 100,
  text: 'Recital de Rock',
  image_url: null,
  description: null,
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  page_id: 5,
  page_title: 'Rock del Sur',
  page_slug: 'rock-del-sur',
  page_image: null,
  distance: null,
  is_event: true,
  ...overrides,
});

/** Respuestas de Feed y de los widgets que monta Navigation. */
function mockearFeed(events = [], ubicacion = { latitude: null, longitude: null }) {
  return mockFetch({
    'pages/feed-events.php': { events, total: events.length },
    'users/location.php': ubicacion,
    'notifications/index.php': { notifications: [], unread_count: 0 },
  });
}

async function render(events = [], ubicacion) {
  const mock = mockearFeed(events, ubicacion);
  const resultado = renderConProviders(<Feed />, { auth: autenticado() });
  await screen.findByRole('heading', { name: 'FEED DE EVENTOS' });
  return { ...resultado, ...mock };
}

describe('Feed', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('carga', () => {
    it('muestra el estado de carga', () => {
      mockearFeed();

      renderConProviders(<Feed />, { auth: autenticado() });

      expect(screen.getByText('Cargando eventos...')).toBeInTheDocument();
    });

    it('pide los eventos con el orden por defecto', async () => {
      const { llamadas } = await render();

      await waitFor(() => {
        const feed = llamadaA(llamadas, 'feed-events.php');
        expect(feed.url).toContain('sortBy=date');
        expect(feed.url).toContain('sortOrder=asc');
      });
    });

    it('consulta la ubicación del usuario', async () => {
      const { llamadas } = await render();

      await waitFor(() => {
        expect(llamadaA(llamadas, 'users/location.php')).not.toBeNull();
      });
    });

    it('cierra sesión si la API responde 401', async () => {
      mockFetch({
        'pages/feed-events.php': { status: 401, body: {} },
        'users/location.php': { status: 401, body: {} },
        'notifications/index.php': { notifications: [], unread_count: 0 },
      });
      const auth = autenticado();

      renderConProviders(<Feed />, { auth });

      await waitFor(() => expect(auth.logout).toHaveBeenCalled());
    });
  });

  describe('sin eventos', () => {
    it('invita a seguir páginas', async () => {
      await render([]);

      expect(await screen.findByText('No hay eventos')).toBeInTheDocument();
      expect(screen.getByText('Sigue algunas páginas para ver sus eventos aquí')).toBeInTheDocument();
    });

    it('enlaza al buscador de páginas', async () => {
      await render([]);

      expect(await screen.findByRole('link', { name: 'BUSCAR PÁGINAS' })).toHaveAttribute(
        'href',
        '/pages'
      );
    });
  });

  describe('listado de eventos', () => {
    it('muestra cada evento', async () => {
      await render([
        evento({ id: 1, text: 'Primero' }),
        evento({ id: 2, text: 'Segundo' }),
      ]);

      expect(await screen.findByText('Primero')).toBeInTheDocument();
      expect(screen.getByText('Segundo')).toBeInTheDocument();
    });

    it('enlaza a la página que lo publica', async () => {
      await render([evento({ page_slug: 'rock-del-sur', text: 'Recital' })]);

      const enlace = (await screen.findByText('Recital')).closest('a');
      expect(enlace).toHaveAttribute('href', '/rock-del-sur');
    });

    it('muestra el nombre de la página', async () => {
      await render([evento({ page_title: 'Rock del Sur' })]);

      expect(await screen.findByText('Rock del Sur')).toBeInTheDocument();
    });

    it('muestra la dirección', async () => {
      await render([evento({ event_address: 'Av. Corrientes 1234' })]);

      expect(await screen.findByText('Av. Corrientes 1234')).toBeInTheDocument();
    });

    it('omite la dirección si no la hay', async () => {
      await render([evento({ event_address: null, text: 'Sin dirección' })]);

      await screen.findByText('Sin dirección');
      expect(screen.queryByText('Av. Corrientes 1234')).not.toBeInTheDocument();
    });

    it('usa una imagen por defecto', async () => {
      await render([evento({ image_url: null, text: 'Recital' })]);

      const img = await screen.findByAltText('Recital');
      expect(img.getAttribute('src')).toContain('pexels.com');
    });

    it('usa la imagen del evento si la tiene', async () => {
      await render([evento({ image_url: 'https://img/e.jpg', text: 'Recital' })]);

      expect(await screen.findByAltText('Recital')).toHaveAttribute('src', 'https://img/e.jpg');
    });

    it('muestra la distancia cuando la hay', async () => {
      await render([evento({ distance: 12.345 })]);

      expect(await screen.findByText('12.3 km')).toBeInTheDocument();
    });

    it('omite la distancia si es null', async () => {
      await render([evento({ distance: null, text: 'Sin distancia' })]);

      await screen.findByText('Sin distancia');
      expect(screen.queryByText(/km$/)).not.toBeInTheDocument();
    });

    it('muestra una distancia de cero', async () => {
      await render([evento({ distance: 0 })]);

      expect(await screen.findByText('0.0 km')).toBeInTheDocument();
    });
  });

  describe('ordenamiento', () => {
    it('alterna entre ascendente y descendente', async () => {
      const { llamadas } = await render([evento()]);

      fireEvent.click(screen.getByTitle('Orden ascendente'));

      await waitFor(() => {
        const desc = llamadas.filter((l) => l.url.includes('sortOrder=desc'));
        expect(desc.length).toBeGreaterThan(0);
      });
    });

    it('permite ordenar por fecha', async () => {
      const { llamadas } = await render([evento()]);

      fireEvent.change(screen.getByRole('combobox'), { target: { value: 'date' } });

      await waitFor(() => {
        expect(llamadas.filter((l) => l.url.includes('sortBy=date')).length).toBeGreaterThan(0);
      });
    });

    it('deshabilita ordenar por distancia sin ubicación', async () => {
      await render([evento()], { latitude: null, longitude: null });

      const opciones = screen.getByRole('combobox').querySelectorAll('option');
      expect(opciones[1]).toBeDisabled();
    });

    it('habilita ordenar por distancia con ubicación', async () => {
      await render([evento()], { latitude: -34.6, longitude: -58.4 });

      await waitFor(() => {
        const opciones = screen.getByRole('combobox').querySelectorAll('option');
        expect(opciones[1]).not.toBeDisabled();
      });
    });

    it('explica cómo habilitar el orden por distancia', async () => {
      await render([evento()], { latitude: null, longitude: null });

      fireEvent.change(screen.getByRole('combobox'), { target: { value: 'distance' } });

      expect(
        await screen.findByText(/necesitas configurar tu ubicación en el perfil/)
      ).toBeInTheDocument();
    });

    it('no muestra el aviso si ya hay ubicación', async () => {
      await render([evento()], { latitude: -34.6, longitude: -58.4 });

      fireEvent.change(screen.getByRole('combobox'), { target: { value: 'distance' } });

      await waitFor(() => {
        expect(screen.queryByText(/necesitas configurar tu ubicación/)).not.toBeInTheDocument();
      });
    });
  });
});
