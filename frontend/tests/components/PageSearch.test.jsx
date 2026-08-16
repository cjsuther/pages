import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import PageSearch from '../../src/components/PageSearch';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const resultado = (overrides = {}) => ({
  id: 7,
  title: 'Página Encontrada',
  description: 'Una descripción',
  slug: 'pagina-encontrada',
  follower_count: 3,
  type: 'page',
  ...overrides,
});

function mockearBusqueda(results = [], following = []) {
  return mockFetch({
    'pages/following.php': { following, total: following.length },
    'public/search.php': { results },
    'pages/follow.php': { success: true },
    'public/followers.php': { followers: [] },
  });
}

async function buscar(texto = 'rock') {
  fireEvent.change(screen.getByPlaceholderText('Buscar páginas...'), { target: { value: texto } });
}

describe('PageSearch', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.alert = vi.fn();
  });

  describe('estado inicial', () => {
    it('invita a escribir un término', async () => {
      mockearBusqueda();

      renderConProviders(<PageSearch />, { auth: autenticado() });

      expect(await screen.findByText('Ingresa un término de búsqueda')).toBeInTheDocument();
    });

    it('consulta las páginas ya seguidas al montar', async () => {
      const { llamadas } = mockearBusqueda();

      renderConProviders(<PageSearch />, { auth: autenticado() });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'pages/following.php')).not.toBeNull();
      });
    });

    it('no busca con el campo vacío', async () => {
      const { llamadas } = mockearBusqueda();

      renderConProviders(<PageSearch />, { auth: autenticado() });

      await waitFor(() => expect(llamadas.length).toBeGreaterThan(0));
      expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
    });

    it('no rompe si falla la consulta de seguidas', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<PageSearch />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('búsqueda', () => {
    it('consulta la API con el término escrito', async () => {
      const { llamadas } = mockearBusqueda([resultado()]);
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar('rock nacional');

      await waitFor(() => {
        const busqueda = llamadaA(llamadas, 'public/search.php');
        expect(busqueda.url).toContain('q=rock%20nacional');
        expect(busqueda.url).toContain('limit=10');
        expect(busqueda.url).toContain('offset=0');
      });
    });

    it('muestra los resultados', async () => {
      mockearBusqueda([resultado({ title: 'Rock del Sur' })]);
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar();

      expect(await screen.findByText('Rock del Sur')).toBeInTheDocument();
    });

    it('enlaza a la página encontrada', async () => {
      mockearBusqueda([resultado({ slug: 'rock-del-sur', title: 'Rock del Sur' })]);
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar();

      expect(await screen.findByRole('link', { name: 'Rock del Sur' })).toHaveAttribute(
        'href',
        '/rock-del-sur'
      );
    });

    it('muestra la descripción y los seguidores', async () => {
      mockearBusqueda([resultado({ description: 'Agenda de recitales', follower_count: 12 })]);
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar();

      expect(await screen.findByText('Agenda de recitales')).toBeInTheDocument();
      expect(screen.getByText(/12 seguidores/)).toBeInTheDocument();
    });

    it('avisa cuando no hay resultados', async () => {
      mockearBusqueda([]);
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar('no-existe');

      expect(await screen.findByText('No se encontraron páginas')).toBeInTheDocument();
    });

    it('oculta las páginas que el usuario ya sigue', async () => {
      mockFetch({
        'pages/following.php': { following: [{ id: 7 }], total: 1 },
        'public/search.php': {
          results: [resultado({ id: 7, title: 'Ya seguida' }), resultado({ id: 8, title: 'Nueva' })],
        },
        'public/followers.php': { followers: [] },
      });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await waitFor(() => expect(screen.getByPlaceholderText('Buscar páginas...')).toBeInTheDocument());

      await buscar();

      expect(await screen.findByText('Nueva')).toBeInTheDocument();
      expect(screen.queryByText('Ya seguida')).not.toBeInTheDocument();
    });

    it('no rompe si falla la búsqueda', async () => {
      mockearBusqueda();
      renderConProviders(<PageSearch />, { auth: autenticado() });
      await waitFor(() => expect(screen.getByPlaceholderText('Buscar páginas...')).toBeInTheDocument());

      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));
      await buscar();

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('paginación', () => {
    const diez = () => Array.from({ length: 10 }, (_, i) => resultado({ id: i + 1, title: `Página ${i + 1}` }));

    it('ofrece cargar más si vino una página completa', async () => {
      mockearBusqueda(diez());
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar();

      expect(await screen.findByRole('button', { name: 'Cargar Más' })).toBeInTheDocument();
    });

    it('no ofrece cargar más con menos de diez', async () => {
      mockearBusqueda([resultado()]);
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar();
      await screen.findByText('Página Encontrada');

      expect(screen.queryByRole('button', { name: 'Cargar Más' })).not.toBeInTheDocument();
    });

    it('pide la página siguiente con el offset correcto', async () => {
      const { llamadas } = mockearBusqueda(diez());
      renderConProviders(<PageSearch />, { auth: autenticado() });

      await buscar();
      fireEvent.click(await screen.findByRole('button', { name: 'Cargar Más' }));

      await waitFor(() => {
        const conOffset = llamadas.filter((l) => l.url.includes('offset=10'));
        expect(conOffset.length).toBeGreaterThan(0);
      });
    });
  });

  describe('seguir una página', () => {
    async function abrirModal() {
      const mock = mockearBusqueda([resultado()]);
      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();
      await screen.findByText('Página Encontrada');
      fireEvent.click(screen.getByRole('button', { name: 'SEGUIR' }));
      return mock;
    }

    it('abre el modal de preferencias', async () => {
      await abrirModal();

      expect(screen.getByText('¿Qué eventos quieres recibir?')).toBeInTheDocument();
    });

    it('arranca con "todos los eventos"', async () => {
      await abrirModal();

      expect(screen.getAllByRole('radio')[0]).toBeChecked();
    });

    it('envía la preferencia elegida', async () => {
      const { llamadas } = await abrirModal();

      fireEvent.click(screen.getAllByRole('radio')[1]);
      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({
          page_id: 7,
          notify_all_events: false,
          max_distance_km: 30,
        });
      });
    });

    it('quita la página de los resultados al seguirla', async () => {
      await abrirModal();

      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        expect(screen.queryByText('Página Encontrada')).not.toBeInTheDocument();
      });
    });

    it('confirma al usuario', async () => {
      await abrirModal();

      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Ahora sigues esta página');
      });
    });

    it('se puede cancelar', async () => {
      const { llamadas } = await abrirModal();

      fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => {
        expect(screen.queryByText('¿Qué eventos quieres recibir?')).not.toBeInTheDocument();
      });
      expect(llamadas.find((l) => l.options.method === 'POST')).toBeUndefined();
      expect(screen.getByText('Página Encontrada')).toBeInTheDocument();
    });

    it('avisa si falla', async () => {
      await abrirModal();
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Error al seguir la página');
      });
    });
  });
});
