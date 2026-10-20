import { describe, it, expect, afterEach, vi } from 'vitest';
import {
  MAX_BYTES_PRODUCTO,
  comprarProducto,
  describirPrecio,
  esPago,
  excesoArchivoProducto,
  guardarTokenDeDescarga,
  subirArchivoDeProducto,
  tokenDeDescarga,
  tokenDelHash,
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
    it('deja afuera los productos y cualquier tipo que no se sepa dibujar', () => {
      const grupos = [
        { id: 1, type: 'links' },
        { id: 2, type: 'productos' },
        { id: 3, type: 'galeria' },
        { id: 4, type: 'eventos' },
        { id: 5, type: 'algo-nuevo' },
      ];

      expect(gruposPublicos(grupos).map((g) => g.id)).toEqual([1, 3, 4]);
    });

    it('un grupo sin tipo es de links, como en la base', () => {
      expect(gruposPublicos([{ id: 1 }])).toHaveLength(1);
      expect(gruposPublicos(undefined)).toEqual([]);
    });
  });
});
