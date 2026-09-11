import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import AvisoNotificaciones from '../../src/components/AvisoNotificaciones';
import * as push from '../../src/utils/pushNotifications';

const conSesion = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });
const sinSesion = () => crearAuth({ token: null, user: null });

const UA = {
  telefono: 'Mozilla/5.0 (Linux; Android 14; SM-A546E) Chrome/126.0 Mobile Safari/537.36',
  iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile Safari/604.1',
  escritorio: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0 Safari/537.36',
};

const conUA = (ua) =>
  Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });

describe('AvisoNotificaciones', () => {
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

    const vista = renderConProviders(<AvisoNotificaciones />, { auth });
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

    expect(await screen.findByText('Que te avisemos al teléfono')).toBeInTheDocument();
  });

  /**
   * El punto del cambio: lo que hay que hacer se lee sin tocar nada.
   *
   * Antes era un botón que abría un popup, y el botón estaba a mitad de la
   * home. Quien entraba desde el teléfono no llegaba nunca hasta ahí, y si
   * llegaba tenía que tocar para enterarse de que había que instalar la app.
   */
  it('la explicación se ve sin tocar nada', async () => {
    await montar();

    expect(await screen.findByText(/te llega un aviso/)).toBeInTheDocument();
    expect(screen.getByText('Instalá Rezonar para no perderte nada')).toBeInTheDocument();
  });

  it('el botón para activarlas está a la vista', async () => {
    // La API de push no existe en jsdom, y sin ella el paso que corresponde no
    // es activar sino instalar.
    window.PushManager = function PushManager() {};
    window.Notification = { permission: 'default' };
    global.Notification = window.Notification;
    Object.defineProperty(navigator, 'serviceWorker', {
      value: { getRegistration: vi.fn(() => Promise.resolve(null)) },
      configurable: true,
    });

    await montar();

    expect(await screen.findByRole('button', { name: /Activar notificaciones/ }))
      .toBeInTheDocument();
  });

  /** Los pasos son los del teléfono de quien mira, no una lista genérica. */
  it('los pasos son los del dispositivo', async () => {
    conUA(UA.iphone);

    await montar();

    expect(await screen.findByText('Agregá Rezonar a tu pantalla de inicio')).toBeInTheDocument();
    expect(screen.getByText(/Tocá el botón Compartir/)).toBeInTheDocument();
  });

  it('no abre ningún popup', async () => {
    await montar();

    await screen.findByText('Que te avisemos al teléfono');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
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

    renderConProviders(<AvisoNotificaciones />, { auth: sinSesion() });

    expect(screen.queryByRole('button', { name: /ACTIVÁ/ })).not.toBeInTheDocument();
  });

  /**
   * Hasta saber si ya están activadas no se muestra nada: ofrecer y esconder
   * dos segundos después es peor que esperar.
   */
  it('no parpadea mientras se averigua el estado', () => {
    vi.spyOn(push, 'estaSuscrito').mockReturnValue(new Promise(() => {}));

    renderConProviders(<AvisoNotificaciones />, { auth: conSesion() });

    expect(screen.queryByRole('button', { name: /ACTIVÁ/ })).not.toBeInTheDocument();
  });
});
