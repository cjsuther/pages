import { describe, it, expect } from 'vitest';
import { esEmailValido, sugerenciaDeEmail } from '../../src/utils/email';

describe('email', () => {
  describe('esEmailValido', () => {
    it('acepta los emails normales', () => {
      ['ana@example.com', 'ana.gomez@example.com.ar', 'ana+entradas@example.com',
       'ana_gomez@sub.example.com', 'a@bc.io'].forEach((e) => {
        expect(esEmailValido(e), e).toBe(true);
      });
    });

    /**
     * El type="email" del navegador acepta todos estos: el estándar no exige
     * punto ni extensión. Por eso no alcanza con dejárselo al navegador.
     */
    it('rechaza lo que el navegador deja pasar', () => {
      ['asd@asd', 'ana@localhost', 'a@b'].forEach((e) => {
        expect(esEmailValido(e), e).toBe(false);
      });
    });

    it('rechaza lo que no tiene forma de email', () => {
      ['asd', 'asd@', '@example.com', 'ana@@example.com', 'ana example@x.com',
       'ana@.com', 'ana@example.', ''].forEach((e) => {
        expect(esEmailValido(e), e).toBe(false);
      });
    });

    it('rechaza una extensión de una sola letra', () => {
      expect(esEmailValido('ana@example.c')).toBe(false);
    });

    it('no rompe con nulo ni indefinido', () => {
      expect(esEmailValido(null)).toBe(false);
      expect(esEmailValido(undefined)).toBe(false);
    });

    it('ignora los espacios de los costados', () => {
      expect(esEmailValido('  ana@example.com  ')).toBe(true);
    });

    it('rechaza algo absurdamente largo', () => {
      expect(esEmailValido('a'.repeat(300) + '@example.com')).toBe(false);
    });
  });

  describe('sugerenciaDeEmail', () => {
    /** gmail.co pasa cualquier validación y no llega nunca. */
    it('sugiere la corrección de los dominios que se escriben mal seguido', () => {
      expect(sugerenciaDeEmail('ana@gmail.co')).toBe('ana@gmail.com');
      expect(sugerenciaDeEmail('ana@hotmial.com')).toBe('ana@hotmail.com');
      expect(sugerenciaDeEmail('ana@gmail.con')).toBe('ana@gmail.com');
    });

    it('no sugiere nada si el dominio está bien', () => {
      expect(sugerenciaDeEmail('ana@gmail.com')).toBeNull();
      expect(sugerenciaDeEmail('ana@mi-empresa.com.ar')).toBeNull();
    });

    it('no sugiere nada sobre algo que no es un email', () => {
      expect(sugerenciaDeEmail('asd')).toBeNull();
      expect(sugerenciaDeEmail('@gmail.co')).toBeNull();
      expect(sugerenciaDeEmail('')).toBeNull();
      expect(sugerenciaDeEmail(null)).toBeNull();
    });

    it('conserva la parte de la izquierda tal como está', () => {
      expect(sugerenciaDeEmail('ana.gomez+x@gmail.co')).toBe('ana.gomez+x@gmail.com');
    });
  });
});
