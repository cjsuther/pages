import { describe, it, expect } from 'vitest';
import {
  contarPorEstado,
  describirEntrega,
  describirLoPedido,
  esLink,
  fechaDeEntrega,
  hoy,
  nombreDelEstado,
  nombreDelExcelDePedidos,
  urlDeLaOperacion,
} from '../../src/utils/pedidos';

describe('pedidos (#44)', () => {
  it('cuenta por estado, sin contar los que no están en gestión', () => {
    expect(contarPorEstado([
      { estado_pedido: 'ingresado' }, { estado_pedido: 'ingresado' }, { estado_pedido: 'enviado' }, { estado_pedido: null },
    ])).toEqual({ ingresado: 2, con_fecha: 0, enviado: 1, entregado: 0, cancelado: 0 });
  });

  it('nombra los estados', () => {
    expect(nombreDelEstado('con_fecha')).toBe('Con fecha de entrega');
    expect(nombreDelEstado('raro')).toBe('raro');
    expect(nombreDelEstado(null)).toBe('');
  });

  it('describe lo pedido', () => {
    expect(describirLoPedido({ opciones: 'Talle M', cantidad: 2 })).toBe('Talle M × 2');
    expect(describirLoPedido({ opciones: null, cantidad: 1 })).toBe('× 1');
  });

  it('describe la fecha de entrega sin correrse por la zona horaria', () => {
    expect(fechaDeEntrega('2026-11-03')).toBe('martes 03/11');
    expect(describirEntrega('2026-11-03', 'manana')).toBe('martes 03/11 · Mañana');
    expect(describirEntrega('2026-11-03', null)).toBe('martes 03/11');
    expect(fechaDeEntrega('')).toBe('');
  });

  it('hoy como lo pide un campo de fecha', () => {
    expect(hoy(new Date(2026, 0, 5))).toBe('2026-01-05');
  });

  it('el link a la operación de Mercado Pago', () => {
    expect(urlDeLaOperacion('880001')).toBe('https://www.mercadopago.com.ar/activities?q=880001');
  });

  it('sólo un http(s) es un link', () => {
    expect(esLink('https://seg.test/1')).toBe(true);
    expect(esLink('CA123456789AR')).toBe(false);
    expect(esLink('javascript:alert(1)')).toBe(false);
  });

  it('nombra el Excel como la API', () => {
    expect(nombreDelExcelDePedidos('La Sala')).toBe('pedidos-la-sala.xlsx');
    expect(nombreDelExcelDePedidos('')).toBe('pedidos-productos.xlsx');
  });
});
