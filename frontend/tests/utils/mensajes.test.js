import { describe, it, expect } from 'vitest';
import { reemplazarVariables, insertarEnCursor } from '../../src/utils/mensajes';

describe('reemplazarVariables', () => {
  it('con nombre pone el completo y el primero', () => {
    expect(reemplazarVariables('Hola {primer_nombre}, gracias {nombre}', ' Ana  María Gómez ')).toBe(
      'Hola Ana, gracias Ana María Gómez'
    );
  });

  it('sin nombre usa el reemplazo', () => {
    expect(reemplazarVariables('Hola {primer_nombre},', '', 'amiga')).toBe('Hola amiga,');
  });

  it.each([
    ['Hola {primer_nombre},', 'Hola,'],
    ['¡Hola {primer_nombre}!', '¡Hola!'],
    ['Gracias {nombre}\nVolvé pronto', 'Gracias\nVolvé pronto'],
    ['Hola {primer_nombre} y bienvenido', 'Hola y bienvenido'],
    ['{primer_nombre} te esperamos', 'te esperamos'],
  ])('sin nombre ni reemplazo limpia "%s"', (plantilla, esperado) => {
    expect(reemplazarVariables(plantilla, '', '')).toBe(esperado);
  });
});

describe('insertarEnCursor', () => {
  it('inserta en el cursor y reemplaza la selección', () => {
    expect(insertarEnCursor('Hola ,', 5, 5, '{nombre}')).toEqual({ texto: 'Hola {nombre},', cursor: 13 });
    expect(insertarEnCursor('Hola XX,', 5, 7, '{nombre}')).toEqual({ texto: 'Hola {nombre},', cursor: 13 });
  });

  it('sin cursor agrega al final', () => {
    expect(insertarEnCursor('Hola ', undefined, undefined, '{nombre}')).toEqual({ texto: 'Hola {nombre}', cursor: 13 });
  });
});
