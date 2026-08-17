import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
      await montar({ resumen: { recaudado: 67500, comision: 6750, neto: 60750 } });

      // Hay varias tarjetas con importes: se busca dentro de la que corresponde.
      const tarjeta = screen.getByText('RECAUDADO').closest('div');

      expect(tarjeta).toHaveTextContent('67.500');
    });

    /** Lo reservado no está cobrado todavía: no puede sumarse a lo vendido. */
    it('muestra aparte lo que se está reservando', async () => {
      await montar({ resumen: { vendidas: 45, reservadas: 3 } });

      expect(screen.getByText('RESERVANDO')).toBeInTheDocument();
      expect(screen.getByText('3')).toBeInTheDocument();
    });
  });


    /**
     * Es el número con el que el dueño hace sus cuentas: lo recaudado no es lo
     * que le entra si hay comisión de por medio.
     */
    it('muestra lo que le queda después de la comisión', async () => {
      await montar({ resumen: { recaudado: 10000, comision: 1000, neto: 9000 } });

      expect(screen.getByText('TE QUEDA')).toBeInTheDocument();
      expect(screen.getByText(/9\.000/)).toBeInTheDocument();
    });

    it('detalla cuánto se llevó la comisión', async () => {
      await montar({ resumen: { recaudado: 10000, comision: 1000, neto: 9000 } });

      expect(screen.getByText(/comisión.*1\.000/)).toBeInTheDocument();
    });

    it('sin comisión no muestra el detalle', async () => {
      await montar({ resumen: { recaudado: 10000, comision: 0, neto: 10000 } });

      expect(screen.queryByText(/comisión/)).not.toBeInTheDocument();
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
    it('el email abre el cliente de correo', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByRole('link', { name: 'ana@example.com' }))
        .toHaveAttribute('href', 'mailto:ana@example.com');
    });

    /**
     * Es por donde se le escribe en la práctica: avisar de un cambio de horario
     * se hace por WhatsApp, no llamando.
     */
    it('el teléfono abre WhatsApp, no el marcador', async () => {
      await montar({ ordenes: [VENTA] });

      const enlace = screen.getByRole('link', { name: /WhatsApp a Ana Gómez/ });

      expect(enlace).toHaveAttribute('href', 'https://wa.me/5491122334455');
      expect(enlace).toHaveAttribute('target', '_blank');
    });

    it('sigue mostrando el número tal como lo escribió el comprador', async () => {
      await montar({ ordenes: [{ ...VENTA, telefono: '011 15 2233-4455' }] });

      expect(screen.getByText('011 15 2233-4455')).toBeInTheDocument();
    });

    it('normaliza el 0 y el 15 para armar el link', async () => {
      await montar({ ordenes: [{ ...VENTA, telefono: '011 15 2233-4455' }] });

      expect(screen.getByRole('link', { name: /WhatsApp/ }))
        .toHaveAttribute('href', 'https://wa.me/5491122334455');
    });

    it('respeta el país si el comprador lo puso', async () => {
      await montar({ ordenes: [{ ...VENTA, telefono: '+34 612 345 678' }] });

      expect(screen.getByRole('link', { name: /WhatsApp/ }))
        .toHaveAttribute('href', 'https://wa.me/34612345678');
    });

    /** Mejor dejarlo como texto que mandar al dueño a un número equivocado. */
    it('un número que no se puede interpretar queda como texto', async () => {
      await montar({ ordenes: [{ ...VENTA, telefono: '1234' }] });

      expect(screen.getByText('1234')).toBeInTheDocument();
      expect(screen.queryByRole('link', { name: /WhatsApp/ })).not.toBeInTheDocument();
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

  describe('cancelar una compra', () => {
    const abrirElCartel = async () => {
      fireEvent.click(screen.getByText('Cancelar'));
      await screen.findByText('¿Cancelar esta compra?');
    };

    it('ofrece cancelar las compras vigentes', async () => {
      await montar({ ordenes: [VENTA] });

      expect(screen.getByText('Cancelar')).toBeInTheDocument();
    });

    /**
     * Una vencida ya no ocupa lugar y una rechazada nunca lo ocupó: cancelarlas
     * no cambiaría nada y sólo agregaría un botón que no hace lo que promete.
     */
    it.each(['vencida', 'rechazada', 'cancelada'])('no ofrece cancelar una %s', async (estado) => {
      await montar({ ordenes: [{ ...VENTA, estado }] });

      expect(screen.queryByText('Cancelar')).not.toBeInTheDocument();
    });

    /** Devuelve lugares al cupo y no se deshace: se pregunta antes. */
    it('pregunta antes de cancelar', async () => {
      await montar({ ordenes: [VENTA] });

      fireEvent.click(screen.getByText('Cancelar'));

      expect(await screen.findByText('¿Cancelar esta compra?')).toBeInTheDocument();
      expect(global.fetch).toHaveBeenCalledTimes(1, 'todavía no pidió nada al servidor');
    });

    it('volver atrás no cancela nada', async () => {
      await montar({ ordenes: [VENTA] });
      await abrirElCartel();

      fireEvent.click(screen.getByText('Volver'));

      await waitFor(() =>
        expect(screen.queryByText('¿Cancelar esta compra?')).not.toBeInTheDocument()
      );
      expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('confirmar manda el código de la compra', async () => {
      await montar({ ordenes: [VENTA] });
      await abrirElCartel();

      global.fetch.mockReturnValueOnce(
        respuestaDe({ cancelada: true, ventas: { ordenes: [], resumen: {}, capacidad: 100 } })
      );
      fireEvent.click(screen.getByText('Cancelar la compra'));

      await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));

      const [url, opciones] = global.fetch.mock.calls[1];

      expect(url).toContain('/entradas/cancelar.php');
      expect(opciones.method).toBe('POST');
      expect(JSON.parse(opciones.body)).toEqual({ codigo: 'ABC123DEF456' });
    });

    /** El servidor contesta con las ventas ya actualizadas: no se repide. */
    it('el listado se actualiza sin volver a pedir las ventas', async () => {
      await montar({ ordenes: [VENTA] });
      await abrirElCartel();

      global.fetch.mockReturnValueOnce(
        respuestaDe({
          cancelada: true,
          ventas: {
            ordenes: [{ ...VENTA, estado: 'cancelada' }],
            resumen: { vendidas: 0, reservadas: 0, recaudado: 0 },
            capacidad: 100,
          },
        })
      );
      fireEvent.click(screen.getByText('Cancelar la compra'));

      await waitFor(() => expect(screen.queryByText('Cancelar')).not.toBeInTheDocument());
      expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    /** Una cancelación fallida no puede llevarse puesto el listado de ventas. */
    it('un rechazo del servidor se explica sin tirar abajo el listado', async () => {
      await montar({ ordenes: [VENTA] });
      await abrirElCartel();

      global.fetch.mockReturnValueOnce(
        respuestaDe({ error: 'ya estaba cancelada' }, false)
      );
      fireEvent.click(screen.getByText('Cancelar la compra'));

      expect(await screen.findByText('ya estaba cancelada')).toBeInTheDocument();
      expect(screen.getByText('Ana Gómez')).toBeInTheDocument();
      expect(screen.getByText('¿Cancelar esta compra?')).toBeInTheDocument();
    });
  });
});
