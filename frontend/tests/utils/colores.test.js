import { describe, it, expect } from 'vitest';
import { aRgb, borde, conAlfa, mezclar, superficie } from '../../src/utils/colores';

describe('aRgb', () => {
  it('entiende el formato largo', () => {
    expect(aRgb('#030c1c')).toEqual([3, 12, 28]);
  });

  it('entiende el formato corto', () => {
    expect(aRgb('#fff')).toEqual([255, 255, 255]);
  });

  it('no se marea con mayúsculas ni espacios', () => {
    expect(aRgb('  #F7F7F7 ')).toEqual([247, 247, 247]);
  });

  /** Los colores llegan de la base: pueden ser cualquier cosa o no estar. */
  it('devuelve null con lo que no sabe leer', () => {
    ['rgb(0,0,0)', 'rojo', '#12345', '', null, undefined, 42].forEach((valor) => {
      expect(aRgb(valor)).toBeNull();
    });
  });
});

describe('mezclar', () => {
  it('sin proporción devuelve la base', () => {
    expect(mezclar('#000000', '#ffffff', 0)).toBe('#000000');
  });

  it('con proporción entera devuelve el de encima', () => {
    expect(mezclar('#000000', '#ffffff', 1)).toBe('#ffffff');
  });

  it('a la mitad queda en el medio', () => {
    expect(mezclar('#000000', '#ffffff', 0.5)).toBe('#808080');
  });

  /** El resultado se sigue usando como color: tiene que poder releerse. */
  it('devuelve algo que las demás funciones saben leer', () => {
    expect(aRgb(mezclar('#030c1c', '#f7f7f7', 0.06))).not.toBeNull();
  });

  it('es null si algún color no se entiende', () => {
    expect(mezclar('#000000', 'azul', 0.5)).toBeNull();
  });
});

describe('conAlfa', () => {
  it('conserva el color y le agrega la opacidad', () => {
    expect(conAlfa('#f7f7f7', 0.14)).toBe('rgba(247, 247, 247, 0.14)');
  });

  it('es null si el color no se entiende', () => {
    expect(conAlfa('violeta', 0.5)).toBeNull();
  });
});

describe('superficie', () => {
  /**
   * La tarjeta no es un color nuevo: es el fondo elegido corrido apenas hacia
   * la tipografía. Sobre fondo oscuro se aclara, sobre fondo claro se
   * oscurece, y en los dos casos sigue siendo el color de la página.
   */
  it('sobre fondo oscuro queda apenas más clara que el fondo', () => {
    const s = superficie('#0a0a0b', '#f7f7f7');

    expect(aRgb(s)[0]).toBeGreaterThan(aRgb('#0a0a0b')[0]);
  });

  it('sobre fondo claro queda apenas más oscura que el fondo', () => {
    const s = superficie('#f3f4f6', '#111827');

    expect(aRgb(s)[0]).toBeLessThan(aRgb('#f3f4f6')[0]);
  });

  /** Si se despegara demasiado dejaría de ser el fondo que se eligió. */
  it('se queda cerca del fondo, no se convierte en otro color', () => {
    const fondo = aRgb('#0a0a0b');
    const s = aRgb(superficie('#0a0a0b', '#ffffff'));

    s.forEach((canal, i) => {
      expect(Math.abs(canal - fondo[i])).toBeLessThan(30);
    });
  });

  it('nunca se va al blanco fijo, que era el problema', () => {
    expect(superficie('#0a0a0b', '#ffffff')).not.toBe('#ffffff');
    expect(superficie('#ffffff', '#ffffff')).toBe('#ffffff');
  });

  it('es null si el fondo no se entiende, para no inventar un color', () => {
    expect(superficie('degradado', '#000000')).toBeNull();
  });
});

describe('borde', () => {
  it('sale de la tipografía, sin sumar un color a la paleta', () => {
    expect(borde('#f7f7f7')).toBe('rgba(247, 247, 247, 0.14)');
  });

  it('es tenue: delimita sin dibujar un marco', () => {
    const alfa = Number(/,\s*([\d.]+)\)$/.exec(borde('#000000'))[1]);

    expect(alfa).toBeGreaterThan(0);
    expect(alfa).toBeLessThan(0.3);
  });

  it('es null si la tipografía no se entiende', () => {
    expect(borde(null)).toBeNull();
  });
});
