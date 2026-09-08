import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import Settings from '../../src/pages/Settings';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch } from '../helpers/api';

describe('Settings', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
    mockFetch({
      'users/location.php': { latitude: null, longitude: null, location_name: null },
      'public/search.php': { results: [] },
      'pages/following.php': { following: [], total: 0 },
    });
  });

  describe('sin sesión', () => {
    it('deniega el acceso', () => {
      renderConProviders(<Settings />);

      expect(screen.getByRole('heading', { name: 'Acceso Denegado' })).toBeInTheDocument();
    });

    it('ofrece iniciar sesión', () => {
      renderConProviders(<Settings />);

      expect(screen.getByRole('link', { name: 'Iniciar Sesión' })).toHaveAttribute('href', '/login');
    });

    it('no muestra las solapas', () => {
      renderConProviders(<Settings />);

      expect(screen.queryByRole('button', { name: 'Mi ubicación' })).not.toBeInTheDocument();
    });
  });

  describe('con sesión', () => {
    const auth = () => crearAuth({ token: 'tok', user: usuarioDePrueba({ email: 'ana@test.local' }) });

    it('muestra el título del perfil', () => {
      renderConProviders(<Settings />, { auth: auth() });

      expect(screen.getByRole('heading', { name: 'Perfil' })).toBeInTheDocument();
    });

    it('muestra el email del usuario', () => {
      renderConProviders(<Settings />, { auth: auth() });

      expect(screen.getByText('ana@test.local')).toBeInTheDocument();
    });

    it('ofrece las tres solapas', () => {
      renderConProviders(<Settings />, { auth: auth() });

      expect(screen.getByRole('button', { name: 'Mi ubicación' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Buscar páginas' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Páginas que sigo' })).toBeInTheDocument();
    });

    it('arranca en la solapa de ubicación', () => {
      renderConProviders(<Settings />, { auth: auth() });

      expect(screen.getByRole('button', { name: 'Mi ubicación' }).className).toContain('bg-verde');
    });

    it('cambia de solapa', () => {
      renderConProviders(<Settings />, { auth: auth() });

      fireEvent.click(screen.getByRole('button', { name: 'Páginas que sigo' }));

      expect(screen.getByRole('button', { name: 'Páginas que sigo' }).className).toContain('bg-verde');
      expect(screen.getByRole('button', { name: 'Mi ubicación' }).className).not.toContain('bg-verde');
    });

    it('cierra sesión desde el botón de salir', () => {
      const contexto = auth();
      renderConProviders(<Settings />, { auth: contexto });

      fireEvent.click(screen.getByRole('button', { name: 'Salir de la Cuenta' }));

      expect(contexto.logout).toHaveBeenCalledOnce();
    });

    it('enlaza al dashboard', () => {
      renderConProviders(<Settings />, { auth: auth() });

      expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('href', '/dashboard');
    });

    it('el logo lleva al inicio', () => {
      renderConProviders(<Settings />, { auth: auth() });

      const alInicio = screen.getAllByRole('link').filter((a) => a.getAttribute('href') === '/');
      expect(alInicio.length).toBeGreaterThan(0);
    });

    it('funciona si el usuario no tiene email cargado', () => {
      const contexto = crearAuth({ token: 'tok', user: null });

      expect(() => renderConProviders(<Settings />, { auth: contexto })).not.toThrow();
    });
  });
});
