import { describe, it, expect } from 'vitest';
import {
  nuevoTipo, tiposIniciales, algunoCobra, precioDesde, problemaConLosTipos, tipoDelElemento,
  tiposPorLugar, pedidoDeLosLugares, resumenDelPedido, resumirTipos,
} from '../../src/utils/tiposDeEntrada';
import { conVariosPrecios } from '../../src/utils/entradas';

const tipos = [
  { id: 'general', nombre: 'General', precio: 8000, cupo: null },
  { id: 'jubilados', nombre: 'Jubilados', precio: 5000, cupo: 20 },
  { id: 'invitado', nombre: 'Invitado', precio: 0, cupo: null },
];

const fila = (nombre, butacas, entrada) => ({
  tipo: 'fila', nombre, butacas, desde: 1, x: 0, y: 0, ...(entrada ? { entrada } : {}),
});

describe('tipos de entrada', () => {
  it('un tipo nuevo no repite el id de ninguno', () => {
    expect(nuevoTipo([{ id: 't2' }, { id: 't3' }]).id).toBe('t4');
    expect(nuevoTipo([]).id).toBe('t1');
  });

  it('al pasar a varios precios, el que había queda como General', () => {
    expect(tiposIniciales(6000)[0]).toEqual({ id: 'general', nombre: 'General', precio: 6000, cupo: null });
    expect(tiposIniciales(6000)).toHaveLength(2);
  });

  /** Un "Invitado" en 0 no puede hacer gratis a un evento que cobra. */
  it('cobra si alguno cobra, y el "desde" es el más barato de los que cobran', () => {
    expect(algunoCobra(tipos)).toBe(true);
    expect(algunoCobra([tipos[2]])).toBe(false);
    expect(precioDesde(tipos)).toBe(5000);
    expect(precioDesde([tipos[2]])).toBe(0);
  });

  it('avisa lo mismo que rechazaría el servidor', () => {
    expect(problemaConLosTipos(tipos)).toBeNull();
    expect(problemaConLosTipos(null)).toBeNull();
    expect(problemaConLosTipos([{ ...tipos[0], nombre: ' ' }])).toMatch(/necesita un nombre/);
    expect(problemaConLosTipos([tipos[0], { ...tipos[1], nombre: 'general' }])).toMatch(/dos tipos/);
    expect(problemaConLosTipos([{ ...tipos[0], precio: -1 }])).toMatch(/negativo/);
    expect(problemaConLosTipos([{ ...tipos[0], cupo: 0 }])).toMatch(/al menos 1/);
  });

  describe('con plano', () => {
    /** La misma regla que la API: sin tipo, o con uno borrado, vende al primero. */
    it('cada fila vende el tipo que dice, o el primero', () => {
      expect(tipoDelElemento(fila('A', 1, 'jubilados'), tipos)).toBe('jubilados');
      expect(tipoDelElemento(fila('A', 1), tipos)).toBe('general');
      expect(tipoDelElemento(fila('A', 1, 'borrado'), tipos)).toBe('general');
      expect(tipoDelElemento(fila('A', 1, 'jubilados'), null)).toBeNull();
    });

    it('arma el tipo de cada lugar y el pedido de los elegidos', () => {
      const plano = { ancho: 10, alto: 4, elementos: [fila('A', 2, 'jubilados'), fila('B', 2)] };
      const mapa = tiposPorLugar(plano, tipos);

      expect(mapa).toEqual({ 'f:A:1': 'jubilados', 'f:A:2': 'jubilados', 'f:B:1': 'general', 'f:B:2': 'general' });
      expect(pedidoDeLosLugares(['f:A:1', 'f:B:1', 'f:B:2'], mapa)).toEqual({ jubilados: 1, general: 2 });
      expect(tiposPorLugar(plano, null)).toEqual({});
    });
  });

  it('el resumen del pedido suma lo de cada tipo, en el orden de los tipos', () => {
    const resumen = resumenDelPedido(tipos, { jubilados: 1, general: 2, invitado: 0 });

    expect(resumen.items.map((i) => i.id)).toEqual(['general', 'jubilados']);
    expect(resumen.cantidad).toBe(3);
    expect(resumen.total).toBe(21000);
    expect(resumirTipos(resumen.items)).toBe('2 General · 1 Jubilados');
  });
});

describe('conVariosPrecios', () => {
  it('anuncia "desde" sólo si los tipos tienen precios distintos', () => {
    expect(conVariosPrecios({ tipos })).toBe(true);
    expect(conVariosPrecios({ tipos: [{ precio: 5000 }, { precio: 5000 }] })).toBe(false);
    expect(conVariosPrecios({ tipos: null })).toBe(false);
    expect(conVariosPrecios(null)).toBe(false);
  });
});
