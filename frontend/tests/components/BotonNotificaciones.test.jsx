import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import BotonNotificaciones from '../../src/components/BotonNotificaciones';
import * as push from '../../src/utils/pushNotifications';

const conSesion = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });
const sinSesion = () => crearAuth({ token: null, user: null });

const UA = {
  telefono: 'Mozilla/5.0 (Linux; Android 14; SM-A546E) Chrome/126.0 Mobile Safari/537.36',
  escritorio: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0 Safari/537.36',
};

const conUA = (ua) =>
  Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });

describe('BotonNotificaciones', () => {
  beforeEach(() => {
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));
    // Un teléfono por defecto: en una computadora la campana no se muestra,
    // porque las notificaciones no se llegan a activar ahí.
    conUA(UA.telefono);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  const montar = async ({ suscrito = false, auth = conSesion() } = {}) => {
    vi.spyOn(push, 'estaSuscrito').mockResolvedValue(suscrito);

    const vista = renderConProviders(<BotonNotificaciones />, { auth });
    await waitFor(() => expect(push.estaSuscrito).toHaveBeenCalled());

    return vista;
  };

  /**
   * La campana existe sólo para ofrecer las notificaciones. En una computadora
   * no se llegan a activar, así que ofrecerlas es abrir un cartel que explica
   * dos pasos y no tiene ningún botón debajo.
   */
  it('no aparece en una computadora', async () => {
    conUA(UA.escritorio);

    const { container } = await montar();

    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it('invita a activarlas cuando todavía no lo están', async () => {
    await montar();

    expect(await screen.findByRole('button', { name: /ACTIVÁ LAS NOTIFICACIONES/ })).toBeInTheDocument();
  });

  /** Un botón que ofrece lo que ya tenés es ruido. */
  it('no aparece si ya están activadas', async () => {
    await montar({ suscrito: true });

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /ACTIVÁ/ })).not.toBeInTheDocument();
    });
  });

  it('no aparece sin sesión', async () => {
    vi.spyOn(push, 'estaSuscrito').mockResolvedValue(false);

    renderConProviders(<BotonNotificaciones />, { auth: sinSesion() });

    expect(screen.queryByRole('button', { name: /ACTIVÁ/ })).not.toBeInTheDocument();
  });

  /**
   * Hasta saber si ya están activadas no se muestra nada: ofrecer y esconder
   * dos segundos después es peor que esperar.
   */
  it('no parpadea mientras se averigua el estado', () => {
    vi.spyOn(push, 'estaSuscrito').mockReturnValue(new Promise(() => {}));

    renderConProviders(<BotonNotificaciones />, { auth: conSesion() });

    expect(screen.queryByRole('button', { name: /ACTIVÁ/ })).not.toBeInTheDocument();
  });

  describe('el popup', () => {
    const abrir = async () => {
      await montar();
      fireEvent.click(await screen.findByRole('button', { name: /ACTIVÁ LAS NOTIFICACIONES/ }));

      return screen.findByRole('dialog');
    };

    it('explica para qué sirven y que hay que instalar la app', async () => {
      await abrir();

      expect(screen.getByText(/una página que seguís publica un evento nuevo/)).toBeInTheDocument();
      expect(screen.getByText(/instalar Rezonar como app/)).toBeInTheDocument();
    });

    /** Los pasos son los del dispositivo de quien mira, no una lista genérica. */
    it('muestra la guía de activación adentro', async () => {
      const dialogo = await abrir();

      expect(dialogo.textContent.length).toBeGreaterThan(120);
    });

    it('se cierra con la cruz', async () => {
      await abrir();

      fireEvent.click(screen.getByLabelText('Cerrar'));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('se cierra tocando afuera', async () => {
      const dialogo = await abrir();

      fireEvent.click(dialogo.parentElement);

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('un clic adentro no lo cierra', async () => {
      const dialogo = await abrir();

      fireEvent.click(dialogo);

      expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
  });
});
