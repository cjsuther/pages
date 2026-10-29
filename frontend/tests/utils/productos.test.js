import { describe, it, expect, afterEach, vi } from 'vitest';
import {
  MAX_BYTES_PRODUCTO,
  esOculto,
  comprarProducto,
  describirPrecio,
  esPago,
  excesoArchivoProducto,
  guardarTokenDeDescarga,
  mensajeDeErrorDeCompra,
  subirArchivoDeProducto,
  tokenDeDescarga,
  tokenDelHash,
  linkDeProducto,
  compartirLink,
  PROVINCIAS,
  codigoPostalValido,
  cuerpoDelPedido,
  describirClaves,
  entregaVacia,
  FRANJAS,
  problemaDelPedido,
  totalDelPedido,
} from '../../src/utils/productos';
import { gruposPublicos } from '../../src/utils/plantillas';
import { mockFetch, llamadaA, respuesta, cuerpoDe } from '../helpers/api';

const archivo = (bytes, nombre = 'guia.pdf') => {
  const f = new File(['x'], nombre, { type: 'application/pdf' });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
};

describe('productos digitales', () => {
  afterEach(() => {
    delete global.fetch;
  });

  describe('tope de 50 MB', () => {
    it('un archivo de justo 50 MB entra', () => {
      expect(excesoArchivoProducto(archivo(MAX_BYTES_PRODUCTO))).toBeNull();
    });

    it('uno más grande se rechaza diciendo cuánto pesa y el máximo', () => {
      expect(excesoArchivoProducto(archivo(80 * 1024 * 1024))).toMatch(/pesa 80 MB; el máximo es 50 MB/);
    });
  });

  describe('precio', () => {
    it('vacío o 0 es gratis', () => {
      expect(esPago('')).toBe(false);
      expect(esPago('0')).toBe(false);
      expect(esPago(undefined)).toBe(false);
      expect(describirPrecio('0.00')).toBe('Gratis');
    });

    it('con precio se cobra y se lee en pesos', () => {
      expect(esPago('1500')).toBe(true);
      expect(describirPrecio('2500.00')).toBe('$ 2.500');
    });
  });

  describe('subida del archivo', () => {
    const subir = (file = archivo(1024)) =>
      subirArchivoDeProducto({ apiUrl: 'http://api', token: 'tok', linkId: 7, file });

    it('manda el archivo al producto con la sesión', async () => {
      const { llamadas } = mockFetch({
        'productos/archivo.php': { link: { id: 7, archivo_nombre: 'guia.pdf' } },
      });

      const link = await subir();

      const llamada = llamadaA(llamadas, 'productos/archivo.php?link_id=7');
      expect(llamada.options.method).toBe('POST');
      expect(llamada.options.headers.Authorization).toBe('Bearer tok');
      expect(llamada.options.body.get('archivo').name).toBe('guia.pdf');
      expect(link.archivo_nombre).toBe('guia.pdf');
    });

    it('un archivo de más de 50 MB ni se manda', async () => {
      const { llamadas } = mockFetch({});

      await expect(subir(archivo(MAX_BYTES_PRODUCTO + 1))).rejects.toThrow(/50 MB/);
      expect(llamadas).toHaveLength(0);
    });

    it('muestra el error que contesta la API', async () => {
      mockFetch({ 'productos/archivo.php': { status: 400, body: { error: 'El archivo está vacío' } } });

      await expect(subir()).rejects.toThrow('El archivo está vacío');
    });

    // El servidor web puede cortar el POST antes de que llegue a PHP, con una
    // página de error que no es JSON: igual hay que decir el máximo.
    it('un 413 sin JSON igual dice el máximo', async () => {
      global.fetch = () => Promise.resolve({ ...respuesta(413, null), json: () => Promise.reject(new Error('no es JSON')) });

      await expect(subir()).rejects.toThrow(/50 MB/);
    });
  });

  describe('compra', () => {
    const TOKEN = 'ab'.repeat(32);

    afterEach(() => {
      window.localStorage.clear();
      vi.restoreAllMocks();
    });

    it('guarda el token antes de devolver a dónde ir a pagar', async () => {
      const { llamadas } = mockFetch({
        'public/comprar-producto.php': {
          status: 201,
          body: { codigo: 'AAAABBBBCCCC', estado: 'pendiente', url: 'https://mp.test/pagar', token: TOKEN },
        },
      });

      const compra = await comprarProducto({ apiUrl: 'http://api', linkId: 7, nombre: 'Ana', email: 'ana@test.local' });

      expect(compra.url).toBe('https://mp.test/pagar');
      expect(tokenDeDescarga('AAAABBBBCCCC')).toBe(TOKEN);
      const llamada = llamadaA(llamadas, 'public/comprar-producto.php');
      expect(llamada.url).not.toContain(TOKEN);
      expect(cuerpoDe(llamada)).toEqual({ link_id: 7, nombre: 'Ana', email: 'ana@test.local' });
    });

    it('un producto gratis no guarda nada: el link llega por mail', async () => {
      mockFetch({ 'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', estado: 'pagada', url: null } } });

      await comprarProducto({ apiUrl: 'http://api', linkId: 7, nombre: 'Ana', email: 'ana@test.local' });

      expect(tokenDeDescarga('AAAABBBBCCCC')).toBeNull();
    });

    it('muestra el error que contesta la API', async () => {
      mockFetch({ 'public/comprar-producto.php': { status: 400, body: { error: 'El email no es válido' } } });

      await expect(comprarProducto({ apiUrl: 'http://api', linkId: 7 })).rejects.toThrow('El email no es válido');
    });

    it('un producto que se ocultó o se borró dice que ya no está disponible', async () => {
      mockFetch({ 'public/comprar-producto.php': { status: 409, body: { error: 'otra cosa' } } });

      await expect(comprarProducto({ apiUrl: 'http://api', linkId: 7 }))
        .rejects.toMatchObject({ status: 409, message: 'Este producto ya no está disponible.' });
    });

    it('demasiados pedidos y datos inválidos tienen su mensaje aunque la API no lo mande', () => {
      expect(mensajeDeErrorDeCompra(429)).toMatch(/demasiados pedidos/);
      expect(mensajeDeErrorDeCompra(400)).toMatch(/Revisá los datos/);
      expect(mensajeDeErrorDeCompra(500)).toBe('No pudimos iniciar la compra');
    });

    // En modo privado de Safari, localStorage tira al escribir.
    it('si no se puede guardar, la compra sigue igual', async () => {
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('QuotaExceededError'); });
      mockFetch({ 'public/comprar-producto.php': { status: 201, body: { codigo: 'C', url: 'https://mp.test', token: TOKEN } } });

      await expect(comprarProducto({ apiUrl: 'http://api', linkId: 7 })).resolves.toMatchObject({ url: 'https://mp.test' });
    });

    it('si no se puede leer, no hay token', () => {
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('SecurityError'); });

      expect(tokenDeDescarga('C')).toBeNull();
    });

    it('un valor guardado que no tiene forma de token no se usa', () => {
      guardarTokenDeDescarga('C', 'cualquier cosa');

      expect(tokenDeDescarga('C')).toBeNull();
    });

    it('el token del link sale de lo que va después del #', () => {
      expect(tokenDelHash(`#${TOKEN}`)).toBe(TOKEN);
      expect(tokenDelHash('')).toBeNull();
      expect(tokenDelHash('#corto')).toBeNull();
    });
  });

  describe('grupos públicos', () => {
    it('incluye los productos y deja afuera cualquier tipo que no se sepa dibujar', () => {
      const grupos = [
        { id: 1, type: 'links' },
        { id: 2, type: 'productos' },
        { id: 3, type: 'galeria' },
        { id: 4, type: 'eventos' },
        { id: 5, type: 'algo-nuevo' },
      ];

      expect(gruposPublicos(grupos).map((g) => g.id)).toEqual([1, 2, 3, 4]);
    });

    it('un grupo sin tipo es de links, como en la base', () => {
      expect(gruposPublicos([{ id: 1 }])).toHaveLength(1);
      expect(gruposPublicos(undefined)).toEqual([]);
    });
  });
});

describe('link de un producto', () => {
  afterEach(() => {
    delete navigator.share;
    delete navigator.clipboard;
  });

  it('es /producto/<id> en el dominio en el que se está, como los eventos', () => {
    expect(linkDeProducto(400)).toBe(`${window.location.origin}/producto/400`);
    expect(linkDeProducto(400, 'https://maxipeque.com')).toBe('https://maxipeque.com/producto/400');
  });

  it('con navigator.share comparte y no copia', async () => {
    const share = vi.fn(() => Promise.resolve());
    const writeText = vi.fn();
    Object.defineProperty(navigator, 'share', { configurable: true, value: share });
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

    expect(await compartirLink({ url: 'https://rezon.ar/producto/1', titulo: 'Guía' })).toBe('compartido');
    expect(share).toHaveBeenCalledWith({ title: 'Guía', url: 'https://rezon.ar/producto/1' });
    expect(writeText).not.toHaveBeenCalled();
  });

  it('si se cancela el menú no copia', async () => {
    const cancelado = Object.assign(new Error('x'), { name: 'AbortError' });
    const writeText = vi.fn();
    Object.defineProperty(navigator, 'share', { configurable: true, value: vi.fn(() => Promise.reject(cancelado)) });
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

    expect(await compartirLink({ url: 'u', titulo: 't' })).toBe('cancelado');
    expect(writeText).not.toHaveBeenCalled();
  });

  it('si navigator.share falla por otra cosa, copia', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.defineProperty(navigator, 'share', { configurable: true, value: vi.fn(() => Promise.reject(new Error('NotAllowed'))) });
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

    expect(await compartirLink({ url: 'u', titulo: 't' })).toBe('copiado');
    expect(writeText).toHaveBeenCalledWith('u');
  });

  it('sin navigator.share copia', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

    expect(await compartirLink({ url: 'u', titulo: 't' })).toBe('copiado');
  });
});

describe('esOculto', () => {
  it('acepta lo que devuelve la API y lo que guarda el editor', () => {
    expect([1, '1', true, 'true'].map(esOculto)).toEqual([true, true, true, true]);
    expect([0, '0', false, null, undefined, ''].map(esOculto)).toEqual([false, false, false, false, false, false]);
  });
});

describe('pedido de un físico (#43)', () => {
  const producto = {
    caracteristicas: [
      { id: 10, nombre: 'Talle', opciones: [{ id: 1, nombre: 'S' }] },
      { id: 20, nombre: 'Color', opciones: [{ id: 3, nombre: 'Negro' }] },
    ],
  };
  const entrega = (overrides = {}) => ({
    ...entregaVacia(),
    calle: 'Av. Corrientes 1234', localidad: 'CABA', provincia: 'Córdoba', codigo_postal: '5000',
    dias: ['lunes'], franjas: ['tarde'],
    ...overrides,
  });
  const problema = (overrides = {}) => problemaDelPedido({
    producto, seleccion: { 10: 1, 20: 3 }, telefono: '11 5555-1234', entrega: entrega(), ...overrides,
  });

  it('el total es precio × cantidad + envío, sin errores de redondeo', () => {
    expect(totalDelPedido(2500, 2, 500)).toEqual({ subtotal: 5000, envio: 500, total: 5500 });
    expect(totalDelPedido('10.10', 3, '0.20')).toEqual({ subtotal: 30.3, envio: 0.2, total: 30.5 });
    expect(totalDelPedido(0, 3, 0).total).toBe(0);
  });

  it('las 24 provincias', () => {
    expect(PROVINCIAS).toHaveLength(24);
    expect(PROVINCIAS).toContain('Ciudad Autónoma de Buenos Aires');
  });

  it('el código postal: 4 números o el CPA', () => {
    expect(['1414', 'C1414BAD', 'c 1414 bad'].map(codigoPostalValido)).toEqual([true, true, true]);
    expect(['141', '14145', 'C1414BA', ''].map(codigoPostalValido)).toEqual([false, false, false, false]);
  });

  it('un pedido completo no tiene problemas', () => {
    expect(problema()).toBeNull();
  });

  it('cada cosa que falta tiene su mensaje', () => {
    expect(problema({ seleccion: { 10: 1 } })).toBe('Elegí color');
    expect(problema({ telefono: '' })).toMatch(/Falta el teléfono/);
    expect(problema({ telefono: '12' })).toBe('El teléfono no es válido');
    expect(problema({ entrega: entrega({ calle: '' }) })).toBe('Falta la calle y el número');
    expect(problema({ entrega: entrega({ provincia: 'Narnia' }) })).toBe('Elegí la provincia');
    expect(problema({ entrega: entrega({ codigo_postal: 'x' }) })).toMatch(/no es válido/);
    expect(problema({ entrega: entrega({ dias: [] }) })).toMatch(/al menos un día/);
    expect(problema({ entrega: entrega({ franjas: [] }) })).toMatch(/al menos una franja/);
  });

  it('el cuerpo lleva las opciones en el orden de las características', () => {
    const cuerpo = cuerpoDelPedido({ producto, seleccion: { 20: 3, 10: 1 }, cantidad: '2', entrega: entrega({ calle: ' Av. 1 ' }) });

    expect(cuerpo.cantidad).toBe(2);
    expect(cuerpo.opciones).toEqual([1, 3]);
    expect(cuerpo.envio.calle).toBe('Av. 1');
    expect(cuerpo.horarios).toEqual({ dias: ['lunes'], franjas: ['tarde'], comentario: '' });
  });

  it('los días y las franjas se leen', () => {
    expect(describirClaves(['lunes', 'miercoles', 'domingo'])).toBe('Lunes, Miércoles y Domingo');
    expect(describirClaves(['noche'], FRANJAS)).toBe('Noche');
  });

  it('un 409 de un físico dice lo que manda la API; el de un digital, que ya no está', () => {
    expect(mensajeDeErrorDeCompra(409, 'No queda stock suficiente', true)).toBe('No queda stock suficiente');
    expect(mensajeDeErrorDeCompra(409, 'No queda stock suficiente')).toBe('Este producto ya no está disponible.');
    expect(mensajeDeErrorDeCompra(409, undefined, true)).toBe('Este producto ya no está disponible.');
  });

  it('comprarProducto suma el pedido al cuerpo', async () => {
    const { llamadas } = mockFetch({ 'public/comprar-producto.php': { status: 201, body: { codigo: 'X', url: 'u' } } });

    await comprarProducto({ apiUrl: 'http://api', linkId: 7, nombre: 'Ana', email: 'a@b.c', telefono: '1', pedido: { cantidad: 2 } });

    expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php'))).toEqual({
      link_id: 7, nombre: 'Ana', email: 'a@b.c', telefono: '1', cantidad: 2,
    });
  });
});
