import { describe, it, expect, afterEach } from 'vitest';
import {
  MAX_BYTES_PRODUCTO,
  describirPrecio,
  esPago,
  excesoArchivoProducto,
  subirArchivoDeProducto,
} from '../../src/utils/productos';
import { gruposPublicos } from '../../src/utils/plantillas';
import { mockFetch, llamadaA, respuesta } from '../helpers/api';

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
