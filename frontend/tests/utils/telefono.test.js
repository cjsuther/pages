import { describe, it, expect } from 'vitest';
import { aWhatsApp, urlDeWhatsApp } from '../../src/utils/telefono';

/** Todos apuntan al mismo celular de Buenos Aires: 11 2233-4455. */
const ESPERADO = '5491122334455';

describe('telefono', () => {
  describe('formas en que la gente escribe el mismo número', () => {
    it.each([
      ['1122334455', 'sólo dígitos'],
      ['11 2233-4455', 'con espacio y guion'],
      ['11 2233 4455', 'con espacios'],
      ['(11) 2233-4455', 'con paréntesis'],
      ['011 2233 4455', 'con el 0 de larga distancia'],
      ['(011) 2233-4455', 'con 0 y paréntesis'],
      ['11.2233.4455', 'con puntos'],
      ['  11 2233 4455  ', 'con espacios de sobra'],
    ])('%s (%s)', (escrito) => {
      expect(aWhatsApp(escrito)).toBe(ESPERADO);
    });

    /** El 15 se escribe muchísimo y wa.me lo rechaza. */
    it.each([
      ['11 15 2233-4455', 'con el 15 de celular'],
      ['011 15 2233 4455', 'con 0 y 15'],
      ['1115 2233 4455', 'con el 15 pegado'],
    ])('%s (%s)', (escrito) => {
      expect(aWhatsApp(escrito)).toBe(ESPERADO);
    });

    it.each([
      ['+54 9 11 2233 4455', 'internacional completo'],
      ['+5491122334455', 'internacional sin espacios'],
      ['5491122334455', 'internacional sin el más'],
      ['+54 11 2233 4455', 'internacional al que le falta el 9'],
      ['0054 9 11 2233 4455', 'con el 00 de salida'],
    ])('%s (%s)', (escrito) => {
      expect(aWhatsApp(escrito)).toBe(ESPERADO);
    });
  });

  describe('características de distinto largo', () => {
    it('Córdoba, característica de 3', () => {
      expect(aWhatsApp('0351 15 234 5678')).toBe('5493512345678');
    });

    it('una característica de 4', () => {
      expect(aWhatsApp('02262 15 34 5678')).toBe('5492262345678');
    });
  });

  describe('números de otros países', () => {
    /**
     * Sin esto, un número de otro país se convertiría en uno argentino que no
     * existe, y el dueño llamaría a un desconocido.
     */
    it('con + al principio se respeta el país', () => {
      expect(aWhatsApp('+1 415 555 0132')).toBe('14155550132');
      expect(aWhatsApp('+34 612 345 678')).toBe('34612345678');
    });

    it('con 00 también', () => {
      expect(aWhatsApp('0034 612 345 678')).toBe('34612345678');
    });
  });

  describe('cuando no se puede armar', () => {
    it('devuelve null en vez de inventar un número', () => {
      expect(aWhatsApp('')).toBeNull();
      expect(aWhatsApp(null)).toBeNull();
      expect(aWhatsApp(undefined)).toBeNull();
      expect(aWhatsApp('no es un teléfono')).toBeNull();
      expect(aWhatsApp('1234')).toBeNull();
    });

    /** Mejor no ofrecer el link que mandar a un número equivocado. */
    it('un número incompleto no se completa a la fuerza', () => {
      expect(aWhatsApp('2233 4455')).toBeNull();
    });

    it('un número absurdamente largo tampoco', () => {
      expect(aWhatsApp('11 2233 4455 6677 8899')).toBeNull();
    });
  });

  describe('urlDeWhatsApp', () => {
    it('arma la URL de wa.me', () => {
      expect(urlDeWhatsApp('11 2233-4455')).toBe(`https://wa.me/${ESPERADO}`);
    });

    it('devuelve null si el teléfono no sirve', () => {
      expect(urlDeWhatsApp('asd')).toBeNull();
    });
  });
});
