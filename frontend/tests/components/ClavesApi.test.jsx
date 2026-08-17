import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import ClavesApi from '../../src/components/ClavesApi';

const CLAVE = {
  id: 3,
  nombre: 'Mi asistente',
  prefijo: 'rzn_3f9ab2cd',
  ultimo_uso_en: null,
  created_at: '2026-08-17 10:00:00',
};

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar({ claves = [] } = {}) {
  global.fetch.mockReturnValueOnce(respuestaDe({ claves }));

  const vista = renderConProviders(<ClavesApi />);
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

  return vista;
}

describe('ClavesApi', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    Object.assign(navigator, { clipboard: { writeText: vi.fn() } });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('lista las claves que ya existen', async () => {
    await montar({ claves: [CLAVE] });

    expect(screen.getByText('Mi asistente')).toBeInTheDocument();
  });

  /** El prefijo es lo único que se guarda en claro: sirve para reconocerla. */
  it('muestra el prefijo, no la clave', async () => {
    await montar({ claves: [CLAVE] });

    expect(screen.getByText(/rzn_3f9ab2cd/)).toBeInTheDocument();
  });

  it('avisa cuando todavía no hay ninguna', async () => {
    await montar();

    expect(screen.getByText('Todavía no creaste ninguna.')).toBeInTheDocument();
  });

  it('no deja crear una clave sin nombre', async () => {
    await montar();

    expect(screen.getByRole('button', { name: 'CREAR' })).toBeDisabled();
  });

  /**
   * El servidor guarda sólo el hash: si no se copia ahora, no hay forma de
   * volver a mostrarla ni siquiera queriendo.
   */
  it('muestra la clave nueva una vez, con el aviso de guardarla', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Nombre de la clave'), {
      target: { value: 'Mi asistente' },
    });

    global.fetch.mockReturnValueOnce(respuestaDe({ clave: 'rzn_secreta', id: 4 }));
    global.fetch.mockReturnValueOnce(respuestaDe({ claves: [CLAVE] }));
    fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

    expect(await screen.findByText('rzn_secreta')).toBeInTheDocument();
    expect(screen.getByText(/no la vas a poder volver a ver/)).toBeInTheDocument();
  });

  it('se puede copiar al portapapeles', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Nombre de la clave'), { target: { value: 'X' } });
    global.fetch.mockReturnValueOnce(respuestaDe({ clave: 'rzn_secreta', id: 4 }));
    global.fetch.mockReturnValueOnce(respuestaDe({ claves: [] }));
    fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

    await screen.findByText('rzn_secreta');
    fireEvent.click(screen.getByLabelText('Copiar la clave'));

    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('rzn_secreta');
  });

  it('explica el motivo cuando el servidor rechaza', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Nombre de la clave'), { target: { value: 'X' } });
    global.fetch.mockReturnValueOnce(respuestaDe({ error: 'Llegaste al máximo de claves' }, false));
    fireEvent.click(screen.getByRole('button', { name: 'CREAR' }));

    expect(await screen.findByText('Llegaste al máximo de claves')).toBeInTheDocument();
  });

  /** Revocar deja de funcionar lo que la esté usando: se pregunta antes. */
  it('pregunta antes de revocar', async () => {
    window.confirm.mockReturnValue(false);
    await montar({ claves: [CLAVE] });

    fireEvent.click(screen.getByLabelText('Revocar Mi asistente'));

    expect(window.confirm).toHaveBeenCalled();
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('revoca al confirmar', async () => {
    await montar({ claves: [CLAVE] });

    global.fetch.mockReturnValueOnce(respuestaDe({ revocada: true }));
    global.fetch.mockReturnValueOnce(respuestaDe({ claves: [] }));
    fireEvent.click(screen.getByLabelText('Revocar Mi asistente'));

    await waitFor(() => expect(global.fetch.mock.calls[1][1].method).toBe('DELETE'));
    expect(global.fetch.mock.calls[1][0]).toContain('id=3');
  });

  /** Sin la dirección del server, la clave sola no sirve para nada. */
  it('muestra la dirección para conectar el server', async () => {
    await montar();

    expect(screen.getByText('https://rezon.ar/mcp')).toBeInTheDocument();
  });
});
