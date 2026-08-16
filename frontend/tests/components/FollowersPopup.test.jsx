import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import FollowersPopup from '../../src/components/FollowersPopup';
import { renderConProviders } from '../helpers/render';
import { mockFetch } from '../helpers/api';

const seguidor = (overrides = {}) => ({
  email: 'ana@test.local',
  page_title: 'Página de Ana',
  page_slug: 'ana',
  followed_at: '2026-01-15 10:00:00',
  ...overrides,
});

describe('FollowersPopup', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('contador', () => {
    it('muestra el plural', () => {
      renderConProviders(<FollowersPopup pageId={5} followerCount={12} />);

      expect(screen.getByText('12 seguidores')).toBeInTheDocument();
    });

    it('muestra el singular con un solo seguidor', () => {
      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);

      expect(screen.getByText('1 seguidor')).toBeInTheDocument();
    });

    it('muestra cero cuando no hay seguidores', () => {
      renderConProviders(<FollowersPopup pageId={5} followerCount={0} />);

      expect(screen.getByText('0 seguidores')).toBeInTheDocument();
    });

    it('muestra cero si el contador viene sin definir', () => {
      renderConProviders(<FollowersPopup pageId={5} />);

      expect(screen.getByText('0 seguidores')).toBeInTheDocument();
    });
  });

  describe('apertura', () => {
    it('no abre nada si no hay seguidores', () => {
      const { llamadas } = mockFetch({ 'public/followers.php': { followers: [] } });

      renderConProviders(<FollowersPopup pageId={5} followerCount={0} />);
      fireEvent.click(screen.getByRole('button'));

      expect(llamadas).toHaveLength(0);
      expect(screen.queryByText('Sin seguidores')).not.toBeInTheDocument();
    });

    it('pide los seguidores de la página al abrir', async () => {
      const { llamadas } = mockFetch({
        'public/followers.php': { followers: [seguidor()] },
      });

      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);
      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(llamadas[0].url).toContain('/public/followers.php?page_id=5');
      });
    });

    it('lista los seguidores recibidos', async () => {
      mockFetch({
        'public/followers.php': {
          followers: [
            seguidor({ page_title: 'Página de Ana', page_slug: 'ana' }),
            seguidor({ page_title: 'Página de Beto', page_slug: 'beto' }),
          ],
        },
      });

      renderConProviders(<FollowersPopup pageId={5} followerCount={2} />);
      fireEvent.click(screen.getByRole('button'));

      expect(await screen.findByText('Página de Ana')).toBeInTheDocument();
      expect(screen.getByText('Página de Beto')).toBeInTheDocument();
    });

    it('enlaza a la página del seguidor', async () => {
      mockFetch({ 'public/followers.php': { followers: [seguidor()] } });

      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);
      fireEvent.click(screen.getByRole('button'));

      const enlace = await screen.findByRole('link', { name: 'Página de Ana' });
      expect(enlace).toHaveAttribute('href', '/ana');
      expect(enlace).toHaveAttribute('target', '_blank');
    });

    it('muestra el email si el seguidor no tiene página', async () => {
      mockFetch({
        'public/followers.php': {
          followers: [seguidor({ page_slug: null, page_title: null })],
        },
      });

      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);
      fireEvent.click(screen.getByRole('button'));

      expect(await screen.findByText('ana@test.local')).toBeInTheDocument();
      expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('usa el email como texto si hay slug pero no título', async () => {
      mockFetch({
        'public/followers.php': {
          followers: [seguidor({ page_title: null })],
        },
      });

      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);
      fireEvent.click(screen.getByRole('button'));

      expect(await screen.findByRole('link', { name: 'ana@test.local' })).toBeInTheDocument();
    });

    it('muestra la fecha de seguimiento formateada', async () => {
      mockFetch({
        'public/followers.php': {
          followers: [seguidor({ followed_at: '2026-01-15 10:00:00' })],
        },
      });

      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);
      fireEvent.click(screen.getByRole('button'));

      expect(await screen.findByText('15/01/2026')).toBeInTheDocument();
    });

    it('avisa cuando la lista viene vacía', async () => {
      mockFetch({ 'public/followers.php': { followers: [] } });

      renderConProviders(<FollowersPopup pageId={5} followerCount={3} />);
      fireEvent.click(screen.getByRole('button'));

      expect(await screen.findByText('Sin seguidores')).toBeInTheDocument();
    });

    it('no rompe si la petición falla', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<FollowersPopup pageId={5} followerCount={3} />);
      fireEvent.click(screen.getByRole('button'));

      expect(await screen.findByText('Sin seguidores')).toBeInTheDocument();
      expect(console.error).toHaveBeenCalled();
    });
  });

  describe('cierre', () => {
    async function abrir() {
      mockFetch({ 'public/followers.php': { followers: [seguidor()] } });
      renderConProviders(<FollowersPopup pageId={5} followerCount={1} />);
      fireEvent.click(screen.getAllByRole('button')[0]);
      await screen.findByText('Página de Ana');
    }

    it('se cierra con la X', async () => {
      await abrir();

      // El primer botón es el contador; el segundo, la X del modal.
      fireEvent.click(screen.getAllByRole('button')[1]);

      await waitFor(() => {
        expect(screen.queryByText('Página de Ana')).not.toBeInTheDocument();
      });
    });

    it('se cierra al hacer click en el fondo', async () => {
      await abrir();

      const fondo = document.querySelector('.fixed.inset-0');
      fireEvent.click(fondo);

      await waitFor(() => {
        expect(screen.queryByText('Página de Ana')).not.toBeInTheDocument();
      });
    });

    it('no se cierra al hacer click dentro del modal', async () => {
      await abrir();

      fireEvent.click(screen.getByText('Página de Ana'));

      expect(screen.getByText('Página de Ana')).toBeInTheDocument();
    });
  });
});
