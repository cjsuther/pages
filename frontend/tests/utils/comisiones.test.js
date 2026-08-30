import { describe, it, expect } from 'vitest';
import { MP_PORCENTAJE, MP_DIAS_ACREDITACION, formatearPorcentaje } from '../../src/utils/comisiones';

/**
 * Los dos datos de Mercado Pago van juntos —el porcentaje depende del plazo—
 * y viven en un solo lugar para que no queden distintos en cada pantalla.
 */
describe('datos de Mercado Pago', () => {
  it('son los de la cuenta de Rezonar', () => {
    expect(MP_PORCENTAJE).toBe(4.39);
    expect(MP_DIAS_ACREDITACION).toBe(10);
  });
});

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
