import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import LoadingSpinner from '../../src/components/LoadingSpinner';

describe('LoadingSpinner', () => {
  it('muestra el mensaje por defecto', () => {
    render(<LoadingSpinner />);

    expect(screen.getByText('Cargando...')).toBeInTheDocument();
  });

  it('acepta un mensaje propio', () => {
    render(<LoadingSpinner message="Guardando cambios" />);

    expect(screen.getByText('Guardando cambios')).toBeInTheDocument();
    expect(screen.queryByText('Cargando...')).not.toBeInTheDocument();
  });

  it('omite el texto si el mensaje es vacío', () => {
    const { container } = render(<LoadingSpinner message="" />);

    expect(container.querySelector('p')).toBeNull();
  });

  it('siempre renderiza el indicador giratorio', () => {
    const { container } = render(<LoadingSpinner message="" />);

    expect(container.querySelector('.animate-spin')).toBeInTheDocument();
  });

  it.each([
    ['sm', 'w-4'],
    ['md', 'w-8'],
    ['lg', 'w-12'],
  ])('el tamaño %s aplica su clase', (size, claseEsperada) => {
    const { container } = render(<LoadingSpinner size={size} />);

    expect(container.querySelector('.animate-spin').className).toContain(claseEsperada);
  });

  it('usa el tamaño mediano por defecto', () => {
    const { container } = render(<LoadingSpinner />);

    expect(container.querySelector('.animate-spin').className).toContain('w-8');
  });
});
