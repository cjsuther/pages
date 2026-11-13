import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import ColaboracionesAutomaticas from '../../src/components/ColaboracionesAutomaticas';

const regla = (overrides = {}) => ({
  id: 4,
  requester_page_id: 5,
  requester_page_title: 'La Sala',
  requester_page_image: null,
  group_title: 'Agenda',
  ...overrides,
});

describe('ColaboracionesAutomaticas', () => {
  it('sin reglas explica cómo se crean', () => {
    render(<ColaboracionesAutomaticas reglas={[]} onDejar={vi.fn()} />);

    expect(screen.getByRole('heading', { name: 'Colaboraciones que acepto automáticamente' })).toBeInTheDocument();
    expect(screen.getByText(/Todavía no aceptás automáticamente ninguna/)).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('lista cada página y el grupo donde se publica', () => {
    render(
      <ColaboracionesAutomaticas
        reglas={[regla(), regla({ id: 6, requester_page_title: 'El Club', group_title: 'Ciclo' })]}
        onDejar={vi.fn()}
      />
    );

    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getByText('La Sala')).toBeInTheDocument();
    expect(screen.getByText('Agenda')).toBeInTheDocument();
    expect(screen.getByText('El Club')).toBeInTheDocument();
    expect(screen.getByText('Ciclo')).toBeInTheDocument();
  });

  it('aclara que dejar de aceptar no deshace las ya aceptadas', () => {
    render(<ColaboracionesAutomaticas reglas={[regla()]} onDejar={vi.fn()} />);

    expect(screen.getByText(/las colaboraciones que ya aceptaste\s+siguen como están/)).toBeInTheDocument();
  });

  it('deja de aceptar la regla elegida', () => {
    const onDejar = vi.fn();
    render(<ColaboracionesAutomaticas reglas={[regla(), regla({ id: 6, requester_page_title: 'El Club' })]} onDejar={onDejar} />);

    fireEvent.click(screen.getByRole('button', { name: 'Dejar de aceptar automáticamente las de El Club' }));

    expect(onDejar).toHaveBeenCalledWith(expect.objectContaining({ id: 6 }));
  });

  it('mientras se quita, el botón queda desactivado', () => {
    render(<ColaboracionesAutomaticas reglas={[regla()]} onDejar={vi.fn()} quitando={4} />);

    expect(screen.getByRole('button', { name: /La Sala/ })).toBeDisabled();
    expect(screen.getByText('Quitando…')).toBeInTheDocument();
  });
});
