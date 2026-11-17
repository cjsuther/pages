import React, { useEffect } from 'react';
import { vi, expect } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';

/**
 * Dobles para el recorte de imágenes.
 *
 * jsdom no decodifica imágenes ni tiene canvas: react-easy-crop nunca llega a
 * medir nada y exportar un recorte no es posible. Los tests de pantalla
 * reemplazan las dos cosas:
 *
 *   vi.mock('react-easy-crop', () => import('../helpers/recorte.jsx'));
 *   vi.mock('../../src/utils/recorte', async (original) =>
 *     (await import('../helpers/recorte.jsx')).utilsDeRecorteFalsos(original));
 */

/** El área que "elige" el recortador falso apenas se monta. */
export const AREA_FALSA = { x: 10, y: 20, width: 400, height: 500 };

/** Las medidas de la imagen que "carga" cargarImagen. */
export const IMAGEN_FALSA = { naturalWidth: 1600, naturalHeight: 1200 };

/** Lo que devuelve exportarRecorte salvo que el test diga otra cosa. */
export function recorteFalso({ nombre = 'recorte.jpg', tipo = 'image/jpeg', bytes = 2048 } = {}) {
  const archivo = new File(['recorte'], nombre, { type: tipo });
  Object.defineProperty(archivo, 'size', { value: bytes });
  return archivo;
}

/**
 * Reemplazo de react-easy-crop: muestra lo que recibió y avisa el área
 * elegida, que es todo lo que la pantalla usa de él.
 */
export default function CropperFalso({ aspect, zoom, cropShape, onCropComplete, cropperProps }) {
  useEffect(() => {
    onCropComplete({ x: 0, y: 0, width: 50, height: 50 }, AREA_FALSA);
  }, [onCropComplete]);

  return (
    <div
      data-testid="recortador"
      data-aspecto={String(aspect)}
      data-zoom={String(zoom)}
      data-forma={cropShape}
      tabIndex={0}
      {...cropperProps}
    />
  );
}

/**
 * Espera el diálogo de recorte y aprieta uno de sus botones: 'Usar recorte',
 * 'Usar sin recortar' o 'Cancelar'. Devuelve el diálogo por si el test quiere
 * mirar su título.
 */
export async function responderRecorte(boton = 'Usar recorte') {
  const dialogo = await screen.findByRole('dialog', { name: /^Recortá/ });
  const elegido = within(dialogo).getByRole('button', { name: boton });
  await waitFor(() => expect(elegido).toBeEnabled());
  fireEvent.click(elegido);
  return dialogo;
}

/** Mantiene src/utils/recorte salvo lo que necesita un navegador de verdad. */
export async function utilsDeRecorteFalsos(importOriginal) {
  const original = await importOriginal();
  return {
    ...original,
    cargarImagen: vi.fn(async () => IMAGEN_FALSA),
    exportarRecorte: vi.fn(async () => recorteFalso()),
  };
}
