import { describe, it, expect } from 'vitest';
import { urlDeInstagram, urlDeRezonar, fechaLarga, fechaCorta, cumplimiento } from '../../src/utils/carcajada';

describe('carcajada', () => {
  it('arma el link de Instagram y el de Rezonar', () => {
    expect(urlDeInstagram('anagomez')).toBe('https://instagram.com/anagomez');
    expect(urlDeInstagram(null)).toBeNull();
    expect(urlDeRezonar('anagomez')).toBe('https://rezon.ar/anagomez');
    expect(urlDeRezonar(null)).toBeNull();
  });

  it('escribe las fechas como se anuncian', () => {
    expect(fechaLarga('2026-10-02', '21:00:00')).toBe('viernes, 2 de octubre · 21:00');
    expect(fechaLarga('2026-10-02')).toBe('viernes, 2 de octubre');
    expect(fechaCorta('2026-10-02')).toBe('2 oct');
    expect(fechaLarga(null)).toBe('');
  });

  describe('cumplimiento', () => {
    /** La pregunta al armar una fecha no es cuánta gente trae, sino si cumple. */
    it('compara lo que trajo contra lo que prometió', () => {
      expect(cumplimiento({ comprometidas: 10, promedio_traidas: 8 })).toBe(80);
      expect(cumplimiento({ comprometidas: 10, promedio_traidas: 13 })).toBe(130);
    });

    it('sin shows medidos no opina', () => {
      expect(cumplimiento({ comprometidas: 10, promedio_traidas: null })).toBeNull();
      expect(cumplimiento({ comprometidas: 0, promedio_traidas: 4 })).toBeNull();
      expect(cumplimiento(null)).toBeNull();
    });
  });
});
