import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import Pages from '../../src/pages/Pages';
import { renderAutenticado } from '../helpers/render';
import { mockFetch } from '../helpers/api';

/**
 * La pantalla tiene tres etapas de lo mismo: encontrar a quién seguir,
 * administrar lo que ya seguís y configurar los avisos. Antes eran dos solapas
 * con la activación de notificaciones colgada arriba de todo.
 */
describe('Pages', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
    // Los paneles hijos consultan la API al montar.
    mockFetch({
      'public/search.php': { results: [] },
      'public/recent-pages.php': { pages: [] },
      'pages/following.php': { following: [], total: 0 },
      'notifications/index.php': { notifications: [], unread_count: 0 },
      'admins/index.php': { invitations: [] },
      'collaborations/index.php': { pending: [] },
      'users/location.php': { latitude: null, longitude: null },
    });
  });

  const solapa = (nombre) => screen.getByRole('button', { name: new RegExp(`^${nombre}`) });

  it('explica para qué sirve seguir una página', () => {
    renderAutenticado(<Pages />);

    expect(
      screen.getByRole('heading', { name: 'Seguí páginas y elegí de qué te enterás' })
    ).toBeInTheDocument();
  });

  it('ofrece las tres solapas', () => {
    renderAutenticado(<Pages />);

    expect(solapa('Descubrir')).toBeInTheDocument();
    expect(solapa('Las que sigo')).toBeInTheDocument();
    expect(solapa('Mis alertas')).toBeInTheDocument();
  });

  it('arranca en la de descubrir', () => {
    renderAutenticado(<Pages />);

    expect(solapa('Descubrir').className).toContain('bg-verde');
  });

  it('la solapa inactiva no está resaltada', () => {
    renderAutenticado(<Pages />);

    expect(solapa('Las que sigo').className).not.toContain('bg-verde');
  });

  it('cambia a las páginas que sigo', () => {
    renderAutenticado(<Pages />);

    fireEvent.click(solapa('Las que sigo'));

    expect(solapa('Las que sigo').className).toContain('bg-verde');
    expect(solapa('Descubrir').className).not.toContain('bg-verde');
  });

  it('cambia a las alertas', () => {
    renderAutenticado(<Pages />);

    fireEvent.click(solapa('Mis alertas'));

    expect(solapa('Mis alertas').className).toContain('bg-verde');
  });

  it('vuelve a descubrir', () => {
    renderAutenticado(<Pages />);

    fireEvent.click(solapa('Las que sigo'));
    fireEvent.click(solapa('Descubrir'));

    expect(solapa('Descubrir').className).toContain('bg-verde');
  });

  it('incluye la navegación principal', () => {
    renderAutenticado(<Pages />);

    expect(screen.getAllByRole('link').length).toBeGreaterThan(0);
  });
});
