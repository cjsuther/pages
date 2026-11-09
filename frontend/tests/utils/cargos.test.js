import { describe, it, expect } from 'vitest';
import { calcularCargos, renglonesDelDesglose } from '../../src/utils/cargos';
import compartidos from '../../../api/tests/Fixtures/cargos-al-comprador.json';

describe('calcularCargos', () => {
  // Los mismos casos que prueba PHP: si el navegador diera otra cosa, se
  // mostraría un total y Mercado Pago cobraría otro.
  it.each(compartidos.casos.map((c) => [c.nombre, c]))('%s', (_nombre, caso) => {
    expect(calcularCargos(caso.precio, caso.tasas)).toEqual(caso.esperado);
  });

  it('el desglose siempre suma el total', () => {
    const tasas = { mp_porcentaje: 4.39, mp_fijo: 0, mp_iva: 21, plataforma_porcentaje: 1.5, plataforma_iva: 0 };
    for (let centavos = 1; centavos < 200000; centavos += 997) {
      const r = calcularCargos(centavos / 100, tasas);
      expect(Math.round(r.total * 100)).toBe(
        Math.round(r.precio * 100) + Math.round(r.mercadopago * 100) + Math.round(r.plataforma * 100)
      );
    }
  });

  it('acepta precios como texto, como vienen de la base', () => {
    expect(calcularCargos('1500.00', null).total).toBe(1500);
  });
});

describe('renglonesDelDesglose', () => {
  it('no nombra una comisión que no se cobra', () => {
    expect(renglonesDelDesglose({ entradas: 3000, mercadopago: 234.51, plataforma: 0 })).toEqual([
      ['Entradas', 3000],
      ['Comisión de Mercado Pago', 234.51],
    ]);
  });
});
