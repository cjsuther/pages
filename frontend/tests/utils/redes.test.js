import { describe, it, expect } from 'vitest';
import { REDES, buscarRed, normalizarUrl, valorVisible } from '../../src/utils/redes';

describe('redes', () => {
  describe('catálogo', () => {
    it('todas las entradas tienen clave, nombre y ejemplo', () => {
      REDES.forEach((red) => {
        expect(red.clave).toBeTruthy();
        expect(red.nombre).toBeTruthy();
        expect(red.ejemplo).toBeTruthy();
      });
    });

    it('no hay claves repetidas', () => {
      const claves = REDES.map((r) => r.clave);

      expect(new Set(claves).size).toBe(claves.length);
    });

    it('incluye las seis que tienen icono de marca propio', () => {
      const claves = REDES.map((r) => r.clave);

      ['instagram', 'tiktok', 'youtube', 'facebook', 'whatsapp', 'cafecito'].forEach((c) => {
        expect(claves).toContain(c);
      });
    });

    it('buscarRed encuentra por clave', () => {
      expect(buscarRed('instagram').nombre).toBe('Instagram');
      expect(buscarRed('inexistente')).toBeNull();
    });
  });

  describe('normalizarUrl', () => {
    it('devuelve vacío si no hay nada cargado', () => {
      expect(normalizarUrl('instagram', '')).toBe('');
      expect(normalizarUrl('instagram', '   ')).toBe('');
      expect(normalizarUrl('instagram', null)).toBe('');
    });

    /** La mitad de la gente pega la URL y la otra mitad escribe el usuario. */
    it('respeta una URL completa', () => {
      expect(normalizarUrl('instagram', 'https://instagram.com/mi-banda'))
        .toBe('https://instagram.com/mi-banda');
    });

    it('respeta http además de https', () => {
      expect(normalizarUrl('web', 'http://mi-sitio.com')).toBe('http://mi-sitio.com');
    });

    it('antepone la base cuando sólo viene el usuario', () => {
      expect(normalizarUrl('instagram', 'mi-banda')).toBe('https://instagram.com/mi-banda');
    });

    it('recorta espacios', () => {
      expect(normalizarUrl('instagram', '  mi-banda  ')).toBe('https://instagram.com/mi-banda');
    });

    it('no duplica la arroba cuando la base ya la trae', () => {
      expect(normalizarUrl('tiktok', '@mi-banda')).toBe('https://tiktok.com/@mi-banda');
      expect(normalizarUrl('tiktok', 'mi-banda')).toBe('https://tiktok.com/@mi-banda');
    });

    it('el email se convierte en mailto', () => {
      expect(normalizarUrl('email', 'hola@mi-banda.com')).toBe('mailto:hola@mi-banda.com');
    });

    it('un mailto ya escrito se respeta', () => {
      expect(normalizarUrl('email', 'mailto:hola@mi-banda.com')).toBe('mailto:hola@mi-banda.com');
    });

    /** La gente escribe +54 9 11 2233-4455 y wa.me sólo acepta dígitos. */
    it('el teléfono de WhatsApp queda sólo con dígitos', () => {
      expect(normalizarUrl('whatsapp', '+54 9 11 2233-4455')).toBe('https://wa.me/5491122334455');
    });

    it('una red desconocida se deja como vino', () => {
      expect(normalizarUrl('inexistente', 'algo')).toBe('algo');
    });
  });

  describe('valorVisible', () => {
    it('muestra sólo el usuario, sin la base', () => {
      expect(valorVisible('instagram', 'https://instagram.com/mi-banda')).toBe('mi-banda');
    });

    it('deja la URL entera si no empieza con la base', () => {
      expect(valorVisible('instagram', 'https://otra-cosa.com/x')).toBe('https://otra-cosa.com/x');
    });

    it('devuelve vacío si no hay URL', () => {
      expect(valorVisible('instagram', '')).toBe('');
      expect(valorVisible('instagram', null)).toBe('');
    });

    it('es la inversa de normalizarUrl para un usuario suelto', () => {
      const usuario = 'mi-banda';

      expect(valorVisible('instagram', normalizarUrl('instagram', usuario))).toBe(usuario);
      expect(valorVisible('tiktok', normalizarUrl('tiktok', usuario))).toBe(usuario);
    });
  });
});
