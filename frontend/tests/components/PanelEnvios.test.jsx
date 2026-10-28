import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import PanelEnvios from '../../src/components/PanelEnvios';

const ENVIO = {
  id: 9, mensaje_nombre: 'Nueva fecha', asunto: '¡Volvemos!', estado: 'en_curso', motivo_pausa: null,
  total: 120, enviados: 40, fallidos: 2, pendientes: 78, omitidos: 0,
  creado_en: '2026-10-22 10:05:00', terminado_en: null,
};

function servidor(respuestas) {
  const llamadas = [];
  global.fetch = vi.fn((url, opciones = {}) => {
    const metodo = opciones.method || 'GET';
    llamadas.push({ url, metodo, cuerpo: opciones.body ? JSON.parse(opciones.body) : null });
    const clave = Object.keys(respuestas).find((k) => {
      const [m, fragmento] = k.split(' ');
      return m === metodo && url.includes(fragmento);
    });
    if (!clave) return Promise.reject(new Error(`fetch no declarado: ${metodo} ${url}`));
    const { status = 200, body } = respuestas[clave];
    return Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
  });
  return llamadas;
}

async function montar(respuestas) {
  const llamadas = servidor(respuestas);
  renderConProviders(<PanelEnvios pageId={5} apiUrl="https://api.test/api" token="tok" />);
  await waitFor(() => expect(screen.queryByText('Buscando envíos…')).not.toBeInTheDocument());
  return llamadas;
}

describe('PanelEnvios', () => {
  beforeEach(() => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('lista los envíos con fecha, mensaje, números y estado', async () => {
    await montar({ 'GET envios.php': { body: { envios: [ENVIO] } } });

    expect(screen.getByText('Nueva fecha')).toBeInTheDocument();
    expect(screen.getByText(/22\/10\/2026 10:05/)).toBeInTheDocument();
    expect(screen.getByText('En curso')).toBeInTheDocument();
    expect(screen.getByText('120 en total')).toBeInTheDocument();
    expect(screen.getByText('40 enviados')).toBeInTheDocument();
    expect(screen.getByText('2 fallidos')).toBeInTheDocument();
    expect(screen.getByText('78 pendientes')).toBeInTheDocument();
  });

  it('sin envíos lo dice', async () => {
    await montar({ 'GET envios.php': { body: { envios: [] } } });

    expect(screen.getByText('Todavía no mandaste ningún email')).toBeInTheDocument();
  });

  it('pausa un envío en curso', async () => {
    const llamadas = await montar({
      'GET envios.php': { body: { envios: [ENVIO] } },
      'PUT envios.php': { body: { envio: { ...ENVIO, estado: 'pausado' } } },
    });

    fireEvent.click(screen.getByRole('button', { name: /Pausar/ }));

    expect(await screen.findByText('Pausado')).toBeInTheDocument();
    expect(llamadas.find((l) => l.metodo === 'PUT').cuerpo).toEqual({ page_id: 5, id: 9, accion: 'pausar' });
    expect(screen.getByRole('button', { name: /Reanudar/ })).toBeInTheDocument();
  });

  it('un envío pausado por el sistema explica por qué y se puede reanudar', async () => {
    const llamadas = await montar({
      'GET envios.php': { body: { envios: [{ ...ENVIO, estado: 'pausado', motivo_pausa: 'El servidor rechazó el usuario o la contraseña.' }] } },
      'PUT envios.php': { body: { envio: ENVIO } },
    });

    expect(screen.getByText('El servidor rechazó el usuario o la contraseña.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Reanudar/ }));

    expect(await screen.findByText('En curso')).toBeInTheDocument();
    expect(llamadas.find((l) => l.metodo === 'PUT').cuerpo.accion).toBe('reanudar');
  });

  it('cancelar pide confirmación', async () => {
    window.confirm.mockReturnValue(false);
    const llamadas = await montar({ 'GET envios.php': { body: { envios: [ENVIO] } } });

    fireEvent.click(screen.getByRole('button', { name: /Cancelar/ }));

    expect(window.confirm).toHaveBeenCalled();
    expect(llamadas.some((l) => l.metodo === 'PUT')).toBe(false);
  });

  it('un envío terminado no tiene acciones', async () => {
    await montar({ 'GET envios.php': { body: { envios: [{ ...ENVIO, estado: 'terminado', fallidos: 0, pendientes: 0 }] } } });

    expect(screen.getByText('Terminado')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Pausar|Reanudar|Cancelar/ })).not.toBeInTheDocument();
  });

  it('si el servidor no deja cambiar el envío, lo explica', async () => {
    await montar({
      'GET envios.php': { body: { envios: [ENVIO] } },
      'PUT envios.php': { status: 409, body: { error: 'Sólo se puede pausar un envío en curso' } },
    });

    fireEvent.click(screen.getByRole('button', { name: /Pausar/ }));

    expect(await screen.findByText('Sólo se puede pausar un envío en curso')).toBeInTheDocument();
    expect(screen.getByText('En curso')).toBeInTheDocument();
  });

  it('muestra el error al traer los envíos', async () => {
    await montar({ 'GET envios.php': { status: 403, body: { error: 'No podés escribirles a los clientes de esta página' } } });

    expect(screen.getByText('No podés escribirles a los clientes de esta página')).toBeInTheDocument();
  });

  it('"Actualizar" vuelve a pedir la lista', async () => {
    const llamadas = await montar({ 'GET envios.php': { body: { envios: [ENVIO] } } });

    fireEvent.click(screen.getByRole('button', { name: /Actualizar/ }));

    await waitFor(() => expect(llamadas.filter((l) => l.metodo === 'GET')).toHaveLength(2));
  });

  it('el detalle se oculta con el mismo botón', async () => {
    await montar({
      'GET id=9': { body: { envio: ENVIO, problemas: [{ email: 'x@example.com', estado: 'omitido', error: null }] } },
      'GET envios.php': { body: { envios: [ENVIO] } },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Ver fallidos' }));
    expect(await screen.findByText('Omitido')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Ocultar detalle' }));
    expect(screen.queryByRole('list', { name: 'Destinatarios con problemas' })).not.toBeInTheDocument();
  });

  it('el detalle muestra quiénes fallaron y por qué', async () => {
    const llamadas = await montar({
      'GET id=9': { body: { envio: ENVIO, problemas: [{ email: 'x@example.com', estado: 'fallido', error: 'Buzón lleno' }] } },
      'GET envios.php': { body: { envios: [ENVIO] } },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Ver fallidos' }));

    const lista = await screen.findByRole('list', { name: 'Destinatarios con problemas' });
    expect(within(lista).getByText('x@example.com')).toBeInTheDocument();
    expect(within(lista).getByText('Buzón lleno')).toBeInTheDocument();
    expect(llamadas.some((l) => l.url.includes('envios.php?page_id=5&id=9'))).toBe(true);
  });
});
