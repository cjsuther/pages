import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { BotonComprar, Producto } from '../../src/components/DetalleProducto';
import { paleta } from '../../src/utils/colores';

/**
 * Un producto físico en la página pública (#42): el envío, un selector por
 * característica, "Agotado" y el botón que todavía dice "Próximamente".
 */

const colores = paleta({ background_color: '#ffffff', text_color: '#000000', primary_color: '#ff8800' });

const fisico = (overrides = {}) => ({
  id: 500,
  text: 'Remera',
  description: 'De algodón',
  image_url: null,
  precio: 9000,
  tipo: 'fisico',
  costo_envio: 0,
  zona_envio: null,
  caracteristicas: [],
  agotadas: [],
  agotado: false,
  ...overrides,
});

// Talle S (1), M (2); Color Negro (3), Blanco (4). Agotadas: S/Negro y S/Blanco, M/Blanco.
const conTalleYColor = (overrides = {}) => fisico({
  caracteristicas: [
    { id: 10, nombre: 'Talle', opciones: [{ id: 1, nombre: 'S' }, { id: 2, nombre: 'M' }] },
    { id: 20, nombre: 'Color', opciones: [{ id: 3, nombre: 'Negro' }, { id: 4, nombre: 'Blanco' }] },
  ],
  agotadas: ['1-3', '1-4', '2-4'],
  ...overrides,
});

const montar = (producto, onComprar = vi.fn()) => {
  render(<Producto producto={producto} colores={colores} onComprar={onComprar} />);
  return onComprar;
};

const grupo = (nombre) => screen.getByRole('radiogroup', { name: nombre });
const opcion = (grupoNombre, nombre) => within(grupo(grupoNombre)).getByRole('radio', { name: new RegExp(`^${nombre}`) });

describe('Producto físico en la página', () => {
  it('dice "Envío incluido" cuando el envío cuesta 0, y la zona', () => {
    montar(fisico({ zona_envio: 'Sólo CABA y GBA' }));

    expect(screen.getByText('Envío incluido')).toBeInTheDocument();
    expect(screen.getByText('Sólo CABA y GBA')).toBeInTheDocument();
  });

  it('con costo de envío lo dice', () => {
    montar(fisico({ costo_envio: 1500 }));

    expect(screen.getByText('Envío: $ 1.500')).toBeInTheDocument();
  });

  it('sin características no pide elegir nada', () => {
    montar(fisico());

    expect(screen.queryByRole('radiogroup')).not.toBeInTheDocument();
    expect(screen.queryByText(/^Elegí/)).not.toBeInTheDocument();
  });

  it('muestra un selector por característica, con las opciones como píldoras', () => {
    montar(conTalleYColor());

    expect(within(grupo('Talle')).getAllByRole('radio').map((b) => b.firstChild.textContent)).toEqual(['S', 'M']);
    expect(within(grupo('Color')).getAllByRole('radio')).toHaveLength(2);
    expect(screen.getByText('Elegí talle y color.')).toBeInTheDocument();
  });

  it('una opción cuyas combinaciones están todas agotadas dice Agotado y no se puede elegir', () => {
    montar(conTalleYColor());

    // S está agotado en los dos colores.
    expect(opcion('Talle', 'S')).toBeDisabled();
    expect(opcion('Talle', 'S')).toHaveAccessibleName('S (agotado)');
    expect(opcion('Talle', 'M')).toBeEnabled();
  });

  it('lo agotado depende de lo ya elegido', () => {
    montar(conTalleYColor());

    expect(opcion('Color', 'Blanco')).toBeDisabled();
    expect(opcion('Color', 'Negro')).toBeEnabled();

    fireEvent.click(opcion('Talle', 'M'));

    expect(opcion('Talle', 'M')).toHaveAttribute('aria-checked', 'true');
    expect(opcion('Color', 'Negro')).toBeEnabled();
    expect(opcion('Color', 'Blanco')).toBeDisabled();
    expect(screen.getByText('Elegí color.')).toBeInTheDocument();

    fireEvent.click(opcion('Color', 'Negro'));
    expect(screen.queryByText(/^Elegí/)).not.toBeInTheDocument();

    // Tocar la elegida la suelta.
    fireEvent.click(opcion('Talle', 'M'));
    expect(opcion('Talle', 'M')).toHaveAttribute('aria-checked', 'false');
  });

  it('todavía no se compra: el botón dice Próximamente y no hace nada', () => {
    const onComprar = montar(conTalleYColor());

    fireEvent.click(opcion('Talle', 'M'));
    fireEvent.click(opcion('Color', 'Negro'));
    const boton = screen.getByRole('button', { name: 'Próximamente' });
    fireEvent.click(boton);

    expect(boton).toBeDisabled();
    expect(onComprar).not.toHaveBeenCalled();
  });

  it('todo agotado: el botón dice Agotado y no ofrece opciones', () => {
    montar(conTalleYColor({ agotado: true, agotadas: ['1-3', '1-4', '2-3', '2-4'] }));

    expect(screen.getByRole('button', { name: 'Agotado' })).toBeDisabled();
    expect(screen.queryByRole('radiogroup')).not.toBeInTheDocument();
  });

  it('no muestra archivo', () => {
    montar(fisico({ archivo_nombre: null }));

    expect(screen.queryByText(/\.pdf/)).not.toBeInTheDocument();
  });
});

describe('BotonComprar de la tarjeta', () => {
  it('en un físico agotado la tarjeta dice Agotado y no abre nada', () => {
    const onComprar = vi.fn();
    render(<BotonComprar producto={fisico({ agotado: true })} colores={colores} onComprar={onComprar} />);

    const boton = screen.getByRole('button', { name: 'Agotado' });
    fireEvent.click(boton);

    expect(boton).toBeDisabled();
    expect(onComprar).not.toHaveBeenCalled();
  });

  it('en un físico con stock dice Próximamente', () => {
    render(<BotonComprar producto={fisico()} colores={colores} onComprar={vi.fn()} />);

    expect(screen.getByRole('button', { name: 'Próximamente' })).toBeDisabled();
  });

  it('un digital sigue diciendo Comprar', () => {
    render(<BotonComprar producto={{ id: 1, precio: 100 }} colores={colores} onComprar={vi.fn()} />);

    expect(screen.getByRole('button', { name: 'Comprar' })).toBeEnabled();
  });
});
