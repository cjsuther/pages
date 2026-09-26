import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { HelmetProvider } from 'react-helmet-async';
import VentaEnVivo from '../../src/pages/VentaEnVivo';

const CLAVE = 'd'.repeat(32);

const ESTADO = {
  evento: {
    text: 'Fiesta de fin de año',
    event_date: '2026-12-31',
    event_time: '22:00:00',
    event_address: 'Niceto Vega 5510',
    pagina: 'Club Niceto',
    colores: { background_color: '#FFFFFF', text_color: '#111111', primary_color: '#6FBE44' },
  },
  venta: {
    activo: true, capacidad: 120, vendidas: 84, reservadas: 2, disponibles: 34,
    recaudado: 1260000, precio: 15000, moneda: 'ARS', compras: 40, ingresadas: 0,
  },
  plano: null,
  ocupados: [],
  ritmo: [{ dia: '2026-09-20', vendidas: 12 }, { dia: '2026-09-21', vendidas: 8 }],
};

function respuesta(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(cuerpo = ESTADO, ok = true) {
  global.fetch.mockReturnValue(respuesta(cuerpo, ok));
  const vista = render(<HelmetProvider><VentaEnVivo apiUrl="https://api.test/api" /></HelmetProvider>);
  await waitFor(() => expect(global.fetch).toHaveBeenCalled());
  return vista;
}

describe('VentaEnVivo', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    window.location.hash = `#${CLAVE}`;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  /** La clave va en el cuerpo del pedido: en la URL quedaría en los registros. */
  it('manda la clave en el cuerpo, no en la dirección', async () => {
    await montar();

    expect(global.fetch.mock.calls[0][0]).toBe('https://api.test/api/public/venta.php');
    expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({ clave: CLAVE });
  });

  it('muestra cuántas van y lo recaudado', async () => {
    await montar();

    expect(await screen.findByText('Fiesta de fin de año')).toBeInTheDocument();
    expect(screen.getByText('84')).toBeInTheDocument();
    expect(screen.getByText('de 120')).toBeInTheDocument();
    expect(screen.getByText('Disponibles').nextSibling.textContent).toBe('34');
    expect(screen.getByText('Recaudado').nextSibling.textContent).toContain('1.260.000');
  });

  it('el avance se anuncia también para quien no ve la barra', async () => {
    await montar();

    expect(await screen.findByLabelText('70% vendido')).toBeInTheDocument();
  });

  /** No alcanza con cuántas van: la pregunta es si se movió esta semana. */
  it('muestra el ritmo de los últimos días', async () => {
    await montar();

    expect(await screen.findByText('20 entradas en 30 días')).toBeInTheDocument();
    expect(screen.getByLabelText('20 sept: 12 entradas')).toBeInTheDocument();
  });

  it('con plano muestra los lugares vendidos, sin poder elegirlos', async () => {
    await montar({
      ...ESTADO,
      plano: { ancho: 10, alto: 4, elementos: [{ tipo: 'fila', nombre: 'A', desde: 1, butacas: 4, x: 0, y: 0 }] },
      ocupados: ['f:A:2'],
    });

    expect(await screen.findByText('Vendido')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  it('sin clave no pide nada y lo dice', async () => {
    window.location.hash = '';
    render(<HelmetProvider><VentaEnVivo apiUrl="https://api.test/api" /></HelmetProvider>);

    expect(await screen.findByText(/link está incompleto/)).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('un link revocado muestra el error del servidor', async () => {
    await montar({ error: 'Este link no es válido. Pedile uno nuevo a quien organiza.' }, false);

    expect(await screen.findByRole('alert')).toHaveTextContent('Este link no es válido');
  });

  it('un evento que todavía no vende entradas lo dice', async () => {
    await montar({ ...ESTADO, venta: null });

    expect(await screen.findByText(/todavía no vende entradas/)).toBeInTheDocument();
  });

  /** Se reenvía por mensaje: no tiene que aparecer en ningún buscador. */
  it('no se indexa', async () => {
    await montar();

    await waitFor(() => {
      const robots = document.head.querySelector('meta[name="robots"]');
      expect(robots && robots.getAttribute('content')).toContain('noindex');
    });
  });
});
