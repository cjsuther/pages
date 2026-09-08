import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import FollowingManager from '../../src/components/FollowingManager';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const seguida = (overrides = {}) => ({
  id: 7,
  title: 'Rock del Sur',
  description: 'Agenda de recitales',
  slug: 'rock-del-sur',
  image_url: null,
  notify_all_events: true,
  max_distance_km: 30,
  following_since: '2026-01-01 10:00:00',
  follower_count: 12,
  ...overrides,
});

function mockearSeguidas(following = []) {
  return mockFetch({
    'pages/following.php': { following, total: following.length },
    'pages/follow.php': { success: true },
  });
}

/**
 * El componente ya no tiene título propio: el encabezado lo pone la pantalla
 * que lo contiene. Se espera al buscador —o al cartel de vacío— para saber que
 * terminó de cargar.
 */
async function render(following = []) {
  const mock = mockearSeguidas(following);
  const resultado = renderConProviders(<FollowingManager />, { auth: autenticado() });

  if (following.length === 0) {
    await screen.findByText('Todavía no seguís ninguna página');
  } else {
    await screen.findByPlaceholderText(buscador(following.length));
  }

  return { ...resultado, ...mock };
}

/** El placeholder dice cuántas seguís, así que depende del caso. */
const buscador = (n) => `Buscar entre las ${n} que seguís...`;

describe('FollowingManager', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
  });

  describe('carga', () => {
    it('muestra un indicador mientras carga', () => {
      mockearSeguidas([]);

      const { container } = renderConProviders(<FollowingManager />, { auth: autenticado() });

      expect(container.querySelector('.animate-spin')).toBeInTheDocument();
    });

    it('avisa si no sigue ninguna página', async () => {
      await render([]);

      expect(screen.getByText('Todavía no seguís ninguna página')).toBeInTheDocument();
    });

    it('no muestra el buscador sin páginas seguidas', async () => {
      await render([]);

      expect(screen.queryByPlaceholderText(/Buscar entre las/)).not.toBeInTheDocument();
    });

    it('lista las páginas seguidas', async () => {
      await render([seguida({ title: 'Rock del Sur' }), seguida({ id: 8, title: 'Jazz Club' })]);

      expect(screen.getByText('Rock del Sur')).toBeInTheDocument();
      expect(screen.getByText('Jazz Club')).toBeInTheDocument();
    });

    it('enlaza a cada página', async () => {
      await render([seguida({ slug: 'rock-del-sur', title: 'Rock del Sur' })]);

      expect(screen.getByRole('link', { name: 'Rock del Sur' })).toHaveAttribute('href', '/rock-del-sur');
    });

    it('no rompe si falla la carga', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<FollowingManager />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
      expect(await screen.findByText('Todavía no seguís ninguna página')).toBeInTheDocument();
    });
  });

  describe('preferencias mostradas', () => {
    it('indica cuando recibe todos los eventos', async () => {
      await render([seguida({ notify_all_events: true })]);

      expect(screen.getByText('Todas sus fechas')).toBeInTheDocument();
    });

    it('indica cuando sólo recibe los cercanos', async () => {
      await render([seguida({ notify_all_events: false })]);

      expect(screen.getByText('Solo a menos de 30 km')).toBeInTheDocument();
    });
  });

  describe('búsqueda dentro de las seguidas', () => {
    async function renderVarias() {
      return render([
        seguida({ id: 1, title: 'Rock del Sur', description: 'Recitales' }),
        seguida({ id: 2, title: 'Jazz Club', description: 'Noches de jazz' }),
      ]);
    }

    it('filtra por título', async () => {
      await renderVarias();

      fireEvent.change(screen.getByPlaceholderText(/Buscar entre las/), {
        target: { value: 'jazz' },
      });

      await waitFor(() => {
        expect(screen.queryByText('Rock del Sur')).not.toBeInTheDocument();
      });
      expect(screen.getByText('Jazz Club')).toBeInTheDocument();
    });

    it('filtra por descripción', async () => {
      await renderVarias();

      fireEvent.change(screen.getByPlaceholderText(/Buscar entre las/), {
        target: { value: 'recitales' },
      });

      await waitFor(() => {
        expect(screen.queryByText('Jazz Club')).not.toBeInTheDocument();
      });
      expect(screen.getByText('Rock del Sur')).toBeInTheDocument();
    });

    it('ignora mayúsculas', async () => {
      await renderVarias();

      fireEvent.change(screen.getByPlaceholderText(/Buscar entre las/), {
        target: { value: 'JAZZ' },
      });

      expect(await screen.findByText('Jazz Club')).toBeInTheDocument();
    });

    it('avisa si el filtro no encuentra nada', async () => {
      await renderVarias();

      fireEvent.change(screen.getByPlaceholderText(/Buscar entre las/), {
        target: { value: 'cumbia' },
      });

      expect(
        await screen.findByText('Ninguna coincide con esa búsqueda')
      ).toBeInTheDocument();
    });

    it('no rompe con páginas sin descripción', async () => {
      await render([seguida({ description: null })]);

      fireEvent.change(screen.getByPlaceholderText(/Buscar entre las/), {
        target: { value: 'algo' },
      });

      expect(
        await screen.findByText('Ninguna coincide con esa búsqueda')
      ).toBeInTheDocument();
    });
  });

  describe('paginación', () => {
    const muchas = (n) =>
      Array.from({ length: n }, (_, i) => seguida({ id: i + 1, title: `Página ${i + 1}` }));

    it('no pagina con ocho o menos', async () => {
      await render(muchas(8));

      expect(screen.queryByRole('button', { name: 'Siguiente' })).not.toBeInTheDocument();
    });

    it('muestra ocho por página', async () => {
      await render(muchas(10));

      expect(screen.getByText('Página 1')).toBeInTheDocument();
      expect(screen.getByText('Página 8')).toBeInTheDocument();
      expect(screen.queryByText('Página 9')).not.toBeInTheDocument();
    });

    it('indica en qué página está', async () => {
      await render(muchas(10));

      expect(screen.getByText('Página 1 de 2')).toBeInTheDocument();
    });

    it('avanza a la página siguiente', async () => {
      await render(muchas(10));

      fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }));

      expect(await screen.findByText('Página 9')).toBeInTheDocument();
      expect(screen.queryByText('Página 1')).not.toBeInTheDocument();
    });

    it('el botón anterior está deshabilitado en la primera', async () => {
      await render(muchas(10));

      expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled();
    });

    it('el botón siguiente está deshabilitado en la última', async () => {
      await render(muchas(10));

      fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }));

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
      });
    });

    it('vuelve a la primera página al filtrar', async () => {
      await render(muchas(10));

      fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }));
      await screen.findByText('Página 9');

      fireEvent.change(screen.getByPlaceholderText(/Buscar entre las/), {
        target: { value: 'Página' },
      });

      expect(await screen.findByText('Página 1 de 2')).toBeInTheDocument();
    });
  });

  describe('editar preferencias', () => {
    it('abre el editor con la preferencia actual', async () => {
      await render([seguida({ notify_all_events: false })]);

      fireEvent.click(screen.getByRole('button', { name: /^Avisos/ }));

      const [todos, cercanos] = screen.getAllByRole('radio');
      expect(cercanos).toBeChecked();
      expect(todos).not.toBeChecked();
    });

    it('guarda la preferencia elegida', async () => {
      const { llamadas } = await render([seguida({ id: 7, notify_all_events: true })]);

      fireEvent.click(screen.getByRole('button', { name: /^Avisos/ }));
      fireEvent.click(screen.getAllByRole('radio')[1]);
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({
          page_id: 7,
          notify_all_events: false,
          max_distance_km: 30,
        });
      });
    });

    it('refleja el cambio en la lista', async () => {
      await render([seguida({ notify_all_events: true })]);

      fireEvent.click(screen.getByRole('button', { name: /^Avisos/ }));
      fireEvent.click(screen.getAllByRole('radio')[1]);
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      expect(await screen.findByText('Solo a menos de 30 km')).toBeInTheDocument();
    });

    it('se puede cancelar sin guardar', async () => {
      const { llamadas } = await render([seguida()]);

      fireEvent.click(screen.getByRole('button', { name: /^Avisos/ }));
      fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => {
        expect(screen.queryAllByRole('radio')).toHaveLength(0);
      });
      expect(llamadas.find((l) => l.options.method === 'POST')).toBeUndefined();
    });
  });

  describe('dejar de seguir', () => {
    it('pide confirmación', async () => {
      await render([seguida()]);

      fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));

      expect(window.confirm).toHaveBeenCalledWith(
        '¿Dejar de seguir a Rock del Sur? No vas a recibir más avisos de sus fechas.'
      );
    });

    it('no hace nada si el usuario cancela', async () => {
      window.confirm = vi.fn(() => false);
      const { llamadas } = await render([seguida()]);

      fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'DELETE')).toBeUndefined();
      });
      expect(screen.getByText('Rock del Sur')).toBeInTheDocument();
    });

    it('envía el DELETE con el id', async () => {
      const { llamadas } = await render([seguida({ id: 7 })]);

      fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));

      await waitFor(() => {
        const del = llamadas.find((l) => l.options.method === 'DELETE');
        expect(del.url).toContain('page_id=7');
      });
    });

    it('quita la página de la lista', async () => {
      await render([seguida({ title: 'Rock del Sur' })]);

      fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));

      await waitFor(() => {
        expect(screen.queryByText('Rock del Sur')).not.toBeInTheDocument();
      });
    });

    it('no rompe si falla', async () => {
      await render([seguida()]);
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      fireEvent.click(screen.getByRole('button', { name: 'Dejar de seguir' }));

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });
});
