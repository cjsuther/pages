import { describe, it, expect } from 'vitest';
import { formatearPorcentaje } from '../../src/utils/comisiones';

describe('formatearPorcentaje', () => {
  // La comisión sale de la configuración del servidor y llega como número:
  // sin esto, un 1,5% se mostraba "1.5%".
  it('usa la coma decimal', () => {
    expect(formatearPorcentaje(1.5)).toBe('1,5');
    expect(formatearPorcentaje(4.39)).toBe('4,39');
  });

  it('un entero no arrastra decimales de más', () => {
    expect(formatearPorcentaje(3)).toBe('3');
    expect(formatearPorcentaje(10)).toBe('10');
  });

  it('funciona con el número en texto, como llega del JSON', () => {
    expect(formatearPorcentaje('1.5')).toBe('1,5');
  });
});
