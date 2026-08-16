import React from 'react';
import { render } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { HelmetProvider } from 'react-helmet-async';
import { vi } from 'vitest';
import { AuthContext } from '../../src/App';

export const API_URL = 'http://localhost:8000/api';

/** Usuario de ejemplo con el shape que guarda AuthContext. */
export const usuarioDePrueba = (overrides = {}) => ({
  id: 9,
  email: 'ana@test.local',
  name: 'Ana',
  ...overrides,
});

/**
 * Valor de AuthContext con los callbacks espiados, para poder afirmar que un
 * componente llamó a login/logout.
 */
export function crearAuth({ token = null, user = null, ...overrides } = {}) {
  return {
    token,
    user,
    apiUrl: API_URL,
    login: vi.fn(),
    logout: vi.fn(),
    updateUser: vi.fn(),
    ...overrides,
  };
}

/**
 * Renderiza un componente con los mismos providers que App.jsx.
 *
 * @param ui                 elemento a renderizar
 * @param options.auth       valor de AuthContext (usar crearAuth())
 * @param options.route      ruta inicial
 * @param options.path       patrón de ruta, si el componente lee useParams()
 */
export function renderConProviders(ui, { auth = crearAuth(), route = '/', path = null, ...options } = {}) {
  const contenido = path
    ? <Routes><Route path={path} element={ui} /></Routes>
    : ui;

  const resultado = render(
    <HelmetProvider>
      <AuthContext.Provider value={auth}>
        <MemoryRouter
          initialEntries={[route]}
          future={{ v7_startTransition: true, v7_relativeSplatPath: true }}
        >
          {contenido}
        </MemoryRouter>
      </AuthContext.Provider>
    </HelmetProvider>,
    options
  );

  return { ...resultado, auth };
}

/** Renderiza con una sesión iniciada. */
export function renderAutenticado(ui, opciones = {}) {
  return renderConProviders(ui, {
    auth: crearAuth({ token: 'token-de-prueba', user: usuarioDePrueba() }),
    ...opciones,
  });
}
