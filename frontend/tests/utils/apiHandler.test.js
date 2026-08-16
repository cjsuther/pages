import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleApiResponse, createAuthenticatedFetch } from '../../src/utils/apiHandler';
import { respuesta } from '../helpers/api';

describe('handleApiResponse', () => {
  let navigate;
  let logout;

  beforeEach(() => {
    navigate = vi.fn();
    logout = vi.fn();
  });

  it('devuelve la respuesta tal cual si es exitosa', async () => {
    const res = respuesta(200, { ok: true });

    await expect(handleApiResponse(res, navigate, logout)).resolves.toBe(res);
    expect(logout).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('cierra la sesión y redirige al home ante un 401', async () => {
    await expect(handleApiResponse(respuesta(401, {}), navigate, logout))
      .rejects.toThrow('Unauthorized');

    expect(logout).toHaveBeenCalledOnce();
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('cierra la sesión antes de redirigir', async () => {
    const orden = [];
    logout.mockImplementation(() => orden.push('logout'));
    navigate.mockImplementation(() => orden.push('navigate'));

    await handleApiResponse(respuesta(401, {}), navigate, logout).catch(() => {});

    expect(orden).toEqual(['logout', 'navigate']);
  });

  it.each([400, 403, 404, 409, 500])('no cierra sesión ante un %i', async (status) => {
    const res = respuesta(status, { error: 'x' });

    await expect(handleApiResponse(res, navigate, logout)).resolves.toBe(res);
    expect(logout).not.toHaveBeenCalled();
  });
});

describe('createAuthenticatedFetch', () => {
  let navigate;
  let logout;

  beforeEach(() => {
    navigate = vi.fn();
    logout = vi.fn();
    global.fetch = vi.fn(() => Promise.resolve(respuesta(200, { ok: true })));
  });

  it('agrega la cabecera Authorization', async () => {
    const authFetch = createAuthenticatedFetch('mi-token', navigate, logout);

    await authFetch('/api/pages');

    expect(global.fetch).toHaveBeenCalledWith('/api/pages', {
      headers: { Authorization: 'Bearer mi-token' },
    });
  });

  it('conserva las cabeceras propias de la llamada', async () => {
    const authFetch = createAuthenticatedFetch('mi-token', navigate, logout);

    await authFetch('/api/pages', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{}',
    });

    const [, opciones] = global.fetch.mock.calls[0];

    expect(opciones.method).toBe('POST');
    expect(opciones.body).toBe('{}');
    expect(opciones.headers).toEqual({
      'Content-Type': 'application/json',
      Authorization: 'Bearer mi-token',
    });
  });

  it('la cabecera Authorization gana sobre una enviada a mano', async () => {
    const authFetch = createAuthenticatedFetch('token-real', navigate, logout);

    await authFetch('/api/pages', { headers: { Authorization: 'Bearer viejo' } });

    const [, opciones] = global.fetch.mock.calls[0];

    expect(opciones.headers.Authorization).toBe('Bearer token-real');
  });

  it('funciona sin opciones', async () => {
    const authFetch = createAuthenticatedFetch('mi-token', navigate, logout);

    await expect(authFetch('/api/pages')).resolves.toBeDefined();
  });

  it('cierra la sesión si la API responde 401', async () => {
    global.fetch = vi.fn(() => Promise.resolve(respuesta(401, {})));
    const authFetch = createAuthenticatedFetch('token-vencido', navigate, logout);

    await expect(authFetch('/api/pages')).rejects.toThrow('Unauthorized');

    expect(logout).toHaveBeenCalledOnce();
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('propaga los errores de red', async () => {
    global.fetch = vi.fn(() => Promise.reject(new Error('sin conexión')));
    const authFetch = createAuthenticatedFetch('mi-token', navigate, logout);

    await expect(authFetch('/api/pages')).rejects.toThrow('sin conexión');
    expect(logout).not.toHaveBeenCalled();
  });

  it('incluye el token aunque sea null (la API responderá 401)', async () => {
    const authFetch = createAuthenticatedFetch(null, navigate, logout);

    await authFetch('/api/pages');

    const [, opciones] = global.fetch.mock.calls[0];
    expect(opciones.headers.Authorization).toBe('Bearer null');
  });
});
