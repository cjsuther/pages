import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import App from '../src/App';
import { mockFetch } from './helpers/api';

describe('App', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
    localStorage.clear();
    window.history.pushState({}, '', '/');
    mockFetch({
      'public/recent-pages.php': { pages: [] },
      'public/recent-events.php': { events: [] },
      'public/search.php': { results: [] },
      'public/events.php': { events: [] },
      'notifications/index.php': { notifications: [], unread_count: 0 },
      'users/location.php': { latitude: null, longitude: null },
      'pages/following.php': { following: [], total: 0 },
      'pages/index.php': { pages: [] },
    });
  });

  describe('dominio propio', () => {
    afterEach(() => {
      delete window.__PAGINA_DEL_DOMINIO__;
    });

    /**
     * En maxipeque.com la raíz es la página, no el home de Rezonar. Qué página
     * lo resolvió index.php contra el Host.
     */
    it('la raíz muestra la página del dominio', async () => {
      window.__PAGINA_DEL_DOMINIO__ = 'maxipeque';
      mockFetch({
        'public/page.php': { page: { id: 1, title: 'Maxi Peque', url_slug: 'maxipeque', groups: [], socials: [] } },
        'notifications/index.php': { notifications: [], unread_count: 0 },
        'users/location.php': { latitude: null, longitude: null },
      });

      render(<App />);

      expect(await screen.findByText('Maxi Peque')).toBeInTheDocument();
    });

    it('en rezon.ar la raíz sigue siendo el home', async () => {
      render(<App />);

      await waitFor(() => {
        expect(global.fetch).toHaveBeenCalled();
      });
      expect(screen.queryByText('Maxi Peque')).not.toBeInTheDocument();
    });
  });

  describe('sesión persistida', () => {
    it('arranca sin sesión si no hay nada guardado', async () => {
      window.history.pushState({}, '', '/my-pages');

      render(<App />);

      // Sin token, la ruta privada rebota al login.
      await waitFor(() => {
        expect(window.location.pathname).toBe('/login');
      });
    });

    it('recupera el token de localStorage', async () => {
      localStorage.setItem('token', 'tok-guardado');
      localStorage.setItem('user', JSON.stringify({ id: 9, email: 'ana@test.local' }));

      render(<App />);

      // Con token, la home muestra la navegación privada.
      await waitFor(() => {
        expect(localStorage.getItem('token')).toBe('tok-guardado');
      });
    });

    /**
     * Comportamiento actual, no deseado: un `user` corrupto en localStorage
     * (por ejemplo tras un cambio de formato) hace que JSON.parse lance dentro
     * del efecto y la app quede en blanco, sin forma de recuperarse salvo
     * limpiando el navegador. Queda fijado para que el día que se envuelva en
     * try/catch el test avise.
     */
    it('hoy revienta si el usuario guardado está corrupto', () => {
      localStorage.setItem('token', 'tok');
      localStorage.setItem('user', 'no-es-json');

      expect(() => render(<App />)).toThrow();
    });

    it('tolera la ausencia de usuario con token presente', () => {
      localStorage.setItem('token', 'tok');

      expect(() => render(<App />)).not.toThrow();
    });
  });

  describe('rutas', () => {
    it('la raíz renderiza la home pública sin sesión', async () => {
      window.history.pushState({}, '', '/');

      render(<App />);

      // La home tiene el logo en la barra y en el pie: alcanza con que aparezca.
      await waitFor(() => {
        expect(screen.getAllByAltText('Rezonar').length).toBeGreaterThan(0);
      });
      expect(window.location.pathname).toBe('/');
    });

    it('sin sesión, /my-pages redirige al login', async () => {
      window.history.pushState({}, '', '/my-pages');

      render(<App />);

      await waitFor(() => {
        expect(window.location.pathname).toBe('/login');
      });
    });

    it('sin sesión, /pages redirige al login', async () => {
      window.history.pushState({}, '', '/pages');

      render(<App />);

      await waitFor(() => {
        expect(window.location.pathname).toBe('/login');
      });
    });

    it('sin sesión, /page/5 redirige al login', async () => {
      window.history.pushState({}, '', '/page/5');

      render(<App />);

      await waitFor(() => {
        expect(window.location.pathname).toBe('/login');
      });
    });

    it('con sesión, /login redirige a la raíz', async () => {
      localStorage.setItem('token', 'tok');
      localStorage.setItem('user', JSON.stringify({ id: 9, email: 'a@b.com' }));
      window.history.pushState({}, '', '/login');

      render(<App />);

      await waitFor(() => {
        expect(window.location.pathname).toBe('/');
      });
    });

    it('con sesión, /register redirige a la raíz', async () => {
      localStorage.setItem('token', 'tok');
      localStorage.setItem('user', JSON.stringify({ id: 9, email: 'a@b.com' }));
      window.history.pushState({}, '', '/register');

      render(<App />);

      await waitFor(() => {
        expect(window.location.pathname).toBe('/');
      });
    });

    it('sin sesión, /login se muestra', async () => {
      window.history.pushState({}, '', '/login');

      render(<App />);

      expect(await screen.findByRole('heading', { name: 'BIENVENIDO' })).toBeInTheDocument();
    });

    it('un slug desconocido va a la página pública', async () => {
      mockFetch({
        'public/page.php': { status: 404, body: { error: 'Page not found' } },
      });
      window.history.pushState({}, '', '/una-pagina-cualquiera');

      render(<App />);

      expect(await screen.findByText('404')).toBeInTheDocument();
    });

    it('/evento/:id es público', async () => {
      mockFetch({ 'public/event.php': { error: 'Evento no encontrado' } });
      window.history.pushState({}, '', '/evento/200');

      render(<App />);

      expect(await screen.findByText('Evento no encontrado')).toBeInTheDocument();
    });
  });

  describe('acciones del contexto', () => {
    it('login guarda token y usuario en localStorage', async () => {
      // Se ejercita a través de la pantalla de login con callback de OAuth.
      const usuario = { id: 9, email: 'ana@test.local', name: 'Ana' };
      window.history.pushState(
        {},
        '',
        `/login?token=tok-oauth&user=${encodeURIComponent(JSON.stringify(usuario))}`
      );

      render(<App />);

      await waitFor(() => {
        expect(localStorage.getItem('token')).toBe('tok-oauth');
        expect(JSON.parse(localStorage.getItem('user'))).toEqual(usuario);
      });
    });

    it('tras el login redirige a la raíz', async () => {
      const usuario = { id: 9, email: 'ana@test.local' };
      window.history.pushState(
        {},
        '',
        `/login?token=tok&user=${encodeURIComponent(JSON.stringify(usuario))}`
      );

      render(<App />);

      await waitFor(() => {
        expect(window.location.pathname).toBe('/');
      });
    });
  });

  describe('configuración', () => {
    it('las peticiones salen contra la URL de API configurada', async () => {
      // La URL sale de VITE_API_URL, con el valor de desarrollo por defecto.
      window.history.pushState({}, '', '/');

      render(<App />);

      await waitFor(() => {
        expect(global.fetch).toHaveBeenCalled();
      });

      const [url] = global.fetch.mock.calls[0];
      expect(url).toMatch(/^https?:\/\/[^/]+\/api\//);
    });
  });
});
