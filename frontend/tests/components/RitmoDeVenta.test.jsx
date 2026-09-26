import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import RitmoDeVenta from '../../src/components/RitmoDeVenta';

/** 30 días terminando hoy, con las ventas que se pasen por índice. */
function dias(ventasPorIndice = {}) {
  return Array.from({ length: 30 }, (unused, i) => ({
    dia: `2026-09-${String(i + 1).padStart(2, '0')}`,
    vendidas: ventasPorIndice[i] || 0,
  }));
}

describe('RitmoDeVenta', () => {
  /**
   * Con tres días sueltos y sin los días vacíos, el gráfico eran tres
   * cuadrados pegados: parecían días seguidos y no decía nada.
   */
  it('dibuja los 30 días, también los que no vendieron', () => {
    render(<RitmoDeVenta dias={dias({ 0: 12, 11: 3, 26: 5 })} color="#6FBE44" />);

    expect(screen.getAllByRole('button')).toHaveLength(30);
    expect(screen.getByLabelText('3 sept: 0 entradas')).toBeInTheDocument();
  });

  /** El alto de una columna no dice cuánto es: el total y el pico van escritos. */
  it('escribe el total y el máximo', () => {
    render(<RitmoDeVenta dias={dias({ 0: 12, 11: 3 })} color="#6FBE44" />);

    expect(screen.getByText('15 entradas en 30 días')).toBeInTheDocument();
    expect(screen.getByText('máximo 12 por día')).toBeInTheDocument();
  });

  it('muestra las fechas de los extremos', () => {
    render(<RitmoDeVenta dias={dias({ 5: 1 })} color="#6FBE44" />);

    expect(screen.getByText('1 sept')).toBeInTheDocument();
    expect(screen.getByText('hoy')).toBeInTheDocument();
  });

  it('al apuntar un día muestra su fecha y su número', () => {
    render(<RitmoDeVenta dias={dias({ 4: 7 })} color="#6FBE44" />);

    fireEvent.mouseEnter(screen.getByLabelText('5 sept: 7 entradas'));

    expect(screen.getByRole('status')).toHaveTextContent('5 sept: 7');
  });

  it('el teclado muestra lo mismo que el mouse', () => {
    render(<RitmoDeVenta dias={dias({ 4: 7 })} color="#6FBE44" />);

    fireEvent.focus(screen.getByLabelText('5 sept: 7 entradas'));

    expect(screen.getByRole('status')).toHaveTextContent('5 sept: 7');
  });

  /** Un gráfico entero en cero es ruido: mejor decirlo con palabras. */
  it('sin ventas no dibuja nada y lo dice', () => {
    render(<RitmoDeVenta dias={dias()} color="#6FBE44" />);

    expect(screen.getByText('Sin ventas en los últimos 30 días.')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
