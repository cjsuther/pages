import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import PanelClientes from '../../src/components/PanelClientes';

const CLIENTE = {
  email: 'ana@example.com',
  nombre: 'Ana Gómez',
  telefono: '+54 11 2233-4455',
  user_id: 12,
  tiene_cuenta: true,
  sigue: true,
  sigue_desde: '2026-07-01 12:00:00',
  eventos: 2,
  compras: 1,
  reservas: 1,
  entradas: 3,
  asistencias: 1,
  gastado: { ARS: 3000 },
  primera_actividad: '2026-07-01 12:00:00',
  ultima_actividad: '2026-09-01 10:00:00',
  participaciones: [
    {
      record_id: 8, evento: 'Fiesta de primavera', event_date: '2026-09-21', event_time: '22:00:00',
      eliminado: true, link_id: null, rol: 'colaborador', codigo: 'B2', estado: 'pagada',
      cantidad: 2, ingresadas: 2, total: 3000, moneda: 'ARS', created_at: '2026-09-01 10:00:00',
    },
    {
      record_id: 7, evento: 'Corta la Semana', event_date: '2026-08-02', event_time: null,
      eliminado: false, link_id: 100, rol: 'organizador', codigo: 'A1', estado: 'pagada',
      cantidad: 1, ingresadas: 0, total: 0, moneda: 'ARS', created_at: '2026-08-01 10:00:00',
    },
  ],
};

const EVENTOS = [
  { id: 8, titulo: 'Fiesta de primavera', event_date: '2026-09-21', event_time: '22:00:00', eliminado: true, rol: 'colaborador', compras: 1 },
  { id: 7, titulo: 'Corta la Semana', event_date: '2026-08-02', event_time: null, eliminado: false, rol: 'organizador', compras: 1 },
];

const RESUMEN = { clientes: 1, con_cuenta: 1, seguidores: 1, compradores: 1, vinieron: 1 };

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, status: ok ? 200 : 400, json: () => Promise.resolve(cuerpo) });
}

async function montar({ clientes = [CLIENTE] } = {}) {
  global.fetch.mockReturnValue(respuestaDe({ clientes, eventos: EVENTOS, resumen: RESUMEN }));

  const vista = renderConProviders(
    <PanelClientes pageId={5} apiUrl="https://api.test/api" token="tok" slug="la-trastienda" />
  );

  await waitFor(() => expect(screen.queryByText('Buscando clientes…')).not.toBeInTheDocument());

  return vista;
}

const ultimaUrl = () => global.fetch.mock.calls[global.fetch.mock.calls.length - 1][0];

describe('PanelClientes', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  it('lista los clientes de la página', async () => {
    await montar();

    expect(screen.getByText('Ana Gómez')).toBeInTheDocument();
    expect(ultimaUrl()).toContain('/pages/clientes.php?page_id=5');
  });

  it('dice si tiene cuenta, si sigue la página y a cuántos eventos vino', async () => {
    await montar();

    expect(screen.getByText('Cuenta en Rezonar')).toBeInTheDocument();
    expect(screen.getByText('Sigue la página')).toBeInTheDocument();
    expect(screen.getByText('Vino a 1 de 2 eventos')).toBeInTheDocument();
  });

  it('al abrir un cliente muestra sus eventos, también los eliminados y las colaboraciones', async () => {
    await montar();

    fireEvent.click(screen.getByText('Ana Gómez'));

    expect(screen.getAllByText('Fiesta de primavera').length).toBeGreaterThan(0);
    expect(screen.getByText(/evento eliminado · colaboración/)).toBeInTheDocument();
    expect(screen.getByText('Gratis')).toBeInTheDocument();
  });

  it('filtra por evento, con los eliminados marcados', async () => {
    await montar();

    expect(screen.getByRole('option', { name: /Fiesta de primavera.*\(eliminado\)/ })).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Evento'), { target: { value: '8' } });

    await waitFor(() => expect(ultimaUrl()).toContain('evento=8'));
  });

  it('filtra por asistencia y por cuenta', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Asistencia'), { target: { value: 'no_vino' } });
    await waitFor(() => expect(ultimaUrl()).toContain('asistencia=no_vino'));

    fireEvent.change(screen.getByLabelText('Cuenta en Rezonar'), { target: { value: 'no' } });
    await waitFor(() => expect(ultimaUrl()).toContain('cuenta=no'));
  });

  it('exporta a Excel con los mismos filtros', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Relación con la página'), { target: { value: 'sigue' } });
    await waitFor(() => expect(ultimaUrl()).toContain('origen=sigue'));

    global.URL.createObjectURL = vi.fn(() => 'blob:x');
    global.URL.revokeObjectURL = vi.fn();
    global.fetch.mockReturnValue(Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['x'])) }));

    fireEvent.click(screen.getByText('Exportar a Excel'));

    await waitFor(() => expect(ultimaUrl()).toContain('formato=excel'));
    expect(ultimaUrl()).toContain('origen=sigue');
  });

  it('sin clientes lo dice', async () => {
    await montar({ clientes: [] });

    expect(screen.getByText('Todavía no hay clientes')).toBeInTheDocument();
  });

  it('muestra el error del servidor', async () => {
    global.fetch.mockReturnValue(respuestaDe({ error: 'No podés ver los clientes de esta página' }, false));

    renderConProviders(<PanelClientes pageId={5} apiUrl="https://api.test/api" token="tok" />);

    expect(await screen.findByText('No podés ver los clientes de esta página')).toBeInTheDocument();
  });
});
