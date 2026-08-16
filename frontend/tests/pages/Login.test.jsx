import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Login from '../../src/pages/Login';
import { renderConProviders, crearAuth, API_URL } from '../helpers/render';

/** Reemplaza window.location por un objeto observable. */
function espiarLocation() {
  const original = window.location;
  delete window.location;
  window.location = { href: '' };
  return () => { window.location = original; };
}

describe('Login', () => {
  let restaurarLocation;

  beforeEach(() => {
    window.gtag = vi.fn();
    restaurarLocation = espiarLocation();
  });

  afterEach(() => {
    restaurarLocation();
  });

  describe('pantalla', () => {
    it('muestra el título de bienvenida', () => {
      renderConProviders(<Login />);

      expect(screen.getByRole('heading', { name: 'BIENVENIDO' })).toBeInTheDocument();
    });

    it('ofrece continuar con Google', () => {
      renderConProviders(<Login />);

      expect(screen.getByRole('button', { name: /CONTINUAR CON GOOGLE/ })).toBeInTheDocument();
    });

    it('el logo lleva al inicio', () => {
      renderConProviders(<Login />);

      const enlaces = screen.getAllByRole('link');
      expect(enlaces[0]).toHaveAttribute('href', '/');
    });

    it('no muestra ningún error de entrada', () => {
      renderConProviders(<Login />);

      expect(screen.queryByText(/Error/)).not.toBeInTheDocument();
    });
  });

  describe('login con Google', () => {
    it('redirige al endpoint de OAuth', () => {
      renderConProviders(<Login />);

      fireEvent.click(screen.getByRole('button', { name: /CONTINUAR CON GOOGLE/ }));

      expect(window.location.href).toBe(`${API_URL}/auth/google-login.php`);
    });

    it('registra el intento en analytics', () => {
      renderConProviders(<Login />);

      fireEvent.click(screen.getByRole('button', { name: /CONTINUAR CON GOOGLE/ }));

      expect(window.gtag).toHaveBeenCalledWith('event', 'login_attempt', { method: 'google' });
    });
  });

  describe('vuelta del callback de OAuth', () => {
    const usuario = { id: 9, email: 'ana@test.local', name: 'Ana' };

    it('inicia sesión con el token y el usuario de la URL', async () => {
      const auth = crearAuth();
      const userParam = encodeURIComponent(JSON.stringify(usuario));

      renderConProviders(<Login />, {
        auth,
        route: `/login?token=abc123&user=${userParam}`,
      });

      await waitFor(() => {
        expect(auth.login).toHaveBeenCalledWith('abc123', usuario);
      });
    });

    it('registra el login por OAuth en analytics', async () => {
      const userParam = encodeURIComponent(JSON.stringify(usuario));

      renderConProviders(<Login />, { route: `/login?token=abc&user=${userParam}` });

      await waitFor(() => {
        expect(window.gtag).toHaveBeenCalledWith('event', 'login', { method: 'oauth' });
      });
    });

    it('avisa si el usuario de la URL no se puede leer', async () => {
      const auth = crearAuth();

      renderConProviders(<Login />, { auth, route: '/login?token=abc&user=esto-no-es-json' });

      expect(await screen.findByText('Error al procesar el inicio de sesión')).toBeInTheDocument();
      expect(auth.login).not.toHaveBeenCalled();
    });

    it('muestra el error que devuelve el proveedor', async () => {
      renderConProviders(<Login />, { route: '/login?error=access_denied' });

      expect(await screen.findByText('Error en autenticación: access_denied')).toBeInTheDocument();
    });

    it('no inicia sesión si falta el usuario', async () => {
      const auth = crearAuth();

      renderConProviders(<Login />, { auth, route: '/login?token=abc' });

      await waitFor(() => {
        expect(auth.login).not.toHaveBeenCalled();
      });
    });

    it('no inicia sesión si falta el token', async () => {
      const auth = crearAuth();
      const userParam = encodeURIComponent(JSON.stringify(usuario));

      renderConProviders(<Login />, { auth, route: `/login?user=${userParam}` });

      await waitFor(() => {
        expect(auth.login).not.toHaveBeenCalled();
      });
    });
  });
});
