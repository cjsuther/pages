import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import SubirImagen from '../../src/pages/SubirImagen';
import { renderConProviders } from '../helpers/render';
import { mockFetch, llamadaA } from '../helpers/api';
import { recorteFalso, responderRecorte } from '../helpers/recorte.jsx';
import { exportarRecorte } from '../../src/utils/recorte';

vi.mock('react-easy-crop', () => import('../helpers/recorte.jsx'));
vi.mock('../../src/utils/recorte', async (original) =>
  (await import('../helpers/recorte.jsx')).utilsDeRecorteFalsos(original));

/**
 * La página del link de un solo uso para el afiche de un evento: quien la
 * abre no tiene sesión, sólo el token.
 */

function archivo({ nombre = 'afiche.jpg', tipo = 'image/jpeg', bytes = 1024 } = {}) {
  const f = new File(['x'], nombre, { type: tipo });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

function montar(rutas = { 'upload/con-token.php': { url: 'https://img/afiche.jpg' } }) {
  const mock = mockFetch(rutas);
  renderConProviders(<SubirImagen />, { route: '/subir/abc123', path: '/subir/:token' });
  return mock;
}

const campo = () => screen.getByLabelText('Elegir la imagen');

describe('SubirImagen', () => {
  it('recorta el afiche en 4:5 y lo sube con el token', async () => {
    const recortada = recorteFalso({ bytes: 300 * 1024 });
    exportarRecorte.mockResolvedValueOnce(recortada);
    const { llamadas } = montar();

    fireEvent.change(campo(), { target: { files: [archivo({ bytes: 3 * 1024 * 1024 })] } });

    await screen.findByRole('dialog', { name: 'Recortá el afiche del evento' });
    expect(screen.getByTestId('recortador')).toHaveAttribute('data-aspecto', String(4 / 5));
    await responderRecorte('Usar recorte');

    expect(await screen.findByRole('heading', { name: 'Listo' })).toBeInTheDocument();
    const subida = llamadaA(llamadas, 'upload/con-token.php');
    expect(subida.url).toContain('token=abc123');
    expect(subida.options.body.get('image')).toBe(recortada);
  });

  it('una imagen soltada también pasa por el recorte', async () => {
    const { llamadas } = montar();

    const zona = campo().closest('label');
    fireEvent.drop(zona, { dataTransfer: { files: [archivo()] } });
    await responderRecorte('Usar sin recortar');

    await screen.findByRole('heading', { name: 'Listo' });
    expect(llamadaA(llamadas, 'upload/con-token.php')).not.toBeNull();
  });

  it('si se cancela el recorte no gasta el link', async () => {
    const { llamadas } = montar();

    fireEvent.change(campo(), { target: { files: [archivo()] } });
    await responderRecorte('Cancelar');

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(llamadaA(llamadas, 'upload/con-token.php')).toBeNull();
    expect(screen.getByRole('heading', { name: 'Soltá el afiche acá' })).toBeInTheDocument();
  });

  it('si aun recortado pasa los 600 KB avisa sin subir', async () => {
    exportarRecorte.mockResolvedValueOnce(recorteFalso({ bytes: 700 * 1024 }));
    const { llamadas } = montar();

    fireEvent.change(campo(), { target: { files: [archivo({ bytes: 3 * 1024 * 1024 })] } });
    await responderRecorte('Usar recorte');

    expect(await screen.findByRole('alert')).toHaveTextContent('para eventos el máximo es 600 KB');
    expect(llamadaA(llamadas, 'upload/con-token.php')).toBeNull();
  });

  it('muestra el error del servidor', async () => {
    montar({ 'upload/con-token.php': { status: 404, body: { error: 'Este link ya se usó o venció. Pedí uno nuevo.' } } });

    fireEvent.change(campo(), { target: { files: [archivo()] } });
    await responderRecorte('Usar sin recortar');

    expect(await screen.findByRole('alert')).toHaveTextContent('Este link ya se usó');
  });
});
