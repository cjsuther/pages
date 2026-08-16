import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  registerServiceWorker,
  requestNotificationPermission,
  subscribeToPushNotifications,
  unsubscribeFromPushNotifications,
  isPushNotificationSubscribed,
} from '../../src/utils/pushNotifications';
import { respuesta } from '../helpers/api';

/** Suscripción push como la devuelve el navegador. */
const suscripcion = () => ({
  endpoint: 'https://push.example/abc',
  unsubscribe: vi.fn(() => Promise.resolve(true)),
  toJSON: () => ({ endpoint: 'https://push.example/abc' }),
});

function instalarServiceWorker({ registration = null, subscription = null } = {}) {
  const reg = registration || {
    pushManager: {
      subscribe: vi.fn(() => Promise.resolve(subscription || suscripcion())),
      getSubscription: vi.fn(() => Promise.resolve(subscription)),
    },
  };

  Object.defineProperty(navigator, 'serviceWorker', {
    value: {
      register: vi.fn(() => Promise.resolve(reg)),
      getRegistration: vi.fn(() => Promise.resolve(reg)),
    },
    writable: true,
    configurable: true,
  });

  return reg;
}

function quitarServiceWorker() {
  delete navigator.serviceWorker;
}

function instalarNotification(permission, requestResult = permission) {
  window.Notification = {
    permission,
    requestPermission: vi.fn(() => Promise.resolve(requestResult)),
  };
  global.Notification = window.Notification;
}

describe('pushNotifications', () => {
  beforeEach(() => {
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.PushManager = function PushManager() {};
  });

  afterEach(() => {
    quitarServiceWorker();
    delete window.Notification;
    delete global.Notification;
    delete window.PushManager;
  });

  // ------------------------------------------------- registerServiceWorker

  describe('registerServiceWorker', () => {
    it('devuelve null si el navegador no lo soporta', async () => {
      quitarServiceWorker();

      await expect(registerServiceWorker()).resolves.toBeNull();
    });

    it('registra /sw.js y devuelve el registration', async () => {
      const reg = instalarServiceWorker();

      await expect(registerServiceWorker()).resolves.toBe(reg);
      expect(navigator.serviceWorker.register).toHaveBeenCalledWith('/sw.js');
    });

    it('devuelve null si el registro falla', async () => {
      Object.defineProperty(navigator, 'serviceWorker', {
        value: { register: vi.fn(() => Promise.reject(new Error('falló'))) },
        writable: true,
        configurable: true,
      });

      await expect(registerServiceWorker()).resolves.toBeNull();
    });
  });

  // ------------------------------------------ requestNotificationPermission

  describe('requestNotificationPermission', () => {
    it('devuelve false si el navegador no soporta notificaciones', async () => {
      await expect(requestNotificationPermission()).resolves.toBe(false);
    });

    it('devuelve true si ya está concedido, sin volver a preguntar', async () => {
      instalarNotification('granted');

      await expect(requestNotificationPermission()).resolves.toBe(true);
      expect(window.Notification.requestPermission).not.toHaveBeenCalled();
    });

    it('devuelve false si el usuario ya lo denegó, sin volver a preguntar', async () => {
      instalarNotification('denied');

      await expect(requestNotificationPermission()).resolves.toBe(false);
      expect(window.Notification.requestPermission).not.toHaveBeenCalled();
    });

    it('pregunta si el permiso está en default y acepta', async () => {
      instalarNotification('default', 'granted');

      await expect(requestNotificationPermission()).resolves.toBe(true);
      expect(window.Notification.requestPermission).toHaveBeenCalledOnce();
    });

    it('pregunta si el permiso está en default y el usuario rechaza', async () => {
      instalarNotification('default', 'denied');

      await expect(requestNotificationPermission()).resolves.toBe(false);
    });
  });

  // ---------------------------------------- subscribeToPushNotifications

  describe('subscribeToPushNotifications', () => {
    it('no hace nada si no hay permiso', async () => {
      instalarNotification('denied');
      global.fetch = vi.fn();

      await expect(subscribeToPushNotifications('tok')).resolves.toBe(false);
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('no hace nada si el service worker no se registra', async () => {
      instalarNotification('granted');
      quitarServiceWorker();
      global.fetch = vi.fn();

      await expect(subscribeToPushNotifications('tok')).resolves.toBe(false);
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('devuelve false si el servidor no da la clave VAPID', async () => {
      instalarNotification('granted');
      instalarServiceWorker();
      global.fetch = vi.fn(() => Promise.resolve(respuesta(200, {})));

      await expect(subscribeToPushNotifications('tok')).resolves.toBe(false);
    });

    it('se suscribe y envía la suscripción al servidor', async () => {
      instalarNotification('granted');
      const reg = instalarServiceWorker();
      global.fetch = vi.fn((url, opts = {}) =>
        Promise.resolve(
          opts.method === 'POST'
            ? respuesta(200, { success: true })
            : respuesta(200, { public_key: 'BObs_base64url' })
        )
      );

      await expect(subscribeToPushNotifications('mi-token')).resolves.toBe(true);

      expect(reg.pushManager.subscribe).toHaveBeenCalledWith(
        expect.objectContaining({ userVisibleOnly: true })
      );

      const post = global.fetch.mock.calls.find(([, o]) => o && o.method === 'POST');
      expect(post[1].headers.Authorization).toBe('Bearer mi-token');
    });

    it('convierte la clave VAPID a Uint8Array', async () => {
      instalarNotification('granted');
      const reg = instalarServiceWorker();
      global.fetch = vi.fn((url, opts = {}) =>
        Promise.resolve(
          opts.method === 'POST'
            ? respuesta(200, {})
            : respuesta(200, { public_key: 'aGVsbG8' })
        )
      );

      await subscribeToPushNotifications('tok');

      const { applicationServerKey } = reg.pushManager.subscribe.mock.calls[0][0];
      expect(applicationServerKey).toBeInstanceOf(Uint8Array);
      expect(new TextDecoder().decode(applicationServerKey)).toBe('hello');
    });

    it('devuelve false si el servidor rechaza la suscripción', async () => {
      instalarNotification('granted');
      instalarServiceWorker();
      global.fetch = vi.fn((url, opts = {}) =>
        Promise.resolve(
          opts.method === 'POST'
            ? respuesta(500, { error: 'x' })
            : respuesta(200, { public_key: 'aGVsbG8' })
        )
      );

      await expect(subscribeToPushNotifications('tok')).resolves.toBe(false);
    });

    it('devuelve false ante cualquier excepción', async () => {
      instalarNotification('granted');
      instalarServiceWorker();
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      await expect(subscribeToPushNotifications('tok')).resolves.toBe(false);
    });
  });

  // ------------------------------------ unsubscribeFromPushNotifications

  describe('unsubscribeFromPushNotifications', () => {
    it('devuelve true si no hay service worker registrado', async () => {
      Object.defineProperty(navigator, 'serviceWorker', {
        value: { getRegistration: vi.fn(() => Promise.resolve(null)) },
        writable: true,
        configurable: true,
      });

      await expect(unsubscribeFromPushNotifications('tok')).resolves.toBe(true);
    });

    it('devuelve true si no había suscripción', async () => {
      instalarServiceWorker({ subscription: null });
      global.fetch = vi.fn();

      await expect(unsubscribeFromPushNotifications('tok')).resolves.toBe(true);
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('desuscribe localmente y avisa al servidor', async () => {
      const sub = suscripcion();
      instalarServiceWorker({ subscription: sub });
      global.fetch = vi.fn(() => Promise.resolve(respuesta(200, {})));

      await expect(unsubscribeFromPushNotifications('mi-token')).resolves.toBe(true);

      expect(sub.unsubscribe).toHaveBeenCalledOnce();

      const [, opciones] = global.fetch.mock.calls[0];
      expect(opciones.method).toBe('DELETE');
      expect(opciones.headers.Authorization).toBe('Bearer mi-token');
      expect(JSON.parse(opciones.body)).toEqual({ endpoint: 'https://push.example/abc' });
    });

    it('devuelve false ante una excepción', async () => {
      Object.defineProperty(navigator, 'serviceWorker', {
        value: { getRegistration: vi.fn(() => Promise.reject(new Error('x'))) },
        writable: true,
        configurable: true,
      });

      await expect(unsubscribeFromPushNotifications('tok')).resolves.toBe(false);
    });
  });

  // ------------------------------------------ isPushNotificationSubscribed

  describe('isPushNotificationSubscribed', () => {
    it('es false si no hay soporte de service worker', async () => {
      quitarServiceWorker();

      await expect(isPushNotificationSubscribed()).resolves.toBe(false);
    });

    it('es false si no hay PushManager', async () => {
      instalarServiceWorker();
      delete window.PushManager;

      await expect(isPushNotificationSubscribed()).resolves.toBe(false);
    });

    it('es false si no hay registration', async () => {
      Object.defineProperty(navigator, 'serviceWorker', {
        value: { getRegistration: vi.fn(() => Promise.resolve(null)) },
        writable: true,
        configurable: true,
      });

      await expect(isPushNotificationSubscribed()).resolves.toBe(false);
    });

    it('es false si no hay suscripción activa', async () => {
      instalarServiceWorker({ subscription: null });

      await expect(isPushNotificationSubscribed()).resolves.toBe(false);
    });

    it('es true si hay suscripción activa', async () => {
      instalarServiceWorker({ subscription: suscripcion() });

      await expect(isPushNotificationSubscribed()).resolves.toBe(true);
    });
  });
});
