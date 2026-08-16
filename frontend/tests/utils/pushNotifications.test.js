import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  registrarServiceWorker,
  pedirPermiso,
  obtenerClaveVapid,
  activarNotificaciones,
  desactivarNotificaciones,
  estaSuscrito,
} from '../../src/utils/pushNotifications';
import { respuesta } from '../helpers/api';

const API = 'https://rezon.ar/api';
const CLAVE_VAPID = 'aGVsbG8'; // base64url de "hello"

const suscripcion = (endpoint = 'https://push.example/abc') => ({
  endpoint,
  unsubscribe: vi.fn(() => Promise.resolve(true)),
  toJSON: () => ({ endpoint, keys: { p256dh: 'clave-p', auth: 'clave-a' } }),
});

function instalarServiceWorker({ registro = null, suscripcionActual = null } = {}) {
  const reg = registro || {
    pushManager: {
      subscribe: vi.fn(() => Promise.resolve(suscripcion())),
      getSubscription: vi.fn(() => Promise.resolve(suscripcionActual)),
    },
  };

  Object.defineProperty(navigator, 'serviceWorker', {
    value: {
      register: vi.fn(() => Promise.resolve(reg)),
      getRegistration: vi.fn(() => Promise.resolve(reg)),
      ready: Promise.resolve(reg),
    },
    writable: true,
    configurable: true,
  });

  return reg;
}

function quitarServiceWorker() {
  delete navigator.serviceWorker;
}

function instalarNotification(permiso, resultado = permiso) {
  window.Notification = {
    permission: permiso,
    requestPermission: vi.fn(() => Promise.resolve(resultado)),
  };
  global.Notification = window.Notification;
}

/** fetch que responde la clave VAPID y acepta el registro de la suscripción. */
function fetchFeliz() {
  return vi.fn((url, opciones = {}) => {
    if (String(url).includes('/push/vapid.php')) {
      return Promise.resolve(respuesta(200, { public_key: CLAVE_VAPID, disponible: true }));
    }
    return Promise.resolve(respuesta(200, { success: true }));
  });
}

describe('pushNotifications', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.PushManager = function PushManager() {};
    window.matchMedia = vi.fn(() => ({ matches: false }));
  });

  afterEach(() => {
    quitarServiceWorker();
    delete window.Notification;
    delete global.Notification;
    delete window.PushManager;
  });

  // ================================================== registrarServiceWorker

  describe('registrarServiceWorker', () => {
    it('devuelve null si el navegador no lo soporta', async () => {
      quitarServiceWorker();

      await expect(registrarServiceWorker()).resolves.toBeNull();
    });

    it('registra /sw.js en el scope raíz', async () => {
      const reg = instalarServiceWorker();

      await expect(registrarServiceWorker()).resolves.toBe(reg);
      expect(navigator.serviceWorker.register).toHaveBeenCalledWith('/sw.js', { scope: '/' });
    });

    it('devuelve null si el registro falla', async () => {
      Object.defineProperty(navigator, 'serviceWorker', {
        value: { register: vi.fn(() => Promise.reject(new Error('MIME incorrecto'))) },
        writable: true,
        configurable: true,
      });

      await expect(registrarServiceWorker()).resolves.toBeNull();
    });
  });

  // ============================================================= pedirPermiso

  describe('pedirPermiso', () => {
    it('devuelve no-soportado si no hay API', async () => {
      await expect(pedirPermiso()).resolves.toBe('no-soportado');
    });

    it('no vuelve a preguntar si ya está concedido', async () => {
      instalarNotification('granted');

      await expect(pedirPermiso()).resolves.toBe('granted');
      expect(window.Notification.requestPermission).not.toHaveBeenCalled();
    });

    /** Una vez en denied el diálogo no se abre más: sólo queda Ajustes. */
    it('no vuelve a preguntar si ya está denegado', async () => {
      instalarNotification('denied');

      await expect(pedirPermiso()).resolves.toBe('denied');
      expect(window.Notification.requestPermission).not.toHaveBeenCalled();
    });

    it('pregunta cuando el estado es default', async () => {
      instalarNotification('default', 'granted');

      await expect(pedirPermiso()).resolves.toBe('granted');
      expect(window.Notification.requestPermission).toHaveBeenCalledOnce();
    });
  });

  // ========================================================= obtenerClaveVapid

  describe('obtenerClaveVapid', () => {
    it('devuelve la clave pública', async () => {
      global.fetch = fetchFeliz();

      const datos = await obtenerClaveVapid(API);

      expect(datos.public_key).toBe(CLAVE_VAPID);
      expect(global.fetch).toHaveBeenCalledWith(`${API}/push/vapid.php`);
    });

    it('falla con el mensaje del servidor si no está configurado', async () => {
      global.fetch = vi.fn(() =>
        Promise.resolve(respuesta(500, { error: 'Las notificaciones push no están configuradas' }))
      );

      await expect(obtenerClaveVapid(API)).rejects.toThrow('no están configuradas');
    });
  });

  // ==================================================== activarNotificaciones

  describe('activarNotificaciones', () => {
    it('no suscribe si el usuario no concede el permiso', async () => {
      instalarNotification('default', 'denied');
      global.fetch = fetchFeliz();

      const r = await activarNotificaciones(API, 'tok');

      expect(r).toEqual({ ok: false, motivo: 'permiso', permiso: 'denied' });
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('informa si el service worker no se puede registrar', async () => {
      instalarNotification('granted');
      quitarServiceWorker();
      global.fetch = fetchFeliz();

      const r = await activarNotificaciones(API, 'tok');

      expect(r).toEqual({ ok: false, motivo: 'service-worker' });
    });

    it('suscribe y registra la suscripción en el servidor', async () => {
      instalarNotification('granted');
      const reg = instalarServiceWorker();
      global.fetch = fetchFeliz();

      const r = await activarNotificaciones(API, 'mi-token');

      expect(r.ok).toBe(true);
      expect(reg.pushManager.subscribe).toHaveBeenCalledWith(
        expect.objectContaining({ userVisibleOnly: true })
      );

      const registro = global.fetch.mock.calls.find(([u]) => String(u).includes('/push/subscribe.php'));
      expect(registro[1].method).toBe('POST');
      expect(registro[1].headers.Authorization).toBe('Bearer mi-token');
      expect(JSON.parse(registro[1].body).suscripcion.endpoint).toBe('https://push.example/abc');
    });

    it('reutiliza la suscripción existente en vez de crear otra', async () => {
      instalarNotification('granted');
      const existente = suscripcion('https://push.example/ya-existe');
      const reg = instalarServiceWorker({ suscripcionActual: existente });
      global.fetch = fetchFeliz();

      await activarNotificaciones(API, 'tok');

      expect(reg.pushManager.subscribe).not.toHaveBeenCalled();
      const registro = global.fetch.mock.calls.find(([u]) => String(u).includes('/push/subscribe.php'));
      expect(JSON.parse(registro[1].body).suscripcion.endpoint).toBe('https://push.example/ya-existe');
    });

    it('informa al servidor si la app está instalada', async () => {
      instalarNotification('granted');
      instalarServiceWorker();
      window.matchMedia = vi.fn(() => ({ matches: true }));
      global.fetch = fetchFeliz();

      await activarNotificaciones(API, 'tok');

      const registro = global.fetch.mock.calls.find(([u]) => String(u).includes('/push/subscribe.php'));
      expect(JSON.parse(registro[1].body).standalone).toBe(true);
    });

    it('convierte la clave VAPID a Uint8Array', async () => {
      instalarNotification('granted');
      const reg = instalarServiceWorker();
      global.fetch = fetchFeliz();

      await activarNotificaciones(API, 'tok');

      const { applicationServerKey } = reg.pushManager.subscribe.mock.calls[0][0];
      expect(applicationServerKey).toBeInstanceOf(Uint8Array);
      expect(new TextDecoder().decode(applicationServerKey)).toBe('hello');
    });

    it('informa si falla la suscripción del navegador', async () => {
      instalarNotification('granted');
      instalarServiceWorker({
        registro: {
          pushManager: {
            getSubscription: vi.fn(() => Promise.resolve(null)),
            subscribe: vi.fn(() => Promise.reject(new Error('AbortError'))),
          },
        },
      });
      global.fetch = fetchFeliz();

      const r = await activarNotificaciones(API, 'tok');

      expect(r).toEqual({ ok: false, motivo: 'suscripcion' });
    });

    it('informa si el servidor rechaza el registro', async () => {
      instalarNotification('granted');
      instalarServiceWorker();
      global.fetch = vi.fn((url) =>
        Promise.resolve(
          String(url).includes('/push/vapid.php')
            ? respuesta(200, { public_key: CLAVE_VAPID })
            : respuesta(500, { error: 'x' })
        )
      );

      const r = await activarNotificaciones(API, 'tok');

      expect(r).toEqual({ ok: false, motivo: 'servidor' });
    });
  });

  // ================================================== desactivarNotificaciones

  describe('desactivarNotificaciones', () => {
    it('devuelve true si no hay nada que desactivar', async () => {
      instalarServiceWorker({ suscripcionActual: null });
      global.fetch = vi.fn();

      await expect(desactivarNotificaciones(API, 'tok')).resolves.toBe(true);
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('desuscribe localmente y avisa al servidor', async () => {
      const sus = suscripcion();
      instalarServiceWorker({ suscripcionActual: sus });
      global.fetch = vi.fn(() => Promise.resolve(respuesta(200, { success: true })));

      await expect(desactivarNotificaciones(API, 'mi-token')).resolves.toBe(true);

      expect(sus.unsubscribe).toHaveBeenCalledOnce();
      const [url, opciones] = global.fetch.mock.calls[0];
      expect(String(url)).toContain('/push/subscribe.php');
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

      await expect(desactivarNotificaciones(API, 'tok')).resolves.toBe(false);
    });
  });

  // ============================================================== estaSuscrito

  describe('estaSuscrito', () => {
    it('es false sin soporte', async () => {
      quitarServiceWorker();

      await expect(estaSuscrito()).resolves.toBe(false);
    });

    it('es false sin PushManager', async () => {
      instalarServiceWorker();
      delete window.PushManager;

      await expect(estaSuscrito()).resolves.toBe(false);
    });

    it('es false si no hay suscripción', async () => {
      instalarServiceWorker({ suscripcionActual: null });

      await expect(estaSuscrito()).resolves.toBe(false);
    });

    it('es true si hay suscripción activa', async () => {
      instalarServiceWorker({ suscripcionActual: suscripcion() });

      await expect(estaSuscrito()).resolves.toBe(true);
    });
  });
});
