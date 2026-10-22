import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import Baja from '../../src/pages/Baja';

function servidor(respuestas) {
  const llamadas = [];
  global.fetch = vi.fn((url, opciones = {}) => {
    const metodo = opciones.method || 'GET';
    llamadas.push({ url, metodo });
    const { status = 200, body } = respuestas[metodo];
    return Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
  });
  return llamadas;
}

function montar(ruta = '/baja-de-mails?t=abc.def') {
  renderConProviders(<Baja apiUrl="https://api.test/api" />, { route: ruta, path: '/baja-de-mails' });
}

describe('Baja', () => {
  it('pregunta con el nombre de la página y da la baja con un clic, sin login', async () => {
    const llamadas = servidor({
      GET: { body: { pagina: 'La Sala', email: 'ana@example.com', de_baja: false } },
      POST: { body: { pagina: 'La Sala', email: 'ana@example.com', de_baja: true } },
    });
    montar();

    fireEvent.click(await screen.findByRole('button', { name: 'No quiero recibir más mails de La Sala' }));

    expect(await screen.findByRole('status')).toHaveTextContent('No vas a recibir más mails de La Sala en ana@example.com');
    const post = llamadas.find((l) => l.metodo === 'POST');
    expect(post.url).toBe('https://api.test/api/public/baja.php?t=abc.def');
  });

  it('al abrir el link no da la baja sola', async () => {
    const llamadas = servidor({ GET: { body: { pagina: 'La Sala', email: 'ana@example.com', de_baja: false } } });
    montar();

    await screen.findByRole('button', { name: /No quiero recibir/ });
    expect(llamadas.map((l) => l.metodo)).toEqual(['GET']);
  });

  it('si ya estaba de baja lo dice', async () => {
    servidor({ GET: { body: { pagina: 'La Sala', email: 'ana@example.com', de_baja: true } } });
    montar();

    expect(await screen.findByRole('status')).toHaveTextContent('La Sala');
  });

  it('un link inválido lo explica', async () => {
    servidor({ GET: { status: 404, body: { error: 'El link para darte de baja no es válido. Revisá que esté completo.' } } });
    montar();

    expect(await screen.findByRole('alert')).toHaveTextContent('no es válido');
  });

  it('sin token no pregunta nada al servidor', async () => {
    const llamadas = servidor({});
    montar('/baja-de-mails');

    expect(screen.getByRole('alert')).toHaveTextContent('no es válido');
    await waitFor(() => expect(llamadas).toHaveLength(0));
  });
});
