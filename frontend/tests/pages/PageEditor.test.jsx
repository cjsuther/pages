import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import PageEditor from '../../src/pages/PageEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

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

const evento = (overrides = {}) =>
  link({
    id: 200,
    text: 'Mi Evento',
    event_date: '2026-12-01',
    event_time: '20:00:00',
    event_address: 'Av. Corrientes 1234',
    event_latitude: '-34.6037',
    event_longitude: '-58.3816',
    event_maps_url: 'https://maps.google.com/x',
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
  // usuarioDePrueba() tiene id 9: así el editor reconoce al dueño y muestra
  // la sección de administradores.
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

function mockearEditor({ page = pagina(), admins = [], pending = [], results = [], esPlataforma = false } = {}) {
  return mockFetch({
    'pages/detail.php': { page, es_plataforma: esPlataforma },
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
  // El encabezado del editor es el nombre de la página, no la palabra "editor".
  await screen.findByRole('heading', { name: 'Mi Página' });

  if (seccion) {
    // El nombre accesible puede incluir el badge de pendientes ("CONTENIDO 1").
    fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${seccion}`) }));
    await screen.findByRole('heading', {
      name: seccion === 'Contenido' ? 'Grupos de links' : seccion,
    });
  }

  return { ...resultado, ...mock };
}

describe('PageEditor', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    window.gtag = vi.fn();
  });

  afterEach(() => {
    window.google = undefined;
  });

  describe('carga', () => {
    it('pide la página por el id de la ruta', async () => {
      const { llamadas } = await render();

      expect(llamadaA(llamadas, 'pages/detail.php').url).toContain('id=5');
    });

    it('ofrece las cinco secciones en el submenú', async () => {
      await render();

      ['Configuración', 'Contenido', 'Redes sociales', 'Entradas', 'Administradores'].forEach((s) => {
        expect(screen.getByRole('button', { name: new RegExp(`^${s}`) })).toBeInTheDocument();
      });
    });

    it('la solapa de entradas abre la configuración de cobros', async () => {
      await render();

      fireEvent.click(screen.getByRole('button', { name: /^Entradas/ }));

      expect(await screen.findByRole('heading', { name: 'Entradas' })).toBeInTheDocument();
    });

    it('abre en configuración', async () => {
      await render();

      expect(screen.getByRole('heading', { name: 'Configuración' })).toBeInTheDocument();
      // El resto vive en sus solapas y no se renderiza hasta abrirlas.
      expect(screen.queryByRole('heading', { name: 'Grupos de links' })).not.toBeInTheDocument();
    });

    it('cambia de sección al tocar el submenú', async () => {
      await render();

      fireEvent.click(screen.getByRole('button', { name: /^Contenido/ }));

      expect(await screen.findByRole('heading', { name: 'Grupos de links' })).toBeInTheDocument();
      expect(screen.queryByRole('heading', { name: 'Configuración' })).not.toBeInTheDocument();
    });

    it('quien no es dueño no ve la solapa de administradores', async () => {
      await render({ page: pagina({ user_id: 77 }) });

      expect(screen.queryByRole('button', { name: /^Administradores/ })).not.toBeInTheDocument();
    });

    it('avisa si la página no tiene grupos', async () => {
      await render({ page: pagina({ groups: [] }) }, 'Contenido');

      expect(screen.getByText('No hay grupos todavía')).toBeInTheDocument();
    });

    it('no rompe si falla la carga', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<PageEditor />, {
        auth: autenticado(), route: '/page/5', path: '/page/:id',
      });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('grupos', () => {
    it('lista los grupos con su tipo', async () => {
      await render({
        page: pagina({
          groups: [
            grupo({ id: 1, title: 'Mis Links', type: 'links' }),
            grupo({ id: 2, title: 'Agenda', type: 'eventos' }),
            grupo({ id: 3, title: 'Fotos', type: 'galeria' }),
          ],
        }),
      }, 'Contenido');

      expect(screen.getByText('Mis Links')).toBeInTheDocument();
      expect(screen.getByText('Links')).toBeInTheDocument();
      expect(screen.getByText('Eventos')).toBeInTheDocument();
      expect(screen.getByText('Galería')).toBeInTheDocument();
    });

    /**
     * El tipo "redes" se sacó, pero puede quedar alguno viejo en una base sin
     * migrar: se muestra como lo que las plantillas siempre dibujaron, links.
     */
    it('un grupo de un tipo que ya no existe se muestra como links', async () => {
      await render({
        page: pagina({ groups: [grupo({ id: 4, title: 'Redes', type: 'redes' })] }),
      }, 'Contenido');

      expect(screen.getByText('Links')).toBeInTheDocument();
      expect(screen.queryByText('Redes Sociales')).not.toBeInTheDocument();
    });

    /**
     * El tipo del grupo decide cómo se ve el contenido, y es lo primero que
     * hay que entender para cargar algo. Antes no estaba dicho en ninguna
     * parte: se creaba un grupo de links y después no había dónde poner la
     * fecha.
     */
    it('explica para qué sirve cada tipo de grupo', async () => {
      await render({}, 'Contenido');

      expect(screen.getByText(/Todo lo que publicás va adentro de un grupo/)).toBeInTheDocument();
      expect(screen.getByText('Links —')).toBeInTheDocument();
      expect(screen.getByText('Eventos —')).toBeInTheDocument();
      expect(screen.getByText('Galería —')).toBeInTheDocument();
    });

    /** Es el único tipo que llega a la agenda, al mapa y a las entradas. */
    it('aclara qué gana un evento por serlo', async () => {
      await render({}, 'Contenido');

      expect(screen.getByText(/aparece en la agenda, en el mapa y en el buscador/)).toBeInTheDocument();
    });

    /** Las redes son de la página, no un bloque de contenido. */
    it('manda las redes sociales a su sección', async () => {
      await render({}, 'Contenido');

      expect(screen.getByText(/se cargan en la sección Redes Sociales/)).toBeInTheDocument();
    });

    it('ya no ofrece crear un grupo de redes sociales', async () => {
      await render({}, 'Contenido');

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));

      expect(screen.getByRole('option', { name: 'Links' })).toBeInTheDocument();
      expect(screen.queryByRole('option', { name: 'Redes Sociales' })).not.toBeInTheDocument();
    });

    it('abre el modal de nuevo grupo', async () => {
      await render({}, 'Contenido');

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));

      expect(await screen.findByRole('heading', { name: 'Nuevo grupo' })).toBeInTheDocument();
    });

    it('crea el grupo con el page_id de la ruta', async () => {
      const { llamadas } = await render({}, 'Contenido');

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'Nuevo grupo' });

      const modal = screen.getByRole('heading', { name: 'Nuevo grupo' }).closest('div');
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'Nuevo Grupo' } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('groups/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toMatchObject({ title: 'Nuevo Grupo', page_id: '5' });
      });
    });

    it('el tipo por defecto es links', async () => {
      const { llamadas } = await render({}, 'Contenido');

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'Nuevo grupo' });

      const modal = screen.getByRole('heading', { name: 'Nuevo grupo' }).closest('div');
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'G' } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('groups/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post).type).toBe('links');
      });
    });

    it('se puede cancelar la creación', async () => {
      const { llamadas } = await render({}, 'Contenido');

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'Nuevo grupo' });
      fireEvent.click(screen.getAllByRole('button', { name: 'Cancelar' })[0]);

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Nuevo grupo' })).not.toBeInTheDocument();
      });
      expect(
        llamadas.find((l) => l.url.includes('groups/index.php') && l.options.method === 'POST')
      ).toBeUndefined();
    });

    it('recarga la página tras crear el grupo', async () => {
      const { llamadas } = await render({}, 'Contenido');
      const antes = llamadas.filter((l) => l.url.includes('pages/detail.php')).length;

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      await screen.findByRole('heading', { name: 'Nuevo grupo' });
      const modal = screen.getByRole('heading', { name: 'Nuevo grupo' }).closest('div');
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'G' } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        const despues = llamadas.filter((l) => l.url.includes('pages/detail.php')).length;
        expect(despues).toBeGreaterThan(antes);
      });
    });
  });

  describe('reordenar grupos', () => {
    const dosGrupos = () =>
      pagina({
        groups: [
          grupo({ id: 1, title: 'Primero', position: 0 }),
          grupo({ id: 2, title: 'Segundo', position: 1 }),
        ],
      });

    it('el primer grupo no puede subir', async () => {
      await render({ page: dosGrupos() }, 'Contenido');

      const subir = screen.getAllByTitle('Mover arriba');
      expect(subir[0]).toBeDisabled();
    });

    it('el último grupo no puede bajar', async () => {
      await render({ page: dosGrupos() }, 'Contenido');

      const bajar = screen.getAllByTitle('Mover abajo');
      expect(bajar[bajar.length - 1]).toBeDisabled();
    });

    it('mover un grupo actualiza las posiciones', async () => {
      const { llamadas } = await render({ page: dosGrupos() }, 'Contenido');

      fireEvent.click(screen.getAllByTitle('Mover abajo')[0]);

      await waitFor(() => {
        const puts = llamadas.filter(
          (l) => l.url.includes('groups/detail.php') && l.options.method === 'PUT'
        );
        expect(puts.length).toBeGreaterThan(0);
        expect(cuerpoDe(puts[0])).toHaveProperty('position');
      });
    });
  });

  describe('links dentro de un grupo', () => {
    const conLinks = () =>
      pagina({
        groups: [grupo({ id: 10, type: 'links', links: [link({ id: 100, text: 'Instagram' })] })],
      });

    it('lista los links del grupo', async () => {
      await render({ page: conLinks() }, 'Contenido');

      expect(screen.getByText('Instagram')).toBeInTheDocument();
    });

    it('ofrece agregar un link al grupo', async () => {
      await render({ page: conLinks() }, 'Contenido');

      expect(screen.getByRole('button', { name: '+ Link' })).toBeInTheDocument();
    });

    it('la etiqueta del botón depende del tipo de grupo', async () => {
      await render({
        page: pagina({
          groups: [
            grupo({ id: 1, type: 'links' }),
            grupo({ id: 2, type: 'eventos' }),
            grupo({ id: 3, type: 'galeria' }),
          ],
        }),
      }, 'Contenido');

      expect(screen.getByRole('button', { name: '+ Link' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: '+ Evento' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: '+ Contenido' })).toBeInTheDocument();
    });
  });

  describe('eventos: validación de coordenadas', () => {
    const grupoEventos = () =>
      pagina({ groups: [grupo({ id: 20, title: 'Agenda', type: 'eventos', links: [] })] });

    it('no deja crear un evento sin dirección de Google Maps', async () => {
      const { llamadas } = await render({ page: grupoEventos() }, 'Contenido');

      fireEvent.click(screen.getByRole('button', { name: '+ Evento' }));
      const crear = await screen.findByRole('button', { name: 'Crear' });

      // Se completan los campos obligatorios para que el navegador deje
      // enviar el formulario y se llegue a la validación de coordenadas.
      const modal = crear.closest('form');
      modal.querySelectorAll('input[required], textarea[required]').forEach((campo) => {
        fireEvent.change(campo, { target: { value: 'x' } });
      });

      fireEvent.submit(modal);

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'Debes seleccionar una dirección válida de Google Maps para el evento'
        );
      });
      expect(
        llamadas.find((l) => l.url.includes('links/index.php') && l.options.method === 'POST')
      ).toBeUndefined();
    });
  });

  describe('administradores', () => {
    it('carga la lista al montar', async () => {
      const { llamadas } = await render({}, 'Administradores');

      expect(llamadaA(llamadas, 'admins/index.php').url).toContain('page_id=5');
    });

    it('lista los administradores', async () => {
      await render({
        admins: [
          { id: 1, user_id: 11, user_name: 'Beto', user_email: 'beto@test.local', status: 'accepted' },
        ],
      }, 'Administradores');

      const encontrados = await screen.findAllByText(/beto@test.local|Beto/);
      expect(encontrados.length).toBeGreaterThan(0);
    });

    it('invita por email', async () => {
      const { llamadas } = await render({}, 'Administradores');

      fireEvent.change(screen.getByPlaceholderText('email@ejemplo.com'), {
        target: { value: 'nuevo@test.local' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'Invitar' }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('admins/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toMatchObject({ email: 'nuevo@test.local' });
      });
    });

    it('muestra el error que devuelve la API al invitar', async () => {
      await render({}, 'Administradores');

      mockFetch({
        'admins/index.php': {
          status: 404,
          body: { error: 'No hay ningún usuario registrado con ese email' },
        },
      });

      fireEvent.change(screen.getByPlaceholderText('email@ejemplo.com'), {
        target: { value: 'nadie@test.local' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'Invitar' }));

      expect(
        await screen.findByText('No hay ningún usuario registrado con ese email')
      ).toBeInTheDocument();
    });
  });

  describe('colaboraciones pendientes', () => {
    it('no muestra la sección si no hay', async () => {
      await render({ pending: [] }, 'Contenido');

      expect(screen.queryByText('Colaboraciones pendientes')).not.toBeInTheDocument();
    });

    it('muestra las invitaciones recibidas', async () => {
      await render({
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
      }, 'Contenido');

      expect(await screen.findByText('Colaboraciones pendientes')).toBeInTheDocument();
      expect(screen.getByText(/Recital Compartido/)).toBeInTheDocument();
    });
  });

  describe('configuración de la página', () => {
    it('muestra los datos actuales', async () => {
      await render({ page: pagina({ title: 'Mi Página', description: 'Una descripción' }) });

      expect(screen.getByDisplayValue('Mi Página')).toBeInTheDocument();
      expect(screen.getByDisplayValue('Una descripción')).toBeInTheDocument();
    });

    it('ofrece elegir plantilla', async () => {
      await render();

      expect(screen.getByText('Template de diseño')).toBeInTheDocument();
    });

    // ------------------------------------------------------------ usuario

    /**
     * Cambiar el usuario rompe todo lo que apunte a la dirección anterior —el
     * link de la bio de Instagram, los QR ya impresos—, así que para el dueño
     * el campo se ve pero no se toca.
     */
    it('el usuario está de sólo lectura para el dueño', async () => {
      await render({ page: pagina({ url_slug: 'mi-pagina' }) });

      const campo = screen.getByLabelText('Usuario');
      expect(campo).toHaveValue('mi-pagina');
      expect(campo).toBeDisabled();
    });

    it('la plataforma sí puede editar el usuario', async () => {
      await render({ page: pagina({ url_slug: 'mi-pagina' }), esPlataforma: true });

      expect(screen.getByLabelText('Usuario')).toBeEnabled();
    });

    it('guarda el usuario nuevo al salir del campo', async () => {
      const { llamadas } = await render({
        page: pagina({ url_slug: 'mi-pagina' }),
        esPlataforma: true,
      });

      const campo = screen.getByLabelText('Usuario');
      fireEvent.change(campo, { target: { value: 'otro-usuario' } });
      fireEvent.blur(campo);

      await waitFor(() => {
        const put = llamadas.find((l) => l.options.method === 'PUT');
        expect(cuerpoDe(put)).toEqual({ url_slug: 'otro-usuario' });
      });
    });

    /** Guardar al salir del campo no puede mandar un cambio que no hubo. */
    it('no guarda si el usuario no cambió', async () => {
      const { llamadas } = await render({
        page: pagina({ url_slug: 'mi-pagina' }),
        esPlataforma: true,
      });

      fireEvent.blur(screen.getByLabelText('Usuario'));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'PUT')).toBeUndefined();
      });
    });

    /**
     * Si el servidor rechaza el usuario, dejarlo en pantalla haría creer que la
     * dirección cambió cuando sigue siendo la de antes.
     */
    it('vuelve al usuario guardado si el servidor lo rechaza', async () => {
      await render({ page: pagina({ url_slug: 'mi-pagina' }), esPlataforma: true });

      // La página ya cargó: de acá en más el servidor rechaza el guardado.
      global.fetch = vi.fn(() =>
        Promise.resolve({
          ok: false,
          status: 400,
          json: () => Promise.resolve({ error: 'Ese usuario ya está tomado por otra página' }),
        })
      );

      const campo = screen.getByLabelText('Usuario');
      fireEvent.change(campo, { target: { value: 'ocupado' } });
      fireEvent.blur(campo);

      expect(await screen.findByText('Ese usuario ya está tomado por otra página')).toBeInTheDocument();
      expect(screen.getByLabelText('Usuario')).toHaveValue('mi-pagina');
    });

    it('enlaza a la página pública', async () => {
      await render({ page: pagina({ url_slug: 'mi-pagina' }) });

      const enlaces = screen.getAllByRole('link').filter(
        (a) => a.getAttribute('href') === '/mi-pagina'
      );
      expect(enlaces.length).toBeGreaterThan(0);
    });
  });
});
