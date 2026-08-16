import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Navigation from '../../src/components/Navigation';
import { renderConProviders, crearAuth, usuarioDePrueba, API_URL } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA, tokenDe } from '../helpers/api';

const autenticado = (user = usuarioDePrueba()) => crearAuth({ token: 'tok-123', user });

/** Respuestas de los widgets que Navigation monta cuando hay sesión. */
function mockearWidgets(extra = {}) {
  return mockFetch({
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'admins/index.php': { invitations: [] },
    'collaborations/index.php': { pending: [] },
    'users/location.php': { latitude: null, longitude: null, location_name: null },
    ...extra,
  });
}

describe('Navigation', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    mockearWidgets();
  });

  describe('sin sesión', () => {
    it('ofrece iniciar sesión', () => {
      renderConProviders(<Navigation />);

      expect(screen.getByRole('link', { name: 'Iniciar Sesión / Registrarse' })).toHaveAttribute(
        'href',
        '/login'
      );
    });

    it('no muestra los enlaces privados', () => {
      renderConProviders(<Navigation />);

      expect(screen.queryByRole('link', { name: 'MIS PÁGINAS' })).not.toBeInTheDocument();
      expect(screen.queryByRole('link', { name: 'PÁGINAS' })).not.toBeInTheDocument();
    });

    it('no muestra el botón de menú móvil', () => {
      const { container } = renderConProviders(<Navigation />);

      expect(container.querySelector('button.md\\:hidden')).toBeNull();
    });

    it('el logo lleva al inicio', () => {
      renderConProviders(<Navigation />);

      expect(screen.getByAltText('Rezonar').closest('a')).toHaveAttribute('href', '/');
    });
  });

  describe('con sesión', () => {
    it('muestra los enlaces de navegación', () => {
      renderConProviders(<Navigation />, { auth: autenticado() });

      expect(screen.getByRole('link', { name: 'INICIO' })).toHaveAttribute('href', '/');
      expect(screen.getByRole('link', { name: 'PÁGINAS' })).toHaveAttribute('href', '/pages');
      expect(screen.getByRole('link', { name: 'MIS PÁGINAS' })).toHaveAttribute('href', '/my-pages');
    });

    it('no ofrece iniciar sesión', () => {
      renderConProviders(<Navigation />, { auth: autenticado() });

      expect(screen.queryByText('Iniciar Sesión / Registrarse')).not.toBeInTheDocument();
    });

    it('resalta la sección activa', () => {
      renderConProviders(<Navigation />, { auth: autenticado(), route: '/pages' });

      expect(screen.getByRole('link', { name: 'PÁGINAS' }).className).toContain('text-white');
      expect(screen.getByRole('link', { name: 'MIS PÁGINAS' }).className).toContain('text-gray-400');
    });

    it('resalta el inicio en la raíz', () => {
      renderConProviders(<Navigation />, { auth: autenticado(), route: '/' });

      expect(screen.getByRole('link', { name: 'INICIO' }).className).toContain('text-white');
    });
  });

  describe('menú móvil', () => {
    it('empieza cerrado', () => {
      renderConProviders(<Navigation />, { auth: autenticado() });

      // Cerrado: cada enlace aparece una sola vez (el de escritorio).
      expect(screen.getAllByRole('link', { name: 'INICIO' })).toHaveLength(1);
    });

    it('se abre y duplica los enlaces', () => {
      const { container } = renderConProviders(<Navigation />, { auth: autenticado() });

      fireEvent.click(container.querySelector('button.md\\:hidden'));

      expect(screen.getAllByRole('link', { name: 'INICIO' })).toHaveLength(2);
    });

    it('se cierra al elegir un enlace', () => {
      const { container } = renderConProviders(<Navigation />, { auth: autenticado() });

      fireEvent.click(container.querySelector('button.md\\:hidden'));
      fireEvent.click(screen.getAllByRole('link', { name: 'PÁGINAS' })[1]);

      expect(screen.getAllByRole('link', { name: 'PÁGINAS' })).toHaveLength(1);
    });

    it('se cierra con el botón', () => {
      const { container } = renderConProviders(<Navigation />, { auth: autenticado() });
      const boton = container.querySelector('button.md\\:hidden');

      fireEvent.click(boton);
      fireEvent.click(boton);

      expect(screen.getAllByRole('link', { name: 'INICIO' })).toHaveLength(1);
    });
  });

  describe('menú de perfil', () => {
    function abrirPerfil(user = usuarioDePrueba()) {
      const auth = autenticado(user);
      const resultado = renderConProviders(<Navigation />, { auth });
      fireEvent.click(screen.getByText(user.name || user.email));
      return { ...resultado, auth };
    }

    it('muestra el nombre del usuario en la barra', () => {
      renderConProviders(<Navigation />, { auth: autenticado(usuarioDePrueba({ name: 'Ana' })) });

      expect(screen.getByText('Ana')).toBeInTheDocument();
    });

    it('usa el email si no hay nombre', () => {
      renderConProviders(<Navigation />, {
        auth: autenticado(usuarioDePrueba({ name: null, email: 'ana@test.local' })),
      });

      expect(screen.getByText('ana@test.local')).toBeInTheDocument();
    });

    it('al abrirse muestra el email y el nombre editable', () => {
      abrirPerfil(usuarioDePrueba({ name: 'Ana', email: 'ana@test.local' }));

      expect(screen.getByPlaceholderText('Tu nombre')).toHaveValue('Ana');
      expect(screen.getByText('ana@test.local')).toBeInTheDocument();
    });

    it('guarda el nombre recortado', async () => {
      const { llamadas } = mockearWidgets({
        'users/profile.php': { success: true, user: { id: 9, name: 'Ana María' } },
      });
      abrirPerfil();

      fireEvent.change(screen.getByPlaceholderText('Tu nombre'), {
        target: { value: '  Ana María  ' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      await waitFor(() => {
        const put = llamadaA(llamadas, 'users/profile.php');
        expect(put.options.method).toBe('PUT');
        expect(cuerpoDe(put)).toEqual({ name: 'Ana María' });
        expect(tokenDe(put)).toBe('Bearer tok-123');
      });
    });

    it('actualiza el usuario en el contexto', async () => {
      mockearWidgets({ 'users/profile.php': { success: true, user: { name: 'Ana María' } } });
      const { auth } = abrirPerfil();

      fireEvent.change(screen.getByPlaceholderText('Tu nombre'), { target: { value: 'Ana María' } });
      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      await waitFor(() => {
        expect(auth.updateUser).toHaveBeenCalledWith(
          expect.objectContaining({ name: 'Ana María' })
        );
      });
    });

    it('confirma visualmente que guardó', async () => {
      mockearWidgets({ 'users/profile.php': { success: true, user: { name: 'Ana' } } });
      abrirPerfil();

      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      expect(await screen.findByRole('button', { name: /Guardado/ })).toBeInTheDocument();
    });

    it('guarda al presionar Enter', async () => {
      const { llamadas } = mockearWidgets({
        'users/profile.php': { success: true, user: { name: 'Ana' } },
      });
      abrirPerfil();

      fireEvent.keyDown(screen.getByPlaceholderText('Tu nombre'), { key: 'Enter' });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'users/profile.php')).not.toBeNull();
      });
    });

    it('no guarda con otras teclas', async () => {
      const { llamadas } = mockearWidgets({
        'users/profile.php': { success: true, user: { name: 'Ana' } },
      });
      abrirPerfil();

      fireEvent.keyDown(screen.getByPlaceholderText('Tu nombre'), { key: 'a' });

      await waitFor(() => {
        expect(llamadaA(llamadas, 'users/profile.php')).toBeNull();
      });
    });

    it('no actualiza el contexto si la API no confirma', async () => {
      mockearWidgets({ 'users/profile.php': { success: false } });
      const { auth } = abrirPerfil();

      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar' })).toBeEnabled();
      });
      expect(auth.updateUser).not.toHaveBeenCalled();
    });

    it('no rompe si falla la red al guardar', async () => {
      abrirPerfil();
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

      await waitFor(() => {
        expect(console.error).toHaveBeenCalled();
      });
    });

    it('cierra la sesión', () => {
      const { auth } = abrirPerfil();

      fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));

      expect(auth.logout).toHaveBeenCalledOnce();
    });

    it('se cierra al hacer click fuera', async () => {
      abrirPerfil();

      expect(screen.getByPlaceholderText('Tu nombre')).toBeInTheDocument();

      fireEvent.mouseDown(document.body);

      await waitFor(() => {
        expect(screen.queryByPlaceholderText('Tu nombre')).not.toBeInTheDocument();
      });
    });

    it('no se cierra al hacer click dentro', () => {
      abrirPerfil();

      fireEvent.mouseDown(screen.getByPlaceholderText('Tu nombre'));

      expect(screen.getByPlaceholderText('Tu nombre')).toBeInTheDocument();
    });
  });
});
