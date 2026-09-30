import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Show from '../../src/carcajada/Show';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';

const SHOW = {
  id: 7, ciclo: 'JaJaJaJueves', fecha: '2099-10-02', hora: '21:00:00', lugar: 'Humboldt 1574',
  descripcion: '<p>Noche de stand up</p>', notas: null,
  lineup: [
    { id: 1, comediante_id: 9, nombre: 'Ana Gómez', con_cuenta: true, comprometidas: 10, puntaje: null, personas_traidas: null, comentario: null },
    { id: 2, comediante_id: 44, nombre: 'Pepe Invitado', con_cuenta: false, comprometidas: 0, puntaje: null, personas_traidas: null, comentario: null },
  ],
};

function respuesta(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(show = SHOW) {
  global.fetch.mockReturnValueOnce(respuesta({ show, comediantes: [] }));
  renderConProviders(<Show />, {
    auth: crearAuth({ token: 'tok', user: usuarioDePrueba() }),
    route: '/shows/7',
    path: '/shows/:id',
  });
  await screen.findByText('Line-up');
}

describe('Show (armado)', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    document.execCommand = vi.fn(() => true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /** A alguien sin cuenta no se le puede pedir que prometa gente: no se anotó. */
  it('marca a quien no tiene cuenta en vez de mostrar un compromiso en cero', async () => {
    await montar();

    expect(screen.getByText('Sin cuenta')).toBeInTheDocument();
    expect(screen.getByText('Se compromete a traer 10')).toBeInTheDocument();
    expect(screen.queryByText('Se compromete a traer 0')).not.toBeInTheDocument();
  });

  it('enlaza a la página pública de la fecha', async () => {
    await montar();

    expect(screen.getByRole('link', { name: /Ver la página de la fecha/ })).toHaveAttribute('href', '/fecha/7');
  });

  describe('descripción', () => {
    it('arranca con la descripción guardada', async () => {
      await montar();

      expect(screen.getByRole('textbox', { name: 'Descripción' }).innerHTML).toBe('<p>Noche de stand up</p>');
    });

    it('guarda lo que se escribió', async () => {
      await montar();
      const area = screen.getByRole('textbox', { name: 'Descripción' });
      area.innerHTML = '<p>Nueva <b>descripción</b></p>';
      fireEvent.input(area);

      global.fetch.mockReturnValueOnce(respuesta({ show: { ...SHOW, descripcion: '<p>Nueva <strong>descripción</strong></p>' } }));
      fireEvent.click(screen.getByRole('button', { name: 'Guardar descripción' }));

      await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
      expect(global.fetch.mock.calls[1][0]).toContain('/carcajada/show.php?id=7');
      expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toEqual({
        accion: 'descripcion', descripcion: '<p>Nueva <b>descripción</b></p>',
      });
      expect(await screen.findByText('Guardada')).toBeInTheDocument();
    });
  });

  describe('alguien sin cuenta', () => {
    const abrir = () => fireEvent.click(screen.getByRole('button', { name: /Sumar a alguien que no tiene cuenta/ }));

    it('se suma con nombre, foto subida e Instagram', async () => {
      await montar();
      abrir();

      fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Luli Nueva' } });
      fireEvent.change(screen.getByLabelText('Instagram'), { target: { value: '@luli' } });

      global.fetch.mockReturnValueOnce(respuesta({ url: 'https://rezon.ar/api/uploads/luli.jpg' }));
      const foto = new File(['x'], 'luli.jpg', { type: 'image/jpeg' });
      fireEvent.change(screen.getByLabelText('Foto del comediante'), { target: { files: [foto] } });

      await screen.findByText('Cambiar foto');
      expect(global.fetch.mock.calls[1][0]).toMatch(/\/upload\/image\.php$/);
      expect(global.fetch.mock.calls[1][1].headers.Authorization).toBe('Bearer tok');

      global.fetch.mockReturnValueOnce(respuesta({ show: SHOW, comediantes: [] }));
      fireEvent.click(screen.getByRole('button', { name: /Sumar al show/ }));

      await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(3));
      expect(JSON.parse(global.fetch.mock.calls[2][1].body)).toEqual({
        accion: 'invitar', nombre: 'Luli Nueva', foto_url: 'https://rezon.ar/api/uploads/luli.jpg', instagram: '@luli',
      });
      // Se cierra el formulario para la próxima.
      await waitFor(() => expect(screen.queryByLabelText('Nombre')).not.toBeInTheDocument());
    });

    it('sin nombre no se puede sumar', async () => {
      await montar();
      abrir();

      expect(screen.getByRole('button', { name: /Sumar al show/ })).toBeDisabled();
    });

    it('rechaza una foto que no es imagen sin subirla', async () => {
      await montar();
      abrir();

      const pdf = new File(['x'], 'cv.pdf', { type: 'application/pdf' });
      fireEvent.change(screen.getByLabelText('Foto del comediante'), { target: { files: [pdf] } });

      expect(await screen.findByText('La foto tiene que ser JPG, PNG, GIF o WebP')).toBeInTheDocument();
      expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('si el servidor lo rechaza muestra el error y deja el formulario', async () => {
      await montar();
      abrir();
      fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Luli' } });

      global.fetch.mockReturnValueOnce(respuesta({ error: 'La foto tiene que ser una imagen subida o una dirección web' }, false));
      fireEvent.click(screen.getByRole('button', { name: /Sumar al show/ }));

      expect(await screen.findByText('La foto tiene que ser una imagen subida o una dirección web')).toBeInTheDocument();
      expect(screen.getByLabelText('Nombre')).toHaveValue('Luli');
    });
  });
});
