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

      expect(within(modal).getByText(/Todavía no se muestran en tu página/)).toBeInTheDocument();

      fireEvent.click(within(modal).getByRole('button', { name: 'Crear' }));

      await waitFor(() => {
        expect(cuerpoDe(llamadaA(llamadas, 'groups/index.php'))).toMatchObject({ title: 'Tienda', type: 'productos' });
      });
    });

    it('el grupo se identifica como de productos digitales', async () => {
      await render();

      expect(screen.getByText('Productos digitales', { selector: 'span' })).toBeInTheDocument();
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
        group_id: 10, url: '', text: 'Guía', description: '', image_url: '', precio: '1500',
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

    it('gratis no avisa de Mercado Pago', async () => {
      await render();
      await abrirAlta();

      fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '0' } });

      expect(screen.queryByText(/Para cobrar tenés que conectar Mercado Pago/)).not.toBeInTheDocument();
    });
  });
});
