import { describe, it, expect } from 'vitest';
import {
  huella, lugaresDelElemento, lugaresDelPlano, describirLugar, resumirLugares, lugaresRepetidos,
  siguienteLetra, nuevoElemento, armarPlatea, redimensionar, alternarMesa, dentroDelPlano,
} from '../../src/utils/plano';

const fila = (nombre = 'A', butacas = 3, desde = 1, x = 0, y = 0) => ({
  tipo: 'fila', nombre, butacas, desde, x, y,
});
const mesa = (nombre = '1', lugares = 4, x = 5, y = 5) => ({
  tipo: 'mesa', nombre, lugares, forma: 'redonda', x, y,
});

describe('plano', () => {
  describe('lugares', () => {
    /** Tienen que ser los mismos identificadores que arma el servidor. */
    it('una fila y una mesa dan sus lugares con el formato de la API', () => {
      const plano = { ancho: 20, alto: 12, elementos: [fila('A', 2, 7), mesa('3', 2)] };

      expect(lugaresDelPlano(plano)).toEqual(['f:A:7', 'f:A:8', 'm:3:1', 'm:3:2']);
    });

    it('las butacas de una fila van una por casillero, después del nombre', () => {
      const [primera, segunda] = lugaresDelElemento(fila('A', 2, 1, 4, 2));

      expect(primera).toMatchObject({ cx: 5.5, cy: 2.5, numero: 1 });
      expect(segunda.cx).toBe(6.5);
    });

    /** El lugar 1 de todas las mesas queda arriba: se encuentra sin buscarlo. */
    it('el primer lugar de una mesa está arriba del centro', () => {
      const [primero] = lugaresDelElemento(mesa('1', 4, 0, 0));

      expect(primero.cx).toBeCloseTo(1.5);
      expect(primero.cy).toBeLessThan(1.5);
    });

    it('una mesa más grande ocupa más lugar', () => {
      expect(huella(mesa('1', 4)).ancho).toBeLessThan(huella(mesa('1', 12)).ancho);
    });

    it('detecta lugares repetidos', () => {
      const plano = { ancho: 20, alto: 12, elementos: [fila('A', 4, 1), fila('A', 4, 3, 0, 2)] };

      expect(lugaresRepetidos(plano)).toEqual(['f:A:3', 'f:A:4']);
    });
  });

  describe('para leer', () => {
    it('describe un lugar', () => {
      expect(describirLugar('f:A:7')).toBe('Fila A, butaca 7');
      expect(describirLugar('m:3:2')).toBe('Mesa 3, lugar 2');
    });

    it('agrupa varios lugares por fila y mesa, ordenados', () => {
      expect(resumirLugares(['f:A:8', 'm:3:1', 'f:A:7'])).toBe('Fila A: 7, 8 · Mesa 3: 1');
    });
  });

  describe('armado', () => {
    it('la letra que sigue a la Z es AA', () => {
      expect(siguienteLetra('A')).toBe('B');
      expect(siguienteLetra('Z')).toBe('AA');
      expect(siguienteLetra('AZ')).toBe('BA');
    });

    it('una fila nueva toma la primera letra libre y va abajo de lo que hay', () => {
      const plano = { ancho: 20, alto: 12, elementos: [fila('A', 3, 1, 0, 0)] };
      const nueva = nuevoElemento('fila', plano);

      expect(nueva.nombre).toBe('B');
      expect(nueva.y).toBe(2);
    });

    it('una mesa nueva toma el primer número libre', () => {
      const plano = { ancho: 20, alto: 12, elementos: [mesa('1'), mesa('2', 4, 10, 0)] };

      expect(nuevoElemento('mesa', plano).nombre).toBe('3');
    });

    it('armar una platea da el escenario y las filas pedidas', () => {
      const plano = armarPlatea({ filas: 3, butacas: 8 });

      expect(plano.elementos[0].tipo).toBe('escenario');
      expect(plano.elementos.slice(1).map((e) => e.nombre)).toEqual(['A', 'B', 'C']);
      expect(lugaresDelPlano(plano)).toHaveLength(24);
    });

    it('un elemento que se arrastra afuera queda en el borde', () => {
      const plano = { ancho: 10, alto: 10, elementos: [] };

      expect(dentroDelPlano(fila('A', 4, 1, 50, -3), plano)).toMatchObject({ x: 5, y: 0 });
    });

    it('achicar el plano trae adentro lo que quedaba afuera', () => {
      const plano = { ancho: 20, alto: 20, elementos: [fila('A', 4, 1, 15, 15)] };
      const chico = redimensionar(plano, 10, 10);

      expect(chico.elementos[0]).toMatchObject({ x: 5, y: 9 });
    });
  });

  describe('elegir una mesa entera', () => {
    it('elige los lugares libres de la mesa', () => {
      expect(alternarMesa(mesa('1', 4), [], ['m:1:2'], 10)).toEqual(['m:1:1', 'm:1:3', 'm:1:4']);
    });

    it('si ya estaban todos elegidos, los suelta', () => {
      expect(alternarMesa(mesa('1', 2), ['m:1:1', 'm:1:2', 'f:A:1'], [], 10)).toEqual(['f:A:1']);
    });

    it('no pasa el máximo por compra', () => {
      expect(alternarMesa(mesa('1', 4), ['f:A:1'], [], 3)).toEqual(['f:A:1', 'm:1:1', 'm:1:2']);
    });
  });
});
