import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import ItemEditor from '../../src/pages/ItemEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

/**
 * Edición de un item en pantalla completa: campos según el tipo de grupo,
 * imagen, dirección de Google Maps y colaboradores.
 *
 * Antes esto era un modal dentro de PageEditor; los tests venían de
 * PageEditor.modales.test.jsx y PageEditor.flujos.test.jsx.
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
  url_slug: 'mi-pagina',
  groups: [],
  ...overrides,
});

/** Página con un solo grupo y un solo item, que es el que se edita. */
const conItem = (overrides = {}, tipo = 'links', idGrupo = 10) =>
  pagina({ groups: [grupo({ id: idGrupo, type: tipo, links: [link({ id: 100, ...overrides })] })] });

function mockearApi({ page = conItem(), results = [] } = {}) {
  return mockFetch({
    'pages/detail.php': { page },
    'links/detail.php': { link: link() },
    'collaborations/index.php': { collaboration_id: 40 },
    'collaborations/detail.php': { message: 'ok' },
    'public/search.php': { results },
    'upload/image.php': { url: 'https://img/subida.png' },
    'entradas/evento.php': { entradas: null, cobros: { configurado: false }, ocupadas: 0, comision: 0 },
  });
}

async function render(datos = {}, linkId = 100) {
  const mock = mockearApi(datos);
  const resultado = renderConProviders(<ItemEditor />, {
    auth: autenticado(),
    route: `/page/5/item/${linkId}`,
    path: '/page/:id/item/:linkId',
    rutasExtra: [editorDePrueba],
  });
  await screen.findByRole('button', { name: 'GUARDAR' });
  return { ...resultado, ...mock };
}

function archivo({ nombre = 'foto.jpg', tipo = 'image/jpeg', bytes = 1024 } = {}) {
  const f = new File(['x'], nombre, { type: tipo });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

/** Sustituto del editor de páginas, para ver a dónde vuelve el item. */
const editorDePrueba = { path: '/page/:id', element: <p>Editor de la página</p> };

const put = (llamadas, fragmento) =>
  llamadas.find((l) => l.url.includes(fragmento) && l.options.method === 'PUT');

describe('ItemEditor', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
  });

  // ========================================================== carga

  describe('carga del item', () => {
    it('muestra los datos del item y de dónde viene', async () => {
      await render({ page: conItem({ text: 'Instagram' }) });

      expect(screen.getByDisplayValue('Instagram')).toBeInTheDocument();
      expect(screen.getByRole('heading', { name: 'EDITAR LINK' })).toBeInTheDocument();
      expect(screen.getByText(/Mi Página · Mis Links/)).toBeInTheDocument();
    });

    it('el título dice qué se está editando según el tipo de grupo', async () => {
      await render({ page: conItem({ event_latitude: '-34.6', event_longitude: '-58.4' }, 'eventos') });

      expect(screen.getByRole('heading', { name: 'EDITAR EVENTO' })).toBeInTheDocument();
    });

    it('ofrece volver al editor si el item no existe', async () => {
      mockearApi({ page: conItem() });
      renderConProviders(<ItemEditor />, {
        auth: autenticado(),
        route: '/page/5/item/999',
        path: '/page/:id/item/:linkId',
        rutasExtra: [editorDePrueba],
      });

      const volver = await screen.findByRole('link', { name: 'VOLVER AL EDITOR' });
      expect(volver).toHaveAttribute('href', '/page/5?s=contenido');
    });

    it('el link de volver apunta a la solapa de contenido', async () => {
      await render();

      expect(screen.getByRole('link', { name: '← Volver al editor' }))
        .toHaveAttribute('href', '/page/5?s=contenido');
    });
  });

  // ========================================================= guardar

  describe('guardar', () => {
    it('guarda los cambios del item', async () => {
      const { llamadas } = await render({ page: conItem({ text: 'Instagram' }) });

      fireEvent.change(screen.getByDisplayValue('Instagram'), {
        target: { value: 'Instagram editado' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR' }));

      await waitFor(() => {
        const editado = put(llamadas, 'links/detail.php');
        expect(editado.url).toContain('id=100');
        expect(cuerpoDe(editado)).toMatchObject({ text: 'Instagram editado' });
      });
      // Guardar devuelve al editor, sin dejar la pantalla del item abierta.
      expect(await screen.findByText('Editor de la página')).toBeInTheDocument();
    });

    it('sube una imagen nueva y la guarda', async () => {
      const { llamadas } = await render();

      fireEvent.change(document.querySelector('input[type="file"]'), {
        target: { files: [archivo()] },
      });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'upload/image.php')).not.toBeNull();
      });

      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'links/detail.php')))
          .toMatchObject({ image_url: 'https://img/subida.png' });
      });
    });

    /**
     * El link de un evento y su texto viven en la solapa ENTRADAS: en un evento
     * son cómo se consiguen las entradas, y esa decisión está entera ahí.
     */
    it('permite editar el link y su texto desde ENTRADAS', async () => {
      const { llamadas } = await render({
        page: conItem(
          { url: 'https://x.com', event_latitude: '-34.6', event_longitude: '-58.4' },
          'eventos'
        ),
      });

      fireEvent.click(screen.getByRole('button', { name: 'ENTRADAS' }));

      fireEvent.change(await screen.findByLabelText('TEXTO DEL BOTÓN (OPCIONAL)'), {
        target: { value: 'Comprar' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'links/detail.php'))).toMatchObject({ url_text: 'Comprar' });
      });
    });

    /** El link del evento ya no está en DATOS: estaba en dos lugares a la vez. */
    it('DATOS ya no pide el link de un evento', async () => {
      await render({
        page: conItem(
          { url: 'https://x.com', event_latitude: '-34.6', event_longitude: '-58.4' },
          'eventos'
        ),
      });

      expect(screen.queryByPlaceholderText('Más información')).not.toBeInTheDocument();
    });

    it('un evento sin coordenadas no se puede guardar', async () => {
      const { llamadas } = await render({
        page: conItem({ text: 'Mi Evento', event_latitude: null, event_longitude: null }, 'eventos'),
      });

      fireEvent.submit(screen.getByRole('button', { name: 'GUARDAR' }).closest('form'));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'Debes seleccionar una dirección válida de Google Maps para el evento'
        );
      });
      expect(put(llamadas, 'links/detail.php')).toBeUndefined();
    });

    it('cancelar no guarda nada', async () => {
      const { llamadas } = await render({ page: conItem({ text: 'Instagram' }) });

      fireEvent.change(screen.getByDisplayValue('Instagram'), { target: { value: 'Otro' } });
      fireEvent.click(screen.getByRole('button', { name: 'CANCELAR' }));

      expect(await screen.findByText('Editor de la página')).toBeInTheDocument();
      expect(put(llamadas, 'links/detail.php')).toBeUndefined();
    });
  });

  // ==================================================== colaboradores

  describe('colaboradores de un evento', () => {
    const conEvento = (collaborations = []) =>
      conItem(
        { text: 'Mi Evento', event_latitude: '-34.6', event_longitude: '-58.4', collaborations },
        'eventos',
        20
      );

    it('lista los colaboradores con su estado', async () => {
      await render({
        page: conEvento([
          { id: 1, status: 'accepted', page_title: 'Aceptó Página', collaborator_page_id: 7 },
          { id: 2, status: 'pending', page_title: 'Pendiente Página', collaborator_page_id: 8 },
          { id: 3, status: 'rejected', page_title: 'Rechazó Página', collaborator_page_id: 9 },
        ]),
      });

      expect(screen.getByText('Aceptó')).toBeInTheDocument();
      expect(screen.getByText('Pendiente')).toBeInTheDocument();
      expect(screen.getByText('Rechazó')).toBeInTheDocument();
    });

    it('quita un colaborador', async () => {
      const { llamadas } = await render({
        page: conEvento([{ id: 1, status: 'accepted', page_title: 'Otra', collaborator_page_id: 7 }]),
      });

      fireEvent.click(screen.getByRole('button', { name: 'Quitar' }));

      await waitFor(() => {
        const del = llamadas.find(
          (l) => l.url.includes('collaborations/detail.php') && l.options.method === 'DELETE'
        );
        expect(del.url).toContain('id=1');
      });
      expect(screen.queryByText('Otra')).not.toBeInTheDocument();
    });

    it('busca páginas e invita a colaborar', async () => {
      const { llamadas } = await render({
        page: conEvento(),
        results: [{ id: 7, type: 'page', title: 'Otra Página', slug: 'otra' }],
      });

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'otra' },
      });

      fireEvent.click(await screen.findByRole('button', { name: /Otra Página/ }));

      await waitFor(() => {
        const post = llamadas.find(
          (l) => l.url.includes('collaborations/index.php') && l.options.method === 'POST'
        );
        expect(cuerpoDe(post)).toEqual({ link_id: 100, collaborator_page_id: 7 });
      });
    });

    it('no ofrece invitar a una página que ya es colaboradora', async () => {
      await render({
        page: conEvento([
          { id: 1, status: 'pending', page_title: 'Otra Página', collaborator_page_id: 7 },
        ]),
        results: [{ id: 7, type: 'page', title: 'Otra Página', slug: 'otra' }],
      });

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'otra' },
      });

      await waitFor(() => {
        // Sólo queda la fila del colaborador ya invitado, sin botón para sumarlo.
        expect(screen.queryByRole('button', { name: /^Otra Página/ })).not.toBeInTheDocument();
      });
    });

    it('no busca si el campo queda en blanco', async () => {
      const { llamadas } = await render({ page: conEvento() });

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: '   ' },
      });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php')).toBeNull();
      });
    });

    it('busca desde el primer caracter', async () => {
      const { llamadas } = await render({ page: conEvento() });

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'a' },
      });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'public/search.php')).not.toBeNull();
      });
    });

    it('descarta los resultados que no son páginas', async () => {
      await render({
        page: conEvento(),
        results: [
          { id: 7, type: 'page', title: 'Una Página', slug: 'una' },
          { id: 8, type: 'event', title: 'Un Evento', slug: 'una' },
        ],
      });

      fireEvent.change(screen.getByPlaceholderText('Buscar página para invitar...'), {
        target: { value: 'un' },
      });

      expect(await screen.findByRole('button', { name: /Una Página/ })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Un Evento/ })).not.toBeInTheDocument();
    });
  });

  // ======================================================== galería

  describe('galería: imagen, YouTube o Instagram', () => {
    const VIDEO = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
    const galeria = (overrides = {}) => conItem(overrides, 'galeria', 30);

    it('una imagen de toda la vida se edita como antes', async () => {
      await render({ page: galeria({ image_url: 'https://img/1.jpg' }) });

      expect(screen.getByLabelText('TIPO DE CONTENIDO')).toHaveValue('imagen');
      expect(screen.queryByLabelText('URL DEL VIDEO')).not.toBeInTheDocument();
    });

    // El tipo no se guarda: se deduce de la URL, así que al abrir el item el
    // formulario tiene que reconocerlo solo.
    it('un item con URL de YouTube se abre como video', async () => {
      await render({ page: galeria({ embed_url: VIDEO }) });

      expect(screen.getByLabelText('TIPO DE CONTENIDO')).toHaveValue('youtube');
      expect(screen.getByLabelText('URL DEL VIDEO')).toHaveValue(VIDEO);
    });

    it('un item con URL de Instagram se abre como Instagram', async () => {
      await render({ page: galeria({ embed_url: 'https://www.instagram.com/p/CxAbC123_-x/' }) });

      expect(screen.getByLabelText('TIPO DE CONTENIDO')).toHaveValue('instagram');
      expect(screen.getByLabelText('URL DEL CONTENIDO')).toBeInTheDocument();
    });

    it('convierte una imagen en un video', async () => {
      const { llamadas } = await render({ page: galeria({ image_url: 'https://img/1.jpg' }) });

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'youtube' } });
      fireEvent.change(screen.getByLabelText('URL DEL VIDEO'), { target: { value: VIDEO } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'links/detail.php'))).toMatchObject({ embed_url: VIDEO });
      });
    });

    // Si al volver a "Imagen" quedara la URL, el item seguiría siendo un video
    // aunque el formulario mostrara otra cosa.
    it('volver a imagen borra la URL del video', async () => {
      const { llamadas } = await render({ page: galeria({ embed_url: VIDEO }) });

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'imagen' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR' }));

      await waitFor(() => {
        expect(cuerpoDe(put(llamadas, 'links/detail.php'))).toMatchObject({ embed_url: '' });
      });
    });

    it('avisa si el link no es de YouTube', async () => {
      const { llamadas } = await render({ page: galeria({ image_url: 'https://img/1.jpg' }) });

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'youtube' } });
      fireEvent.change(screen.getByLabelText('URL DEL VIDEO'), {
        target: { value: 'https://vimeo.com/123456' },
      });
      fireEvent.submit(screen.getByRole('button', { name: 'GUARDAR' }).closest('form'));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Ese link no parece un video de YouTube');
      });
      expect(put(llamadas, 'links/detail.php')).toBeUndefined();
    });

    // Pegar un post de Instagram habiendo elegido YouTube es el error fácil de
    // cometer, y guardarlo dejaría el item mostrando lo que no es.
    it('avisa si el link es del otro servicio', async () => {
      const { llamadas } = await render({ page: galeria({ image_url: 'https://img/1.jpg' }) });

      fireEvent.change(screen.getByLabelText('TIPO DE CONTENIDO'), { target: { value: 'instagram' } });
      fireEvent.change(screen.getByLabelText('URL DEL CONTENIDO'), { target: { value: VIDEO } });
      fireEvent.submit(screen.getByRole('button', { name: 'GUARDAR' }).closest('form'));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'Ese link no parece un post, reel o carrusel de Instagram'
        );
      });
      expect(put(llamadas, 'links/detail.php')).toBeUndefined();
    });

    // Sin portada la grilla muestra el propio contenido de Instagram, así que
    // subirla es una preferencia, no un requisito.
    it('en un embed la portada es opcional', async () => {
      await render({ page: galeria({ embed_url: 'https://www.instagram.com/p/CxAbC123_-x/' }) });

      expect(screen.getByText('PORTADA (OPCIONAL)')).toBeInTheDocument();
      expect(document.querySelector('input[type="file"]')).not.toBeRequired();
    });
  });

  // =========================================================== tabs

  describe('solapas del evento', () => {
    it('un link no tiene solapas', async () => {
      await render();

      expect(screen.queryByRole('button', { name: 'ENTRADAS' })).not.toBeInTheDocument();
    });

    it('un evento ofrece datos, entradas y ventas', async () => {
      await render({
        page: conItem({ event_latitude: '-34.6', event_longitude: '-58.4' }, 'eventos'),
      });

      expect(screen.getByRole('button', { name: 'DATOS' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'ENTRADAS' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'VENTAS' })).toBeInTheDocument();
    });
  });
});
