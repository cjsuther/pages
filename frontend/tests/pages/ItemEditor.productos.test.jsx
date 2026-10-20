import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import ItemEditor from '../../src/pages/ItemEditor';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, respuesta } from '../helpers/api';

/**
 * Edición de un producto digital (#17): título, descripción, imagen, precio
 * y archivo. El archivo se sube apenas se elige, porque el producto ya existe.
 */

const producto = (overrides = {}) => ({
  id: 100,
  url: '',
  text: 'Guía de viaje',
  description: 'Todo lo que hay que saber',
  image_url: null,
  position: 0,
  precio: '0.00',
  archivo_nombre: null,
  archivo_bytes: null,
  archivo_tipo: null,
  ...overrides,
});

const pagina = (item = producto()) => ({
  id: 5,
  user_id: 9,
  title: 'Mi Página',
  url_slug: 'mi-pagina',
  groups: [{ id: 10, title: 'Tienda', type: 'productos', position: 0, links: [item] }],
});

function mockearApi({ item = producto(), cobros = { configurado: false }, subida = null } = {}) {
  return mockFetch({
    'pages/detail.php': { page: pagina(item) },
    'links/detail.php': { link: item },
    'productos/archivo.php': subida || { link: { ...item, archivo_nombre: 'nuevo.pdf', archivo_bytes: 2097152 } },
    'entradas/credenciales.php': { cobros },
  });
}

async function render(datos = {}) {
  const mock = mockearApi(datos);
  renderConProviders(<ItemEditor />, {
    auth: crearAuth({ token: 'tok-123', user: usuarioDePrueba() }),
    route: '/page/5/item/100',
    path: '/page/:id/item/:linkId',
    rutasExtra: [{ path: '/page/:id', element: <p>Editor de la página</p> }],
  });
  await screen.findByRole('button', { name: 'Guardar' });
  return mock;
}

function archivo({ nombre = 'nuevo.pdf', bytes = 2097152 } = {}) {
  const f = new File(['x'], nombre, { type: 'application/pdf' });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

const campoArchivo = () => document.getElementById('archivo-producto');

describe('ItemEditor — productos digitales', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.alert = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
  });

  it('muestra título, descripción, imagen, precio y archivo, y no pide URL', async () => {
    await render();

    expect(screen.getByRole('heading', { name: 'Editar producto' })).toBeInTheDocument();
    expect(screen.getByLabelText('Título')).toHaveValue('Guía de viaje');
    expect(screen.getByDisplayValue('Todo lo que hay que saber')).toBeInTheDocument();
    expect(screen.getByText('IMAGEN (OPCIONAL)')).toBeInTheDocument();
    expect(screen.getByLabelText('Precio')).toBeInTheDocument();
    expect(screen.getByText(/0 = gratis/)).toBeInTheDocument();
    expect(screen.getByLabelText('Archivo')).toBeInTheDocument();
    expect(screen.queryByText('URL')).not.toBeInTheDocument();
  });

  it('explica que es un solo archivo de hasta 50 MB y que varios van en un ZIP', async () => {
    await render();

    expect(screen.getByText(
      'Un solo archivo de hasta 50 MB. Si tu producto tiene varios archivos, comprimilos en un ZIP y subí el ZIP.'
    )).toBeInTheDocument();
  });

  it('muestra el nombre y el tamaño del archivo cargado, y ofrece reemplazarlo', async () => {
    await render({ item: producto({ archivo_nombre: 'guia.pdf', archivo_bytes: 1572864 }) });

    expect(screen.getByText('guia.pdf')).toBeInTheDocument();
    expect(screen.getByText('1,5 MB')).toBeInTheDocument();
    expect(screen.getByLabelText('Reemplazar archivo')).toBeInTheDocument();
  });

  it('al elegir un archivo lo sube y muestra el nuevo', async () => {
    const { llamadas } = await render({ item: producto({ archivo_nombre: 'viejo.pdf', archivo_bytes: 1024 }) });

    fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });

    expect(await screen.findByText('nuevo.pdf')).toBeInTheDocument();
    expect(screen.getByText('2 MB')).toBeInTheDocument();
    expect(screen.queryByText('viejo.pdf')).not.toBeInTheDocument();
    const subida = llamadas.find((l) => l.url.includes('productos/archivo.php?link_id=100'));
    expect(subida.options.body.get('archivo').name).toBe('nuevo.pdf');
  });

  it('mientras sube lo dice y no deja guardar', async () => {
    let terminar;
    await render();
    const anterior = global.fetch;
    global.fetch = vi.fn((url, opciones) => (
      String(url).includes('productos/archivo.php')
        ? new Promise((resolve) => { terminar = () => resolve(respuesta(200, { link: producto({ archivo_nombre: 'nuevo.pdf', archivo_bytes: 10 }) })); })
        : anterior(url, opciones)
    ));

    fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });

    expect(await screen.findByRole('status')).toHaveTextContent(/Subiendo el archivo/);
    expect(screen.getByRole('button', { name: 'Guardar' })).toBeDisabled();
    expect(campoArchivo()).toBeDisabled();

    terminar();

    expect(await screen.findByText('nuevo.pdf')).toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Guardar' })).not.toBeDisabled();
  });

  it('un archivo de más de 50 MB se rechaza sin subirlo, diciendo el máximo', async () => {
    const { llamadas } = await render();

    fireEvent.change(campoArchivo(), { target: { files: [archivo({ bytes: 60 * 1024 * 1024 })] } });

    expect(await screen.findByText(/el máximo es 50 MB/)).toBeInTheDocument();
    expect(llamadas.some((l) => l.url.includes('productos/archivo.php'))).toBe(false);
  });

  it('si la API rechaza el archivo, muestra su mensaje', async () => {
    await render({
      subida: { status: 413, body: { error: 'El archivo pesa más de 50 MB, que es el máximo.' } },
    });

    fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });

    expect(await screen.findByText('El archivo pesa más de 50 MB, que es el máximo.')).toBeInTheDocument();
  });

  it('guarda el título, la descripción y el precio', async () => {
    const { llamadas } = await render();

    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Guía 2026' } });
    fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '2500' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(await screen.findByText('Editor de la página')).toBeInTheDocument();
    const cuerpo = cuerpoDe(llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'));
    expect(cuerpo.text).toBe('Guía 2026');
    expect(cuerpo.precio).toBe('2500');
  });

  describe('oculto', () => {
    it('explica qué hace ocultarlo', async () => {
      await render();

      expect(screen.getByLabelText(/^Oculto/)).not.toBeChecked();
      expect(screen.getByText(
        'Oculto: no se muestra y no acepta pedidos nuevos. Quienes ya compraron pueden seguir descargando.'
      )).toBeInTheDocument();
    });

    it('un producto oculto llega marcado', async () => {
      await render({ item: producto({ oculto: '1' }) });

      expect(screen.getByLabelText(/^Oculto/)).toBeChecked();
    });

    it('al guardar manda si está oculto', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByLabelText(/^Oculto/));
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      expect(await screen.findByText('Editor de la página')).toBeInTheDocument();
      const cuerpo = cuerpoDe(llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'));
      expect(cuerpo.oculto).toBe(true);
    });
  });

  describe('aviso de Mercado Pago', () => {
    it('con precio y sin Mercado Pago conectado, avisa', async () => {
      await render({ item: producto({ precio: '1500.00' }) });

      expect(await screen.findByText(/Para cobrar tenés que conectar Mercado Pago/)).toBeInTheDocument();
    });

    it('gratis no avisa ni pregunta', async () => {
      const { llamadas } = await render({ item: producto({ precio: '0.00' }) });

      expect(screen.queryByText(/Para cobrar tenés que conectar Mercado Pago/)).not.toBeInTheDocument();
      expect(llamadas.some((l) => l.url.includes('entradas/credenciales.php'))).toBe(false);
    });

    it('con Mercado Pago conectado no avisa', async () => {
      const { llamadas } = await render({ item: producto({ precio: '1500.00' }), cobros: { configurado: true } });

      await waitFor(() => {
        expect(llamadas.some((l) => l.url.includes('entradas/credenciales.php?page_id=5'))).toBe(true);
      });
      expect(screen.queryByText(/Para cobrar tenés que conectar Mercado Pago/)).not.toBeInTheDocument();
    });

    it('avisa apenas el precio pasa de 0', async () => {
      await render();

      fireEvent.change(screen.getByLabelText('Precio'), { target: { value: '100' } });

      expect(await screen.findByText(/Para cobrar tenés que conectar Mercado Pago/)).toBeInTheDocument();
    });
  });

  // #19: como un evento, un producto tiene sus ventas en otra pestaña.
  describe('pestañas', () => {
    const ventas = {
      ordenes: [{
        id: 1, codigo: 'AAAABBBBCCCC', nombre: 'Ana', email: 'ana@test.local', telefono: null,
        total: 2500, moneda: 'ARS', estado: 'pagada', creado_en: '2026-10-01 10:00:00',
        descargas: 1, primera_descarga_en: '2026-10-02 09:00:00', producto: 'Guía de viaje',
        producto_borrado: false, link_id: 100,
      }],
      resumen: { pagadas: 1, recaudado: 2500, descargaron: 1 },
    };

    it('tiene Datos y Ventas, y empieza en Datos', async () => {
      await render();

      const nav = screen.getByRole('navigation', { name: 'Secciones del producto' });
      expect(nav).toHaveTextContent('Datos');
      expect(nav).toHaveTextContent('Ventas');
      expect(nav).not.toHaveTextContent('Entradas');
      expect(screen.getByLabelText('Título')).toBeInTheDocument();
    });

    it('Ventas muestra las ventas del producto con la columna Descargó', async () => {
      const mock = mockFetch({
        'pages/detail.php': { page: pagina() },
        'entradas/credenciales.php': { cobros: { configurado: false } },
        'productos/ventas.php': ventas,
      });
      renderConProviders(<ItemEditor />, {
        auth: crearAuth({ token: 'tok-123', user: usuarioDePrueba() }),
        route: '/page/5/item/100',
        path: '/page/:id/item/:linkId',
      });
      await screen.findByRole('button', { name: 'Guardar' });

      fireEvent.click(screen.getByRole('button', { name: 'Ventas' }));

      expect(await screen.findByText('Sí · 1 vez')).toBeInTheDocument();
      expect(screen.getByRole('columnheader', { name: 'Descargó' })).toBeInTheDocument();
      expect(screen.queryByLabelText('Título')).not.toBeInTheDocument();
      expect(mock.llamadas.find((l) => l.url.includes('productos/ventas.php')).url).toContain('link_id=100');
    });
  });
});
