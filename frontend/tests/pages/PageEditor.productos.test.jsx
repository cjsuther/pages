import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import PageEditor from '../../src/pages/PageEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA, respuesta } from '../helpers/api';

/**
 * Productos digitales en el editor de la página (#17): el tipo de grupo, el
 * alta de un producto con su archivo y cómo se listan.
 */

const grupoDeProductos = (links = []) => ({
  id: 10,
  title: 'Tienda',
  type: 'productos',
  position: 0,
  links,
  collaborated_events: [],
});

const pagina = (groups = []) => ({
  id: 5,
  user_id: 9,
  title: 'Mi Página',
  description: '',
  url_slug: 'mi-pagina',
  primary_color: '#3B82F6',
  background_color: '#FFFFFF',
  text_color: '#000000',
  template: 'minimal',
  groups,
});

function mockearEditor({ page = pagina([grupoDeProductos()]), cobros = { configurado: false }, subida = null } = {}) {
  return mockFetch({
    'pages/detail.php': { page },
    'admins/index.php': { admins: [] },
    'collaborations/index.php': { pending: [], collaborations: [] },
    'groups/index.php': { group: grupoDeProductos() },
    'links/index.php': { status: 201, body: { link: { id: 77, text: 'Guía' } } },
    'links/detail.php': { link: { id: 2 } },
    'productos/archivo.php': subida || { link: { id: 77, archivo_nombre: 'guia.pdf' } },
    'entradas/credenciales.php': { cobros },
    'upload/image.php': { url: 'https://img/tapa.png' },
    'notifications/index.php': { notifications: [], unread_count: 0 },
  });
}

async function render(datos = {}) {
  const mock = mockearEditor(datos);
  renderConProviders(<PageEditor />, {
    auth: crearAuth({ token: 'tok-123', user: usuarioDePrueba() }),
    route: '/page/5?s=contenido',
    path: '/page/:id',
  });
  await screen.findByRole('heading', { name: 'Grupos de links' });
  return mock;
}

function archivo({ nombre = 'guia.pdf', bytes = 3 * 1024 * 1024 } = {}) {
  const f = new File(['x'], nombre, { type: 'application/pdf' });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

async function abrirAlta() {
  fireEvent.click(screen.getByRole('button', { name: '+ Producto' }));
  await screen.findByRole('heading', { name: 'Nuevo producto' });
}

const campoArchivo = () => document.getElementById('archivo-producto');

describe('PageEditor — productos digitales', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.confirm = vi.fn(() => true);
    window.alert = vi.fn();
    window.gtag = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
  });

  describe('grupo', () => {
    it('se puede elegir "Productos digitales" al crear un grupo', async () => {
      const { llamadas } = await render({ page: pagina() });

      fireEvent.click(screen.getByRole('button', { name: '+ NUEVO GRUPO' }));
      const modal = screen.getByRole('heading', { name: 'Nuevo grupo' }).parentElement;
      fireEvent.change(within(modal).getAllByRole('textbox')[0], { target: { value: 'Tienda' } });
      fireEvent.change(within(modal).getByRole('combobox'), { target: { value: 'productos' } });

      expect(within(modal).getByText(/Archivos para vender o regalar desde tu página/)).toBeInTheDocument();

      fireEvent.click(within(modal).getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        expect(cuerpoDe(llamadaA(llamadas, 'groups/index.php'))).toMatchObject({ title: 'Tienda', type: 'productos' });
      });
    });

    it('el grupo se identifica como de productos', async () => {
      await render();

      expect(screen.getByText('Productos', { selector: 'span' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: '+ Producto' })).toBeInTheDocument();
    });

    it('cada producto dice su precio y su archivo', async () => {
      await render({
        page: pagina([grupoDeProductos([
          { id: 1, text: 'Guía', precio: '2500.00', archivo_nombre: 'guia.pdf', position: 0 },
          { id: 2, text: 'Plantilla', precio: '0.00', archivo_nombre: null, position: 1 },
        ])]),
      });

      expect(screen.getByText(/\$ 2\.500/)).toHaveTextContent('guia.pdf');
      expect(screen.getByText(/Gratis/)).toHaveTextContent('Sin archivo');
    });

    it('un producto inactivo se distingue en la lista', async () => {
      await render({
        page: pagina([grupoDeProductos([
          { id: 1, text: 'Guía', precio: '2500.00', archivo_nombre: 'guia.pdf', oculto: '1', position: 0 },
          { id: 2, text: 'Mapa', precio: '0.00', archivo_nombre: 'mapa.pdf', oculto: '0', position: 1 },
        ])]),
      });

      expect(screen.getAllByText(/Inactivo: no se muestra en tu página/)).toHaveLength(1);
      expect(screen.getByRole('switch', { name: 'Guía: activo' })).not.toBeChecked();
      expect(screen.getByRole('switch', { name: 'Mapa: activo' })).toBeChecked();
    });

    // #42: se activa o desactiva desde la lista, sin entrar al producto.
    it('el interruptor de la lista desactiva el producto sin entrar', async () => {
      const { llamadas } = await render({
        page: pagina([grupoDeProductos([
          { id: 2, text: 'Mapa', precio: '0.00', archivo_nombre: 'mapa.pdf', oculto: '0', position: 0 },
        ])]),
      });

      fireEvent.click(screen.getByRole('switch', { name: 'Mapa: activo' }));

      expect(screen.getByRole('switch', { name: 'Mapa: activo' })).not.toBeChecked();
      await waitFor(() => {
        const put = llamadas.find((l) => l.url.includes('links/detail.php?id=2') && l.options.method === 'PUT');
        expect(cuerpoDe(put)).toEqual({ oculto: true });
      });
      expect(screen.getByText(/Inactivo: no se muestra en tu página/)).toBeInTheDocument();
    });

    it('el interruptor de la lista activa un producto inactivo', async () => {
      const { llamadas } = await render({
        page: pagina([grupoDeProductos([
          { id: 2, text: 'Remera', tipo: 'fisico', precio: '9000.00', costo_envio: '0.00', oculto: '1', position: 0 },
        ])]),
      });

      fireEvent.click(screen.getByRole('switch', { name: 'Remera: activo' }));

      await waitFor(() => {
        const put = llamadas.find((l) => l.url.includes('links/detail.php?id=2') && l.options.method === 'PUT');
        expect(cuerpoDe(put)).toEqual({ oculto: false });
      });
      expect(screen.getByRole('switch', { name: 'Remera: activo' })).toBeChecked();
    });

    it('si la API no acepta el cambio, el interruptor vuelve atrás', async () => {
      mockFetch({
        'pages/detail.php': { page: pagina([grupoDeProductos([
          { id: 2, text: 'Mapa', precio: '0.00', archivo_nombre: 'mapa.pdf', oculto: '0', position: 0 },
        ])]) },
        'admins/index.php': { admins: [] },
        'collaborations/index.php': { pending: [], collaborations: [] },
        'notifications/index.php': { notifications: [], unread_count: 0 },
        'links/detail.php': { status: 500, body: { error: 'falló' } },
      });
      renderConProviders(<PageEditor />, {
        auth: crearAuth({ token: 'tok-123', user: usuarioDePrueba() }),
        route: '/page/5?s=contenido',
        path: '/page/:id',
      });
      await screen.findByRole('heading', { name: 'Grupos de links' });

      fireEvent.click(screen.getByRole('switch', { name: 'Mapa: activo' }));

      await waitFor(() => expect(window.alert).toHaveBeenCalledWith('No se pudo desactivar el producto'));
      expect(screen.getByRole('switch', { name: 'Mapa: activo' })).toBeChecked();
    });

    it('un físico dice que es físico y su envío, y si está agotado', async () => {
      await render({
        page: pagina([grupoDeProductos([
          { id: 1, text: 'Remera', tipo: 'fisico', precio: '9000.00', costo_envio: '0.00', agotado: false, position: 0 },
          { id: 2, text: 'Buzo', tipo: 'fisico', precio: '20000.00', costo_envio: '1500.00', agotado: true, position: 1 },
          { id: 3, text: 'Guía', tipo: 'digital', precio: '0.00', archivo_nombre: 'guia.pdf', position: 2 },
        ])]),
      });

      expect(screen.getByText(/\$ 9\.000/)).toHaveTextContent('Físico · $ 9.000 · Envío incluido');
      expect(screen.getByText(/\$ 20\.000/)).toHaveTextContent('Físico · $ 20.000 · Agotado');
      expect(screen.getByText(/guia\.pdf/)).toHaveTextContent('Digital · Gratis · guia.pdf');
      expect(screen.queryByText('Sin archivo')).not.toBeInTheDocument();
    });
  });

  describe('ventas de la página', () => {
    it('la sección muestra las ventas de todos los productos, también los borrados', async () => {
      const mock = mockFetch({
        'pages/detail.php': { page: pagina([grupoDeProductos()]) },
        'admins/index.php': { admins: [] },
        'collaborations/index.php': { pending: [], collaborations: [] },
        'notifications/index.php': { notifications: [], unread_count: 0 },
        'productos/ventas.php': {
          ordenes: [{
            id: 1, codigo: 'AAAABBBBCCCC', nombre: 'Ana', email: 'ana@test.local', telefono: null,
            total: 2500, moneda: 'ARS', estado: 'pagada', creado_en: '2026-10-01 10:00:00',
            descargas: 0, primera_descarga_en: null, producto: 'Pack viejo', producto_borrado: true, link_id: null,
          }],
          resumen: { pagadas: 1, recaudado: 2500, descargaron: 0 },
        },
      });
      renderConProviders(<PageEditor />, {
        auth: crearAuth({ token: 'tok-123', user: usuarioDePrueba() }),
        route: '/page/5?s=productos',
        path: '/page/:id',
      });

      expect(await screen.findByText('Pack viejo')).toBeInTheDocument();
      expect(screen.getByText('Producto borrado')).toBeInTheDocument();
      expect(llamadaA(mock.llamadas, 'productos/ventas.php').url).toContain('page_id=5');
    });
  });

  describe('alta de un producto', () => {
    it('pide título, descripción, imagen, precio y archivo, y no URL', async () => {
      await render();
      await abrirAlta();

      expect(screen.getByLabelText('Título')).toBeRequired();
      expect(screen.getByText('Descripción (opcional)')).toBeInTheDocument();
      expect(screen.getByText('IMAGEN (OPCIONAL)')).toBeInTheDocument();
      expect(screen.getByLabelText('Precio')).toBeInTheDocument();
      expect(screen.getByText(/0 = gratis/)).toBeInTheDocument();
      expect(screen.getByLabelText('Archivo')).toBeInTheDocument();
      expect(screen.getByText(/Un solo archivo de hasta 50 MB\. Si tu producto tiene varios archivos, comprimilos en un ZIP y subí el ZIP\./)).toBeInTheDocument();
      expect(screen.queryByText('URL')).not.toBeInTheDocument();
    });

    it('crea el producto y después le sube el archivo', async () => {
      const { llamadas } = await render();
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Guía' } });
      fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '1500' } });
      fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });

      // El elegido se muestra antes de crear.
      expect(screen.getByText('guia.pdf')).toBeInTheDocument();
      expect(screen.getByText('3 MB')).toBeInTheDocument();

      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        expect(llamadaA(llamadas, 'productos/archivo.php?link_id=77')).not.toBeNull();
      });
      expect(cuerpoDe(llamadaA(llamadas, 'links/index.php'))).toEqual({
        group_id: 10, url: '', text: 'Guía', description: '', image_url: '', precio: '1500', tipo: 'digital',
      });
      expect(llamadaA(llamadas, 'productos/archivo.php').options.body.get('archivo').name).toBe('guia.pdf');
      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Nuevo producto' })).not.toBeInTheDocument();
      });
    });

    it('sin archivo crea el producto igual', async () => {
      const { llamadas } = await render();
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Guía' } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Nuevo producto' })).not.toBeInTheDocument();
      });
      expect(llamadaA(llamadas, 'links/index.php')).not.toBeNull();
      expect(llamadaA(llamadas, 'productos/archivo.php')).toBeNull();
    });

    it('mientras sube el archivo lo dice y no deja cancelar ni volver a crear', async () => {
      let terminar;
      await render();
      const anterior = global.fetch;
      global.fetch = vi.fn((url, opciones) => (
        String(url).includes('productos/archivo.php')
          ? new Promise((resolve) => { terminar = () => resolve(respuesta(200, { link: { id: 77 } })); })
          : anterior(url, opciones)
      ));
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Guía' } });
      fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      expect(await screen.findByRole('status')).toHaveTextContent(/Subiendo el archivo/);
      expect(screen.getByRole('button', { name: 'Crear' })).toBeDisabled();
      expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled();

      terminar();

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Nuevo producto' })).not.toBeInTheDocument();
      });
    });

    it('un archivo de más de 50 MB se rechaza al elegirlo, diciendo el máximo', async () => {
      await render();
      await abrirAlta();

      fireEvent.change(campoArchivo(), { target: { files: [archivo({ bytes: 51 * 1024 * 1024 })] } });

      expect(window.alert).toHaveBeenCalledWith(expect.stringMatching(/el máximo es 50 MB/));
      expect(screen.queryByText('guia.pdf')).not.toBeInTheDocument();
    });

    it('si la subida falla, avisa que el producto quedó creado sin el archivo', async () => {
      await render({ subida: { status: 413, body: { error: 'El archivo pesa más de 50 MB, que es el máximo.' } } });
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Guía' } });
      fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(expect.stringMatching(/se creó, pero el archivo no se pudo subir: El archivo pesa más de 50 MB/));
      });
    });

    it('con precio y sin Mercado Pago conectado, avisa', async () => {
      await render();
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '1500' } });

      expect(await screen.findByText(/Para cobrar tenés que conectar Mercado Pago/)).toBeInTheDocument();
    });

    // #42: digital o físico se elige al crearlo.
    it('por defecto es digital, y al elegir físico pide el envío en lugar del archivo', async () => {
      await render();
      await abrirAlta();

      expect(screen.getByRole('radio', { name: /Digital/ })).toBeChecked();
      expect(screen.getByText('No se puede cambiar después de crearlo.')).toBeInTheDocument();

      fireEvent.click(screen.getByRole('radio', { name: /Físico/ }));

      expect(screen.queryByLabelText('Archivo')).not.toBeInTheDocument();
      expect(screen.getByLabelText('Costo de envío')).toBeInTheDocument();
      expect(screen.getByLabelText('Zona de envío (opcional)')).toBeInTheDocument();
    });

    it('crea un físico con su envío y sin subir archivo', async () => {
      const { llamadas } = await render();
      await abrirAlta();

      fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });
      fireEvent.click(screen.getByRole('radio', { name: /Físico/ }));
      fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Remera' } });
      fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '9000' } });
      fireEvent.change(screen.getByLabelText('Costo de envío'), { target: { value: '0' } });
      fireEvent.change(screen.getByLabelText('Zona de envío (opcional)'), { target: { value: 'Sólo CABA y GBA' } });
      fireEvent.click(screen.getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Nuevo producto' })).not.toBeInTheDocument();
      });
      expect(cuerpoDe(llamadaA(llamadas, 'links/index.php'))).toMatchObject({
        text: 'Remera', precio: '9000', tipo: 'fisico', costo_envio: '0', zona_envio: 'Sólo CABA y GBA',
      });
      expect(llamadaA(llamadas, 'productos/archivo.php')).toBeNull();
    });

    it('gratis no avisa de Mercado Pago', async () => {
      await render();
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '0' } });

      expect(screen.queryByText(/Para cobrar tenés que conectar Mercado Pago/)).not.toBeInTheDocument();
    });
  });
});
