import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import PageEditor from '../../src/pages/PageEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

/**
 * Flujos de edición del editor: configuración de la página, grupos, links,
 * colaboraciones y administradores. La suite PageEditor.test.jsx cubre la
 * carga y las validaciones; acá van los caminos de escritura.
 */

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const link = (overrides = {}) => ({
  id: 100,
  url: 'https://ejemplo.com',
  url_text: null,
  text: 'Un link',
  description: null,
  image_url: null,
  position: 0,
  collaborations: [],
  ...overrides,
});

const grupo = (overrides = {}) => ({
  id: 10,
  title: 'Mis Links',
  type: 'links',
  position: 0,
  links: [],
  collaborated_events: [],
  ...overrides,
});

const pagina = (overrides = {}) => ({
  id: 5,
  user_id: 9,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  profile_image: null,
  background_image: null,
  primary_color: '#3B82F6',
  secondary_color: '#1E40AF',
  background_color: '#FFFFFF',
  text_color: '#000000',
  template: 'minimal',
  groups: [],
  ...overrides,
});

function mockearEditor({ page = pagina(), admins = [], pending = [], results = [] } = {}) {
  return mockFetch({
    'pages/detail.php': { page },
    'admins/index.php': { admins },
    'admins/detail.php': { message: 'ok' },
    'collaborations/index.php': { pending, collaborations: [] },
    'collaborations/detail.php': { message: 'ok' },
    'groups/index.php': { group: grupo() },
    'groups/detail.php': { group: grupo() },
    'links/index.php': { link: link() },
    'links/detail.php': { link: link() },
    'public/search.php': { results },
    'upload/image.php': { url: 'https://img/subida.png' },
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'users/location.php': { latitude: null, longitude: null },
  });
}

async function render(datos = {}, seccion = null) {
  const mock = mockearEditor(datos);
  const resultado = renderConProviders(<PageEditor />, {
    auth: autenticado(),
    route: '/page/5',
    path: '/page/:id',
  });
  await screen.findByRole('heading', { name: 'EDITOR' });

  if (seccion) {
    // El nombre accesible puede incluir el badge de pendientes ("CONTENIDO 1").
    fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${seccion}`) }));
    await screen.findByRole('heading', {
      name: seccion === 'CONTENIDO' ? 'GRUPOS DE LINKS' : seccion,
    });
  }

  return { ...resultado, ...mock };
}

/** Archivo de imagen válido para los inputs de tipo file. */
function archivo({ nombre = 'foto.jpg', tipo = 'image/jpeg', bytes = 1024 } = {}) {
  const f = new File(['x'], nombre, { type: tipo });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

const put = (llamadas, fragmento) =>
  llamadas.find((l) => l.url.includes(fragmento) && l.options.method === 'PUT');

describe('PageEditor — flujos de edición', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    window.gtag = vi.fn();
  });

  afterEach(() => {
    window.google = undefined;
  });

  // ======================================================== configuración

  describe('datos de la página', () => {
    it('guarda el título al salir del campo', async () => {
      const { llamadas } = await render();

      const titulo = screen.getByDisplayValue('Mi Página');
      fireEvent.change(titulo, { target: { value: 'Nuevo Título' } });
      fireEvent.blur(titulo);

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({ title: 'Nuevo Título' });
      });
    });

    it('guarda la descripción al salir del campo', async () => {
      const { llamadas } = await render();

      const desc = screen.getByDisplayValue('Una descripción');
      fireEvent.change(desc, { target: { value: 'Otra descripción' } });
      fireEvent.blur(desc);

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({
          description: 'Otra descripción',
        });
      });
    });

    it('el slug no se puede editar', async () => {
      await render();

      expect(screen.getByDisplayValue('mi-pagina')).toBeDisabled();
    });

    it('guarda los colores', async () => {
      const { container, llamadas } = await render();

      const colores = container.querySelectorAll('input[type="color"]');
      expect(colores.length).toBeGreaterThan(0);

      fireEvent.change(colores[0], { target: { value: '#ff0000' } });

      await waitFor(() => {
        expect(put(llamadas, 'pages/detail.php')).toBeDefined();
      });
    });

    it('cambia la plantilla', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByText('Cards').closest('button'));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({ template: 'cards' });
      });
    });

    it.each([
      ['Minimal', 'minimal'],
      ['Cards', 'cards'],
      ['Modern', 'modern'],
      ['Condensado', 'condensed'],
    ])('la plantilla %s envía "%s"', async (etiqueta, valor) => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByText(etiqueta).closest('button'));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({ template: valor });
      });
    });

    // Elegir a ciegas entre cuatro nombres no dice nada: cada opción muestra
    // cómo se ve.
    it('cada plantilla se ofrece con una vista previa', async () => {
      const { container } = await render();

      ['minimal', 'cards', 'modern', 'condensed'].forEach((clave) => {
        expect(container.querySelector(`[data-plantilla="${clave}"]`)).not.toBeNull();
      });
    });

    it('la vista previa se pinta con los colores de la página', async () => {
      const { container } = await render({
        page: pagina({ background_color: '#102030' }),
      });

      expect(container.querySelector('[data-plantilla="minimal"] > div'))
        .toHaveStyle({ backgroundColor: '#102030' });
    });

    it('la plantilla en uso se ve elegida', async () => {
      await render({ page: pagina({ template: 'cards' }) });

      expect(screen.getByText('Cards').closest('button')).toHaveAttribute('aria-pressed', 'true');
      expect(screen.getByText('Minimal').closest('button')).toHaveAttribute('aria-pressed', 'false');
    });
  });

  // ====================================================== subida de imágenes

  describe('subida de imágenes', () => {
    it('sube la imagen de perfil y la guarda en la página', async () => {
      const { container, llamadas } = await render();

      const inputs = container.querySelectorAll('input[type="file"]');
      fireEvent.change(inputs[0], { target: { files: [archivo()] } });

      await waitFor(() => {
        const subida = llamadaA(llamadas, 'upload/image.php');
        expect(subida.options.method).toBe('POST');
        expect(subida.options.body).toBeInstanceOf(FormData);
      });

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({
          profile_image: 'https://img/subida.png',
        });
      });
    });

    it('sube la imagen de fondo', async () => {
      const { container, llamadas } = await render();

      const inputs = container.querySelectorAll('input[type="file"]');
      fireEvent.change(inputs[1], { target: { files: [archivo()] } });

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({
          background_image: 'https://img/subida.png',
        });
      });
    });

    it('rechaza archivos de más de 5MB sin llamar a la API', async () => {
      const { container, llamadas } = await render();

      const inputs = container.querySelectorAll('input[type="file"]');
      fireEvent.change(inputs[0], {
        target: { files: [archivo({ bytes: 6 * 1024 * 1024 })] },
      });

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('La imagen es muy grande. Máximo 5MB');
      });
      expect(llamadaA(llamadas, 'upload/image.php')).toBeNull();
    });

    it('rechaza tipos de archivo no permitidos', async () => {
      const { container, llamadas } = await render();

      const inputs = container.querySelectorAll('input[type="file"]');
      fireEvent.change(inputs[0], {
        target: { files: [archivo({ nombre: 'doc.pdf', tipo: 'application/pdf' })] },
      });

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'Tipo de archivo no válido. Solo JPG, PNG, GIF y WebP'
        );
      });
      expect(llamadaA(llamadas, 'upload/image.php')).toBeNull();
    });

    it('no hace nada si no se eligió archivo', async () => {
      const { container, llamadas } = await render();

      const inputs = container.querySelectorAll('input[type="file"]');
      fireEvent.change(inputs[0], { target: { files: [] } });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'upload/image.php')).toBeNull();
      });
    });

    it('avisa si la API rechaza la subida', async () => {
      const { container } = await render();

      mockFetch({ 'upload/image.php': { status: 400, body: { error: 'Invalid file type' } } });

      const inputs = container.querySelectorAll('input[type="file"]');
      fireEvent.change(inputs[0], { target: { files: [archivo()] } });

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Error al subir imagen: Invalid file type');
      });
    });

    it('quita la imagen de perfil', async () => {
      const { llamadas } = await render({
        page: pagina({ profile_image: 'https://img/perfil.png' }),
      });

      const quitar = screen.getByAltText('Perfil').closest('div').querySelector('button');
      fireEvent.click(quitar);

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'pages/detail.php'))).toEqual({ profile_image: null });
      });
    });
  });

  // =============================================================== grupos

  describe('grupos', () => {
    const unGrupo = (extra = {}) => pagina({ groups: [grupo({ id: 10, title: 'Mis Links', ...extra })] });

    const accionDeGrupo = (etiqueta) => screen.getAllByText(etiqueta)[0];

    /**
     * Antes las acciones vivían en un menú contextual en móvil, y un título
     * largo empujaba el botón que lo abría fuera de la pantalla: no había
     * forma de llegar a ellas. Ahora son los mismos botones siempre, que se
     * apilan debajo del título cuando no entran.
     */
    it('las acciones del grupo existen una sola vez y siempre visibles', async () => {
      await render({ page: unGrupo() }, 'CONTENIDO');

      expect(screen.getAllByText('Editar Título')).toHaveLength(1);
      expect(screen.getAllByText('Eliminar')).toHaveLength(1);
    });

    /** El título se recorta en vez de estirar la fila y empujar los botones. */
    it('un título largo no desplaza las acciones', async () => {
      await render({
        page: unGrupo({ title: 'Un título larguísimo que en un teléfono no entra de ninguna manera' }),
      }, 'CONTENIDO');

      const titulo = screen.getByRole('heading', { level: 3 });

      expect(titulo.className).toContain('truncate');
      expect(screen.getByText('Eliminar')).toBeInTheDocument();
    });

    it('edita el título del grupo', async () => {
      const { llamadas } = await render({ page: unGrupo() }, 'CONTENIDO');

      fireEvent.click(accionDeGrupo('Editar Título'));

      await screen.findByRole('heading', { name: 'EDITAR GRUPO' });
      const input = document.querySelector('form input[type="text"]');
      fireEvent.change(input, { target: { value: 'Otro Título' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'groups/detail.php'))).toEqual({ title: 'Otro Título' });
      });
    });

    it('elimina el grupo tras confirmar', async () => {
      const { llamadas } = await render({ page: unGrupo() }, 'CONTENIDO');

      fireEvent.click(accionDeGrupo('Eliminar'));

      await waitFor(() => {
        expect(window.confirm).toHaveBeenCalled();
        const del = llamadas.find(
          (l) => l.url.includes('groups/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=10');
      });
    });

    it('no elimina el grupo si se cancela', async () => {
      window.confirm = vi.fn(() => false);
      const { llamadas } = await render({ page: unGrupo() }, 'CONTENIDO');

      fireEvent.click(accionDeGrupo('Eliminar'));

      await waitFor(() => {
        expect(window.confirm).toHaveBeenCalled();
      });
      expect(
        llamadas.find((l) => l.url.includes('groups/detail.php') && l.options.method === 'DELETE')
      ).toBeUndefined();
    });
  });

  // ================================================================ links

  describe('links', () => {
    const conLink = () =>
      pagina({ groups: [grupo({ id: 10, type: 'links', links: [link({ id: 100, text: 'Instagram' })] })] });

    it('crea un link con sus datos', async () => {
      const { llamadas } = await render({ page: pagina({ groups: [grupo({ id: 10 })] }) }, 'CONTENIDO');

      fireEvent.click(screen.getByRole('button', { name: '+ Link' }));
      const crear = await screen.findByRole('button', { name: 'CREAR' });
      const form = crear.closest('form');

      const campos = form.querySelectorAll('input[type="text"], input[type="url"], textarea');
      fireEvent.change(campos[0], { target: { value: 'Mi Link' } });
      fireEvent.submit(form);

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('links/index.php') && l.options.method === 'POST'
        );
        expect(post).toBeDefined();
        expect(cuerpoDe(post)).toMatchObject({ group_id: 10 });
      });
    });

    // La edición vive en su propia pantalla (ver ItemEditor.test.jsx): acá se
    // comprueba que tanto el título como "Editar" llevan hasta ella.
    it('el título del link lleva a su edición', async () => {
      await render({ page: conLink() }, 'CONTENIDO');

      expect(screen.getByRole('link', { name: 'Instagram' }))
        .toHaveAttribute('href', '/page/5/item/100');
    });

    it('el botón Editar lleva a la edición del link', async () => {
      await render({ page: conLink() }, 'CONTENIDO');

      expect(screen.getByRole('link', { name: 'Editar' }))
        .toHaveAttribute('href', '/page/5/item/100');
    });

    it('elimina el link tras confirmar', async () => {
      const { llamadas } = await render({ page: conLink() }, 'CONTENIDO');

      // [0] es el del grupo, [1] el del link.
      fireEvent.click(screen.getAllByRole('button', { name: 'Eliminar' })[1]);

      await waitFor(() => {
        expect(window.confirm).toHaveBeenCalledWith('¿Eliminar este link?');
        const del = llamadas.find(
          (l) => l.url.includes('links/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=100');
      });
    });

    it('no elimina el link si se cancela', async () => {
      window.confirm = vi.fn(() => false);
      const { llamadas } = await render({ page: conLink() }, 'CONTENIDO');

      fireEvent.click(screen.getAllByRole('button', { name: 'Eliminar' })[1]);

      await waitFor(() => expect(window.confirm).toHaveBeenCalled());
      expect(
        llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'DELETE')
      ).toBeUndefined();
    });

    it('reordena los links del grupo', async () => {
      const { llamadas } = await render({
        page: pagina({
          groups: [
            grupo({
              id: 10,
              type: 'links',
              links: [link({ id: 1, text: 'Uno' }), link({ id: 2, text: 'Dos', position: 1 })],
            }),
          ],
        }),
      }, 'CONTENIDO');

      // [0] mueve el grupo; [1] es el primer link, el único que puede bajar.
      const bajar = screen.getAllByTitle('Mover abajo');
      fireEvent.click(bajar[1]);

      await waitFor(() => {
        const puts = llamadas.filter(
          (l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'
        );
        expect(puts.length).toBeGreaterThan(0);
      });
    });

    it('avisa cuando un evento está vencido', async () => {
      await render({
        page: pagina({
          groups: [
            grupo({ id: 20, type: 'eventos', links: [link({ id: 200, text: 'Viejo', event_due: '1' })] }),
          ],
        }),
      }, 'CONTENIDO');

      expect(screen.getByText('¡Evento vencido!')).toBeInTheDocument();
    });

    it('los grupos de eventos no ofrecen reordenar', async () => {
      await render({
        page: pagina({
          groups: [grupo({ id: 20, type: 'eventos', links: [link({ id: 200 })] })],
        }),
      }, 'CONTENIDO');

      // Sólo quedan las flechas del grupo, no las de los links.
      expect(screen.getAllByTitle('Mover arriba')).toHaveLength(1);
    });
  });

  // ======================================================= colaboraciones

  describe('colaboraciones de un evento', () => {
    const conEvento = (collaborations = []) =>
      pagina({
        groups: [
          grupo({
            id: 20,
            type: 'eventos',
            links: [link({ id: 200, text: 'Mi Evento', collaborations })],
          }),
        ],
      });

    it('muestra el estado de cada colaborador', async () => {
      await render({
        page: conEvento([
          { id: 1, status: 'accepted', page_title: 'Aceptada', collaborator_page_id: 7 },
          { id: 2, status: 'pending', page_title: 'Pendiente', collaborator_page_id: 8 },
          { id: 3, status: 'rejected', page_title: 'Rechazada', collaborator_page_id: 9 },
        ]),
      }, 'CONTENIDO');

      expect(screen.getByText(/aceptó/)).toBeInTheDocument();
      expect(screen.getByText(/pendiente/)).toBeInTheDocument();
      expect(screen.getByText(/rechazó/)).toBeInTheDocument();
    });

    it('quita un colaborador', async () => {
      const { llamadas } = await render({
        page: conEvento([{ id: 1, status: 'accepted', page_title: 'Otra', collaborator_page_id: 7 }]),
      }, 'CONTENIDO');

      fireEvent.click(screen.getByTitle('Quitar colaborador'));

      await waitFor(() => {
        const del = llamadas.find(
          (l) => l.url.includes('collaborations/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=1');
      });
    });

  });

  describe('invitaciones a colaborar recibidas', () => {
    const conPendiente = () => ({
      page: pagina({ groups: [grupo({ id: 20, type: 'eventos' })] }),
      pending: [
        {
          id: 1,
          link_id: 100,
          event_title: 'Recital Compartido',
          requester_page_title: 'Otra Página',
          collaborator_page_id: 5,
          status: 'pending',
        },
      ],
    });

    /** Con un único grupo de eventos el modal lo asigna solo, sin preguntar. */
    it('con un solo grupo de eventos no pregunta y lo asigna', async () => {
      const { llamadas } = await render(conPendiente(), 'CONTENIDO');

      fireEvent.click(await screen.findByRole('button', { name: 'Aceptar' }));
      await screen.findByRole('heading', { name: 'ACEPTAR COLABORACIÓN' });

      expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
      expect(screen.getByText(/El evento se agregará al grupo/)).toBeInTheDocument();

      fireEvent.click(screen.getByRole('button', { name: 'ACEPTAR' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'collaborations/detail.php'))).toEqual({
          status: 'accepted',
          group_id: 20,
        });
      });
    });

    /** Con dos o más, pide elegir el grupo destino. */
    it('con varios grupos de eventos pide elegir destino', async () => {
      const { llamadas } = await render({
        page: pagina({
          groups: [
            grupo({ id: 20, title: 'Agenda', type: 'eventos' }),
            grupo({ id: 21, title: 'Ciclo', type: 'eventos' }),
          ],
        }),
        pending: conPendiente().pending,
      }, 'CONTENIDO');

      fireEvent.click(await screen.findByRole('button', { name: 'Aceptar' }));
      await screen.findByRole('heading', { name: 'ACEPTAR COLABORACIÓN' });

      const select = screen.getByRole('combobox');
      expect(within(select).getAllByRole('option')).toHaveLength(3); // vacío + 2 grupos

      fireEvent.change(select, { target: { value: '21' } });
      fireEvent.click(screen.getByRole('button', { name: 'ACEPTAR' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'collaborations/detail.php'))).toEqual({
          status: 'accepted',
          group_id: 21,
        });
      });
    });

    it('rechaza la colaboración', async () => {
      const { llamadas } = await render(conPendiente(), 'CONTENIDO');

      fireEvent.click(await screen.findByRole('button', { name: 'Rechazar' }));

      await waitFor(() => {
        const respuesta = put(llamadas, 'collaborations/detail.php');
        expect(cuerpoDe(respuesta)).toMatchObject({ status: 'rejected' });
      });
    });

    it('se puede cancelar el modal de aceptación', async () => {
      const { llamadas } = await render(conPendiente(), 'CONTENIDO');

      fireEvent.click(await screen.findByRole('button', { name: 'Aceptar' }));
      await screen.findByRole('heading', { name: 'ACEPTAR COLABORACIÓN' });
      fireEvent.click(screen.getAllByRole('button', { name: 'CANCELAR' })[0]);

      await waitFor(() => {
        expect(
          screen.queryByRole('heading', { name: 'ACEPTAR COLABORACIÓN' })
        ).not.toBeInTheDocument();
      });
      expect(put(llamadas, 'collaborations/detail.php')).toBeUndefined();
    });
  });

  // ======================================================= administradores

  describe('administradores', () => {
    it('quita un administrador aceptado', async () => {
      const { llamadas } = await render({
        admins: [
          { id: 3, user_id: 11, user_name: 'Beto', user_email: 'beto@test.local', status: 'accepted' },
        ],
      }, 'ADMINISTRADORES');

      fireEvent.click(await screen.findByRole('button', { name: 'QUITAR' }));

      await waitFor(() => {
        const del = llamadas.find(
          (l) => l.url.includes('admins/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=3');
      });
    });

    it('cancela una invitación pendiente', async () => {
      const { llamadas } = await render({
        admins: [
          { id: 4, user_id: 12, user_name: null, user_email: 'pend@test.local', status: 'pending' },
        ],
      }, 'ADMINISTRADORES');

      fireEvent.click(await screen.findByRole('button', { name: 'CANCELAR' }));

      await waitFor(() => {
        const del = llamadas.find(
          (l) => l.url.includes('admins/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=4');
      });
    });
  });
});
