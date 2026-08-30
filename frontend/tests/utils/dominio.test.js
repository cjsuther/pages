import { describe, it, expect, afterEach } from 'vitest';
import { paginaDelDominio } from '../../src/utils/dominio';

/**
 * En un dominio propio la dirección no dice qué página mostrar: lo resuelve
 * index.php contra el Host y lo deja en window.
 */
describe('paginaDelDominio', () => {
  afterEach(() => {
    delete window.__PAGINA_DEL_DOMINIO__;
  });

  it('devuelve la página que dejó el servidor', () => {
    window.__PAGINA_DEL_DOMINIO__ = 'maxipeque';

    expect(paginaDelDominio()).toBe('maxipeque');
  });

  it('en rezon.ar no hay ninguna', () => {
    expect(paginaDelDominio()).toBeNull();
  });

  // Se lee en cada llamada y no al cargar el módulo: si se congelara antes de
  // que index.php lo escriba, el dominio propio mostraría el home.
  it('ve el valor aunque se haya puesto después de importar', () => {
    expect(paginaDelDominio()).toBeNull();

    window.__PAGINA_DEL_DOMINIO__ = 'maxipeque';

    expect(paginaDelDominio()).toBe('maxipeque');
  });
});
