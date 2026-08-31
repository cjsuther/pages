import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import BuscadorDeVentas, { fechaLegible, vendidasLegibles } from '../../src/components/BuscadorDeVentas';

const EVENTO = {
  id: 100,
  text: 'Corta la Semana',
  event_date: '2026-09-02',
  event_time: '21:00:00',
  event_address: 'Humboldt 1574',
  activo: true,
  capacidad: 80,
  precio: '5000.00',
  moneda: 'ARS',
  ordenes: 4,
  vendidas: 6,
  reservadas: 2,
  recaudado: 30000,
};

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, status: ok ? 200 : 400, json: () => Promise.resolve(cuerpo) });
}

async function montar({ eventos = [EVENTO] } = {}) {
  global.fetch.mockReturnValue(respuestaDe({ eventos }));

  const vista = renderConProviders(
    <BuscadorDeVentas pageId={5} apiUrl="https://api.test/api" token="tok" />
  );

  await waitFor(() => expect(screen.queryByText('Buscando eventos…')).not.toBeInTheDocument());

  return vista;
}

/** La URL que pidió la última búsqueda. */
const ultimaUrl = () => global.fetch.mock.calls[global.fetch.mock.calls.length - 1][0];

describe('BuscadorDeVentas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  it('lista los eventos con entradas de la página', async () => {
    await montar();

    expect(screen.getByText('Corta la Semana')).toBeInTheDocument();
    expect(ultimaUrl()).toContain('page_id=5');
  });

  it('muestra cuánto lleva vendido cada evento', async () => {
    await montar();

    expect(screen.getByText(/6\/80 vendidas/)).toBeInTheDocument();
  });

  /**
   * Las reservadas todavía pueden caerse: sumadas a las pagadas darían un
   * número de ventas que no es.
   */
  it('cuenta las reservadas aparte de las vendidas', async () => {
    await montar();

    expect(screen.getByText(/2 reservadas/)).toBeInTheDocument();
  });

  it('busca por nombre', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Buscar por nombre del evento'), {
      target: { value: 'Corta' },
    });

    await waitFor(() => expect(ultimaUrl()).toContain('q=Corta'));
  });

  it('busca por rango de fechas', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-09-01' } });

    await waitFor(() => expect(ultimaUrl()).toContain('desde=2026-09-01'));
  });

  /**
   * Escribir un nombre son varias teclas. Sin esperar, cada una es una
   * consulta y las respuestas pueden llegar desordenadas.
   */
  it('no consulta una vez por tecla', async () => {
    await montar();

    const campo = screen.getByLabelText('Buscar por nombre del evento');
    const antes = global.fetch.mock.calls.length;

    fireEvent.change(campo, { target: { value: 'C' } });
    fireEvent.change(campo, { target: { value: 'Co' } });
    fireEvent.change(campo, { target: { value: 'Cor' } });

    await waitFor(() => expect(ultimaUrl()).toContain('q=Cor'));

    expect(global.fetch.mock.calls.length).toBe(antes + 1);
  });

  it('avisa cuando la búsqueda no trae nada', async () => {
    await montar({ eventos: [] });

    expect(screen.getByText(/Todavía no hay eventos con entradas/)).toBeInTheDocument();
  });

  it('distingue no tener eventos de que la búsqueda no encuentre', async () => {
    await montar({ eventos: [] });

    fireEvent.change(screen.getByLabelText('Buscar por nombre del evento'), {
      target: { value: 'zzz' },
    });

    await waitFor(() =>
      expect(screen.getByText(/Ningún evento con entradas coincide/)).toBeInTheDocument());
  });

  it('avisa si la búsqueda falla', async () => {
    global.fetch.mockReturnValue(respuestaDe({ error: 'No podés ver las ventas de esta página' }, false));

    renderConProviders(<BuscadorDeVentas pageId={5} apiUrl="https://api.test/api" token="tok" />);

    expect(await screen.findByText('No podés ver las ventas de esta página')).toBeInTheDocument();
  });

  it('al elegir un evento muestra sus ventas', async () => {
    await montar();

    fireEvent.click(screen.getByText('Corta la Semana'));

    // El panel de ventas pide las del evento elegido.
    await waitFor(() => expect(ultimaUrl()).toContain('ventas.php?link_id=100'));
  });

  it('desde las ventas de un evento se puede volver al listado', async () => {
    await montar();

    fireEvent.click(screen.getByText('Corta la Semana'));

    fireEvent.click(await screen.findByText('Volver a los eventos'));

    expect(screen.getByLabelText('Buscar por nombre del evento')).toBeInTheDocument();
  });
});

describe('fechaLegible', () => {
  /**
   * `new Date('2026-09-02')` se interpreta como UTC y en Argentina muestra el
   * día anterior: el 2 se veía como 1.
   */
  it('no corre el día por la zona horaria', () => {
    expect(fechaLegible({ event_date: '2026-09-02' })).toBe('02/09/2026');
  });

  it('agrega la hora si está', () => {
    expect(fechaLegible({ event_date: '2026-09-02', event_time: '21:00:00' })).toBe('02/09/2026, 21:00');
  });

  it('sin fecha no inventa nada', () => {
    expect(fechaLegible({})).toBe('Sin fecha');
  });
});

describe('vendidasLegibles', () => {
  it('muestra el cupo cuando lo hay', () => {
    expect(vendidasLegibles({ vendidas: 6, capacidad: 80, reservadas: 0 })).toBe('6/80 vendidas');
  });

  it('sin cupo muestra sólo lo vendido', () => {
    expect(vendidasLegibles({ vendidas: 6, capacidad: 0, reservadas: 0 })).toBe('6 vendidas');
  });

  it('nombra las reservadas aparte', () => {
    expect(vendidasLegibles({ vendidas: 6, capacidad: 80, reservadas: 2 }))
      .toBe('6/80 vendidas · 2 reservadas');
  });
});
