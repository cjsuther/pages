import { describe, it, expect } from 'vitest';
import {
  reemplazarVariables,
  insertarEnCursor,
  textoParaWhatsApp,
  recortarParaWhatsApp,
  LARGO_MAXIMO_WHATSAPP,
} from '../../src/utils/mensajes';

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

describe('textoParaWhatsApp', () => {
  const MENSAJE = { asunto: 'Volvemos', cuerpo: 'Hola {primer_nombre}, te esperamos el sábado.\n', reemplazo_nombre: 'amiga' };

  it('usa el cuerpo sin el asunto, con el nombre reemplazado', () => {
    expect(textoParaWhatsApp(MENSAJE, 'Ana Gómez')).toEqual({ texto: 'Hola Ana, te esperamos el sábado.', recortado: false });
  });

  it('sin nombre usa el reemplazo del mensaje', () => {
    expect(textoParaWhatsApp(MENSAJE, '').texto).toBe('Hola amiga, te esperamos el sábado.');
  });

  it('sin mensaje no hay texto', () => {
    expect(textoParaWhatsApp(null, 'Ana')).toEqual({ texto: '', recortado: false });
  });
});

describe('recortarParaWhatsApp', () => {
  it('cambia una mitad de surrogate suelta por "�" y deja los emojis enteros', () => {
    expect(recortarParaWhatsApp('a\uD83Db')).toEqual({ texto: 'a\uFFFDb', recortado: false });
    expect(recortarParaWhatsApp('a\uDE00b')).toEqual({ texto: 'a\uFFFDb', recortado: false });
    expect(recortarParaWhatsApp('Hola 😀')).toEqual({ texto: 'Hola 😀', recortado: false });
  });

  it('un texto corto queda igual', () => {
    expect(recortarParaWhatsApp('Hola')).toEqual({ texto: 'Hola', recortado: false });
  });

  it('uno largo se recorta con "…" sin pasar del máximo codificado', () => {
    const { texto, recortado } = recortarParaWhatsApp('ñ'.repeat(1000));

    expect(recortado).toBe(true);
    expect(texto.endsWith('…')).toBe(true);
    expect(encodeURIComponent(texto).length).toBeLessThanOrEqual(LARGO_MAXIMO_WHATSAPP);
    expect(encodeURIComponent(texto).length).toBeGreaterThan(LARGO_MAXIMO_WHATSAPP - 20);
  });

  it('no parte un emoji al recortar', () => {
    const { texto } = recortarParaWhatsApp('😀'.repeat(300), 100);

    expect(() => encodeURIComponent(texto)).not.toThrow();
    expect(texto).toBe(`${'😀'.repeat(7)}…`);
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
