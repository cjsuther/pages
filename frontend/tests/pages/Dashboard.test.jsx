import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Dashboard from '../../src/pages/Dashboard';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe } from '../helpers/api';

/**
 * Dashboard hoy no tiene ruta en App.jsx ni lo importa ningún componente: es
 * código muerto que igual viaja en el bundle. Se testea igual para que, si se
 * vuelve a cablear o se decide borrarlo, la decisión sea consciente.
 */

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const pagina = (overrides = {}) => ({
  id: 5,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  ...overrides,
});

function mockearDashboard(pages = []) {
  return mockFetch({
    'pages/index.php': { pages },
    'pages/detail.php': { message: 'Page deleted successfully' },
  });
}

async function render(pages = []) {
  const mock = mockearDashboard(pages);
  const resultado = renderConProviders(<Dashboard />, { auth: autenticado() });
  await screen.findByRole('button', { name: '+ NUEVA PÁGINA' });
  return { ...resultado, ...mock };
}

describe('Dashboard', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
  });

  describe('listado', () => {
    it('avisa si no hay páginas', async () => {
      await render([]);

      expect(await screen.findByText('No tienes páginas todavía')).toBeInTheDocument();
    });

    it('lista las páginas del usuario', async () => {
      await render([pagina({ title: 'Mi Página' })]);

      expect(await screen.findByText('Mi Página')).toBeInTheDocument();
    });

    it('enlaza al editor', async () => {
      await render([pagina({ id: 5 })]);

      expect(await screen.findByRole('link', { name: 'EDITAR' })).toHaveAttribute('href', '/page/5');
    });

    it('no rompe si falla la carga', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<Dashboard />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('crear página', () => {
    async function abrirModal() {
      const mock = await render([]);
      fireEvent.click(screen.getByRole('button', { name: '+ NUEVA PÁGINA' }));
      await screen.findByRole('heading', { name: 'NUEVA PÁGINA' });
      return mock;
    }

    function completar({ titulo = 'Nueva', slug = 'nueva-pagina' } = {}) {
      const modal = screen.getByRole('heading', { name: 'NUEVA PÁGINA' }).closest('div');
      const campos = modal.querySelectorAll('input, textarea');

      fireEvent.change(campos[0], { target: { value: titulo } });
      fireEvent.change(screen.getByPlaceholderText('solo-letras-numeros-guiones'), {
        target: { value: slug },
      });
    }

    it('abre el modal', async () => {
      await abrirModal();

      expect(screen.getByPlaceholderText('solo-letras-numeros-guiones')).toBeInTheDocument();
    });

    it('rechaza los slugs reservados sin llamar a la API', async () => {
      const { llamadas } = await abrirModal();

      completar({ slug: 'login' });
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      expect(
        await screen.findByText('Esta URL está reservada y no puede ser utilizada')
      ).toBeInTheDocument();
      expect(llamadas.find((l) => l.options.method === 'POST')).toBeUndefined();
    });

    it('envía los datos de la nueva página', async () => {
      const { llamadas } = await abrirModal();

      completar({ titulo: 'Nueva', slug: 'nueva-pagina' });
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toMatchObject({ title: 'Nueva', url_slug: 'nueva-pagina' });
      });
    });

    it('muestra el error de la API', async () => {
      await abrirModal();

      mockFetch({ 'pages/index.php': { status: 400, body: { error: 'URL slug already exists' } } });
      completar();
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      expect(await screen.findByText('URL slug already exists')).toBeInTheDocument();
    });

    it('no incluye "feed" ni "my-pages" entre los reservados', async () => {
      // MyPages sí los reserva: las dos listas están duplicadas y difieren.
      const { llamadas } = await abrirModal();

      completar({ slug: 'feed' });
      fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'POST')).toBeDefined();
      });
    });
  });

  describe('eliminar página', () => {
    it('pide confirmación', async () => {
      await render([pagina()]);

      fireEvent.click(await screen.findByRole('button', { name: 'ELIMINAR' }));

      expect(window.confirm).toHaveBeenCalledWith('¿Estás seguro de eliminar esta página?');
    });

    it('no borra si el usuario cancela', async () => {
      window.confirm = vi.fn(() => false);
      const { llamadas } = await render([pagina()]);

      fireEvent.click(await screen.findByRole('button', { name: 'ELIMINAR' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'DELETE')).toBeUndefined();
      });
    });

    it('envía el DELETE con el id', async () => {
      const { llamadas } = await render([pagina({ id: 5 })]);

      fireEvent.click(await screen.findByRole('button', { name: 'ELIMINAR' }));

      await waitFor(() => {
        const del = llamadas.find((l) => l.options.method === 'DELETE');
        expect(del.url).toContain('pages/detail.php?id=5');
      });
    });
  });
});
