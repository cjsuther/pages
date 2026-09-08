import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import FollowButton from '../../src/components/FollowButton';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA, tokenDe } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

describe('FollowButton', () => {

  /**
   * El color de botones tiene que aplicarse en los dos estados. Siguiendo iba
   * como una tarjeta y el color elegido no lo tocaba: se veía como si el
   * control no funcionara.
   */
  describe('colores de la página', () => {
    const paleta = {
      fondo: '#111827', texto: '#e5e7eb', acento: '#f59e0b',
      titulo: '#ffffff', boton: '#7c3aed',
      tarjeta: '#1b2432', bordeTarjeta: 'rgba(229, 231, 235, 0.14)',
    };

    it('sin seguir, el botón va relleno con el color de botones', async () => {
      mockFetch({ 'pages/follow.php': { is_following: false } });

      renderConProviders(<FollowButton pageId={5} colores={paleta} />, {
        auth: crearAuth({ token: 'tok', user: usuarioDePrueba() }),
      });

      const boton = await screen.findByRole('button', { name: 'Seguir' });
      expect(boton).toHaveStyle({ backgroundColor: '#7c3aed' });
    });

    /** Los dos estados se ven igual: los distingue la etiqueta, no la forma. */
    it('siguiendo, el botón se ve igual que sin seguir', async () => {
      mockFetch({ 'pages/follow.php': { is_following: true } });

      renderConProviders(<FollowButton pageId={5} colores={paleta} />, {
        auth: crearAuth({ token: 'tok', user: usuarioDePrueba() }),
      });

      const boton = await screen.findByRole('button', { name: 'Siguiendo' });
      expect(boton).toHaveStyle({ backgroundColor: '#7c3aed' });
    });

    /** En el Home de Rezonar no llega paleta y conserva su estilo propio. */
    it('sin paleta no inventa colores', async () => {
      mockFetch({ 'pages/follow.php': { is_following: false } });

      renderConProviders(<FollowButton pageId={5} />, {
        auth: crearAuth({ token: 'tok', user: usuarioDePrueba() }),
      });

      const boton = await screen.findByRole('button', { name: 'Seguir' });
      expect(boton.getAttribute('style')).toBeFalsy();
    });
  });
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
  });

  describe('sin sesión', () => {
    it('muestra SEGUIR sin consultar el estado', async () => {
      const { llamadas } = mockFetch({ 'pages/follow.php': { is_following: false } });

      renderConProviders(<FollowButton pageId={5} />);

      expect(await screen.findByRole('button', { name: 'Seguir' })).toBeInTheDocument();
      expect(llamadas).toHaveLength(0);
    });

    it('manda al login al intentar seguir', async () => {
      renderConProviders(<FollowButton pageId={5} />);

      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));

      await waitFor(() => {
        expect(screen.queryByText('¿De qué querés enterarte?')).not.toBeInTheDocument();
      });
    });
  });

  describe('con sesión', () => {
    it('consulta el estado de seguimiento al montar', async () => {
      const { llamadas } = mockFetch({ 'pages/follow.php': { is_following: false } });

      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });

      await waitFor(() => expect(llamadas).toHaveLength(1));

      expect(llamadas[0].url).toContain('/pages/follow.php?page_id=5');
      expect(tokenDe(llamadas[0])).toBe('Bearer tok-123');
    });

    it('muestra SEGUIR si no lo sigue', async () => {
      mockFetch({ 'pages/follow.php': { is_following: false } });

      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });

      expect(await screen.findByRole('button', { name: 'Seguir' })).toBeInTheDocument();
    });

    it('muestra SIGUIENDO si ya lo sigue', async () => {
      mockFetch({ 'pages/follow.php': { is_following: true, notify_all_events: true } });

      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });

      expect(await screen.findByRole('button', { name: 'Siguiendo' })).toBeInTheDocument();
    });

    it('no muestra nada mientras carga', () => {
      mockFetch({ 'pages/follow.php': { is_following: false } });

      const { container } = renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });

      expect(container.querySelector('button')).toBeNull();
    });

    it('no rompe si falla la consulta de estado', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });

      expect(await screen.findByRole('button', { name: 'Seguir' })).toBeInTheDocument();
    });
  });

  describe('seguir', () => {
    async function abrirModal() {
      mockFetch({ 'pages/follow.php': { is_following: false } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));
      await screen.findByText('¿De qué querés enterarte?');
    }

    it('abre el modal de preferencias', async () => {
      await abrirModal();

      expect(screen.getByText('Todas sus fechas')).toBeInTheDocument();
      expect(screen.getByText('Solo si es cerca')).toBeInTheDocument();
    });

    it('viene con "todos los eventos" preseleccionado', async () => {
      await abrirModal();

      const [todos, cercanos] = screen.getAllByRole('radio');
      expect(todos).toBeChecked();
      expect(cercanos).not.toBeChecked();
    });

    it('permite elegir sólo eventos cercanos', async () => {
      await abrirModal();

      const [, cercanos] = screen.getAllByRole('radio');
      fireEvent.click(cercanos);

      expect(cercanos).toBeChecked();
    });

    it('se cierra con Cancelar sin enviar nada', async () => {
      const { llamadas } = mockFetch({ 'pages/follow.php': { is_following: false } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));
      await screen.findByText('¿De qué querés enterarte?');

      fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => {
        expect(screen.queryByText('¿De qué querés enterarte?')).not.toBeInTheDocument();
      });
      expect(llamadas.filter((l) => l.options.method === 'POST')).toHaveLength(0);
    });

    it('envía la preferencia "todos" al confirmar', async () => {
      const { llamadas } = mockFetch({ 'pages/follow.php': { is_following: false } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));
      await screen.findByText('¿De qué querés enterarte?');

      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({
          page_id: 5,
          notify_all_events: true,
          max_distance_km: 50,
        });
      });
    });

    it('envía la preferencia "cercanos" al confirmar', async () => {
      const { llamadas } = mockFetch({ 'pages/follow.php': { is_following: false } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));
      await screen.findByText('¿De qué querés enterarte?');

      fireEvent.click(screen.getAllByRole('radio')[1]);
      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post).notify_all_events).toBe(false);
      });
    });

    it('pasa a SIGUIENDO tras confirmar', async () => {
      mockFetch({ 'pages/follow.php': { is_following: false } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      fireEvent.click(await screen.findByRole('button', { name: 'Seguir' }));
      await screen.findByText('¿De qué querés enterarte?');

      fireEvent.click(screen.getByRole('button', { name: 'Seguir página' }));

      expect(await screen.findByRole('button', { name: 'Siguiendo' })).toBeInTheDocument();
    });
  });

  describe('dejar de seguir', () => {
    async function renderSiguiendo() {
      const mock = mockFetch({ 'pages/follow.php': { is_following: true, notify_all_events: true } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      await screen.findByRole('button', { name: 'Siguiendo' });
      return mock;
    }

    /**
     * Siguiendo ya no dispara un confirm(): abre el mismo panel donde se
     * eligen los avisos. Dejar de seguir es una acción más de ese panel, así
     * que se puede cambiar la preferencia sin arriesgarse a perder el
     * seguimiento de un clic.
     */
    it('al tocar Siguiendo abre los avisos, no un confirm', async () => {
      await renderSiguiendo();

      fireEvent.click(screen.getByRole('button', { name: 'Siguiendo' }));

      expect(await screen.findByText('Tus avisos de esta página')).toBeInTheDocument();
      expect(window.confirm).not.toHaveBeenCalled();
    });

    it('cerrar el panel no deja de seguir', async () => {
      const { llamadas } = await renderSiguiendo();

      fireEvent.click(screen.getByRole('button', { name: 'Siguiendo' }));
      await screen.findByText('Tus avisos de esta página');
      fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => {
        expect(llamadas.filter((l) => l.options.method === 'DELETE')).toHaveLength(0);
      });
      expect(screen.getByRole('button', { name: 'Siguiendo' })).toBeInTheDocument();
    });

    it('envía el DELETE con el id de la página', async () => {
      const { llamadas } = await renderSiguiendo();

      fireEvent.click(screen.getByRole('button', { name: 'Siguiendo' }));
      fireEvent.click(await screen.findByRole('button', { name: 'Dejar de seguir' }));

      await waitFor(() => {
        const del = llamadas.find((l) => l.options.method === 'DELETE');
        expect(del.url).toContain('page_id=5');
        expect(tokenDe(del)).toBe('Bearer tok-123');
      });
    });

    it('vuelve a Seguir al dejar de seguir', async () => {
      await renderSiguiendo();

      fireEvent.click(screen.getByRole('button', { name: 'Siguiendo' }));
      fireEvent.click(await screen.findByRole('button', { name: 'Dejar de seguir' }));

      expect(await screen.findByRole('button', { name: 'Seguir' })).toBeInTheDocument();
    });

    /** Si el DELETE falla se sigue siguiendo: el botón no miente. */
    it('si falla, sigue mostrando Siguiendo', async () => {
      mockFetch({ 'pages/follow.php': { is_following: true, notify_all_events: true } });
      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      await screen.findByRole('button', { name: 'Siguiendo' });

      fireEvent.click(screen.getByRole('button', { name: 'Siguiendo' }));
      const dejar = await screen.findByRole('button', { name: 'Dejar de seguir' });

      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));
      fireEvent.click(dejar);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Siguiendo' })).toBeInTheDocument();
      });
    });
  });

  describe('estado inicial de las preferencias', () => {
    it('preselecciona "cercanos" si así lo tenía guardado', async () => {
      mockFetch({ 'pages/follow.php': { is_following: true, notify_all_events: false } });

      renderConProviders(<FollowButton pageId={5} />, { auth: autenticado() });
      await screen.findByRole('button', { name: 'Siguiendo' });

      // El panel se abre desde Siguiendo y muestra lo que estaba guardado.
      fireEvent.click(screen.getByRole('button', { name: 'Siguiendo' }));

      const [todos, cercanos] = await screen.findAllByRole('radio');
      expect(cercanos).toBeChecked();
      expect(todos).not.toBeChecked();
    });
  });
});
