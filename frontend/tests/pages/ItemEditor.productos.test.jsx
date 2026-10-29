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

  // #42: el checkbox "Oculto" pasó a ser un interruptor Activo / Inactivo.
  describe('activo / inactivo', () => {
    it('explica qué hace desactivarlo', async () => {
      await render();

      expect(screen.getByRole('switch', { name: 'Activo' })).toBeChecked();
      expect(screen.getByText(
        'Inactivo: no se muestra en tu página ni en su link y no acepta pedidos nuevos. Quienes ya compraron pueden seguir descargando.'
      )).toBeInTheDocument();
    });

    it('un producto oculto llega inactivo', async () => {
      await render({ item: producto({ oculto: '1' }) });

      expect(screen.getByRole('switch', { name: 'Activo' })).not.toBeChecked();
      expect(screen.getByRole('switch', { name: 'Activo' })).toHaveTextContent('Inactivo');
    });

    it('al guardar manda si está oculto', async () => {
      const { llamadas } = await render();

      fireEvent.click(screen.getByRole('switch', { name: 'Activo' }));
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      expect(await screen.findByText('Editor de la página')).toBeInTheDocument();
      const cuerpo = cuerpoDe(llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'));
      expect(cuerpo.oculto).toBe(true);
    });
  });

  describe('link para compartir', () => {
    const conArchivo = (extra = {}) => producto({ archivo_nombre: 'guia.pdf', archivo_bytes: 1024, ...extra });

    afterEach(() => {
      delete navigator.clipboard;
    });

    it('muestra el link público del producto', async () => {
      await render({ item: conArchivo() });

      expect(screen.getByLabelText('Link para compartir')).toHaveValue(`${window.location.origin}/producto/100`);
      expect(screen.queryByText(/no está disponible/)).not.toBeInTheDocument();
    });

    it('Copiar link lo copia y avisa', async () => {
      const writeText = vi.fn(() => Promise.resolve());
      Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
      await render({ item: conArchivo() });

      fireEvent.click(screen.getByRole('button', { name: 'Copiar link' }));

      expect(await screen.findByRole('button', { name: 'Link copiado' })).toBeInTheDocument();
      expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/producto/100`);
    });

    it('si no se puede copiar, pide copiarlo a mano', async () => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true, value: { writeText: vi.fn(() => Promise.reject(new Error('no'))) },
      });
      await render({ item: conArchivo() });

      fireEvent.click(screen.getByRole('button', { name: 'Copiar link' }));

      expect(await screen.findByRole('alert')).toHaveTextContent('copialo a mano');
    });

    it('si está inactivo, avisa que el link todavía no muestra el producto', async () => {
      await render({ item: conArchivo({ oculto: '1' }) });

      expect(screen.getByText(/está inactivo: hasta que lo actives, el link dice que no está disponible/)).toBeInTheDocument();
    });

    it('sin archivo, avisa que el link todavía no muestra el producto', async () => {
      await render();

      expect(screen.getByText(/todavía no tiene archivo: hasta que lo subas, el link dice que no está disponible/)).toBeInTheDocument();
    });

    it('el aviso se va al subir el archivo', async () => {
      await render();

      fireEvent.change(campoArchivo(), { target: { files: [archivo()] } });

      await waitFor(() => expect(screen.queryByText(/no está disponible/)).not.toBeInTheDocument());
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

  // #42: un físico no tiene archivo; tiene envío, características y stock.
  describe('producto físico', () => {
    const fisico = (overrides = {}) => producto({
      tipo: 'fisico', precio: '9000.00', costo_envio: '0.00', zona_envio: null, stock: null,
      caracteristicas: [], variantes: [], agotado: false, ...overrides,
    });

    const conTalle = () => fisico({
      caracteristicas: [{ id: 70, nombre: 'Talle', opciones: [{ id: 100, nombre: 'S' }, { id: 101, nombre: 'M' }] }],
      variantes: [{ clave: '101', stock: 0 }],
    });

    const guardar = async (llamadas) => {
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));
      expect(await screen.findByText('Editor de la página')).toBeInTheDocument();
      return cuerpoDe(llamadas.find((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT'));
    };

    it('no pide archivo y pide envío, zona y stock', async () => {
      await render({ item: fisico() });

      expect(screen.queryByLabelText('Archivo')).not.toBeInTheDocument();
      expect(screen.getByText(/Producto físico\./).closest('p')).toHaveTextContent('El tipo no se cambia después de crearlo');
      expect(screen.getByLabelText('Costo de envío')).toHaveValue(0);
      expect(screen.getByText('En pesos. 0 = envío incluido.')).toBeInTheDocument();
      expect(screen.getByLabelText('Zona de envío (opcional)')).toBeInTheDocument();
      const stock = screen.getByLabelText('Stock (opcional)');
      expect(stock).toHaveAttribute('placeholder', 'Sin límite');
      // Entero y >= 0: el navegador no deja mandar otra cosa.
      expect(stock).toHaveAttribute('min', '0');
      expect(stock).toHaveAttribute('step', '1');
      expect(screen.queryByText(/todavía no tiene archivo/)).not.toBeInTheDocument();
    });

    it('sin características guarda el stock único, el envío y la zona', async () => {
      const { llamadas } = await render({ item: fisico() });

      fireEvent.change(screen.getByLabelText('Costo de envío'), { target: { value: '1500' } });
      fireEvent.change(screen.getByLabelText('Zona de envío (opcional)'), { target: { value: 'Sólo CABA y GBA' } });
      fireEvent.change(screen.getByLabelText('Stock (opcional)'), { target: { value: '12' } });

      const cuerpo = await guardar(llamadas);
      expect(cuerpo).toMatchObject({
        tipo: 'fisico', costo_envio: '1500', zona_envio: 'Sólo CABA y GBA', stock: '12', caracteristicas: [], variantes: [],
      });
      expect(cuerpo).not.toHaveProperty('agotado');
    });

    it('agrega una característica con sus opciones y arma la grilla de stock', async () => {
      const { llamadas } = await render({ item: fisico() });

      fireEvent.click(screen.getByRole('button', { name: 'Agregar característica' }));
      fireEvent.change(screen.getByLabelText('Nombre de la característica 1'), { target: { value: 'Talle' } });
      const nueva = screen.getByLabelText('Nueva opción de Talle');
      fireEvent.change(nueva, { target: { value: 'S' } });
      fireEvent.keyDown(nueva, { key: 'Enter' });
      fireEvent.change(nueva, { target: { value: 'M' } });
      fireEvent.click(screen.getByRole('button', { name: 'Agregar opción' }));

      // Con características, el stock va por combinación.
      expect(screen.queryByLabelText('Stock (opcional)')).not.toBeInTheDocument();
      expect(screen.getByText('Stock por combinación')).toBeInTheDocument();
      fireEvent.change(screen.getByLabelText('Stock de M'), { target: { value: '0' } });
      expect(screen.getByLabelText('Stock de S')).toHaveValue(null);
      expect(screen.getByText('Agotado')).toBeInTheDocument();

      const cuerpo = await guardar(llamadas);
      expect(cuerpo.caracteristicas).toEqual([{ nombre: 'Talle', opciones: [{ nombre: 'S' }, { nombre: 'M' }] }]);
      expect(cuerpo.variantes).toEqual([{ opciones: ['M'], stock: '0' }]);
      expect(cuerpo.stock).toBe('');
    });

    it('sugiere Talle, Color y Otra como nombres', async () => {
      await render({ item: fisico() });

      fireEvent.click(screen.getByRole('button', { name: 'Agregar característica' }));

      const sugerencias = [...document.querySelectorAll('#sugerencias-de-caracteristica option')].map((o) => o.value);
      expect(sugerencias).toEqual(['Talle', 'Color', 'Otra']);
      expect(screen.getByLabelText('Nombre de la característica 1')).toHaveAttribute('list', 'sugerencias-de-caracteristica');
    });

    it('carga las guardadas con su stock, y renombrar conserva el id y el stock', async () => {
      const { llamadas } = await render({ item: conTalle() });

      expect(screen.getByLabelText('Nombre de la característica 1')).toHaveValue('Talle');
      expect(screen.getByLabelText('Stock de M')).toHaveValue(0);

      fireEvent.change(screen.getByLabelText('Opción 2 de Talle'), { target: { value: 'Mediano' } });
      expect(screen.getByLabelText('Stock de Mediano')).toHaveValue(0);

      const cuerpo = await guardar(llamadas);
      expect(cuerpo.caracteristicas).toEqual([
        { id: 70, nombre: 'Talle', opciones: [{ id: 100, nombre: 'S' }, { id: 101, nombre: 'Mediano' }] },
      ]);
      expect(cuerpo.variantes).toEqual([{ opciones: ['Mediano'], stock: '0' }]);
    });

    it('reordena y quita opciones y características', async () => {
      const { llamadas } = await render({ item: conTalle() });

      fireEvent.click(screen.getByRole('button', { name: 'Mover M antes' }));
      expect(screen.getByLabelText('Opción 1 de Talle')).toHaveValue('M');

      fireEvent.click(screen.getByRole('button', { name: 'Quitar S' }));
      expect(screen.queryByDisplayValue('S')).not.toBeInTheDocument();

      fireEvent.click(screen.getByRole('button', { name: 'Agregar característica' }));
      fireEvent.change(screen.getByLabelText('Nombre de la característica 2'), { target: { value: 'Color' } });
      fireEvent.change(screen.getByLabelText('Nueva opción de Color'), { target: { value: 'Negro' } });
      fireEvent.click(screen.getAllByRole('button', { name: 'Agregar opción' })[1]);
      fireEvent.click(screen.getByRole('button', { name: 'Subir Color' }));
      expect(screen.getByLabelText('Nombre de la característica 1')).toHaveValue('Color');

      // Con otra característica las combinaciones son otras: su stock empieza vacío.
      fireEvent.change(screen.getByLabelText('Stock de Negro / M'), { target: { value: '4' } });

      // Reordenar no pierde el stock cargado.
      fireEvent.click(screen.getByRole('button', { name: 'Bajar Color' }));
      expect(screen.getByLabelText('Stock de M / Negro')).toHaveValue(4);

      fireEvent.click(screen.getByRole('button', { name: 'Quitar Talle' }));
      expect(screen.queryByDisplayValue('Talle')).not.toBeInTheDocument();

      const cuerpo = await guardar(llamadas);
      expect(cuerpo.caracteristicas).toEqual([{ nombre: 'Color', opciones: [{ nombre: 'Negro' }] }]);
      expect(cuerpo.variantes).toEqual([]);
    });

    it('no deja agregar más de 3 características', async () => {
      await render({ item: fisico() });

      for (let i = 0; i < 3; i += 1) {
        fireEvent.click(screen.getByRole('button', { name: 'Agregar característica' }));
      }

      expect(screen.queryByRole('button', { name: 'Agregar característica' })).not.toBeInTheDocument();
    });

    it.each([
      ['una característica sin nombre', () => {
        fireEvent.click(screen.getByRole('button', { name: 'Agregar característica' }));
      }, 'Cada característica necesita un nombre'],
      ['una característica sin opciones', () => {
        fireEvent.click(screen.getByRole('button', { name: 'Agregar característica' }));
        fireEvent.change(screen.getByLabelText('Nombre de la característica 1'), { target: { value: 'Color' } });
      }, '"Color" necesita al menos una opción'],
    ])('con %s avisa y no guarda', async (_, preparar, mensaje) => {
      const { llamadas } = await render({ item: fisico() });

      preparar();
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent(mensaje);
      expect(llamadas.some((l) => l.url.includes('links/detail.php') && l.options.method === 'PUT')).toBe(false);
    });

    it('una opción repetida avisa y no guarda', async () => {
      const { llamadas } = await render({ item: conTalle() });

      fireEvent.change(screen.getByLabelText('Opción 2 de Talle'), { target: { value: 's' } });
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent('La opción "s" está repetida en "Talle"');
      expect(llamadas.some((l) => l.options.method === 'PUT')).toBe(false);
    });

    it('el interruptor Activo explica que los pedidos existentes siguen', async () => {
      await render({ item: fisico({ oculto: '1' }) });

      expect(screen.getByRole('switch', { name: 'Activo' })).not.toBeChecked();
      expect(screen.getByText(/Los pedidos que ya existen siguen su curso/)).toBeInTheDocument();
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
