import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import LinkDePuerta from '../../src/components/LinkDePuerta';

const URL_PUERTA = `https://rezon.ar/puerta#${'a'.repeat(32)}`;

function respuesta(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(url = null) {
  global.fetch.mockReturnValueOnce(respuesta({ url }));
  render(<LinkDePuerta linkId={100} apiUrl="https://api.test/api" token="tok" />);
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());
}

describe('LinkDePuerta', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('sin link ofrece crearlo', async () => {
    await montar();
    global.fetch.mockReturnValueOnce(respuesta({ url: URL_PUERTA }));

    fireEvent.click(screen.getByRole('button', { name: 'Crear link de puerta' }));

    expect(await screen.findByDisplayValue(URL_PUERTA)).toBeInTheDocument();
    expect(global.fetch.mock.calls[1][1].method).toBe('POST');
  });

  /** Cambiarlo deja afuera a quien tenía el anterior: no puede ser de un clic. */
  it('cambiar el link pide confirmación', async () => {
    await montar(URL_PUERTA);

    fireEvent.click(screen.getByRole('button', { name: /Cambiar el link/ }));

    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(screen.getByText(/va a dejar de funcionar/)).toBeInTheDocument();

    global.fetch.mockReturnValueOnce(respuesta({ url: `https://rezon.ar/puerta#${'b'.repeat(32)}` }));
    fireEvent.click(screen.getByRole('button', { name: 'Sí, cambiarlo' }));

    expect(await screen.findByDisplayValue(/b{32}$/)).toBeInTheDocument();
  });

  it('desactivar borra el link', async () => {
    await montar(URL_PUERTA);

    fireEvent.click(screen.getByRole('button', { name: 'Desactivar' }));
    global.fetch.mockReturnValueOnce(respuesta({ url: null }));
    fireEvent.click(screen.getByRole('button', { name: 'Sí, desactivarlo' }));

    expect(await screen.findByRole('button', { name: 'Crear link de puerta' })).toBeInTheDocument();
    expect(global.fetch.mock.calls[1][1].method).toBe('DELETE');
  });
});
