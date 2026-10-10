import { describe, it, expect } from 'vitest';
import {
  nuevoTipo, tiposIniciales, algunoCobra, precioDesde, problemaConLosTipos, tipoDelElemento,
  tiposPorLugar, pedidoDeLosLugares, resumenDelPedido, resumirTipos,
  personasDe, unidades, lugaresSinCargo, precioDelTipo,
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
    expect(tiposIniciales(6000)[0]).toEqual({ id: 'general', nombre: 'General', precio: 6000, personas: 1, cupo: null });
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

  describe('promos', () => {
    const promo = { id: '2x1', nombre: 'Promo 2x1', precio: 8000, personas: 2, cupo: null };

    it('un tipo sin personas es de una', () => {
      expect(personasDe(tipos[0])).toBe(1);
      expect(personasDe(promo)).toBe(2);
    });

    it('cada grupo que empieza paga la promo entera', () => {
      expect([1, 2, 3, 4].map((n) => unidades(n, 2))).toEqual([1, 1, 2, 2]);
      expect([0, 1, 2, 3].map((n) => lugaresSinCargo(n, 2))).toEqual([0, 1, 0, 1]);
      expect(lugaresSinCargo(3, 1)).toBe(0);
    });

    it('el resumen cobra las promos y cuenta las personas', () => {
      const resumen = resumenDelPedido([tipos[0], promo], { general: 1, '2x1': 3 });

      expect(resumen.cantidad).toBe(4);
      expect(resumen.total).toBe(24000);
      expect(resumirTipos(resumen.items)).toBe('1 General · 2 Promo 2x1 (3 personas)');
    });

    it('el precio de una promo dice para cuántos es', () => {
      const formatear = (p) => `$${p}`;

      expect(precioDelTipo(promo, formatear)).toBe('$8000 cada 2');
      expect(precioDelTipo(tipos[0], formatear)).toBe('$8000');
      expect(precioDelTipo(tipos[2], formatear)).toBeNull();
    });

    it('las personas van de 1 a 10', () => {
      expect(problemaConLosTipos([{ ...promo, personas: 11 }])).toBe('En "Promo 2x1" entran de 1 a 10 personas.');
      expect(problemaConLosTipos([{ ...promo, personas: 0 }])).toBe('En "Promo 2x1" entran de 1 a 10 personas.');
      expect(problemaConLosTipos([promo])).toBeNull();
    });
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
