import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import RezonarBadge from '../../src/components/RezonarBadge';

describe('RezonarBadge', () => {
  it('enlaza al inicio', () => {
    render(<RezonarBadge />);

    expect(screen.getByRole('link')).toHaveAttribute('href', '/');
  });

  it('muestra el logo con texto alternativo', () => {
    render(<RezonarBadge />);

    const logo = screen.getByAltText('Rezonar');
    expect(logo).toHaveAttribute('src', '/logo.png');
  });

  it('tiene título accesible', () => {
    render(<RezonarBadge />);

    expect(screen.getByTitle('Ir a Rezonar')).toBeInTheDocument();
  });

  it('queda fijo por encima del contenido', () => {
    render(<RezonarBadge />);

    expect(screen.getByRole('link').className).toContain('fixed');
  });
});
