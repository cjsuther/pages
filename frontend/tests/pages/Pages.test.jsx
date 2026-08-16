import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import Pages from '../../src/pages/Pages';
import { renderAutenticado } from '../helpers/render';
import { mockFetch } from '../helpers/api';

describe('Pages', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
    // Los dos paneles hijos consultan la API al montar.
    mockFetch({
      'public/search.php': { results: [] },
      'pages/following.php': { following: [], total: 0 },
      'notifications/index.php': { notifications: [], unread_count: 0 },
      'admins/index.php': { invitations: [] },
      'collaborations/index.php': { pending: [] },
      'users/location.php': { latitude: null, longitude: null },
    });
  });

  it('muestra el título de la sección', () => {
    renderAutenticado(<Pages />);

    expect(screen.getByRole('heading', { name: 'PÁGINAS' })).toBeInTheDocument();
  });

  it('ofrece las dos solapas', () => {
    renderAutenticado(<Pages />);

    expect(screen.getByRole('button', { name: 'BUSCAR PÁGINAS' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'PÁGINAS QUE SIGO' })).toBeInTheDocument();
  });

  it('arranca en la solapa de búsqueda', () => {
    renderAutenticado(<Pages />);

    const buscar = screen.getByRole('button', { name: 'BUSCAR PÁGINAS' });
    expect(buscar.className).toContain('bg-white');
  });

  it('la solapa inactiva no está resaltada', () => {
    renderAutenticado(<Pages />);

    const siguiendo = screen.getByRole('button', { name: 'PÁGINAS QUE SIGO' });
    expect(siguiendo.className).not.toContain('bg-white');
  });

  it('cambia a la solapa de páginas seguidas', () => {
    renderAutenticado(<Pages />);

    fireEvent.click(screen.getByRole('button', { name: 'PÁGINAS QUE SIGO' }));

    expect(screen.getByRole('button', { name: 'PÁGINAS QUE SIGO' }).className).toContain('bg-white');
    expect(screen.getByRole('button', { name: 'BUSCAR PÁGINAS' }).className).not.toContain('bg-white');
  });

  it('vuelve a la solapa de búsqueda', () => {
    renderAutenticado(<Pages />);

    fireEvent.click(screen.getByRole('button', { name: 'PÁGINAS QUE SIGO' }));
    fireEvent.click(screen.getByRole('button', { name: 'BUSCAR PÁGINAS' }));

    expect(screen.getByRole('button', { name: 'BUSCAR PÁGINAS' }).className).toContain('bg-white');
  });

  it('incluye la navegación principal', () => {
    renderAutenticado(<Pages />);

    expect(screen.getAllByRole('link').length).toBeGreaterThan(0);
  });
});
