import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Register from '../../src/pages/Register';
import { renderConProviders, crearAuth, API_URL } from '../helpers/render';
import { mockFetch, cuerpoDe } from '../helpers/api';

function espiarLocation() {
  const original = window.location;
  delete window.location;
  window.location = { href: '' };
  return () => { window.location = original; };
}

/** Completa el formulario de alta. */
function completar({ email = 'ana@test.local', password = 'secreto123', confirmar = 'secreto123' } = {}) {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } });
  fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: password } });
  fireEvent.change(screen.getByLabelText('Confirmar Contraseña'), { target: { value: confirmar } });
}

describe('Register', () => {
  let restaurarLocation;

  beforeEach(() => {
    window.gtag = vi.fn();
    restaurarLocation = espiarLocation();
  });

  afterEach(() => {
    restaurarLocation();
  });

  describe('pantalla', () => {
    it('muestra el título', () => {
      renderConProviders(<Register />);

      expect(screen.getByRole('heading', { name: 'Crear Cuenta' })).toBeInTheDocument();
    });

    it('ofrece Google y Apple', () => {
      renderConProviders(<Register />);

      expect(screen.getByRole('button', { name: /Registrarse con Google/ })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Registrarse con Apple/ })).toBeInTheDocument();
    });

    it('enlaza al login', () => {
      renderConProviders(<Register />);

      expect(screen.getByRole('link', { name: 'Inicia sesión' })).toHaveAttribute('href', '/login');
    });

    it('los tres campos son obligatorios', () => {
      const { container } = renderConProviders(<Register />);

      container.querySelectorAll('input').forEach((input) => {
        expect(input).toBeRequired();
      });
    });
  });

  describe('validaciones del formulario', () => {
    it('rechaza contraseñas que no coinciden', async () => {
      const { llamadas } = mockFetch({ 'auth/register.php': { token: 'x' } });
      renderConProviders(<Register />);

      completar({ password: 'secreto123', confirmar: 'otra-cosa' });
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      expect(await screen.findByText('Las contraseñas no coinciden')).toBeInTheDocument();
      expect(llamadas).toHaveLength(0);
    });

    it('rechaza contraseñas de menos de 6 caracteres', async () => {
      const { llamadas } = mockFetch({ 'auth/register.php': { token: 'x' } });
      renderConProviders(<Register />);

      completar({ password: '12345', confirmar: '12345' });
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      expect(await screen.findByText('La contraseña debe tener al menos 6 caracteres')).toBeInTheDocument();
      expect(llamadas).toHaveLength(0);
    });

    it('acepta una contraseña de exactamente 6', async () => {
      const { llamadas } = mockFetch({
        'auth/register.php': { token: 'tok', user: { id: 1 } },
      });
      renderConProviders(<Register />);

      completar({ password: '123456', confirmar: '123456' });
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      await waitFor(() => expect(llamadas).toHaveLength(1));
    });
  });

  describe('alta con email', () => {
    it('envía email y contraseña a la API', async () => {
      const { llamadas } = mockFetch({
        'auth/register.php': { token: 'tok', user: { id: 1 } },
      });
      renderConProviders(<Register />);

      completar({ email: 'nueva@test.local' });
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      await waitFor(() => {
        expect(llamadas[0].url).toBe(`${API_URL}/auth/register.php`);
        expect(llamadas[0].options.method).toBe('POST');
        expect(cuerpoDe(llamadas[0])).toEqual({
          email: 'nueva@test.local',
          password: 'secreto123',
        });
      });
    });

    it('no envía la confirmación de contraseña', async () => {
      const { llamadas } = mockFetch({ 'auth/register.php': { token: 'tok', user: {} } });
      renderConProviders(<Register />);

      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      await waitFor(() => {
        expect(cuerpoDe(llamadas[0])).not.toHaveProperty('confirmPassword');
      });
    });

    it('inicia sesión con la respuesta de la API', async () => {
      const auth = crearAuth();
      const usuario = { id: 15, email: 'nueva@test.local', name: null };
      mockFetch({ 'auth/register.php': { token: 'tok-nuevo', user: usuario } });

      renderConProviders(<Register />, { auth });

      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      await waitFor(() => {
        expect(auth.login).toHaveBeenCalledWith('tok-nuevo', usuario);
      });
    });

    it('registra el alta en analytics', async () => {
      mockFetch({ 'auth/register.php': { token: 'tok', user: {} } });
      renderConProviders(<Register />);

      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      await waitFor(() => {
        expect(window.gtag).toHaveBeenCalledWith('event', 'sign_up', { method: 'email' });
      });
    });

    it('muestra el error que devuelve la API', async () => {
      const auth = crearAuth();
      mockFetch({
        'auth/register.php': { status: 400, body: { error: 'Email already exists' } },
      });

      renderConProviders(<Register />, { auth });

      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      expect(await screen.findByText('Email already exists')).toBeInTheDocument();
      expect(auth.login).not.toHaveBeenCalled();
    });

    it('muestra un mensaje genérico si la API no explica el error', async () => {
      mockFetch({ 'auth/register.php': { status: 500, body: {} } });
      renderConProviders(<Register />);

      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      expect(await screen.findByText('Error al registrarse')).toBeInTheDocument();
    });

    it('muestra el error si falla la red', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin conexión')));
      renderConProviders(<Register />);

      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      expect(await screen.findByText('sin conexión')).toBeInTheDocument();
    });

    it('deshabilita el botón mientras envía', async () => {
      let resolver;
      global.fetch = vi.fn(() => new Promise((r) => { resolver = r; }));

      renderConProviders(<Register />);
      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      const boton = await screen.findByRole('button', { name: 'Cargando...' });
      expect(boton).toBeDisabled();

      resolver({ ok: true, status: 200, json: () => Promise.resolve({ token: 't', user: {} }) });
      await waitFor(() => expect(screen.getByRole('button', { name: 'Registrarse' })).toBeEnabled());
    });

    it('limpia el error anterior al reintentar', async () => {
      renderConProviders(<Register />);

      completar({ confirmar: 'distinta' });
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));
      await screen.findByText('Las contraseñas no coinciden');

      mockFetch({ 'auth/register.php': { token: 'tok', user: {} } });
      completar();
      fireEvent.click(screen.getByRole('button', { name: 'Registrarse' }));

      await waitFor(() => {
        expect(screen.queryByText('Las contraseñas no coinciden')).not.toBeInTheDocument();
      });
    });
  });

  describe('alta con proveedores', () => {
    it('Google redirige al endpoint de OAuth', () => {
      renderConProviders(<Register />);

      fireEvent.click(screen.getByRole('button', { name: /Registrarse con Google/ }));

      expect(window.location.href).toBe(`${API_URL}/auth/google-login.php`);
      expect(window.gtag).toHaveBeenCalledWith('event', 'register_attempt', { method: 'google' });
    });

    it('Apple redirige al endpoint de OAuth', () => {
      renderConProviders(<Register />);

      fireEvent.click(screen.getByRole('button', { name: /Registrarse con Apple/ }));

      expect(window.location.href).toBe(`${API_URL}/auth/apple-login.php`);
      expect(window.gtag).toHaveBeenCalledWith('event', 'register_attempt', { method: 'apple' });
    });
  });

  describe('vuelta del callback de OAuth', () => {
    const usuario = { id: 9, email: 'ana@test.local', name: 'Ana' };

    it('inicia sesión con los datos de la URL', async () => {
      const auth = crearAuth();
      const userParam = encodeURIComponent(JSON.stringify(usuario));

      renderConProviders(<Register />, { auth, route: `/register?token=abc&user=${userParam}` });

      await waitFor(() => {
        expect(auth.login).toHaveBeenCalledWith('abc', usuario);
        expect(window.gtag).toHaveBeenCalledWith('event', 'sign_up', { method: 'oauth' });
      });
    });

    it('avisa si los datos vienen corruptos', async () => {
      renderConProviders(<Register />, { route: '/register?token=abc&user=roto' });

      expect(await screen.findByText('Error al procesar el registro')).toBeInTheDocument();
    });

    it('muestra el error del proveedor', async () => {
      renderConProviders(<Register />, { route: '/register?error=access_denied' });

      expect(await screen.findByText('Error en autenticación: access_denied')).toBeInTheDocument();
    });
  });
});
