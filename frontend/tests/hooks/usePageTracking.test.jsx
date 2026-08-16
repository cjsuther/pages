import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { MemoryRouter, Routes, Route, useNavigate } from 'react-router-dom';
import { usePageTracking } from '../../src/hooks/usePageTracking';

function Sonda() {
  usePageTracking();
  return <div>sonda</div>;
}

function ConNavegacion() {
  usePageTracking();
  const navigate = useNavigate();
  return <button onClick={() => navigate('/otra?x=1')}>ir</button>;
}

describe('usePageTracking', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
    document.title = 'Título de prueba';
  });

  it('registra la vista al montar', () => {
    render(
      <MemoryRouter initialEntries={['/mi-ruta']}>
        <Sonda />
      </MemoryRouter>
    );

    expect(window.gtag).toHaveBeenCalledWith(
      'config',
      expect.any(String),
      expect.objectContaining({ page_path: '/mi-ruta', page_title: 'Título de prueba' })
    );
  });

  it('incluye el query string en la ruta registrada', () => {
    render(
      <MemoryRouter initialEntries={['/buscar?q=rock']}>
        <Sonda />
      </MemoryRouter>
    );

    expect(window.gtag).toHaveBeenCalledWith(
      'config',
      expect.any(String),
      expect.objectContaining({ page_path: '/buscar?q=rock' })
    );
  });

  it('registra una vista nueva al navegar', async () => {
    const { getByText } = render(
      <MemoryRouter initialEntries={['/inicio']}>
        <Routes>
          <Route path="*" element={<ConNavegacion />} />
        </Routes>
      </MemoryRouter>
    );

    expect(window.gtag).toHaveBeenCalledTimes(1);

    fireEvent.click(getByText('ir'));

    await vi.waitFor(() => {
      expect(window.gtag).toHaveBeenCalledTimes(2);
    });

    expect(window.gtag).toHaveBeenLastCalledWith(
      'config',
      expect.any(String),
      expect.objectContaining({ page_path: '/otra?x=1' })
    );
  });

  it('no rompe si gtag no está cargado', () => {
    window.gtag = undefined;

    expect(() =>
      render(
        <MemoryRouter initialEntries={['/x']}>
          <Sonda />
        </MemoryRouter>
      )
    ).not.toThrow();
  });
});
