import { describe, it, expect } from 'vitest';
import { codigoDesdeQr, filtrarOrdenes, horaCorta, describirResultado } from '../../src/utils/puerta';

const ORDENES = [
  { codigo: 'AAA111BBB222', nombre: 'Ana Gómez', lugares: ['f:A:7'] },
  { codigo: 'CCC333DDD444', nombre: 'Beto Pérez', lugares: [] },
];

describe('puerta', () => {
  describe('codigoDesdeQr', () => {
    it('saca el código de la dirección del QR', () => {
      expect(codigoDesdeQr('https://rezon.ar/entrada/abc123def456')).toBe('ABC123DEF456');
    });

    it('acepta el código tipeado con espacios', () => {
      expect(codigoDesdeQr(' abc123 def456 ')).toBe('ABC123DEF456');
    });

    it('un QR que no es de una entrada no da código', () => {
      expect(codigoDesdeQr('https://otro.sitio/promo')).toBeNull();
    });
  });

  describe('filtrarOrdenes', () => {
    it('busca por nombre sin importar tildes ni mayúsculas', () => {
      expect(filtrarOrdenes(ORDENES, 'gomez')).toHaveLength(1);
    });

    it('busca por código', () => {
      expect(filtrarOrdenes(ORDENES, 'ccc333')[0].nombre).toBe('Beto Pérez');
    });

    /** "Tengo la A7": sirve cuando no se acuerdan a nombre de quién está. */
    it('busca por lugar', () => {
      expect(filtrarOrdenes(ORDENES, 'fila a 7')[0].nombre).toBe('Ana Gómez');
    });

    it('sin búsqueda devuelve todas', () => {
      expect(filtrarOrdenes(ORDENES, '  ')).toHaveLength(2);
    });
  });

  it('la hora sale de la fecha de la base', () => {
    expect(horaCorta('2026-09-21 21:14:05')).toBe('21:14');
    expect(horaCorta(null)).toBe('');
  });

  describe('describirResultado', () => {
    it('una entrada de otro evento dice de cuál es', () => {
      const r = describirResultado({ resultado: 'otro_evento', orden: null, evento: 'Otro show' });

      expect(r.tono).toBe('mal');
      expect(r.detalle).toContain('Otro show');
    });

    it('una cancelada dice por qué no pasa', () => {
      const r = describirResultado({ resultado: 'no_pagada', orden: { estado: 'cancelada' } });

      expect(r.detalle).toContain('cancelada');
    });

    it('una que ya entró dice a qué hora', () => {
      const r = describirResultado({
        resultado: 'ya_entro', orden: { cantidad: 2, ingreso_en: '2026-09-21 21:14:00' },
      });

      expect(r.tono).toBe('aviso');
      expect(r.detalle).toBe('Entraron las 2 a las 21:14.');
    });
  });
});
