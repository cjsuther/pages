import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import RecortarImagen from '../../src/components/RecortarImagen';
import { DESTINOS, cargarImagen, exportarRecorte } from '../../src/utils/recorte';
import { AREA_FALSA, IMAGEN_FALSA, recorteFalso } from '../helpers/recorte.jsx';

vi.mock('react-easy-crop', () => import('../helpers/recorte.jsx'));
vi.mock('../../src/utils/recorte', async (original) =>
  (await import('../helpers/recorte.jsx')).utilsDeRecorteFalsos(original));

function archivo({ nombre = 'foto.jpg', tipo = 'image/jpeg', bytes = 1024 } = {}) {
  const f = new File(['x'], nombre, { type: tipo });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

function montar({ destino = 'perfil', elegido = archivo() } = {}) {
  const onListo = vi.fn();
  const onCancelar = vi.fn();
  render(
    <RecortarImagen
      archivo={elegido}
      destino={DESTINOS[destino]}
      onListo={onListo}
      onCancelar={onCancelar}
    />
  );
  return { onListo, onCancelar, elegido };
}

/** Espera a que la imagen "cargue" y el botón principal se habilite. */
async function listo() {
  await waitFor(() => expect(screen.getByRole('button', { name: 'Usar recorte' })).toBeEnabled());
}

describe('RecortarImagen', () => {
  it('es un diálogo con título y ayuda, y toma el foco', async () => {
    montar();
    const dialogo = screen.getByRole('dialog', { name: 'Recortá la imagen de perfil' });

    expect(dialogo).toHaveAttribute('aria-modal', 'true');
    expect(dialogo).toHaveAccessibleDescription(/flechas para mover/);
    expect(dialogo).toHaveFocus();
    expect(screen.getByRole('group', { name: /Área de recorte/ })).toBeInTheDocument();
    await listo();
  });

  it('el perfil es cuadrado con máscara circular y sin elegir proporción', async () => {
    montar({ destino: 'perfil' });
    await listo();

    const recortador = screen.getByTestId('recortador');
    expect(recortador).toHaveAttribute('data-aspecto', '1');
    expect(recortador).toHaveAttribute('data-forma', 'round');
    expect(screen.queryByRole('group', { name: 'Proporción' })).not.toBeInTheDocument();
  });

  it('el afiche de un evento es 4:5 y rectangular', async () => {
    montar({ destino: 'evento' });
    await listo();

    expect(screen.getByTestId('recortador')).toHaveAttribute('data-aspecto', String(4 / 5));
    expect(screen.getByTestId('recortador')).toHaveAttribute('data-forma', 'rect');
  });

  it('en la galería se elige la proporción, con la original de la imagen', async () => {
    montar({ destino: 'galeria' });
    await listo();

    const original = screen.getByRole('button', { name: 'Original' });
    expect(original).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByTestId('recortador')).toHaveAttribute(
      'data-aspecto',
      String(IMAGEN_FALSA.naturalWidth / IMAGEN_FALSA.naturalHeight)
    );

    fireEvent.click(screen.getByRole('button', { name: '16:9' }));

    expect(screen.getByRole('button', { name: '16:9' })).toHaveAttribute('aria-pressed', 'true');
    expect(original).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByTestId('recortador')).toHaveAttribute('data-aspecto', String(16 / 9));
  });

  it('exporta el área elegida con el tamaño y el tope del lugar', async () => {
    const recortada = recorteFalso();
    exportarRecorte.mockResolvedValueOnce(recortada);
    const { onListo } = montar({ destino: 'evento', elegido: archivo({ nombre: 'afiche.png', tipo: 'image/png' }) });
    await listo();

    fireEvent.click(screen.getByRole('button', { name: 'Usar recorte' }));

    await waitFor(() => expect(onListo).toHaveBeenCalledWith(recortada));
    expect(exportarRecorte).toHaveBeenCalledWith(IMAGEN_FALSA, AREA_FALSA, {
      maxLado: 1350,
      maxBytes: 600 * 1024,
      tipo: 'image/png',
      nombre: 'afiche.png',
    });
  });

  it('sin recortar manda el archivo original si ya entra', async () => {
    const { onListo, elegido } = montar({ destino: 'perfil' });
    await listo();

    fireEvent.click(screen.getByRole('button', { name: 'Usar sin recortar' }));

    expect(onListo).toHaveBeenCalledWith(elegido);
    expect(exportarRecorte).not.toHaveBeenCalled();
  });

  it('sin recortar, si pasa el tope, achica la imagen entera', async () => {
    const { onListo } = montar({
      destino: 'evento',
      elegido: archivo({ nombre: 'afiche.png', tipo: 'image/png', bytes: 2 * 1024 * 1024 }),
    });
    await listo();

    fireEvent.click(screen.getByRole('button', { name: 'Usar sin recortar' }));

    await waitFor(() => expect(onListo).toHaveBeenCalled());
    expect(exportarRecorte).toHaveBeenCalledWith(
      IMAGEN_FALSA,
      { x: 0, y: 0, width: 1600, height: 1200 },
      expect.objectContaining({ maxBytes: 600 * 1024 })
    );
  });

  it('cancela con el botón y con Escape', async () => {
    const { onCancelar, onListo } = montar();
    await listo();

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(onCancelar).toHaveBeenCalledTimes(1);

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onCancelar).toHaveBeenCalledTimes(2);
    expect(onListo).not.toHaveBeenCalled();
  });

  it('el Escape no le llega a lo que está atrás', async () => {
    const deAtras = vi.fn();
    document.addEventListener('keydown', deAtras);
    montar();
    await listo();

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });

    expect(deAtras).not.toHaveBeenCalled();
    document.removeEventListener('keydown', deAtras);
  });

  it('hace zoom con el deslizante, los botones y las teclas + y −', async () => {
    montar();
    await listo();
    const recortador = screen.getByTestId('recortador');
    const deslizante = screen.getByRole('slider', { name: 'Zoom' });

    expect(screen.getByRole('button', { name: 'Alejar' })).toBeDisabled();

    fireEvent.change(deslizante, { target: { value: '2' } });
    expect(recortador).toHaveAttribute('data-zoom', '2');
    expect(deslizante).toHaveAttribute('aria-valuetext', '200%');

    fireEvent.click(screen.getByRole('button', { name: 'Acercar' }));
    expect(Number(recortador.dataset.zoom)).toBeCloseTo(2.2);

    fireEvent.keyDown(recortador, { key: '-' });
    fireEvent.keyDown(recortador, { key: '-' });
    expect(Number(recortador.dataset.zoom)).toBeCloseTo(1.8);

    fireEvent.keyDown(recortador, { key: '+' });
    expect(Number(recortador.dataset.zoom)).toBeCloseTo(2);

    // No pasa de los topes.
    fireEvent.change(deslizante, { target: { value: '9' } });
    expect(recortador).toHaveAttribute('data-zoom', '4');
    expect(screen.getByRole('button', { name: 'Acercar' })).toBeDisabled();
  });

  it('el Tab da la vuelta dentro del diálogo', async () => {
    montar();
    await listo();
    const usar = screen.getByRole('button', { name: 'Usar recorte' });
    const recortador = screen.getByTestId('recortador');

    usar.focus();
    fireEvent.keyDown(usar, { key: 'Tab' });
    expect(recortador).toHaveFocus();

    fireEvent.keyDown(recortador, { key: 'Tab', shiftKey: true });
    expect(usar).toHaveFocus();
  });

  it('avisa si la imagen no se puede abrir y sólo deja cancelar', async () => {
    cargarImagen.mockRejectedValueOnce(new Error('no'));
    montar({ elegido: archivo({ nombre: 'foto.heic', tipo: 'image/heic' }) });

    expect(await screen.findByRole('alert')).toHaveTextContent('No pudimos abrir esta imagen');
    expect(screen.getByRole('button', { name: 'Usar recorte' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Usar sin recortar' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeEnabled();
  });

  it('avisa si no se pudo procesar el recorte y deja reintentar', async () => {
    exportarRecorte.mockRejectedValueOnce(new Error('Este navegador no puede recortar imágenes'));
    const { onListo } = montar();
    await listo();

    fireEvent.click(screen.getByRole('button', { name: 'Usar recorte' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Este navegador no puede recortar imágenes');
    expect(onListo).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Usar recorte' })).toBeEnabled();
  });

  it('devuelve el foco a donde estaba al cerrarse', async () => {
    const boton = document.createElement('button');
    document.body.appendChild(boton);
    boton.focus();

    const { unmount } = render(
      <RecortarImagen archivo={archivo()} destino={DESTINOS.link} onListo={vi.fn()} onCancelar={vi.fn()} />
    );
    await listo();
    expect(boton).not.toHaveFocus();

    unmount();
    expect(boton).toHaveFocus();
    boton.remove();
  });
});
