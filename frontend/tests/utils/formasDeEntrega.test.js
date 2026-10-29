import { describe, it, expect } from 'vitest';
import {
  costoDeEnvio,
  cuerpoDelPedido,
  describirEntregas,
  describirFormaDeEnvio,
  entregaVacia,
  formasDeEntrega,
  problemaDeFormas,
  problemaDelPedido,
  totalDelPedido,
} from '../../src/utils/productos';
import { accionHacia, contarPorForma, estadosDelPedido, nombreDelEstado } from '../../src/utils/pedidos';

/** Las formas de entrega de un físico (#53): envío, retiro y coordinar. */

describe('formas de entrega', () => {
  it('un producto sin las columnas es de antes: sólo envío', () => {
    expect(formasDeEntrega({ tipo: 'fisico' })).toEqual(['envio']);
  });

  it('lee las columnas del editor, como 0/1 o texto', () => {
    expect(formasDeEntrega({ entrega_envio: '0', entrega_retiro: '1', entrega_coordinar: 1 })).toEqual(['retiro', 'coordinar']);
    expect(formasDeEntrega({ entrega_envio: false, entrega_retiro: true, entrega_coordinar: false })).toEqual(['retiro']);
  });

  it('el producto público ya las trae', () => {
    expect(formasDeEntrega({ formas_entrega: ['coordinar'], entrega_envio: '1' })).toEqual(['coordinar']);
  });

  it('hace falta al menos una, y con retiro la dirección elegida de Google', () => {
    expect(problemaDeFormas({ entrega_envio: false })).toBe('Elegí al menos una forma de entrega');
    expect(problemaDeFormas({ entrega_retiro: true, retiro_direccion: ' ' })).toBe('Falta la dirección del retiro');
    expect(problemaDeFormas({ entrega_retiro: true, retiro_direccion: 'Corrientes 1234' })).toMatch(/sugerencias de Google Maps/);
    expect(problemaDeFormas({
      entrega_retiro: true, retiro_direccion: 'Corrientes 1234', retiro_latitud: -34.6, retiro_longitud: -58.4,
    })).toBeNull();
    expect(problemaDeFormas({ entrega_envio: '1' })).toBeNull();
  });

  it('cómo se lee el envío y el resumen de la lista', () => {
    expect(describirFormaDeEnvio(0)).toBe('Envío a domicilio (incluido)');
    expect(describirFormaDeEnvio('1500.00')).toBe('Envío a domicilio ($ 1.500)');
    expect(describirEntregas({ costo_envio: 0 })).toBe('Envío incluido');
    expect(describirEntregas({ entrega_envio: 1, entrega_retiro: 1, entrega_coordinar: 1 })).toBe('Envío · Retiro · A coordinar');
  });

  it('el envío se cobra sólo con envío', () => {
    const producto = { precio: 2500, costo_envio: 500 };

    expect(totalDelPedido(producto.precio, 2, costoDeEnvio(producto, 'envio')).total).toBe(5500);
    expect(totalDelPedido(producto.precio, 2, costoDeEnvio(producto, 'retiro')).total).toBe(5000);
    expect(totalDelPedido(producto.precio, 2, costoDeEnvio(producto, 'coordinar')).total).toBe(5000);
  });
});

describe('el pedido según la forma', () => {
  const producto = { caracteristicas: [] };

  it('sin forma elegida no se manda', () => {
    expect(problemaDelPedido({ producto, telefono: '11 5555-1234', entrega: entregaVacia(), forma: null }))
      .toBe('Elegí cómo querés recibir el pedido');
  });

  it('sin envío no pide dirección, pero sí el teléfono', () => {
    expect(problemaDelPedido({ producto, telefono: '11 5555-1234', entrega: entregaVacia(), forma: 'retiro' })).toBeNull();
    expect(problemaDelPedido({ producto, telefono: '', entrega: entregaVacia(), forma: 'coordinar' }))
      .toMatch(/Falta el teléfono/);
    expect(problemaDelPedido({ producto, telefono: '11 5555-1234', entrega: entregaVacia(), forma: 'envio' }))
      .toBe('Falta la calle y el número');
  });

  it('el cuerpo lleva la forma, y la dirección sólo con envío', () => {
    expect(cuerpoDelPedido({ producto, cantidad: 1, entrega: entregaVacia(), forma: 'coordinar' }))
      .toEqual({ cantidad: 1, opciones: [], forma_entrega: 'coordinar' });
    expect(cuerpoDelPedido({ producto, cantidad: 1, entrega: entregaVacia(), forma: 'envio' }))
      .toHaveProperty('envio');
  });
});

describe('los estados según la forma', () => {
  it('un retiro tiene sus nombres; envío y coordinar los de siempre', () => {
    expect(['con_fecha', 'enviado', 'entregado'].map((e) => nombreDelEstado(e, 'retiro')))
      .toEqual(['Con fecha de retiro', 'Listo para retirar', 'Retirado']);
    expect(['con_fecha', 'enviado', 'entregado'].map((e) => nombreDelEstado(e, 'coordinar')))
      .toEqual(['Con fecha de entrega', 'Enviado', 'Entregado']);
    expect(nombreDelEstado('enviado')).toBe('Enviado');
    expect(estadosDelPedido('retiro').map((e) => e.plural))
      .toEqual(['Ingresados', 'Con fecha', 'Listos para retirar', 'Retirados', 'Cancelados']);
  });

  it('los botones de cada paso', () => {
    expect(accionHacia('enviado', 'ingresado', 'retiro')).toBe('Marcar listo para retirar');
    expect(accionHacia('con_fecha', 'con_fecha', 'retiro')).toBe('Cambiar la fecha de retiro');
    expect(accionHacia('con_fecha', 'con_fecha', 'envio')).toBe('Cambiar la fecha de entrega');
    expect(accionHacia('enviado', 'ingresado', 'coordinar')).toBe('Marcar enviado');
    expect(accionHacia('cancelado', 'ingresado', 'retiro')).toBe('Cancelar pedido');
  });

  it('cuenta por forma, y uno sin forma es envío', () => {
    expect(contarPorForma([{ forma_entrega: 'retiro' }, {}, { forma_entrega: 'envio' }]))
      .toEqual({ envio: 2, retiro: 1, coordinar: 0 });
  });
});
