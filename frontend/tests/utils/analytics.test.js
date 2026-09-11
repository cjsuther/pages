import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pageview, trackEvent, iniciarAnalytics, GA_MEASUREMENT_ID } from '../../src/utils/analytics';

describe('analytics', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  describe('cuando gtag no está cargado', () => {
    beforeEach(() => {
      window.gtag = undefined;
    });

    it('pageview no rompe', () => {
      expect(() => pageview('/inicio', 'Inicio')).not.toThrow();
    });

    it('los eventos no rompen', () => {
      expect(() => trackEvent.userLogin()).not.toThrow();
      expect(() => trackEvent.clickLink('https://x.com', 'X')).not.toThrow();
      expect(() => trackEvent.viewMap()).not.toThrow();
    });
  });

  describe('pageview', () => {
    /**
     * Va como evento y no como `config`.
     *
     * Con `config` había que nombrar la propiedad en cada vista, y nombrarla
     * mal mandaba la vista a otro lado sin que nada fallara: es exactamente lo
     * que pasaba en producción, donde la variable no estaba definida y todas
     * las vistas iban a un identificador de relleno. Un evento va a la
     * propiedad que ya está configurada y no hay dónde equivocarse.
     */
    it('manda la vista como evento, no como config', () => {
      pageview('/mi-pagina?x=1', 'Mi página');

      expect(window.gtag).toHaveBeenCalledWith('event', 'page_view', {
        page_path: '/mi-pagina?x=1',
        page_title: 'Mi página',
        page_location: window.location.href,
      });
      expect(window.gtag).not.toHaveBeenCalledWith('config', expect.anything(), expect.anything());
    });
  });

  describe('iniciarAnalytics', () => {
    beforeEach(() => {
      delete window.gtag;
      delete window.dataLayer;
      document.head.querySelectorAll('script[src*="googletagmanager"]').forEach((e) => e.remove());
    });

    afterEach(() => {
      document.head.querySelectorAll('script[src*="googletagmanager"]').forEach((e) => e.remove());
    });

    it('carga el tag de la propiedad configurada', () => {
      iniciarAnalytics();

      const tag = document.head.querySelector('script[src*="googletagmanager"]');
      expect(tag.src).toContain(GA_MEASUREMENT_ID);
      expect(tag.async).toBe(true);
    });

    /**
     * Esto es una SPA: si Google manda la vista automática y la aplicación
     * manda la suya, la primera pantalla de cada visita cuenta dos veces.
     */
    it('deja las vistas en manos de la aplicación', () => {
      iniciarAnalytics();

      expect(window.dataLayer.some(
        (a) => a[0] === 'config' && a[1] === GA_MEASUREMENT_ID && a[2].send_page_view === false
      )).toBe(true);
    });

    it('no carga el tag dos veces', () => {
      iniciarAnalytics();

      expect(iniciarAnalytics()).toBe(false);
      expect(document.head.querySelectorAll('script[src*="googletagmanager"]')).toHaveLength(1);
    });

    /**
     * Sin propiedad no se pide nada a Google. Antes se le pedía igual, con un
     * identificador de relleno.
     */
    it('sin propiedad configurada no carga nada', async () => {
      vi.stubEnv('VITE_GA_MEASUREMENT_ID', '');
      vi.resetModules();

      const modulo = await import('../../src/utils/analytics');

      expect(modulo.iniciarAnalytics()).toBe(false);
      expect(document.head.querySelector('script[src*="googletagmanager"]')).toBeNull();

      vi.unstubAllEnvs();
      vi.resetModules();
    });

    it('sin propiedad configurada tampoco manda eventos', async () => {
      vi.stubEnv('VITE_GA_MEASUREMENT_ID', '');
      vi.resetModules();

      const modulo = await import('../../src/utils/analytics');
      window.gtag = vi.fn();
      modulo.pageview('/inicio', 'Inicio');
      modulo.trackEvent.userLogin();

      expect(window.gtag).not.toHaveBeenCalled();

      vi.unstubAllEnvs();
      vi.resetModules();
    });
  });

  describe('trackEvent', () => {
    it.each([
      ['userLogin', [], 'login', { method: 'email' }],
      ['userLogin', ['google'], 'login', { method: 'google' }],
      ['userRegister', [], 'sign_up', { method: 'email' }],
      ['userRegister', ['apple'], 'sign_up', { method: 'apple' }],
      ['createPage', [7], 'create_page', { page_id: 7 }],
      ['editPage', [7], 'edit_page', { page_id: 7 }],
      ['addLink', ['evento'], 'add_link', { link_type: 'evento' }],
      ['addEvent', [12], 'add_event', { event_id: 12 }],
      ['viewPublicPage', ['mi-slug'], 'view_public_page', { page_slug: 'mi-slug' }],
      ['searchPages', ['rock'], 'search', { search_term: 'rock' }],
      ['changeTemplate', ['cards'], 'change_template', { template_name: 'cards' }],
      ['uploadImage', ['perfil'], 'upload_image', { image_type: 'perfil' }],
      ['interactMap', ['zoom'], 'map_interaction', { interaction_type: 'zoom' }],
    ])('%s emite el evento correcto', (metodo, args, accionEsperada, paramsEsperados) => {
      trackEvent[metodo](...args);

      expect(window.gtag).toHaveBeenCalledWith('event', accionEsperada, paramsEsperados);
    });

    it('clickLink incluye url y título', () => {
      trackEvent.clickLink('https://x.com', 'Mi link');

      expect(window.gtag).toHaveBeenCalledWith('event', 'click_link', {
        link_url: 'https://x.com',
        link_title: 'Mi link',
      });
    });

    it('shareEvent incluye el método de compartido', () => {
      trackEvent.shareEvent(12, 'whatsapp');

      expect(window.gtag).toHaveBeenCalledWith('event', 'share_event', {
        event_id: 12,
        method: 'whatsapp',
      });
    });

    it('viewMap no lleva parámetros propios', () => {
      trackEvent.viewMap();

      expect(window.gtag).toHaveBeenCalledWith('event', 'view_map', {});
    });

    it('event permite enviar cualquier acción', () => {
      trackEvent.event('accion_propia', { a: 1 });

      expect(window.gtag).toHaveBeenCalledWith('event', 'accion_propia', { a: 1 });
    });
  });

  /** La propiedad sale de la variable de entorno y de ningún otro lado. */
  it('la propiedad viene del entorno', () => {
    expect(GA_MEASUREMENT_ID).toBe('G-TEST00000');
  });

  /**
   * Que no vuelva a haber dos copias de la propiedad.
   *
   * Este error ya pasó dos veces. Estaba en duro en los dos puntos de entrada
   * —index.html y public/index.php, que es el que sirve producción— y además
   * por variable acá. La variable no estaba definida en producción, así que
   * todo lo que mandaba la aplicación iba a un identificador de relleno y
   * nadie se enteraba: no falla nada, los datos simplemente no llegan.
   */
  it('ningún punto de entrada lleva la propiedad en duro', () => {
    ['index.html', 'public/index.php'].forEach((archivo) => {
      const contenido = readFileSync(resolve(__dirname, '../../', archivo), 'utf8');

      expect(contenido, `${archivo} tiene una propiedad de Google en duro`)
        .not.toMatch(/G-[A-Z0-9]{8,}/);
      expect(contenido, `${archivo} carga el tag por su cuenta`)
        .not.toContain('googletagmanager.com');
    });
  });
});
