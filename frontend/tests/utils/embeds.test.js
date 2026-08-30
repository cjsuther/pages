import { describe, it, expect } from 'vitest';
import { analizarEmbed, esEmbed, portadaDe } from '../../src/utils/embeds';

/**
 * Lo que pega el usuario es la URL que tenía a mano: la de la barra del
 * navegador, la del botón compartir o la de un short. Todas tienen que llevar
 * al mismo video.
 */
describe('analizarEmbed', () => {
  describe('YouTube', () => {
    const ID = 'dQw4w9WgXcQ';

    it.each([
      ['página del video', `https://www.youtube.com/watch?v=${ID}`],
      ['sin www', `https://youtube.com/watch?v=${ID}`],
      ['con más parámetros', `https://www.youtube.com/watch?app=desktop&v=${ID}&t=30s`],
      ['link de compartir', `https://youtu.be/${ID}`],
      ['link de compartir con tiempo', `https://youtu.be/${ID}?t=42`],
      ['short', `https://www.youtube.com/shorts/${ID}`],
      ['embed', `https://www.youtube.com/embed/${ID}`],
      ['en vivo', `https://www.youtube.com/live/${ID}`],
    ])('reconoce el %s', (_caso, url) => {
      expect(analizarEmbed(url)).toMatchObject({ tipo: 'youtube', id: ID });
    });

    it('arma el embed sin cookies y sin sugerencias de otros canales', () => {
      expect(analizarEmbed(`https://youtu.be/${ID}`).urlEmbed)
        .toBe(`https://www.youtube-nocookie.com/embed/${ID}?rel=0`);
    });

    it('trae la miniatura del propio video', () => {
      expect(analizarEmbed(`https://youtu.be/${ID}`).miniatura)
        .toBe(`https://img.youtube.com/vi/${ID}/hqdefault.jpg`);
    });

    // El id son once caracteres exactos: recortarlo llevaría a otro video o a
    // ninguno, así que no se acepta.
    it('no acepta un id incompleto', () => {
      expect(analizarEmbed('https://youtu.be/dQw4w9')).toBeNull();
    });
  });

  describe('Instagram', () => {
    it.each([
      ['post', 'https://www.instagram.com/p/CxAbC123_-x/', 'p'],
      ['reel', 'https://www.instagram.com/reel/CxAbC123_-x/', 'reel'],
      ['video antiguo', 'https://www.instagram.com/tv/CxAbC123_-x/', 'tv'],
    ])('reconoce un %s', (_caso, url, seccion) => {
      expect(analizarEmbed(url)).toMatchObject({
        tipo: 'instagram',
        id: 'CxAbC123_-x',
        urlEmbed: `https://www.instagram.com/${seccion}/CxAbC123_-x/embed`,
      });
    });

    // Instagram sirve los reels por las dos rutas, pero el embed vive en la
    // singular.
    it('normaliza /reels/ a /reel/', () => {
      expect(analizarEmbed('https://www.instagram.com/reels/CxAbC123_-x/').urlEmbed)
        .toBe('https://www.instagram.com/reel/CxAbC123_-x/embed');
    });

    it('funciona con parámetros de seguimiento pegados al link', () => {
      expect(analizarEmbed('https://www.instagram.com/p/CxAbC123_-x/?igsh=abc123'))
        .toMatchObject({ tipo: 'instagram', id: 'CxAbC123_-x' });
    });

    // Sin API no hay miniatura pública: quien renderiza tiene que saberlo.
    it('no promete miniatura', () => {
      expect(analizarEmbed('https://www.instagram.com/p/CxAbC123_-x/').miniatura).toBeNull();
    });

    it('el perfil de una cuenta no es contenido embebible', () => {
      expect(analizarEmbed('https://www.instagram.com/una-cuenta/')).toBeNull();
    });
  });

  describe('lo que no se reconoce', () => {
    it.each([
      ['vacío', ''],
      ['sólo espacios', '   '],
      ['nulo', null],
      ['indefinido', undefined],
      ['un número', 42],
      ['otro sitio', 'https://vimeo.com/123456'],
      ['una imagen', 'https://ejemplo.com/foto.jpg'],
    ])('devuelve null con %s', (_caso, valor) => {
      expect(analizarEmbed(valor)).toBeNull();
    });
  });
});

describe('esEmbed', () => {
  it('un item con URL de video es un embed', () => {
    expect(esEmbed({ embed_url: 'https://youtu.be/dQw4w9WgXcQ' })).toBe(true);
  });

  it('una imagen de toda la vida no lo es', () => {
    expect(esEmbed({ image_url: 'https://img/1.jpg', embed_url: null })).toBe(false);
  });

  it('no rompe con un item sin datos', () => {
    expect(esEmbed(undefined)).toBe(false);
  });
});

describe('portadaDe', () => {
  it('la imagen subida manda sobre la del servicio', () => {
    expect(portadaDe({
      image_url: 'https://img/propia.jpg',
      embed_url: 'https://youtu.be/dQw4w9WgXcQ',
    })).toBe('https://img/propia.jpg');
  });

  it('sin imagen propia usa la miniatura de YouTube', () => {
    expect(portadaDe({ embed_url: 'https://youtu.be/dQw4w9WgXcQ' }))
      .toBe('https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
  });

  it('un contenido de Instagram sin portada no tiene ninguna', () => {
    expect(portadaDe({ embed_url: 'https://www.instagram.com/p/CxAbC123_-x/' })).toBeNull();
  });
});
