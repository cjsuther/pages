import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Alta from '../../src/carcajada/Alta';
import { renderConProviders } from '../helpers/render';

const SUGERENCIA = {
  page_id: 5, titulo: 'Ana Gómez', url_slug: 'anagomez',
  foto_url: 'https://rezon.ar/uploads/ana.jpg', instagram: 'anagomez',
};

function respuesta(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(estado = { comediante: null, sugerencia: SUGERENCIA, productor: false }) {
  global.fetch.mockReturnValueOnce(respuesta(estado));
  const vista = renderConProviders(<Alta />, { route: '/', path: '/' });
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());
  return vista;
}

describe('Alta de comediante', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /** Pedir de nuevo lo que ya cargó en su página es como se abandona un formulario. */
  it('viene con lo que ya cargó en su página de Rezonar', async () => {
    await montar();

    expect(screen.getByLabelText('¿Con qué nombre querés aparecer?')).toHaveValue('Ana Gómez');
    expect(screen.getByLabelText('Instagram')).toHaveValue('anagomez');
    expect(screen.getByText('rezon.ar/anagomez')).toBeInTheDocument();
  });

  /** El QR del show manda a esa página: sin página no hay alta. */
  it('sin página de Rezonar manda a crearla', async () => {
    await montar({ comediante: null, sugerencia: null, productor: false });

    expect(screen.getByText('Primero, tu página de Rezonar')).toBeInTheDocument();
    expect(screen.queryByLabelText('¿Con qué nombre querés aparecer?')).not.toBeInTheDocument();
  });

  it('manda el alta con la página, el nombre y la gente que promete traer', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('¿Cuánta gente te comprometés a traer cuando te presentás?'), {
      target: { value: '12' },
    });

    global.fetch.mockReturnValueOnce(respuesta({ success: true, comediante: { nombre: 'Ana Gómez', comprometidas: 12 } }));
    fireEvent.click(screen.getByRole('button', { name: 'Anotarme' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
    const cuerpo = JSON.parse(global.fetch.mock.calls[1][1].body);

    expect(cuerpo).toMatchObject({ page_id: 5, nombre: 'Ana Gómez', personas_comprometidas: 12 });
    expect(await screen.findByText('¡Listo, quedaste anotado!')).toBeInTheDocument();
  });

  it('quien ya se anotó ve sus datos para corregir', async () => {
    await montar({
      comediante: { nombre: 'La Turca', instagram: 'laturca', foto_url: null, comprometidas: 8 },
      sugerencia: SUGERENCIA,
      productor: false,
    });

    expect(screen.getByLabelText('¿Con qué nombre querés aparecer?')).toHaveValue('La Turca');
    expect(screen.getByRole('button', { name: 'Guardar cambios' })).toBeInTheDocument();
  });

  /** La formación y el material son para la producción: van en el alta. */
  it('manda con quién estudió, el año de egreso y su material', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('¿Con quién estudiaste?'), { target: { value: 'Escuela de Humor Sur' } });
    fireEvent.change(screen.getByLabelText('Año de egreso'), { target: { value: '2019' } });
    fireEvent.change(screen.getByLabelText('Contanos de qué va tu material'), { target: { value: 'Stand up de mi familia' } });

    global.fetch.mockReturnValueOnce(respuesta({ success: true, comediante: { nombre: 'Ana Gómez', comprometidas: 10 } }));
    fireEvent.click(screen.getByRole('button', { name: 'Anotarme' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
    expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toMatchObject({
      estudio_con: 'Escuela de Humor Sur', egreso_anio: '2019', material: 'Stand up de mi familia',
    });
  });

  it('quien ya se anotó ve su formación cargada', async () => {
    await montar({
      comediante: {
        nombre: 'La Turca', instagram: null, foto_url: null, comprometidas: 8,
        estudio_con: 'Taller de Pepe', egreso_anio: 2021, material: 'Absurdo',
      },
      sugerencia: SUGERENCIA,
      productor: false,
    });

    expect(screen.getByLabelText('¿Con quién estudiaste?')).toHaveValue('Taller de Pepe');
    expect(screen.getByLabelText('Año de egreso')).toHaveValue(2021);
    expect(screen.getByLabelText('Contanos de qué va tu material')).toHaveValue('Absurdo');
  });

  it('muestra el error del servidor', async () => {
    await montar();
    global.fetch.mockReturnValueOnce(respuesta({ error: 'Esa página no es tuya.' }, false));

    fireEvent.click(screen.getByRole('button', { name: 'Anotarme' }));

    expect(await screen.findByText('Esa página no es tuya.')).toBeInTheDocument();
  });
});
