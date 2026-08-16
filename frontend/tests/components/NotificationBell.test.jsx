import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import NotificationBell from '../../src/components/NotificationBell';
import { renderConProviders, crearAuth, usuarioDePrueba, API_URL } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA, tokenDe } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const notificacion = (overrides = {}) => ({
  id: 1,
  title: 'Nuevo evento',
  message: 'La página X publicó un evento',
  type: 'event',
  is_read: false,
  created_at: new Date().toISOString(),
  page_id: 5,
  page_slug: 'mi-pagina',
  page_title: 'Mi Página',
  link_id: 100,
  ...overrides,
});

function mockearLista(notifications = [], unread_count = 0, extra = {}) {
  return mockFetch({
    'notifications/index.php': { notifications, unread_count },
    ...extra,
  });
}

function espiarLocation() {
  const original = window.location;
  delete window.location;
  window.location = { href: '' };
  return () => { window.location = original; };
}

describe('NotificationBell', () => {
  let restaurarLocation;

  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    restaurarLocation = espiarLocation();
  });

  afterEach(() => {
    restaurarLocation();
  });

  describe('carga inicial', () => {
    it('pide las notificaciones al montar', async () => {
      const { llamadas } = mockearLista();

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      await waitFor(() => {
        expect(llamadas[0].url).toBe(`${API_URL}/notifications/index.php?limit=20`);
        expect(tokenDe(llamadas[0])).toBe('Bearer tok-123');
      });
    });

    it('no rompe si falla la petición', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
      expect(screen.getByRole('button')).toBeInTheDocument();
    });

    it('refresca periódicamente', async () => {
      vi.useFakeTimers();
      const { llamadas } = mockearLista();

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      await vi.advanceTimersByTimeAsync(60000);

      expect(llamadas.length).toBeGreaterThanOrEqual(2);
      vi.useRealTimers();
    });

    it('deja de refrescar al desmontar', async () => {
      vi.useFakeTimers();
      const { llamadas } = mockearLista();

      const { unmount } = renderConProviders(<NotificationBell />, { auth: autenticado() });
      await vi.advanceTimersByTimeAsync(0);

      unmount();
      const antes = llamadas.length;
      await vi.advanceTimersByTimeAsync(120000);

      expect(llamadas.length).toBe(antes);
      vi.useRealTimers();
    });
  });

  describe('contador', () => {
    it('no muestra globo si no hay pendientes', async () => {
      mockearLista([], 0);

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      await waitFor(() => {
        expect(screen.queryByText('0')).not.toBeInTheDocument();
      });
    });

    it('muestra la cantidad de no leídas', async () => {
      mockearLista([notificacion()], 3);

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      expect(await screen.findByText('3')).toBeInTheDocument();
    });

    it('corta en 9+', async () => {
      mockearLista([notificacion()], 25);

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      expect(await screen.findByText('9+')).toBeInTheDocument();
    });

    it('muestra 9 sin cortar', async () => {
      mockearLista([notificacion()], 9);

      renderConProviders(<NotificationBell />, { auth: autenticado() });

      expect(await screen.findByText('9')).toBeInTheDocument();
    });
  });

  describe('panel desplegable', () => {
    async function abrir(notifications = [notificacion()], unread = 1, extra = {}) {
      const mock = mockearLista(notifications, unread, extra);
      renderConProviders(<NotificationBell />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);
      return mock;
    }

    it('se abre al tocar la campana', async () => {
      await abrir();

      expect(screen.getByText('Notificaciones')).toBeInTheDocument();
    });

    it('avisa cuando no hay notificaciones', async () => {
      await abrir([], 0);

      expect(screen.getByText('No tienes notificaciones')).toBeInTheDocument();
    });

    it('lista las notificaciones', async () => {
      await abrir([
        notificacion({ id: 1, title: 'Primera' }),
        notificacion({ id: 2, title: 'Segunda' }),
      ], 2);

      expect(screen.getByText('Primera')).toBeInTheDocument();
      expect(screen.getByText('Segunda')).toBeInTheDocument();
    });

    it('muestra el mensaje y la página', async () => {
      await abrir();

      expect(screen.getByText('La página X publicó un evento')).toBeInTheDocument();
      expect(screen.getByText('Mi Página')).toBeInTheDocument();
    });

    it('resalta las no leídas', async () => {
      const { container } = renderConProviders(<NotificationBell />, { auth: autenticado() });
      mockearLista([notificacion({ is_read: false })], 1);
      fireEvent.click(screen.getAllByRole('button')[0]);

      await waitFor(() => {
        expect(container.querySelectorAll('.bg-blue-50').length).toBeGreaterThanOrEqual(0);
      });
    });

    it('etiqueta las invitaciones a colaborar', async () => {
      await abrir([notificacion({ type: 'collaboration_request' })], 1);

      expect(screen.getByText('Colaboración')).toBeInTheDocument();
      expect(screen.getByText('Ver editor →')).toBeInTheDocument();
    });

    it('se cierra al hacer click fuera', async () => {
      await abrir();

      fireEvent.mouseDown(document.body);

      await waitFor(() => {
        expect(screen.queryByText('Notificaciones')).not.toBeInTheDocument();
      });
    });
  });

  describe('marcar como leídas', () => {
    async function abrirCon(notifications, unread) {
      const mock = mockearLista(notifications, unread);
      renderConProviders(<NotificationBell />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);
      return mock;
    }

    it('no ofrece "marcar todas" si no hay pendientes', async () => {
      await abrirCon([notificacion({ is_read: true })], 0);

      expect(screen.queryByText('Marcar todas como leídas')).not.toBeInTheDocument();
    });

    it('marca todas como leídas', async () => {
      const { llamadas } = await abrirCon([notificacion()], 2);

      fireEvent.click(screen.getByText('Marcar todas como leídas'));

      await waitFor(() => {
        const put = llamadas.find((l) => l.options.method === 'PUT');
        expect(cuerpoDe(put)).toEqual({ mark_all_as_read: true });
      });
    });

    it('marca una sola al abrirla', async () => {
      const { llamadas } = await abrirCon([notificacion({ id: 42, is_read: false })], 1);

      fireEvent.click(screen.getByText('Nuevo evento'));

      await waitFor(() => {
        const put = llamadas.find((l) => l.options.method === 'PUT');
        expect(cuerpoDe(put)).toEqual({ notification_ids: [42] });
      });
    });

    it('no vuelve a marcar una ya leída', async () => {
      const { llamadas } = await abrirCon([notificacion({ is_read: true })], 0);

      fireEvent.click(screen.getByText('Nuevo evento'));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'PUT')).toBeUndefined();
      });
    });

    it('recarga la lista después de marcar', async () => {
      const { llamadas } = await abrirCon([notificacion()], 2);
      const antes = llamadas.length;

      fireEvent.click(screen.getByText('Marcar todas como leídas'));

      await waitFor(() => {
        expect(llamadas.length).toBeGreaterThan(antes + 1);
      });
    });
  });

  describe('navegación al tocar una notificación', () => {
    async function abrirCon(notif) {
      const mock = mockearLista([notif], 1);
      renderConProviders(<NotificationBell />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);
      return mock;
    }

    it('las de eventos llevan a la página pública', async () => {
      await abrirCon(notificacion({ type: 'event', page_slug: 'la-pagina' }));

      fireEvent.click(screen.getByText('Nuevo evento'));

      await waitFor(() => {
        expect(window.location.href).toBe('/la-pagina');
      });
    });

    it('las de colaboración llevan al editor', async () => {
      await abrirCon(notificacion({ type: 'collaboration_request', page_id: 77 }));

      fireEvent.click(screen.getByText('Nuevo evento'));

      // Va por react-router, no por window.location.
      await waitFor(() => {
        expect(window.location.href).toBe('');
      });
    });

    it('cierra el panel al navegar', async () => {
      await abrirCon(notificacion({ type: 'collaboration_response' }));

      fireEvent.click(screen.getByText('Nuevo evento'));

      await waitFor(() => {
        expect(screen.queryByText('Notificaciones')).not.toBeInTheDocument();
      });
    });
  });

  describe('formato de fechas', () => {
    async function abrirConFecha(created_at) {
      const mock = mockearLista([notificacion({ created_at })], 1);
      renderConProviders(<NotificationBell />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);
    }

    const haceMinutos = (m) => new Date(Date.now() - m * 60000).toISOString();

    it('muestra "Ahora" para lo recién creado', async () => {
      await abrirConFecha(new Date().toISOString());

      expect(screen.getByText('Ahora')).toBeInTheDocument();
    });

    it('muestra los minutos', async () => {
      await abrirConFecha(haceMinutos(30));

      expect(screen.getByText('Hace 30 min')).toBeInTheDocument();
    });

    it('muestra las horas', async () => {
      await abrirConFecha(haceMinutos(60 * 5));

      expect(screen.getByText('Hace 5h')).toBeInTheDocument();
    });

    it('muestra los días', async () => {
      await abrirConFecha(haceMinutos(60 * 24 * 3));

      expect(screen.getByText('Hace 3d')).toBeInTheDocument();
    });

    it('usa fecha completa a partir de una semana', async () => {
      await abrirConFecha(haceMinutos(60 * 24 * 30));

      expect(screen.queryByText(/Hace/)).not.toBeInTheDocument();
    });
  });
});
