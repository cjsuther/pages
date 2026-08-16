import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import PanelVentas from '../../src/components/PanelVentas';

const VENTA = {
  id: 1,
  codigo: 'ABC123DEF456',
  nombre: 'Ana Gómez',
  email: 'ana@example.com',
  telefono: '1122334455',
  cantidad: 2,
  total: '3000.00',
  moneda: 'ARS',
  estado: 'pagada',
};

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar({ ordenes = [], resumen = {}, capacidad = 100, ok = true } = {}) {
  global.fetch.mockReturnValueOnce(
    respuestaDe(
      ok
        ? { ordenes, resumen: { vendidas: 0, reservadas: 0, recaudado: 0, ...resumen }, capacidad }
        : { error: 'No podés ver las ventas de este evento' },
      ok
    )
  );

  const vista = render(<PanelVentas linkId={100} apiUrl="https://api.test/api" token="tok" />);
  await waitFor(() => expect(screen.queryByText('Cargando ventas...')).not.toBeInTheDocument());

  return vista;
}

describe('PanelVentas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('resumen', () => {
    it('muestra vendidas sobre la capacidad', async () => {
      await montar({ resumen: { vendidas: 45 }, capacidad: 100 });

      expect(screen.getByText('45/100')).toBeInTheDocument();
    });

    it('muestra lo recaudado', async () => {
      await montar({ resumen: { recaudado: 67500 } });

      expect(screen.getByText(/67\.500/)).toBeInTheDocument();
    });

    /** Lo reservado no está cobrado todavía: no puede sumarse a lo vendido. */
    it('muestra aparte lo que se está reservando', async () => {
      await montar({ resumen: { vendidas: 45, reservadas: 3 } });

      expect(screen.getByText('RESERVANDO')).toBeInTheDocument();
      expect(screen.getByText('3')).toBeInTheDocument();
    });
  });

  describe('listado', () => {
    it('avisa cuando todavía no hay ventas', async () => {
      await montar({ ordenes: [] });

      expect(screen.getByText(/Todavía no hay ventas/)).toBeInTheDocument();
    });

    it('muestra cada compra con su comprador', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByText('Ana Gómez')).toBeInTheDocument();
      expect(screen.getByText('ABC123DEF456')).toBeInTheDocument();
    });

    /** El dueño necesita poder contactar a quien compró. */
    it('el email y el teléfono son clicables', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByRole('link', { name: 'ana@example.com' }))
        .toHaveAttribute('href', 'mailto:ana@example.com');
      expect(screen.getByRole('link', { name: '1122334455' }))
        .toHaveAttribute('href', 'tel:1122334455');
    });

    it('muestra la cantidad y el total', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByText('2')).toBeInTheDocument();
      expect(screen.getByText(/3\.000/)).toBeInTheDocument();
    });

    it('traduce el estado a algo legible', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByText('Pagada')).toBeInTheDocument();
    });

    it('distingue una reserva vencida de una pagada', async () => {
      await montar({
        ordenes: [
          { ...VENTA, id: 1, estado: 'pagada' },
          { ...VENTA, id: 2, codigo: 'XYZ', nombre: 'Luis Paz', estado: 'vencida' },
        ],
      });

      expect(screen.getByText('Pagada')).toBeInTheDocument();
      expect(screen.getByText('Vencida')).toBeInTheDocument();
    });
  });

  describe('exportar', () => {
    it('el botón aparece sólo si hay ventas', async () => {
      await montar({ ordenes: [] });

      expect(screen.queryByText('Exportar CSV')).not.toBeInTheDocument();
    });

    it('aparece cuando hay ventas', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByText('Exportar CSV')).toBeInTheDocument();
    });
  });

  describe('cuando algo sale mal', () => {
    it('muestra el motivo que da el servidor', async () => {
      await montar({ ok: false });

      expect(screen.getByText(/No podés ver las ventas/)).toBeInTheDocument();
    });

    it('la sesión viaja en la cabecera', async () => {
      await montar({ ordenes: [] });

      expect(global.fetch.mock.calls[0][1].headers.Authorization).toBe('Bearer tok');
    });
  });
});
