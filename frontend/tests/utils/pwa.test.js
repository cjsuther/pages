import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  estaInstalada,
  detectarMarca,
  guiaDeBateria,
  detectarEntorno,
  diagnosticar,
  base64UrlABytes,
  PASOS,
} from '../../src/utils/pwa';

const UA = {
  iphoneSafari:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
  iphoneChrome:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1',
  androidSamsung:
    'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36',
  escritorio:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
};

/** Sustituye el User-Agent y devuelve la función para restaurarlo. */
function conUA(ua) {
  const original = Object.getOwnPropertyDescriptor(navigator, 'userAgent');
  Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });
  return () => {
    if (original) Object.defineProperty(navigator, 'userAgent', original);
  };
}

function simularInstalada({ displayMode = false, iosStandalone = false } = {}) {
  window.matchMedia = vi.fn(() => ({ matches: displayMode, addEventListener: vi.fn(), removeEventListener: vi.fn() }));
  window.navigator.standalone = iosStandalone;
}

function simularSoportePush(soporta) {
  if (soporta) {
    window.PushManager = function PushManager() {};
    if (!('serviceWorker' in navigator)) {
      Object.defineProperty(navigator, 'serviceWorker', { value: {}, configurable: true });
    }
  } else {
    delete window.PushManager;
  }
}

function simularPermiso(estado) {
  window.Notification = { permission: estado, requestPermission: vi.fn() };
  global.Notification = window.Notification;
}

describe('pwa', () => {
  let restaurarUA = () => {};

  beforeEach(() => {
    simularInstalada();
    simularSoportePush(true);
    simularPermiso('default');
  });

  afterEach(() => {
    restaurarUA();
    delete window.PushManager;
    delete window.Notification;
    delete global.Notification;
    delete window.navigator.standalone;
  });

  // ============================================================ instalación

  describe('estaInstalada', () => {
    it('es false en una pestaña normal', () => {
      simularInstalada();

      expect(estaInstalada()).toBe(false);
    });

    it('detecta la instalación por display-mode (Android y escritorio)', () => {
      simularInstalada({ displayMode: true });

      expect(estaInstalada()).toBe(true);
    });

    /** En iPhone display-mode siempre da false: hay que mirar navigator.standalone. */
    it('detecta la instalación por navigator.standalone (iOS)', () => {
      simularInstalada({ displayMode: false, iosStandalone: true });

      expect(estaInstalada()).toBe(true);
    });
  });

  // ================================================================== marca

  describe('detectarMarca', () => {
    it.each([
      ['Mozilla/5.0 (Linux; Android 14; SM-A546E)', 'Samsung'],
      ['Mozilla/5.0 (Linux; Android 13; Redmi Note 12)', 'Xiaomi'],
      ['Mozilla/5.0 (Linux; Android 13; POCO X5)', 'Xiaomi'],
      ['Mozilla/5.0 (Linux; Android 12; HUAWEI P40)', 'Huawei'],
      ['Mozilla/5.0 (Linux; Android 13; CPH2381)', 'Oppo'],
    ])('reconoce %s', (ua, esperada) => {
      expect(detectarMarca(ua)).toBe(esperada);
    });

    it('devuelve null si no reconoce la marca', () => {
      expect(detectarMarca(UA.iphoneSafari)).toBeNull();
    });
  });

  describe('guiaDeBateria', () => {
    it('da instrucciones concretas para las marcas agresivas', () => {
      expect(guiaDeBateria('Xiaomi')).toContain('Inicio automático');
      expect(guiaDeBateria('Samsung')).toContain('suspensión');
    });

    it('no inventa una guía para marcas sin problema conocido', () => {
      expect(guiaDeBateria(null)).toBeNull();
      expect(guiaDeBateria('OtraMarca')).toBeNull();
    });
  });

  // =============================================================== entorno

  describe('detectarEntorno', () => {
    it('reconoce iPhone con Safari', () => {
      restaurarUA = conUA(UA.iphoneSafari);

      const entorno = detectarEntorno();

      expect(entorno.esIOS).toBe(true);
      expect(entorno.esSafariIOS).toBe(true);
      expect(entorno.esAndroid).toBe(false);
    });

    it('reconoce Chrome en iPhone, que no puede instalar', () => {
      restaurarUA = conUA(UA.iphoneChrome);

      expect(detectarEntorno().esSafariIOS).toBe(false);
    });

    it('fuera de iOS la pregunta por Safari no aplica', () => {
      restaurarUA = conUA(UA.androidSamsung);

      const entorno = detectarEntorno();

      expect(entorno.esAndroid).toBe(true);
      expect(entorno.esSafariIOS).toBe(true);
    });

    it('el soporte se decide por capacidades, no por versión', () => {
      restaurarUA = conUA(UA.iphoneSafari);
      simularSoportePush(false);

      expect(detectarEntorno().soportaPush).toBe(false);

      simularSoportePush(true);
      expect(detectarEntorno().soportaPush).toBe(true);
    });

    it('informa el permiso actual', () => {
      simularPermiso('granted');

      expect(detectarEntorno().permiso).toBe('granted');
    });

    it('informa cuando no hay API de notificaciones', () => {
      delete window.Notification;
      delete global.Notification;

      expect(detectarEntorno().permiso).toBe('no-soportado');
    });
  });

  // ============================================================ diagnóstico

  describe('diagnosticar — el orden es el contrato', () => {
    /**
     * Este es el error 2.2 de la guía: en iPhone sin instalar, PushManager no
     * existe. Si se preguntara por la capacidad antes que por la instalación,
     * el usuario recibiría "tu navegador no soporta notificaciones", iría a
     * Ajustes a buscar una actualización que no le falta, y se perdería ahí.
     */
    it('en iPhone sin instalar pide instalar, no habla de soporte', () => {
      const d = diagnosticar({
        esIOS: true, esAndroid: false, esSafariIOS: true,
        instalada: false, soportaPush: false, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.INSTALAR);
      expect(d.titulo).toContain('pantalla de inicio');
      expect(d.mensaje).not.toMatch(/no soporta|no admite/i);
      expect(d.puedeSuscribirse).toBe(false);
    });

    it('el navegador se verifica antes que la instalación', () => {
      const d = diagnosticar({
        esIOS: true, esAndroid: false, esSafariIOS: false,
        instalada: false, soportaPush: false, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.NAVEGADOR);
      expect(d.titulo).toContain('Safari');
    });

    it('en iPhone instalado y sin soporte real, recién ahí habla de versión', () => {
      const d = diagnosticar({
        esIOS: true, esAndroid: false, esSafariIOS: true,
        instalada: true, soportaPush: false, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.SOPORTE);
      expect(d.mensaje).toContain('16.4');
    });

    it('el permiso denegado se explica sin prometer que se puede reintentar', () => {
      const d = diagnosticar({
        esIOS: true, esAndroid: false, esSafariIOS: true,
        instalada: true, soportaPush: true, permiso: 'denied', marca: null,
      });

      expect(d.paso).toBe(PASOS.PERMISO_DENEGADO);
      expect(d.puedeSuscribirse).toBe(false);
      expect(d.instrucciones.join(' ')).toContain('Ajustes');
    });

    /**
     * Chrome y Firefox de computadora declaran soporte de push, así que sin
     * esta rama el diagnóstico llegaba hasta LISTO y ofrecía activarlas. Acá no
     * llegan a funcionar: mejor no ofrecer nada que un botón que no hace nada.
     */
    it('en una computadora no se pueden activar', () => {
      const d = diagnosticar({
        esIOS: false, esAndroid: false, esEscritorio: true, esSafariIOS: true,
        instalada: false, soportaPush: true, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.SOLO_MOVIL);
      expect(d.puedeSuscribirse).toBe(false);
    });

    /** El teléfono no se ve afectado: ahí el diagnóstico sigue igual. */
    it('en un teléfono la rama de escritorio no interfiere', () => {
      const d = diagnosticar({
        esIOS: false, esAndroid: true, esEscritorio: false, esSafariIOS: true,
        instalada: true, soportaPush: true, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.LISTO);
    });

    it('todo en orden habilita la suscripción', () => {
      const d = diagnosticar({
        esIOS: true, esAndroid: false, esSafariIOS: true,
        instalada: true, soportaPush: true, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.LISTO);
      expect(d.puedeSuscribirse).toBe(true);
    });

    it('en Android sin instalar sugiere instalar pero deja continuar', () => {
      // A diferencia de iOS, en Android el push funciona sin instalar.
      const d = diagnosticar({
        esIOS: false, esAndroid: true, esSafariIOS: true,
        instalada: false, soportaPush: true, permiso: 'default', marca: 'Samsung',
      });

      expect(d.paso).toBe(PASOS.INSTALAR);
      expect(d.opcional).toBe(true);
      expect(d.puedeSuscribirse).toBe(true);
    });

    it('en Android sin instalar y con permiso denegado no deja continuar', () => {
      const d = diagnosticar({
        esIOS: false, esAndroid: true, esSafariIOS: true,
        instalada: false, soportaPush: true, permiso: 'denied', marca: null,
      });

      expect(d.puedeSuscribirse).toBe(false);
    });

    it('en escritorio con soporte está listo', () => {
      const d = diagnosticar({
        esIOS: false, esAndroid: false, esSafariIOS: true,
        instalada: false, soportaPush: true, permiso: 'default', marca: null,
      });

      expect(d.paso).toBe(PASOS.LISTO);
    });

    it('las instrucciones de iOS nombran los botones reales', () => {
      const d = diagnosticar({
        esIOS: true, esAndroid: false, esSafariIOS: true,
        instalada: false, soportaPush: false, permiso: 'default', marca: null,
      });

      const texto = d.instrucciones.join(' ');

      expect(texto).toContain('Compartir');
      expect(texto).toContain('Agregar a inicio');
    });
  });

  // =========================================================== clave VAPID

  describe('base64UrlABytes', () => {
    it('convierte a Uint8Array', () => {
      const bytes = base64UrlABytes('aGVsbG8');

      expect(bytes).toBeInstanceOf(Uint8Array);
      expect(new TextDecoder().decode(bytes)).toBe('hello');
    });

    it('agrega el relleno que falta', () => {
      // "aGk" sin relleno equivale a "aGk=" con él.
      expect(new TextDecoder().decode(base64UrlABytes('aGk'))).toBe('hi');
    });

    it('traduce los caracteres propios de base64url', () => {
      const conBase64Url = base64UrlABytes('-_8');
      expect(conBase64Url).toBeInstanceOf(Uint8Array);
      expect(conBase64Url.length).toBeGreaterThan(0);
    });
  });
});
