import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import PublicPage from '../../src/pages/PublicPage';
import { renderConProviders, API_URL } from '../helpers/render';
import { mockFetch } from '../helpers/api';

const pagina = (overrides = {}) => ({
  id: 5,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  profile_image: null,
  background_image: null,
  background_color: '#ffffff',
  text_color: '#000000',
  primary_color: '#3b82f6',
  follower_count: 0,
  template: 'minimal',
  groups: [],
  ...overrides,
});

/** Renderiza en la ruta /:slug para que useParams() reciba el slug. */
function render(slug = 'mi-pagina') {
  return renderConProviders(<PublicPage />, { route: `/${slug}`, path: '/:slug' });
}

describe('PublicPage', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  describe('carga', () => {
    it('muestra un estado de carga', () => {
      mockFetch({ 'public/page.php': { page: pagina() } });

      render();

      expect(screen.getByText('Cargando...')).toBeInTheDocument();
    });

    it('pide la página por su slug', async () => {
      const { llamadas } = mockFetch({ 'public/page.php': { page: pagina() } });

      render('bandas-de-rock');

      await waitFor(() => {
        expect(llamadas[0].url).toBe(`${API_URL}/public/page.php?slug=bandas-de-rock`);
      });
    });

    it('muestra la página cuando llega', async () => {
      mockFetch({ 'public/page.php': { page: pagina() } });

      render();

      expect(await screen.findByText('Mi Página')).toBeInTheDocument();
    });

    it('registra la visita en analytics', async () => {
      mockFetch({ 'public/page.php': { page: pagina() } });

      render('mi-pagina');

      await waitFor(() => {
        expect(window.gtag).toHaveBeenCalledWith('event', 'view_public_page', {
          page_slug: 'mi-pagina',
        });
      });
    });
  });

  describe('errores', () => {
    it('muestra un 404 si la página no existe', async () => {
      mockFetch({ 'public/page.php': { status: 404, body: { error: 'Page not found' } } });

      render('no-existe');

      expect(await screen.findByText('404')).toBeInTheDocument();
      expect(screen.getByText('Page not found')).toBeInTheDocument();
    });

    it('muestra un mensaje genérico si la API no explica el error', async () => {
      mockFetch({ 'public/page.php': { status: 500, body: {} } });

      render();

      expect(await screen.findByText('Página no encontrada')).toBeInTheDocument();
    });

    it('muestra el 404 si falla la red', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin conexión')));

      render();

      expect(await screen.findByText('404')).toBeInTheDocument();
      expect(screen.getByText('sin conexión')).toBeInTheDocument();
    });

    it('no registra la visita si falló', async () => {
      mockFetch({ 'public/page.php': { status: 404, body: { error: 'x' } } });

      render();
      await screen.findByText('404');

      const vistas = window.gtag.mock.calls.filter((c) => c[1] === 'view_public_page');
      expect(vistas).toHaveLength(0);
    });
  });

  describe('selección de plantilla', () => {
    /** Cada plantilla deja una marca distinta en el DOM; alcanza con el título. */
    async function renderConPlantilla(template) {
      mockFetch({ 'public/page.php': { page: pagina({ template }) } });
      render();
      return screen.findByText('Mi Página');
    }

    it.each(['minimal', 'cards', 'modern', 'condensed'])(
      'renderiza la plantilla %s',
      async (template) => {
        await renderConPlantilla(template);

        expect(screen.getByText('Mi Página')).toBeInTheDocument();
      }
    );

    it('cae en minimal si la plantilla es desconocida', async () => {
      await renderConPlantilla('plantilla-inventada');

      expect(screen.getByText('Mi Página')).toBeInTheDocument();
    });

    it('cae en minimal si no hay plantilla definida', async () => {
      mockFetch({ 'public/page.php': { page: pagina({ template: null }) } });

      render();

      expect(await screen.findByText('Mi Página')).toBeInTheDocument();
    });

    it('renderiza una sola plantilla', async () => {
      await renderConPlantilla('cards');

      // Si se renderizaran dos plantillas, el título aparecería duplicado.
      expect(screen.getAllByText('Mi Página')).toHaveLength(1);
    });
  });

  describe('metadatos para redes sociales', () => {
    it('pone el título de la página en el <title>', async () => {
      mockFetch({ 'public/page.php': { page: pagina() } });

      render();
      await screen.findByText('Mi Página');

      await waitFor(() => {
        expect(document.title).toBe('Mi Página | Rezonar');
      });
    });

    it('usa la descripción de la página', async () => {
      mockFetch({ 'public/page.php': { page: pagina({ description: 'Agenda cultural' }) } });

      render();
      await screen.findByText('Mi Página');

      await waitFor(() => {
        const meta = document.querySelector('meta[property="og:description"]');
        expect(meta).toHaveAttribute('content', 'Agenda cultural');
      });
    });

    it('arma una descripción si la página no tiene', async () => {
      mockFetch({ 'public/page.php': { page: pagina({ description: null }) } });

      render();
      await screen.findByText('Mi Página');

      await waitFor(() => {
        const meta = document.querySelector('meta[property="og:description"]');
        expect(meta.getAttribute('content')).toContain('Mi Página');
      });
    });

    it('usa la imagen de perfil como og:image', async () => {
      mockFetch({ 'public/page.php': { page: pagina({ profile_image: 'https://img/p.png' }) } });

      render();
      await screen.findByText('Mi Página');

      await waitFor(() => {
        expect(document.querySelector('meta[property="og:image"]')).toHaveAttribute(
          'content',
          'https://img/p.png'
        );
      });
    });

    it('recurre a la imagen de fondo si no hay foto de perfil', async () => {
      mockFetch({
        'public/page.php': { page: pagina({ profile_image: null, background_image: 'https://img/f.png' }) },
      });

      render();
      await screen.findByText('Mi Página');

      await waitFor(() => {
        expect(document.querySelector('meta[property="og:image"]')).toHaveAttribute(
          'content',
          'https://img/f.png'
        );
      });
    });

    it('omite og:image si no hay ninguna imagen', async () => {
      mockFetch({ 'public/page.php': { page: pagina() } });

      render();
      await screen.findByText('Mi Página');

      await waitFor(() => {
        expect(document.querySelector('meta[property="og:image"]')).toBeNull();
      });
    });
  });
});
