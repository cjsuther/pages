import { describe, it, expect } from 'vitest';
import {
  esGratis,
  formatearPrecio,
  textoDeAccion,
  opcionesDeCantidad,
  etiquetaDeEstado,
  colorDeEstado,
  minutosRestantes,
} from '../../src/utils/entradas';

describe('entradas', () => {
  describe('esGratis', () => {
    it('un precio de cero es reserva sin cobro', () => {
      expect(esGratis(0)).toBe(true);
      expect(esGratis('0.00')).toBe(true);
    });

    it('sin precio también', () => {
      expect(esGratis(null)).toBe(true);
      expect(esGratis(undefined)).toBe(true);
    });

    it('con precio no', () => {
      expect(esGratis(1500)).toBe(false);
      expect(esGratis('1500.00')).toBe(false);
    });
  });

  describe('formatearPrecio', () => {
    it('muestra el símbolo de la moneda', () => {
      expect(formatearPrecio(1500, 'ARS')).toContain('1.500');
      expect(formatearPrecio(1500, 'ARS')).toContain('$');
    });

    it('no muestra decimales cuando el precio es redondo', () => {
      expect(formatearPrecio(1500, 'ARS')).not.toContain(',00');
    });

    it('los muestra cuando los hay', () => {
      expect(formatearPrecio(1500.5, 'ARS')).toContain('50');
    });

    /** Una moneda desconocida hace tirar a Intl; mejor mostrar algo que romper. */
    it('una moneda inválida no rompe', () => {
      expect(() => formatearPrecio(1500, 'XXXXX')).not.toThrow();
      expect(formatearPrecio(1500, 'XXXXX')).toContain('1500');
    });

    it('un monto ausente se muestra como cero', () => {
      expect(formatearPrecio(null)).toContain('0');
    });
  });

  describe('textoDeAccion', () => {
    it('sin venta activa no hay botón', () => {
      expect(textoDeAccion(null)).toBeNull();
      expect(textoDeAccion({ activo: false })).toBeNull();
    });

    it('con precio se compra', () => {
      expect(textoDeAccion({ activo: true, es_gratis: false })).toBe('COMPRAR ENTRADAS');
    });

    it('sin precio se reserva', () => {
      expect(textoDeAccion({ activo: true, es_gratis: true })).toBe('RESERVAR LUGAR');
    });

    it('agotado gana sobre todo lo demás', () => {
      expect(textoDeAccion({ activo: true, es_gratis: false, agotado: true })).toBe('AGOTADO');
    });
  });

  describe('opcionesDeCantidad', () => {
    it('ofrece hasta el máximo por compra', () => {
      expect(opcionesDeCantidad({ max_por_compra: 4, disponibles: 50 })).toEqual([1, 2, 3, 4]);
    });

    /** Ofrecer 10 cuando quedan 3 lleva a un error recién al confirmar. */
    it('nunca ofrece más de lo que queda', () => {
      expect(opcionesDeCantidad({ max_por_compra: 10, disponibles: 3 })).toEqual([1, 2, 3]);
    });

    it('sin disponibilidad no ofrece nada', () => {
      expect(opcionesDeCantidad({ max_por_compra: 10, disponibles: 0 })).toEqual([]);
    });

    it('sin datos no rompe', () => {
      expect(opcionesDeCantidad(null)).toEqual([]);
    });
  });

  describe('estados', () => {
    it('traduce los estados a algo legible', () => {
      expect(etiquetaDeEstado('pagada')).toBe('Pagada');
      expect(etiquetaDeEstado('reservada')).toBe('Reservada');
    });

    it('un estado desconocido se muestra tal cual', () => {
      expect(etiquetaDeEstado('rarísimo')).toBe('rarísimo');
    });

    it('lo pagado se distingue de lo pendiente por color', () => {
      expect(colorDeEstado('pagada')).not.toBe(colorDeEstado('reservada'));
      expect(colorDeEstado('pagada')).toContain('emerald');
    });
  });

  describe('minutosRestantes', () => {
    const ahora = new Date('2026-08-16T20:00:00').getTime();

    it('cuenta los minutos que faltan', () => {
      expect(minutosRestantes('2026-08-16 20:15:00', ahora)).toBe(15);
    });

    it('una reserva ya vencida da cero', () => {
      expect(minutosRestantes('2026-08-16 19:45:00', ahora)).toBe(0);
    });

    it('sin vencimiento da cero', () => {
      expect(minutosRestantes(null, ahora)).toBe(0);
    });

    /** Safari no parsea "YYYY-MM-DD HH:MM:SS" sin la T. */
    it('acepta el formato con espacio que devuelve MySQL', () => {
      expect(minutosRestantes('2026-08-16 20:10:00', ahora)).toBe(10);
    });
  });
});
