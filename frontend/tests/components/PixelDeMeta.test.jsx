import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import PixelDeMeta from '../../src/components/PixelDeMeta';

const PIXEL = '1234567890123456';

function montar(props = {}) {
  const onGuardar = props.onGuardar || vi.fn().mockResolvedValue(undefined);
  render(<PixelDeMeta pixelInicial={props.pixelInicial ?? null} onGuardar={onGuardar} />);
  return onGuardar;
}

describe('PixelDeMeta', () => {
  it('muestra el pixel que ya tiene la página', () => {
    montar({ pixelInicial: PIXEL });

    expect(screen.getByLabelText('ID del pixel')).toHaveValue(PIXEL);
  });

  it('guarda el pixel', async () => {
    const onGuardar = montar();

    fireEvent.change(screen.getByLabelText('ID del pixel'), { target: { value: PIXEL } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    await waitFor(() => expect(onGuardar).toHaveBeenCalledWith(PIXEL));
    expect(await screen.findByText('Guardado')).toBeInTheDocument();
  });

  /** Se pega el fragmento de código entero más veces de las que uno esperaría. */
  it('si pegan el código entero se queda con el número', () => {
    montar();

    fireEvent.change(screen.getByLabelText('ID del pixel'), {
      target: { value: `<script>fbq('init', '${PIXEL}');</script>` },
    });

    expect(screen.getByLabelText('ID del pixel')).toHaveValue(PIXEL);
  });

  it('un valor que no es un pixel no se manda al servidor', async () => {
    const onGuardar = montar();

    fireEvent.change(screen.getByLabelText('ID del pixel'), { target: { value: '123' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('15 o 16 dígitos');
    expect(onGuardar).not.toHaveBeenCalled();
  });

  /** Vaciarlo es la forma de dejar de medir. */
  it('se puede vaciar para dejar de medir', async () => {
    const onGuardar = montar({ pixelInicial: PIXEL });

    fireEvent.change(screen.getByLabelText('ID del pixel'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    await waitFor(() => expect(onGuardar).toHaveBeenCalledWith(''));
    expect(await screen.findByText('Se dejó de medir con Meta')).toBeInTheDocument();
  });

  it('muestra el error del servidor', async () => {
    const onGuardar = vi.fn().mockRejectedValue(new Error('No pudimos guardar el pixel'));
    montar({ pixelInicial: PIXEL, onGuardar });

    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('No pudimos guardar el pixel');
  });
});
