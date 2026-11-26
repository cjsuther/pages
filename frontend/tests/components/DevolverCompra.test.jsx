import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import DevolverCompra, { fechaYHora } from '../../src/components/DevolverCompra';

const ORDEN = {
  codigo: 'ABC123DEF456',
  nombre: 'Ana Gómez',
  email: 'ana@example.com',
  evento: 'Noche de Prueba',
  estado: 'pagada',
  cantidad: 2,
  ingresadas: 0,
  moneda: 'ARS',
  lugares: [],
  items: [],
};

const DESGLOSE = {
  cobrado: 20000,
  comision_plataforma: 200,
  comision_mercadopago: 1062.38,
  neto: 18737.62,
  devuelto: 0,
  devolvible: 18737.62,
};

function datos(extra = {}) {
  return {
    orden: ORDEN,
    desglose: DESGLOSE,
    por_entrada: { forma: 'cantidad', cantidad: 2 },
    aviso: null,
    intentos: [],
    ...extra,
  };
}

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(cuerpo = datos()) {
  global.fetch.mockReturnValueOnce(respuestaDe(cuerpo));
  const onDevuelta = vi.fn();
  const onCerrar = vi.fn();

  render(
    <DevolverCompra
      codigo="ABC123DEF456"
      apiUrl="https://api.test/api"
      token="tok"
      onCerrar={onCerrar}
      onDevuelta={onDevuelta}
    />
  );
  await waitFor(() => expect(screen.queryByText('Cargando la compra...')).not.toBeInTheDocument());

  return { onDevuelta, onCerrar };
}

/** Los cuerpos de los POST que se mandaron, en orden. */
function enviados() {
  return global.fetch.mock.calls
    .filter(([, opciones]) => opciones && opciones.method === 'POST')
    .map(([, opciones]) => JSON.parse(opciones.body));
}

describe('DevolverCompra', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('muestra el desglose: lo que pagó, las comisiones y lo que se puede devolver', async () => {
    await montar();

    expect(screen.getByText('Comisión de Mercado Pago')).toBeInTheDocument();
    expect(screen.getByText('Comisión de Rezonar')).toBeInTheDocument();
    expect(screen.getByText('Te llegó a Mercado Pago')).toBeInTheDocument();
    expect(screen.getByText('Se puede devolver')).toBeInTheDocument();
    expect(screen.getAllByText(/18\.737,62/).length).toBeGreaterThan(0);
    expect(screen.getByText(/no se devuelven/)).toBeInTheDocument();
  });

  it('primero calcula sin devolver nada, y recién después devuelve el monto confirmado', async () => {
    const { onDevuelta } = await montar();

    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, simulada: true, monto: 18737.62, modo: 'completa' }));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));

    await waitFor(() => expect(screen.getByText(/Se le devuelven/)).toBeInTheDocument());
    expect(enviados()[0]).toEqual({ codigo: 'ABC123DEF456', modo: 'completa', simular: true });
    expect(onDevuelta).not.toHaveBeenCalled();

    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, estado: 'aprobada', monto: 18737.62, mail: { enviado: true } }));
    fireEvent.click(screen.getByRole('button', { name: /Devolver \$\s?18\.737,62/ }));

    await waitFor(() => expect(onDevuelta).toHaveBeenCalled());
    expect(enviados()[1]).toEqual({ codigo: 'ABC123DEF456', modo: 'completa', confirmacion: 18737.62 });
    expect(onDevuelta.mock.calls[0][0]).toMatchObject({ estado: 'aprobada', nombre: 'Ana Gómez' });
  });

  it('por entrada manda cuántas se sacan', async () => {
    await montar();

    fireEvent.click(screen.getByLabelText(/Algunas entradas/));
    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, simulada: true, monto: 9368.81, modo: 'entradas' }));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));

    await waitFor(() => expect(screen.getByText(/Esas entradas se sacan/)).toBeInTheDocument());
    expect(enviados()[0]).toEqual({ codigo: 'ABC123DEF456', modo: 'entradas', cantidad: 1, simular: true });
  });

  it('con tipos manda las personas de cada tipo', async () => {
    await montar(datos({
      por_entrada: {
        forma: 'tipos',
        items: [
          { tipo: 'promo', nombre: 'Promo 2x1', precio: 9000, personas: 2, cantidad: 4 },
          { tipo: 'general', nombre: 'General', precio: 6000, personas: 1, cantidad: 1 },
        ],
      },
    }));

    fireEvent.click(screen.getByLabelText(/Algunas entradas/));
    expect(screen.getByRole('button', { name: 'Calcular devolución' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Más Promo 2x1' }));
    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, simulada: true, monto: 100, modo: 'entradas' }));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));

    await waitFor(() => expect(enviados()).toHaveLength(1));
    expect(enviados()[0].tipos).toEqual({ promo: 2, general: 0 });
  });

  it('con plano se eligen los lugares', async () => {
    await montar(datos({ por_entrada: { forma: 'lugares', lugares: ['f:A:1', 'f:A:2'] } }));

    fireEvent.click(screen.getByLabelText(/Algunas entradas/));
    fireEvent.click(screen.getByLabelText('Fila A, butaca 2'));
    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, simulada: true, monto: 100, modo: 'entradas' }));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));

    await waitFor(() => expect(enviados()).toHaveLength(1));
    expect(enviados()[0].lugares).toEqual(['f:A:2']);
  });

  it('si no se puede, muestra el motivo y lo suma al historial', async () => {
    await montar();

    global.fetch.mockReturnValueOnce(respuestaDe({
      error: 'Ya entró gente con esta compra: las entradas usadas no se devuelven.',
      estado: 'rechazada',
      intentos: [{
        id: 3, estado: 'rechazada', monto: 0, created_at: '2026-11-26 14:05:00',
        motivo_rechazo: 'Ya entró gente con esta compra: las entradas usadas no se devuelven.',
      }],
    }, false));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Ya entró gente'));
    expect(screen.getByText('INTENTOS ANTERIORES')).toBeInTheDocument();
    expect(screen.getByText(/26\/11 14:05 · No realizada/)).toBeInTheDocument();
  });

  it('un rechazo de Mercado Pago al confirmar no cierra el modal y deja recalcular', async () => {
    const { onDevuelta } = await montar();

    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, simulada: true, monto: 18737.62, modo: 'completa' }));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));
    await waitFor(() => expect(screen.getByText(/Se le devuelven/)).toBeInTheDocument());

    global.fetch.mockReturnValueOnce(respuestaDe({
      error: 'No hay saldo suficiente en la cuenta de Mercado Pago del organizador para hacer la devolución.',
      estado: 'rechazada',
      intentos: [],
    }, false));
    fireEvent.click(screen.getByRole('button', { name: /Devolver/ }));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('saldo suficiente'));
    expect(onDevuelta).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Calcular devolución' })).toBeInTheDocument();
  });

  it('si el monto cambió, muestra el nuevo para confirmarlo', async () => {
    await montar();

    global.fetch.mockReturnValueOnce(respuestaDe({ ok: true, simulada: true, monto: 18737.62, modo: 'completa' }));
    fireEvent.click(screen.getByRole('button', { name: 'Calcular devolución' }));
    await waitFor(() => expect(screen.getByText(/Se le devuelven/)).toBeInTheDocument());

    global.fetch.mockReturnValueOnce(respuestaDe({ error: 'El monto a devolver cambió', monto: 9368.81 }, false));
    fireEvent.click(screen.getByRole('button', { name: /Devolver/ }));

    await waitFor(() => expect(screen.getByRole('button', { name: /Devolver \$\s?9\.368,81/ })).toBeInTheDocument());
  });

  it('muestra el aviso del servidor antes de intentar', async () => {
    await montar(datos({ aviso: 'Ya se devolvió todo lo que llegó a la cuenta por esta compra.' }));

    expect(screen.getByRole('status')).toHaveTextContent('Ya se devolvió todo');
  });

  it('si las devoluciones están apagadas lo dice', async () => {
    global.fetch.mockReturnValueOnce(respuestaDe({ error: 'Las devoluciones no están habilitadas en la plataforma.' }, false));
    render(<DevolverCompra codigo="X" apiUrl="https://api.test/api" token="tok" onCerrar={() => {}} onDevuelta={() => {}} />);

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('no están habilitadas'));
    expect(screen.queryByRole('button', { name: 'Calcular devolución' })).not.toBeInTheDocument();
  });

  it('con una sola entrada no ofrece devolver algunas', async () => {
    await montar(datos({ orden: { ...ORDEN, cantidad: 1 } }));

    expect(screen.queryByLabelText(/Algunas entradas/)).not.toBeInTheDocument();
  });
});

describe('fechaYHora', () => {
  it('arma día, mes y hora', () => {
    expect(fechaYHora('2026-11-26 14:05:00')).toBe('26/11 14:05');
    expect(fechaYHora('')).toBe('');
  });
});
