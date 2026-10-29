import { describe, it, expect } from 'vitest';
import {
  caracteristicasParaEditar,
  caracteristicasParaGuardar,
  claveDeCombinacion,
  claveDeFila,
  combinaciones,
  describirEnvio,
  esFisico,
  opcionDisponible,
  problemaDeCaracteristicas,
  stockParaEditar,
  stockValido,
} from '../../src/utils/productos';

/** Lo de los productos físicos (#42): envío, combinaciones, stock y validación. */

describe('esFisico', () => {
  it('sólo con tipo "fisico"', () => {
    expect(esFisico({ tipo: 'fisico' })).toBe(true);
    expect(esFisico({ tipo: 'digital' })).toBe(false);
    expect(esFisico({})).toBe(false);
    expect(esFisico(null)).toBe(false);
  });
});

describe('describirEnvio', () => {
  it('0 o vacío es envío incluido', () => {
    expect(describirEnvio(0)).toBe('Envío incluido');
    expect(describirEnvio('0.00')).toBe('Envío incluido');
    expect(describirEnvio(null)).toBe('Envío incluido');
  });

  it('con costo, lo dice en pesos', () => {
    expect(describirEnvio('2500.00')).toBe('Envío: $ 2.500');
  });
});

describe('combinaciones', () => {
  it('arma todas en el orden de las características', () => {
    const combos = combinaciones([
      { opciones: [{ nombre: 'S' }, { nombre: 'M' }] },
      { opciones: [{ nombre: 'Rojo' }] },
    ]);

    expect(combos.map((c) => c.map((o) => o.nombre).join('/'))).toEqual(['S/Rojo', 'M/Rojo']);
  });

  it('sin características no hay ninguna', () => {
    expect(combinaciones([])).toEqual([]);
    expect(combinaciones(undefined)).toEqual([]);
  });
});

describe('claveDeCombinacion', () => {
  it('ordena los ids de menor a mayor, como la API', () => {
    expect(claveDeCombinacion([12, 3])).toBe('3-12');
  });
});

describe('opcionDisponible', () => {
  const producto = {
    caracteristicas: [
      { id: 10, opciones: [{ id: 1 }, { id: 2 }] },
      { id: 20, opciones: [{ id: 3 }, { id: 4 }] },
    ],
    agotadas: ['1-3', '1-4'],
  };

  it('no, si todas las combinaciones con esa opción están agotadas', () => {
    expect(opcionDisponible(producto, 10, 1)).toBe(false);
    expect(opcionDisponible(producto, 10, 2)).toBe(true);
  });

  it('mira lo ya elegido en las otras características', () => {
    const conM = { ...producto, agotadas: ['2-3'] };
    expect(opcionDisponible(conM, 20, 3, {})).toBe(true);
    expect(opcionDisponible(conM, 20, 3, { 10: 2 })).toBe(false);
  });
});

describe('editar y guardar características', () => {
  const api = [{ id: 70, nombre: 'Talle', opciones: [{ id: 100, nombre: 'S' }, { id: 101, nombre: 'M' }] }];

  it('lo que viene de la API queda con claves estables por id', () => {
    const editables = caracteristicasParaEditar(api);

    expect(editables[0].clave).toBe('c70');
    expect(editables[0].opciones.map((o) => o.clave)).toEqual(['o100', 'o101']);
  });

  it('el stock de la API se ubica en su fila de la grilla', () => {
    const editables = caracteristicasParaEditar(api);
    const grilla = stockParaEditar([{ clave: '101', stock: 0 }]);

    expect(grilla[claveDeFila([editables[0].opciones[1]])]).toBe('0');
  });

  it('manda opciones por nombre y sólo las combinaciones con stock', () => {
    const editables = caracteristicasParaEditar(api);
    editables[0].opciones.push({ clave: 'n1', nombre: ' L ' });
    const grilla = { o100: '', o101: '3', n1: '0' };

    expect(caracteristicasParaGuardar(editables, grilla)).toEqual({
      caracteristicas: [{ id: 70, nombre: 'Talle', opciones: [{ id: 100, nombre: 'S' }, { id: 101, nombre: 'M' }, { nombre: 'L' }] }],
      variantes: [{ opciones: ['M'], stock: '3' }, { opciones: ['L'], stock: '0' }],
    });
  });
});

describe('problemaDeCaracteristicas', () => {
  const c = (nombre, opciones) => ({ nombre, opciones: opciones.map((o) => ({ nombre: o })) });

  it('sin problemas devuelve null', () => {
    expect(problemaDeCaracteristicas([])).toBeNull();
    expect(problemaDeCaracteristicas([c('Talle', ['S', 'M'])])).toBeNull();
  });

  it.each([
    [[c(' ', ['S'])], 'Cada característica necesita un nombre'],
    [[c('Talle', ['S']), c('talle', ['M'])], 'Hay dos características que se llaman "talle"'],
    [[c('Talle', [])], '"Talle" necesita al menos una opción'],
    [[c('Talle', ['S', ''])], 'Hay una opción vacía en "Talle"'],
    [[c('Talle', ['M', 'm'])], 'La opción "m" está repetida en "Talle"'],
  ])('%j → %s', (lista, mensaje) => {
    expect(problemaDeCaracteristicas(lista)).toBe(mensaje);
  });
});

describe('stockValido', () => {
  it('vacío o entero >= 0', () => {
    expect(['', null, undefined, '0', '12', 5].every(stockValido)).toBe(true);
    expect(['-1', '1.5', 'muchos'].some(stockValido)).toBe(false);
  });
});
