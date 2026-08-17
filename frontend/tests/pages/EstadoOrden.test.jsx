import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import EstadoOrden from '../../src/pages/EstadoOrden';

const ORDEN = {
  codigo: 'ABC123DEF456',
  estado: 'pagada',
  cantidad: 2,
  total: 3000,
  moneda: 'ARS',
  nombre: 'Ana Gómez',
  evento: 'Fiesta de fin de año',
  event_date: '2026-12-01',
  event_time: '21:00:00',
  event_address: 'Av. Corrientes 1234',
  pagina: 'Mi Página',
  url_slug: 'mi-pagina',
};

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(orden = ORDEN, ok = true) {
  global.fetch.mockReturnValue(respuestaDe(ok ? { orden } : { error: 'No encontramos esa orden' }, ok));

  const vista = renderConProviders(<EstadoOrden apiUrl="https://api.test/api" />, {
    route: '/entrada/ABC123DEF456',
    path: '/entrada/:codigo',
  });

  await waitFor(() => expect(screen.queryByText(/Cargando/)).not.toBeInTheDocument());

  return vista;
}

describe('EstadoOrden', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    vi.useFakeTimers({ shouldAdvanceTime: true });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  describe('pagada', () => {
    it('confirma que el lugar está tomado', async () => {
      await montar();

      expect(screen.getByText('¡Listo!')).toBeInTheDocument();
    });

    it('muestra el código bien grande, que es lo que van a pedir en la puerta', async () => {
      await montar();

      expect(screen.getByText('ABC123DEF456')).toBeInTheDocument();
    });

    it('muestra los datos del evento', async () => {
      await montar();

      expect(screen.getByText('Fiesta de fin de año')).toBeInTheDocument();
      expect(screen.getByText(/Av. Corrientes 1234/)).toBeInTheDocument();
    });

    it('muestra a nombre de quién y cuántas', async () => {
      await montar();

      expect(screen.getByText(/2 entradas a nombre de Ana Gómez/)).toBeInTheDocument();
    });

    it('usa el singular con una sola entrada', async () => {
      await montar({ ...ORDEN, cantidad: 1 });

      expect(screen.getByText(/1 entrada a nombre de/)).toBeInTheDocument();
    });

    it('muestra el total pagado', async () => {
      await montar();

      expect(screen.getByText(/3\.000/)).toBeInTheDocument();
    });

    it('una reserva sin costo no muestra total', async () => {
      await montar({ ...ORDEN, total: 0 });

      expect(screen.queryByText(/\$/)).not.toBeInTheDocument();
    });

    it('ofrece volver a la página', async () => {
      await montar();

      expect(screen.getByRole('link', { name: /Volver a Mi Página/ }))
        .toHaveAttribute('href', '/mi-pagina');
    });
  });

  describe('todavía sin confirmar', () => {
    /**
     * Volver de Mercado Pago no significa que el pago esté acreditado: eso lo
     * confirma el aviso al servidor, que puede llegar unos segundos después.
     */
    it('explica que se está confirmando, sin decir que falló', async () => {
      await montar({ ...ORDEN, estado: 'reservada' });

      expect(screen.getByText(/Estamos confirmando tu pago/)).toBeInTheDocument();
    });

    it('vuelve a consultar sola', async () => {
      await montar({ ...ORDEN, estado: 'reservada' });

      const consultasIniciales = global.fetch.mock.calls.length;

      await vi.advanceTimersByTimeAsync(3500);

      await waitFor(() =>
        expect(global.fetch.mock.calls.length).toBeGreaterThan(consultasIniciales)
      );
    });

    /** Reintentar para siempre dejaría la pestaña consultando sin fin. */
    it('deja de reintentar en algún momento', async () => {
      await montar({ ...ORDEN, estado: 'reservada' });

      // La cadena de reintentos no avanza sola con el reloj: cada consulta
      // programa la siguiente recién cuando la anterior respondió. Saltar un
      // bloque grande de tiempo de una vez no garantiza haber llegado al
      // final, y esperar a que se quede quieta tampoco, porque puede pausarse
      // un intervalo y seguir. Se avanza hasta el tope esperado —si el
      // componente no frenara lo pasaría de largo, y la comprobación fallaría
      // igual— y recién ahí se verifica que ya no consulta más.
      const TOPE = 11; // la consulta inicial más los 10 reintentos

      for (let vuelta = 0; vuelta < 60 && global.fetch.mock.calls.length < TOPE; vuelta++) {
        await vi.advanceTimersByTimeAsync(3000);
      }

      expect(global.fetch.mock.calls.length).toBe(TOPE);

      await vi.advanceTimersByTimeAsync(3000 * 10);

      expect(global.fetch.mock.calls.length).toBe(TOPE);
    });

    it('una orden ya confirmada no reintenta', async () => {
      await montar({ ...ORDEN, estado: 'pagada' });

      const consultas = global.fetch.mock.calls.length;
      await vi.advanceTimersByTimeAsync(10000);

      expect(global.fetch.mock.calls.length).toBe(consultas);
    });
  });

  describe('otros finales', () => {
    it('una reserva vencida se explica y no se hace pasar por error', async () => {
      await montar({ ...ORDEN, estado: 'vencida' });

      expect(screen.getByText('La reserva venció')).toBeInTheDocument();
      expect(screen.getByText(/Podés volver a intentarlo/)).toBeInTheDocument();
    });

    it('un pago rechazado lo dice claro', async () => {
      await montar({ ...ORDEN, estado: 'rechazada' });

      expect(screen.getByText('El pago fue rechazado')).toBeInTheDocument();
    });

    it('una orden cancelada también', async () => {
      await montar({ ...ORDEN, estado: 'cancelada' });

      expect(screen.getByText('La orden se canceló')).toBeInTheDocument();
    });

    it('un código inexistente no muestra una pantalla rota', async () => {
      await montar(null, false);

      expect(screen.getByText('No encontramos tu orden')).toBeInTheDocument();
    });
  });
});
