import { describe, it, expect } from 'vitest';
import { localidadDe } from '../../src/utils/eventos';

/**
 * Las direcciones llegan armadas por Google y no tienen una forma fija: vienen
 * con tres, cuatro o cinco partes según incluyan el nombre del lugar, el barrio
 * o ninguno de los dos. Lo único que ocupa siempre el mismo lugar es la parte
 * anterior al país.
 */
describe('localidadDe', () => {
  it.each([
    [
      'con barrio',
      'Humboldt 1574, Palermo, Ciudad Autónoma de Buenos Aires, Argentina',
      'Ciudad Autónoma de Buenos Aires',
    ],
    [
      'con nombre del lugar y barrio',
      'Teatro Colón, Cerrito 628, San Nicolás, Ciudad Autónoma de Buenos Aires, Argentina',
      'Ciudad Autónoma de Buenos Aires',
    ],
    [
      'sin barrio, con código postal pegado',
      'Av. S. Martín 5743, C1417 Cdad. Autónoma de Buenos Aires, Argentina',
      'Cdad. Autónoma de Buenos Aires',
    ],
    [
      'del interior',
      'Calle Córdoba Z9015 Pico Truncado, Santa Cruz, Argentina',
      'Santa Cruz',
    ],
  ])('la saca de una dirección %s', (_caso, direccion, esperada) => {
    expect(localidadDe(direccion)).toBe(esperada);
  });

  // El código postal es parte de la misma parte que la localidad y no aporta
  // nada en pantalla.
  it.each([
    ['sin letra', '1417 San Telmo'],
    ['con letra', 'C1417 San Telmo'],
    ['con letras al final', 'C1417ABC San Telmo'],
  ])('descarta el código postal %s', (_caso, parte) => {
    expect(localidadDe(`Una calle 100, ${parte}, Argentina`)).toBe('San Telmo');
  });

  /**
   * Con dos partes la anterior al país es la calle, y decir que la localidad
   * es "Av. San Martín 5743" es peor que no decir nada.
   */
  it.each([
    ['sin comas', 'Av. San Martín 5743'],
    ['con una sola coma', 'Av. San Martín 5743, Argentina'],
    ['vacía', ''],
    ['nula', null],
    ['un número', 42],
  ])('no inventa una localidad con una dirección %s', (_caso, valor) => {
    expect(localidadDe(valor)).toBeNull();
  });
});
