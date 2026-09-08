import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import MyPages from '../../src/pages/MyPages';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const pagina = (overrides = {}) => ({
  id: 5,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  is_owner: 1,
  ...overrides,
});

const invitacion = (overrides = {}) => ({
  id: 3,
  page_id: 7,
  page_title: 'Página Ajena',
  owner_name: 'Beto',
  owner_email: 'beto@test.local',
  status: 'pending',
  ...overrides,
});

function mockearMisPaginas({ pages = [], invitations = [], pending = [] } = {}) {
  return mockFetch({
    'pages/index.php': { pages },
    'admins/index.php': { invitations },
    'admins/detail.php': { message: 'ok' },
    'collaborations/index.php': { pending },
    'pages/detail.php': { message: 'Page deleted successfully' },
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'users/location.php': { latitude: null, longitude: null },
  });
}

async function render(opciones = {}) {
  const mock = mockearMisPaginas(opciones);
  const resultado = renderConProviders(<MyPages />, { auth: autenticado() });
  await screen.findByRole('heading', { name: 'Mis páginas' });
  return { ...resultado, ...mock };
}

describe('MyPages', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
  });

  describe('listado', () => {
    it('avisa si no hay páginas', async () => {
      await render({ pages: [] });

      expect(await screen.findByText('Todavía no tenés ninguna página')).toBeInTheDocument();
    });

    it('muestra cada página con su título y descripción', async () => {
      await render({ pages: [pagina({ title: 'Mi Página', description: 'Una descripción' })] });

      expect(await screen.findByText('Mi Página')).toBeInTheDocument();
      expect(screen.getByText('Una descripción')).toBeInTheDocument();
    });

    it('enlaza a la página pública', async () => {
      await render({ pages: [pagina({ url_slug: 'mi-pagina' })] });

      const enlace = await screen.findByRole('link', { name: /rezon\.ar\/mi-pagina/ });
      expect(enlace).toHaveAttribute('href', '/mi-pagina');
      expect(enlace).toHaveAttribute('target', '_blank');
    });

    it('enlaza al editor', async () => {
      await render({ pages: [pagina({ id: 5 })] });

      expect(await screen.findByRole('link', { name: 'Editar' })).toHaveAttribute('href', '/page/5');
    });

    it('no rompe si falla la carga', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<MyPages />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('páginas propias vs administradas', () => {
    it('la página propia ofrece eliminar', async () => {
      await render({ pages: [pagina({ is_owner: 1 })] });

      expect(await screen.findByRole('button', { name: 'Eliminar' })).toBeInTheDocument();
      expect(screen.queryByText('Dejar de administrar')).not.toBeInTheDocument();
    });

    it('la página administrada ofrece dejar de administrar', async () => {
      await render({ pages: [pagina({ is_owner: 0 })] });

      expect(await screen.findByRole('button', { name: 'Dejar de administrar' })).toBeInTheDocument();
      expect(screen.queryByText('Eliminar')).not.toBeInTheDocument();
    });

    it('marca las administradas con la etiqueta ADMIN', async () => {
      await render({ pages: [pagina({ is_owner: 0 })] });

      expect(await screen.findByText('Administrás esta página')).toBeInTheDocument();
    });

    it('no marca las propias', async () => {
      await render({ pages: [pagina({ is_owner: 1 })] });

      await screen.findByText('Mi Página');
      expect(screen.queryByText('Administrás esta página')).not.toBeInTheDocument();
    });

    it('interpreta is_owner como texto (lo que devuelve MySQL)', async () => {
      await render({ pages: [pagina({ is_owner: '1' })] });

      await screen.findByText('Mi Página');
      expect(screen.queryByText('Administrás esta página')).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Eliminar' })).toBeInTheDocument();
    });
  });

  describe('colaboraciones pendientes', () => {
    it('marca la página con colaboraciones por aprobar', async () => {
      await render({
        pages: [pagina({ id: 5 })],
        pending: [{ id: 1, collaborator_page_id: 5 }],
      });

      expect(await screen.findByTitle('Tenés colaboraciones pendientes para aprobar')).toBeInTheDocument();
    });

    it('no marca nada si no hay pendientes', async () => {
      await render({ pages: [pagina({ id: 5 })], pending: [] });

      await screen.findByText('Mi Página');
      expect(screen.queryByTitle('Tenés colaboraciones pendientes para aprobar')).not.toBeInTheDocument();
    });
  });

  describe('invitaciones para administrar', () => {
    it('no muestra el bloque si no hay invitaciones', async () => {
      await render({ invitations: [] });

      expect(screen.queryByText('Te invitaron a administrar')).not.toBeInTheDocument();
    });

    it('lista las invitaciones recibidas', async () => {
      await render({ invitations: [invitacion({ page_title: 'Página Ajena', owner_name: 'Beto' })] });

      expect(await screen.findByText('Te invitaron a administrar')).toBeInTheDocument();
      expect(screen.getByText('Página Ajena')).toBeInTheDocument();
      expect(screen.getByText(/te invitó Beto/)).toBeInTheDocument();
    });

    it('usa el email si el invitante no tiene nombre', async () => {
      await render({ invitations: [invitacion({ owner_name: null, owner_email: 'beto@test.local' })] });

      expect(await screen.findByText(/te invitó beto@test.local/)).toBeInTheDocument();
    });

    it('acepta la invitación', async () => {
      const { llamadas } = await render({ invitations: [invitacion({ id: 3 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Aceptar' }));

      await waitFor(() => {
        const put = llamadas.find((l) => l.options.method === 'PUT');
        expect(put.url).toContain('admins/detail.php?id=3');
        expect(cuerpoDe(put)).toEqual({ status: 'accepted' });
      });
    });

    it('rechaza la invitación', async () => {
      const { llamadas } = await render({ invitations: [invitacion({ id: 3 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Rechazar' }));

      await waitFor(() => {
        const put = llamadas.find((l) => l.options.method === 'PUT');
        expect(cuerpoDe(put)).toEqual({ status: 'rejected' });
      });
    });

    it('recarga las páginas al aceptar', async () => {
      const { llamadas } = await render({ invitations: [invitacion()] });
      const antes = llamadas.filter((l) => l.url.includes('pages/index.php')).length;

      fireEvent.click(await screen.findByRole('button', { name: 'Aceptar' }));

      await waitFor(() => {
        const despues = llamadas.filter((l) => l.url.includes('pages/index.php')).length;
        expect(despues).toBeGreaterThan(antes);
      });
    });

    it('al rechazar no recarga las páginas', async () => {
      const { llamadas } = await render({ invitations: [invitacion()] });
      const antes = llamadas.filter((l) => l.url.includes('pages/index.php')).length;

      fireEvent.click(await screen.findByRole('button', { name: 'Rechazar' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'PUT')).toBeDefined();
      });
      expect(llamadas.filter((l) => l.url.includes('pages/index.php')).length).toBe(antes);
    });
  });

  describe('crear página', () => {
    async function abrirModal() {
      const mock = await render({ pages: [] });
      fireEvent.click(screen.getByRole('button', { name: 'Nueva página' }));
      await screen.findByRole('heading', { name: 'Nueva página' });
      return mock;
    }

    function completar({ titulo = 'Nueva', descripcion = 'Desc', slug = 'nueva-pagina' } = {}) {
      fireEvent.change(screen.getByLabelText('Título'), { target: { value: titulo } });
      fireEvent.change(screen.getByLabelText('Descripción'), { target: { value: descripcion } });
      fireEvent.change(screen.getByLabelText('Tu dirección'), { target: { value: slug } });
    }

    it('abre el modal', async () => {
      await abrirModal();

      expect(screen.getByLabelText('Título')).toBeInTheDocument();
      expect(screen.getByLabelText('Tu dirección')).toBeInTheDocument();
    });

    it('pasa el slug a minúsculas mientras se escribe', async () => {
      await abrirModal();

      fireEvent.change(screen.getByLabelText('Tu dirección'), { target: { value: 'MiPagina' } });

      expect(screen.getByLabelText('Tu dirección')).toHaveValue('mipagina');
    });

    it('restringe el slug por patrón', async () => {
      await abrirModal();

      expect(screen.getByLabelText('Tu dirección')).toHaveAttribute('pattern', '[a-z0-9-]+');
    });

    it('rechaza los slugs reservados sin llamar a la API', async () => {
      const { llamadas } = await abrirModal();

      completar({ slug: 'login' });
      fireEvent.click(screen.getByRole('button', { name: 'Crear página' }));

      expect(
        await screen.findByText('Esta URL está reservada y no puede ser utilizada')
      ).toBeInTheDocument();
      expect(llamadas.find((l) => l.options.method === 'POST')).toBeUndefined();
    });

    it.each(['login', 'api', 'dashboard', 'feed', 'my-pages', 'settings'])(
      'rechaza el slug reservado "%s"',
      async (slug) => {
        await abrirModal();

        completar({ slug });
        fireEvent.click(screen.getByRole('button', { name: 'Crear página' }));

        expect(
          await screen.findByText('Esta URL está reservada y no puede ser utilizada')
        ).toBeInTheDocument();
      }
    );

    it('envía los datos de la nueva página', async () => {
      const { llamadas } = await abrirModal();

      completar({ titulo: 'Nueva', descripcion: 'Desc', slug: 'nueva-pagina' });
      fireEvent.click(screen.getByRole('button', { name: 'Crear página' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({
          title: 'Nueva',
          description: 'Desc',
          url_slug: 'nueva-pagina',
        });
      });
    });

    it('muestra el error que devuelve la API', async () => {
      await abrirModal();

      mockFetch({ 'pages/index.php': { status: 400, body: { error: 'URL slug already exists' } } });
      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Crear página' }));

      expect(await screen.findByText('URL slug already exists')).toBeInTheDocument();
    });

    it('se puede cancelar', async () => {
      await abrirModal();

      fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Nueva página' })).not.toBeInTheDocument();
      });
    });

    it('cancelar limpia el error anterior', async () => {
      await abrirModal();

      completar({ slug: 'login' });
      fireEvent.click(screen.getByRole('button', { name: 'Crear página' }));
      await screen.findByText('Esta URL está reservada y no puede ser utilizada');

      fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
      fireEvent.click(screen.getByRole('button', { name: 'Nueva página' }));

      await screen.findByRole('heading', { name: 'Nueva página' });
      expect(screen.queryByText('Esta URL está reservada y no puede ser utilizada')).not.toBeInTheDocument();
    });
  });

  describe('eliminar página', () => {
    it('pide confirmación', async () => {
      await render({ pages: [pagina({ is_owner: 1 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Eliminar' }));

      expect(window.confirm).toHaveBeenCalledWith('¿Estás seguro de eliminar esta página?');
    });

    it('no borra si el usuario cancela', async () => {
      window.confirm = vi.fn(() => false);
      const { llamadas } = await render({ pages: [pagina({ is_owner: 1 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Eliminar' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'DELETE')).toBeUndefined();
      });
    });

    it('envía el DELETE con el id de la página', async () => {
      const { llamadas } = await render({ pages: [pagina({ id: 5, is_owner: 1 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Eliminar' }));

      await waitFor(() => {
        const del = llamadas.find((l) => l.options.method === 'DELETE');
        expect(del.url).toContain('pages/detail.php?id=5');
      });
    });
  });

  describe('dejar de administrar', () => {
    it('pide confirmación', async () => {
      await render({ pages: [pagina({ is_owner: 0 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Dejar de administrar' }));

      expect(window.confirm).toHaveBeenCalledWith('¿Dejar de administrar esta página?');
    });

    it('envía el DELETE al endpoint de administradores', async () => {
      const { llamadas } = await render({ pages: [pagina({ id: 5, is_owner: 0 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Dejar de administrar' }));

      await waitFor(() => {
        const del = llamadas.find((l) => l.options.method === 'DELETE');
        expect(del.url).toContain('admins/detail.php?page_id=5');
      });
    });

    it('no hace nada si el usuario cancela', async () => {
      window.confirm = vi.fn(() => false);
      const { llamadas } = await render({ pages: [pagina({ is_owner: 0 })] });

      fireEvent.click(await screen.findByRole('button', { name: 'Dejar de administrar' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'DELETE')).toBeUndefined();
      });
    });
  });
});
