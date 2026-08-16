import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import ActivarNotificaciones from '../../src/components/ActivarNotificaciones';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, respuesta } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const UA = {
  iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1',
  iphoneChrome: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) CriOS/120.0 Mobile/15E148 Safari/604.1',
  samsung: 'Mozilla/5.0 (Linux; Android 14; SM-A546E) Chrome/126.0 Mobile Safari/537.36',
  escritorio: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0 Safari/537.36',
};

function conUA(ua) {
  Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });
}

/**
 * Prepara el entorno del navegador.
 *
 * @param instalada  si la PWA está agregada a la pantalla de inicio
 * @param soporta    si existe la API de push
 * @param permiso    estado de Notification.permission
 * @param suscrito   si ya hay una suscripción activa
 */
function prepararEntorno({
  ua = UA.escritorio,
  instalada = false,
  soporta = true,
  permiso = 'default',
  suscrito = false,
} = {}) {
  conUA(ua);

  window.matchMedia = vi.fn(() => ({ matches: instalada, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  window.navigator.standalone = instalada;

  if (soporta) {
    window.PushManager = function PushManager() {};
  } else {
    delete window.PushManager;
  }

  window.Notification = { permission: permiso, requestPermission: vi.fn(() => Promise.resolve('granted')) };
  global.Notification = window.Notification;

  const sus = suscrito
    ? { endpoint: 'https://push.example/abc', unsubscribe: vi.fn(() => Promise.resolve(true)), toJSON: () => ({ endpoint: 'https://push.example/abc', keys: {} }) }
    : null;

  const registro = {
    pushManager: {
      getSubscription: vi.fn(() => Promise.resolve(sus)),
      subscribe: vi.fn(() =>
        Promise.resolve({ endpoint: 'https://push.example/nueva', toJSON: () => ({ endpoint: 'https://push.example/nueva', keys: {} }) })
      ),
    },
  };

  Object.defineProperty(navigator, 'serviceWorker', {
    value: {
      register: vi.fn(() => Promise.resolve(registro)),
      getRegistration: vi.fn(() => Promise.resolve(registro)),
      ready: Promise.resolve(registro),
    },
    writable: true,
    configurable: true,
  });

  return registro;
}

function mockearApi() {
  return mockFetch({
    'push/vapid.php': { public_key: 'aGVsbG8', disponible: true },
    'push/subscribe.php': { success: true },
  });
}

describe('ActivarNotificaciones', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    mockearApi();
  });

  afterEach(() => {
    delete navigator.serviceWorker;
    delete window.PushManager;
    delete window.Notification;
    delete global.Notification;
    delete window.navigator.standalone;
  });

  it('no muestra nada sin sesión', () => {
    prepararEntorno();

    const { container } = renderConProviders(<ActivarNotificaciones />);

    expect(container).toBeEmptyDOMElement();
  });

  // ================================================================== iPhone

  describe('en iPhone', () => {
    it('con Chrome pide abrirlo en Safari', async () => {
      prepararEntorno({ ua: UA.iphoneChrome, soporta: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText('Abrilo en Safari')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Activar/ })).not.toBeInTheDocument();
    });

    /**
     * El error 2.2 de la guía: sin instalar, PushManager no existe. Si el
     * componente hablara de soporte, el usuario iría a buscar una
     * actualización de iOS que no le falta.
     */
    it('sin instalar guía la instalación en vez de hablar de soporte', async () => {
      prepararEntorno({ ua: UA.iphoneSafari, instalada: false, soporta: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText('Agregá Rezonar a tu pantalla de inicio')).toBeInTheDocument();
      expect(screen.queryByText(/no admite notificaciones/i)).not.toBeInTheDocument();
    });

    it('explica los pasos concretos con los nombres reales de los botones', async () => {
      prepararEntorno({ ua: UA.iphoneSafari, instalada: false, soporta: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      await screen.findByText('Agregá Rezonar a tu pantalla de inicio');

      expect(screen.getByText(/Tocá el botón Compartir/)).toBeInTheDocument();
      expect(screen.getByText(/abrí Rezonar desde el ícono nuevo/)).toBeInTheDocument();
      // Aparece en la instrucción y también como referencia visual del icono.
      expect(screen.getAllByText(/Agregar a inicio/).length).toBeGreaterThan(0);
    });

    it('numera las instrucciones', async () => {
      prepararEntorno({ ua: UA.iphoneSafari, instalada: false, soporta: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      await screen.findByText('Agregá Rezonar a tu pantalla de inicio');

      expect(screen.getByText('1')).toBeInTheDocument();
      expect(screen.getByText('4')).toBeInTheDocument();
    });

    it('instalado y con soporte ofrece activar', async () => {
      prepararEntorno({ ua: UA.iphoneSafari, instalada: true, soporta: true });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByRole('button', { name: /Activar notificaciones/ })).toBeInTheDocument();
    });

    it('instalado pero sin API real habla de la versión', async () => {
      prepararEntorno({ ua: UA.iphoneSafari, instalada: true, soporta: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText(/16.4/)).toBeInTheDocument();
    });
  });

  // ================================================================= Android

  describe('en Android', () => {
    it('sin instalar sugiere instalar pero deja activar igual', async () => {
      prepararEntorno({ ua: UA.samsung, instalada: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText('Instalá Rezonar para no perderte nada')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Activar notificaciones/ })).toBeInTheDocument();
    });

    it('muestra el botón de instalación cuando el navegador lo ofrece', async () => {
      prepararEntorno({ ua: UA.samsung, instalada: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });
      await screen.findByText('Instalá Rezonar para no perderte nada');

      const evento = new Event('beforeinstallprompt');
      evento.prompt = vi.fn();
      evento.userChoice = Promise.resolve({ outcome: 'accepted' });
      fireEvent(window, evento);

      expect(await screen.findByRole('button', { name: /Instalar aplicación/ })).toBeInTheDocument();
    });

    it('el botón de instalación dispara el diálogo del navegador', async () => {
      prepararEntorno({ ua: UA.samsung, instalada: false });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });
      await screen.findByText('Instalá Rezonar para no perderte nada');

      const evento = new Event('beforeinstallprompt');
      evento.prompt = vi.fn();
      evento.userChoice = Promise.resolve({ outcome: 'accepted' });
      fireEvent(window, evento);

      fireEvent.click(await screen.findByRole('button', { name: /Instalar aplicación/ }));

      await waitFor(() => expect(evento.prompt).toHaveBeenCalled());
    });
  });

  // ================================================================= permiso

  describe('permiso denegado', () => {
    it('explica que hay que habilitarlo en los ajustes', async () => {
      prepararEntorno({ ua: UA.samsung, instalada: true, permiso: 'denied' });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText('Las notificaciones están bloqueadas')).toBeInTheDocument();
      expect(screen.getByText(/Ajustes del sistema/)).toBeInTheDocument();
    });

    it('no ofrece activar, porque no se puede volver a preguntar', async () => {
      prepararEntorno({ ua: UA.samsung, instalada: true, permiso: 'denied' });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      await screen.findByText('Las notificaciones están bloqueadas');
      expect(screen.queryByRole('button', { name: /Activar notificaciones/ })).not.toBeInTheDocument();
    });
  });

  // ================================================================ activar

  describe('activación', () => {
    it('registra la suscripción y confirma', async () => {
      prepararEntorno({ ua: UA.escritorio, instalada: true });
      const { llamadas } = mockearApi();

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      fireEvent.click(await screen.findByRole('button', { name: /Activar notificaciones/ }));

      expect(await screen.findByText('Notificaciones activadas')).toBeInTheDocument();
      expect(llamadas.some((l) => l.url.includes('/push/subscribe.php'))).toBe(true);
    });

    it('avisa si el usuario rechaza el permiso', async () => {
      prepararEntorno({ ua: UA.escritorio, instalada: true });
      window.Notification.requestPermission = vi.fn(() => Promise.resolve('denied'));

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      fireEvent.click(await screen.findByRole('button', { name: /Activar notificaciones/ }));

      expect(await screen.findByText(/Bloqueaste las notificaciones/)).toBeInTheDocument();
    });

    it('avisa si el servidor falla', async () => {
      prepararEntorno({ ua: UA.escritorio, instalada: true });
      global.fetch = vi.fn((url) =>
        Promise.resolve(
          String(url).includes('vapid') ? respuesta(200, { public_key: 'aGVsbG8' }) : respuesta(500, {})
        )
      );

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      fireEvent.click(await screen.findByRole('button', { name: /Activar notificaciones/ }));

      expect(await screen.findByText(/No se pudo activar/)).toBeInTheDocument();
    });
  });

  // ============================================================ ya suscrito

  describe('cuando ya está activado', () => {
    it('lo indica y ofrece desactivar', async () => {
      prepararEntorno({ ua: UA.escritorio, instalada: true, suscrito: true });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText('Notificaciones activadas')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Desactivar en este dispositivo/ })).toBeInTheDocument();
    });

    it('muestra la guía de batería de la marca del teléfono', async () => {
      // El ahorro de batería del fabricante demora o bloquea las
      // notificaciones; es del sistema, no de la PWA.
      prepararEntorno({ ua: UA.samsung, instalada: true, suscrito: true });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      expect(await screen.findByText(/Tu Samsung puede demorar/)).toBeInTheDocument();
      expect(screen.getByText(/Aplicaciones en suspensión/)).toBeInTheDocument();
    });

    it('no muestra guía de batería en iPhone', async () => {
      prepararEntorno({ ua: UA.iphoneSafari, instalada: true, suscrito: true });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      await screen.findByText('Notificaciones activadas');
      expect(screen.queryByText(/puede demorar/)).not.toBeInTheDocument();
    });

    it('desactiva la suscripción', async () => {
      prepararEntorno({ ua: UA.escritorio, instalada: true, suscrito: true });

      renderConProviders(<ActivarNotificaciones />, { auth: autenticado() });

      fireEvent.click(await screen.findByRole('button', { name: /Desactivar en este dispositivo/ }));

      expect(await screen.findByText(/Notificaciones desactivadas/)).toBeInTheDocument();
    });
  });
});
