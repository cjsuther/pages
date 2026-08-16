import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SeccionRedes from '../../src/components/SeccionRedes';
import { REDES } from '../../src/utils/redes';

describe('SeccionRedes', () => {
  it('ofrece un campo por cada red del catálogo', () => {
    render(<SeccionRedes onGuardar={vi.fn()} />);

    REDES.forEach((red) => {
      expect(screen.getByLabelText(red.nombre.toUpperCase())).toBeInTheDocument();
    });
  });

  it('explica que las vacías no se muestran', () => {
    render(<SeccionRedes onGuardar={vi.fn()} />);

    expect(screen.getByText(/las que dejes vacías no se muestran/)).toBeInTheDocument();
  });

  it('precarga lo que la página ya tenía, sin la base', () => {
    render(
      <SeccionRedes
        socials={[{ red: 'instagram', url: 'https://instagram.com/mi-banda' }]}
        onGuardar={vi.fn()}
      />
    );

    expect(screen.getByLabelText('INSTAGRAM')).toHaveValue('mi-banda');
  });

  it('deja vacías las que la página no tenía', () => {
    render(
      <SeccionRedes
        socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]}
        onGuardar={vi.fn()}
      />
    );

    expect(screen.getByLabelText('TIKTOK')).toHaveValue('');
  });

  describe('guardado', () => {
    it('manda sólo las completadas, ya normalizadas', async () => {
      const onGuardar = vi.fn();
      render(<SeccionRedes onGuardar={onGuardar} />);

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: 'mi-banda' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR REDES' }));

      await waitFor(() => {
        expect(onGuardar).toHaveBeenCalledWith([
          { red: 'instagram', url: 'https://instagram.com/mi-banda' },
        ]);
      });
    });

    it('no manda las que quedaron vacías', async () => {
      const onGuardar = vi.fn();
      render(<SeccionRedes onGuardar={onGuardar} />);

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: 'mi-banda' } });
      fireEvent.change(screen.getByLabelText('TIKTOK'), { target: { value: '   ' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR REDES' }));

      await waitFor(() => {
        expect(onGuardar.mock.calls[0][0]).toHaveLength(1);
      });
    });

    it('permite vaciar una red que estaba cargada', async () => {
      const onGuardar = vi.fn();
      render(
        <SeccionRedes
          socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]}
          onGuardar={onGuardar}
        />
      );

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: '' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR REDES' }));

      await waitFor(() => {
        expect(onGuardar).toHaveBeenCalledWith([]);
      });
    });

    it('normaliza el teléfono de WhatsApp', async () => {
      const onGuardar = vi.fn();
      render(<SeccionRedes onGuardar={onGuardar} />);

      fireEvent.change(screen.getByLabelText('WHATSAPP'), { target: { value: '+54 9 11 2233-4455' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR REDES' }));

      await waitFor(() => {
        expect(onGuardar).toHaveBeenCalledWith([
          { red: 'whatsapp', url: 'https://wa.me/5491122334455' },
        ]);
      });
    });

    it('confirma cuando terminó', async () => {
      render(<SeccionRedes onGuardar={vi.fn()} />);

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: 'yo' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR REDES' }));

      expect(await screen.findByText('Guardado')).toBeInTheDocument();
    });

    it('la confirmación desaparece al volver a editar', async () => {
      render(<SeccionRedes onGuardar={vi.fn()} />);

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: 'yo' } });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR REDES' }));
      await screen.findByText('Guardado');

      fireEvent.change(screen.getByLabelText('TIKTOK'), { target: { value: 'yo' } });

      expect(screen.queryByText('Guardado')).not.toBeInTheDocument();
    });

    it('deshabilita el botón mientras guarda', () => {
      render(<SeccionRedes onGuardar={vi.fn()} guardando />);

      expect(screen.getByRole('button', { name: 'GUARDANDO...' })).toBeDisabled();
    });
  });

  describe('vista previa', () => {
    it('avisa cuando no hay ninguna cargada', () => {
      render(<SeccionRedes onGuardar={vi.fn()} />);

      expect(screen.getByText(/Todavía no cargaste ninguna/)).toBeInTheDocument();
    });

    /** Ver el resultado antes de guardar evita ir a la página pública a mirar. */
    it('muestra los iconos a medida que se completan', async () => {
      render(<SeccionRedes onGuardar={vi.fn()} />);

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: 'mi-banda' } });

      expect(await screen.findByRole('link', { name: 'Instagram' })).toHaveAttribute(
        'href',
        'https://instagram.com/mi-banda'
      );
    });

    it('la vista previa acompaña lo que se borra', async () => {
      render(
        <SeccionRedes
          socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]}
          onGuardar={vi.fn()}
        />
      );

      await screen.findByRole('link', { name: 'Instagram' });

      fireEvent.change(screen.getByLabelText('INSTAGRAM'), { target: { value: '' } });

      await waitFor(() => {
        expect(screen.queryByRole('link', { name: 'Instagram' })).not.toBeInTheDocument();
      });
    });
  });
});
