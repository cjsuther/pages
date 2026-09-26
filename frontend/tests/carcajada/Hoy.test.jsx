import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { HelmetProvider } from 'react-helmet-async';
import Hoy from '../../src/carcajada/Hoy';

const ESTADO = {
  show: { ciclo: 'JaJaJaJueves', fecha: '2026-10-02', hora: '21:00:00', lugar: 'Humboldt 1574', es_hoy: true },
  comediantes: [
    { nombre: 'Ana Gómez', foto_url: 'https://rezon.ar/uploads/ana.jpg', instagram: 'anagomez', url_slug: 'anagomez' },
    { nombre: 'Beto Pérez', foto_url: null, instagram: null, url_slug: 'betoperez' },
  ],
};

function montar(cuerpo = ESTADO) {
  global.fetch.mockReturnValue(Promise.resolve({ ok: true, json: () => Promise.resolve(cuerpo) }));
  return render(<HelmetProvider><Hoy apiUrl="https://api.test/api" /></HelmetProvider>);
}

describe('Hoy (la pantalla del QR)', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /** Es un cartel en la pared: no puede pedir sesión ni clave. */
  it('no manda ninguna credencial', async () => {
    montar();

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(global.fetch.mock.calls[0][1]).toBeUndefined();
  });

  it('muestra el show y a cada comediante con su acceso', async () => {
    montar();

    expect(await screen.findByText('JaJaJaJueves')).toBeInTheDocument();
    expect(screen.getByText('Esta noche')).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: /Sus fechas/ })[0]).toHaveAttribute('href', 'https://rezon.ar/anagomez');
    expect(screen.getByRole('link', { name: '@anagomez' })).toHaveAttribute('href', 'https://instagram.com/anagomez');
  });

  /** Sin foto, la inicial: un círculo vacío no dice quién es. */
  it('sin foto muestra la inicial', async () => {
    montar();

    expect(await screen.findByText('B')).toBeInTheDocument();
  });

  it('si la fecha no es hoy lo dice', async () => {
    montar({ ...ESTADO, show: { ...ESTADO.show, es_hoy: false } });

    expect(await screen.findByText('Próxima fecha')).toBeInTheDocument();
  });

  it('sin fechas cargadas no deja la pantalla vacía', async () => {
    montar({ show: null, comediantes: [] });

    expect(await screen.findByText(/No hay ninguna fecha cargada/)).toBeInTheDocument();
  });
});
