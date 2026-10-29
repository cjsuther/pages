import { describe, it, expect } from 'vitest';
import { compartirLink, linkDeEvento } from '../../src/utils/compartir';
import { compartirLink as compartirLinkDeProductos } from '../../src/utils/productos';

describe('linkDeEvento', () => {
  it('sale del dominio en el que se está', () => {
    expect(linkDeEvento(200)).toBe(`${window.location.origin}/evento/200`);
  });

  it('acepta otro origen, como un dominio propio', () => {
    expect(linkDeEvento(7, 'https://mi-banda.com')).toBe('https://mi-banda.com/evento/7');
  });
});

describe('compartirLink', () => {
  it('es el mismo que se usaba desde utils/productos', () => {
    expect(compartirLinkDeProductos).toBe(compartirLink);
  });
});
