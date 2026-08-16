import { describe, it, expect, vi, beforeEach } from 'vitest';
import { pageview, trackEvent, GA_MEASUREMENT_ID } from '../../src/utils/analytics';

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
    it('envía la config con la ruta y el título', () => {
      pageview('/mi-pagina?x=1', 'Mi página');

      expect(window.gtag).toHaveBeenCalledWith('config', GA_MEASUREMENT_ID, {
        page_path: '/mi-pagina?x=1',
        page_title: 'Mi página',
      });
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

    it('pageView combina el nombre con los parámetros extra', () => {
      trackEvent.pageView('Dashboard', { seccion: 'links' });

      expect(window.gtag).toHaveBeenCalledWith('event', 'page_view', {
        page_name: 'Dashboard',
        seccion: 'links',
      });
    });

    it('event permite enviar cualquier acción', () => {
      trackEvent.event('accion_propia', { a: 1 });

      expect(window.gtag).toHaveBeenCalledWith('event', 'accion_propia', { a: 1 });
    });
  });

  it('expone un measurement id', () => {
    expect(typeof GA_MEASUREMENT_ID).toBe('string');
    expect(GA_MEASUREMENT_ID.length).toBeGreaterThan(0);
  });
});
