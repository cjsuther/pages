import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { esPixelValido, iniciarPixel, evento, vista, olvidarPixeles } from '../../src/utils/metaPixel';

const PIXEL = '1234567890123456';

describe('metaPixel', () => {
  beforeEach(() => {
    olvidarPixeles();
    delete window.fbq;
    delete window._fbq;
    document.head.querySelectorAll('script[src*="fbevents"]').forEach((s) => s.remove());
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('un pixel es un número largo', () => {
    expect(esPixelValido(PIXEL)).toBe(true);
    expect(esPixelValido('123')).toBe(false);
    expect(esPixelValido('<script>...</script>')).toBe(false);
    expect(esPixelValido(null)).toBe(false);
  });

  /** Una página sin pixel no tiene por qué cargar nada de Meta. */
  it('sin pixel no carga la librería', () => {
    expect(iniciarPixel('')).toBe(false);
    expect(iniciarPixel(null)).toBe(false);
    expect(document.head.querySelector('script[src*="fbevents"]')).toBeNull();
    expect(window.fbq).toBeUndefined();
  });

  it('con pixel carga la librería una sola vez y declara el pixel', () => {
    expect(iniciarPixel(PIXEL)).toBe(true);
    iniciarPixel(PIXEL);

    expect(document.head.querySelectorAll('script[src*="fbevents"]')).toHaveLength(1);
    expect(window.fbq.queue.filter((l) => l[0] === 'init')).toHaveLength(1);
  });

  /**
   * En una pestaña pueden quedar declarados los pixeles de dos páginas: con
   * `track` a secas, la compra de una aparecería en la cuenta de la otra.
   */
  it('los eventos van sólo al pixel de esa página', () => {
    iniciarPixel(PIXEL);
    window.fbq = vi.fn();

    evento(PIXEL, 'Purchase', { value: 3000, currency: 'ARS' });

    expect(window.fbq).toHaveBeenCalledWith('trackSingle', PIXEL, 'Purchase', { value: 3000, currency: 'ARS' });
  });

  it('una vista es un PageView', () => {
    iniciarPixel(PIXEL);
    window.fbq = vi.fn();

    expect(vista(PIXEL)).toBe(true);
    expect(window.fbq).toHaveBeenCalledWith('trackSingle', PIXEL, 'PageView', undefined);
  });

  it('sin pixel no se manda ningún evento', () => {
    window.fbq = vi.fn();

    expect(evento('', 'Purchase')).toBe(false);
    expect(window.fbq).not.toHaveBeenCalled();
  });
});
