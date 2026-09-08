import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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
  profile_image: null,
  follower_count: 3,
  type: 'page',
  ...overrides,
});

const sugerida = (overrides = {}) => ({
  id: 42,
  title: 'Página Nueva',
  description: 'Recién llegada',
  url_slug: 'pagina-nueva',
  profile_image: null,
  follower_count: 0,
  ...overrides,
});

function mockear({ results = [], following = [], recientes = [] } = {}) {
  return mockFetch({
    'pages/following.php': { following, total: following.length },
    'public/recent-pages.php': { pages: recientes },
    'public/search.php': { results },
    'pages/follow.php': { is_following: false },
    'public/followers.php': { followers: [] },
  });
}

/** El campo espera 300 ms antes de consultar: una consulta por tecla es una de más. */
async function buscar(texto = 'rock') {
  fireEvent.change(
    screen.getByPlaceholderText('Buscá por nombre de artista, banda, ciclo o sala...'),
    { target: { value: texto } }
  );
  await vi.advanceTimersByTimeAsync(400);
}

describe('PageSearch', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    vi.useFakeTimers({ shouldAdvanceTime: true });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  describe('estado inicial', () => {
    /**
     * Con el campo vacío se muestran las páginas nuevas, no un cartel pidiendo
     * que se escriba algo: quien entra a descubrir casi nunca sabe todavía a
     * quién buscar.
     */
    it('sugiere las páginas nuevas antes de buscar nada', async () => {
      mockear({ recientes: [sugerida()] });

      renderConProviders(<PageSearch />, { auth: autenticado() });

      expect(await screen.findByText('Página Nueva')).toBeInTheDocument();
      expect(screen.getByText('Páginas nuevas en Rezonar')).toBeInTheDocument();
    });

    it('consulta las páginas ya seguidas al montar', async () => {
      const { llamadas } = mockear();

      renderConProviders(<PageSearch />, { auth: autenticado() });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'pages/following.php')).not.toBeNull();
      });
    });

    it('no busca con el campo vacío', async () => {
      const { llamadas } = mockear();

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await waitFor(() => expect(llamadaA(llamadas, 'pages/following.php')).not.toBeNull());

      expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
    });

    /** Con una sola letra el LIKE del servidor devolvería medio catálogo. */
    it('no busca con una sola letra', async () => {
      const { llamadas } = mockear();

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar('a');

      expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
    });
  });

  describe('búsqueda', () => {
    it('consulta la API con el término escrito', async () => {
      const { llamadas } = mockear();

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar('rock');

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php').url).toContain('q=rock');
      });
    });

    it('muestra los resultados', async () => {
      mockear({ results: [resultado()] });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      expect(await screen.findByText('Página Encontrada')).toBeInTheDocument();
      expect(screen.getByText('Una descripción')).toBeInTheDocument();
    });

    it('enlaza a la página encontrada', async () => {
      mockear({ results: [resultado()] });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      const enlace = await screen.findByRole('link', { name: /Página Encontrada/ });
      expect(enlace).toHaveAttribute('href', '/pagina-encontrada');
    });

    /** Los eventos que devuelve el buscador no van acá: esta pantalla es de páginas. */
    it('descarta los resultados que no son páginas', async () => {
      mockear({ results: [resultado(), { id: 9, type: 'event', title: 'Un Show' }] });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      await screen.findByText('Página Encontrada');
      expect(screen.queryByText('Un Show')).not.toBeInTheDocument();
    });

    it('avisa cuando no hay resultados', async () => {
      mockear({ results: [] });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar('inexistente');

      expect(
        await screen.findByText('No encontramos páginas con ese nombre')
      ).toBeInTheDocument();
    });

    /** Lo que ya seguís no se vuelve a ofrecer: para eso está la otra solapa. */
    it('oculta las páginas que el usuario ya sigue', async () => {
      mockear({
        results: [resultado(), resultado({ id: 8, title: 'Otra Página' })],
        following: [{ id: 7 }],
      });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      expect(await screen.findByText('Otra Página')).toBeInTheDocument();
      expect(screen.queryByText('Página Encontrada')).not.toBeInTheDocument();
    });

    it('no rompe si falla la búsqueda', async () => {
      mockFetch({
        'pages/following.php': { following: [], total: 0 },
        'public/recent-pages.php': { pages: [] },
        'public/search.php': { status: 500, body: { error: 'boom' } },
      });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      expect(
        await screen.findByText('No encontramos páginas con ese nombre')
      ).toBeInTheDocument();
    });
  });

  describe('seguir una página', () => {
    /**
     * El botón es el mismo componente que en el resto del sitio. Antes esta
     * pantalla tenía su propia copia del diálogo, con el radio de distancia
     * clavado en 30 km: seguir desde acá guardaba algo distinto que seguir
     * desde la página del artista.
     */
    it('ofrece seguir con el botón compartido', async () => {
      mockear({ results: [resultado()] });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      expect(await screen.findByRole('button', { name: 'Seguir' })).toBeInTheDocument();
    });

    it('guarda la preferencia elegida', async () => {
      const { llamadas } = mockear({ results: [resultado()] });

      renderConProviders(<PageSearch />, { auth: autenticado() });
      await buscar();

      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));
      fireEvent.click(await screen.findByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({
          page_id: 7,
          notify_all_events: true,
          max_distance_km: 50,
        });
      });
    });
  });
});
