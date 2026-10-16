import { describe, it, expect } from 'vitest';
import { describirPeso, excesoImagenEvento, MAX_BYTES_EVENTO } from '../../src/utils/imagenes';

const archivoDe = (size) => ({ size });

describe('describirPeso', () => {
  it.each([
    [614401, '601 KB'],
    [900 * 1024, '900 KB'],
    [2202009, '2,1 MB'],
    [3 * 1024 * 1024, '3 MB'],
  ])('%i bytes son %s', (bytes, esperado) => {
    expect(describirPeso(bytes)).toBe(esperado);
  });
});

describe('excesoImagenEvento', () => {
  it('deja pasar una imagen de exactamente 600 KB', () => {
    expect(excesoImagenEvento(archivoDe(MAX_BYTES_EVENTO))).toBeNull();
  });

  it('frena una de un byte más, diciendo cuánto pesa', () => {
    expect(excesoImagenEvento(archivoDe(MAX_BYTES_EVENTO + 1)))
      .toBe('La imagen pesa 601 KB; para eventos el máximo es 600 KB');
  });
});
