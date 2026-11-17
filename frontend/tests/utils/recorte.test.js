import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  DESTINOS,
  ORIGINAL,
  areaCompleta,
  cargarImagen,
  destinoDeGrupo,
  exportarRecorte,
  medidasDeSalida,
  sirveTalCual,
} from '../../src/utils/recorte';
import { MAX_BYTES, MAX_BYTES_EVENTO } from '../../src/utils/imagenes';

function archivo({ tipo = 'image/jpeg', bytes = 1024 } = {}) {
  const f = new File(['x'], 'foto', { type: tipo });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
}

/**
 * Un canvas de mentira: jsdom no dibuja. `pesos(tipo, calidad, canvas)`
 * decide cuánto pesa cada blob; `escribe` qué formatos sabe escribir el
 * "navegador" (los demás salen en PNG, como hace Safari viejo con WebP).
 */
function canvasFalso({ alfa = false, pesos = () => 1000, escribe = ['image/jpeg', 'image/png', 'image/webp'] } = {}) {
  const ctx = {
    drawImage: vi.fn(),
    getImageData: vi.fn((x, y, w, h) => {
      const data = new Uint8ClampedArray(w * h * 4).fill(255);
      if (alfa) data[3] = 0;
      return { data };
    }),
  };
  const lienzos = [];

  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(function getContext() {
    lienzos.push(this);
    return ctx;
  });
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function toBlob(cb, tipo, calidad) {
    const real = escribe.includes(tipo) ? tipo : 'image/png';
    cb(new Blob(['x'.repeat(pesos(real, calidad, this))], { type: real }));
  });

  return { ctx, lienzos };
}

const imagen = { naturalWidth: 4000, naturalHeight: 3000 };

describe('utils/recorte', () => {
  describe('destinos', () => {
    it('cada lugar tiene su proporción y su tope', () => {
      expect(DESTINOS.perfil.proporciones.map((p) => p.valor)).toEqual([1]);
      expect(DESTINOS.perfil.forma).toBe('round');
      expect(DESTINOS.evento.proporciones.map((p) => p.valor)).toEqual([4 / 5]);
      expect(DESTINOS.evento.maxBytes).toBe(MAX_BYTES_EVENTO);
      expect(DESTINOS.producto.maxBytes).toBe(MAX_BYTES);
      expect(DESTINOS.galeria.proporciones[0].valor).toBe(ORIGINAL);
      expect(DESTINOS.fondo.proporciones[0].valor).toBe(ORIGINAL);
    });

    it('el destino de un item sale del tipo de su grupo', () => {
      expect(destinoDeGrupo('eventos')).toBe('evento');
      expect(destinoDeGrupo('productos')).toBe('producto');
      expect(destinoDeGrupo('galeria')).toBe('galeria');
      expect(destinoDeGrupo('links')).toBe('link');
      expect(destinoDeGrupo(undefined)).toBe('link');
    });
  });

  describe('sirveTalCual', () => {
    it('sirve si el tipo se acepta y entra en el tope', () => {
      expect(sirveTalCual(archivo(), DESTINOS.evento)).toBe(true);
      expect(sirveTalCual(archivo({ bytes: 700 * 1024 }), DESTINOS.evento)).toBe(false);
      expect(sirveTalCual(archivo({ bytes: 700 * 1024 }), DESTINOS.galeria)).toBe(true);
      expect(sirveTalCual(archivo({ tipo: 'image/heic' }), DESTINOS.galeria)).toBe(false);
    });
  });

  describe('medidas', () => {
    it('achica el lado mayor hasta el máximo y nunca agranda', () => {
      expect(medidasDeSalida({ width: 3000, height: 3750 }, 1350)).toEqual({ ancho: 1080, alto: 1350 });
      expect(medidasDeSalida({ width: 400, height: 500 }, 1350)).toEqual({ ancho: 400, alto: 500 });
    });

    it('el área completa es la imagen entera', () => {
      expect(areaCompleta(imagen)).toEqual({ x: 0, y: 0, width: 4000, height: 3000 });
    });
  });

  describe('cargarImagen', () => {
    it('resuelve con la imagen cuando carga', async () => {
      vi.stubGlobal('Image', class {
        set src(v) { this.cargada = v; setTimeout(() => this.onload()); }
      });
      const cargada = await cargarImagen('blob:x');
      expect(cargada.cargada).toBe('blob:x');
      vi.unstubAllGlobals();
    });

    it('rechaza si el navegador no la puede abrir', async () => {
      vi.stubGlobal('Image', class {
        set src(v) { setTimeout(() => this.onerror()); }
      });
      await expect(cargarImagen('blob:x')).rejects.toThrow('No pudimos abrir la imagen');
      vi.unstubAllGlobals();
    });
  });

  describe('exportarRecorte', () => {
    beforeEach(() => {
      vi.restoreAllMocks();
    });

    it('dibuja el área elegida al tamaño del destino y sale en JPEG', async () => {
      const { ctx, lienzos } = canvasFalso();
      const area = { x: 100, y: 50, width: 2400, height: 3000 };

      const salida = await exportarRecorte(imagen, area, {
        maxLado: 1350, maxBytes: MAX_BYTES_EVENTO, tipo: 'image/jpeg', nombre: 'IMG_1234.JPG',
      });

      expect(ctx.drawImage).toHaveBeenCalledWith(imagen, 100, 50, 2400, 3000, 0, 0, 1080, 1350);
      expect(lienzos[0].width).toBe(1080);
      expect(lienzos[0].height).toBe(1350);
      expect(salida).toBeInstanceOf(File);
      expect(salida.type).toBe('image/jpeg');
      expect(salida.name).toBe('IMG_1234.jpg');
      // Un JPEG opaco no necesita revisar la transparencia.
      expect(ctx.getImageData).not.toHaveBeenCalled();
    });

    it('un PNG con transparencia la conserva en WebP', async () => {
      canvasFalso({ alfa: true });

      const salida = await exportarRecorte(imagen, areaCompleta(imagen), {
        maxLado: 800, maxBytes: MAX_BYTES, tipo: 'image/png', nombre: 'logo.png',
      });

      expect(salida.type).toBe('image/webp');
      expect(salida.name).toBe('logo.webp');
    });

    it('si el navegador no escribe WebP, la transparencia sale en PNG', async () => {
      canvasFalso({ alfa: true, escribe: ['image/jpeg', 'image/png'] });

      const salida = await exportarRecorte(imagen, areaCompleta(imagen), {
        maxLado: 800, maxBytes: MAX_BYTES, tipo: 'image/png', nombre: 'logo.png',
      });

      expect(salida.type).toBe('image/png');
      expect(salida.name).toBe('logo.png');
    });

    it('un PNG sin transparencia sale en JPEG, que pesa menos', async () => {
      canvasFalso({ alfa: false });

      const salida = await exportarRecorte(imagen, areaCompleta(imagen), {
        maxLado: 800, maxBytes: MAX_BYTES, tipo: 'image/png', nombre: 'captura.png',
      });

      expect(salida.type).toBe('image/jpeg');
    });

    it('para entrar en el tope baja primero la calidad', async () => {
      const calidades = [];
      canvasFalso({
        pesos: (tipo, calidad) => {
          calidades.push(calidad);
          return calidad > 0.8 ? 700 * 1024 : 500 * 1024;
        },
      });

      const salida = await exportarRecorte(imagen, { x: 0, y: 0, width: 2400, height: 3000 }, {
        maxLado: 1350, maxBytes: MAX_BYTES_EVENTO, tipo: 'image/jpeg', nombre: 'a.jpg',
      });

      expect(calidades).toEqual([0.88, 0.78]);
      expect(salida.size).toBe(500 * 1024);
    });

    it('si con la calidad no alcanza, achica la imagen', async () => {
      const { lienzos } = canvasFalso({
        pesos: (tipo, calidad, canvas) => (canvas.width >= 1080 ? 900 * 1024 : 400 * 1024),
      });

      const salida = await exportarRecorte(imagen, { x: 0, y: 0, width: 2400, height: 3000 }, {
        maxLado: 1350, maxBytes: MAX_BYTES_EVENTO, tipo: 'image/jpeg', nombre: 'a.jpg',
      });

      expect(lienzos.map((c) => c.width)).toEqual([1080, 864]);
      expect(salida.size).toBe(400 * 1024);
    });

    it('si nunca entra devuelve la versión más liviana y deja que avise quien sube', async () => {
      const { lienzos } = canvasFalso({ pesos: (tipo, calidad, canvas) => 700 * 1024 + canvas.width });

      const salida = await exportarRecorte(imagen, areaCompleta(imagen), {
        maxLado: 1350, maxBytes: MAX_BYTES_EVENTO, tipo: 'image/jpeg', nombre: 'a.jpg',
      });

      expect(lienzos).toHaveLength(6);
      expect(salida.size).toBe(700 * 1024 + lienzos[5].width);
    });

    it('falla con un mensaje claro si no hay canvas', async () => {
      vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);

      await expect(exportarRecorte(imagen, areaCompleta(imagen), {
        maxLado: 800, maxBytes: MAX_BYTES, tipo: 'image/jpeg', nombre: 'a.jpg',
      })).rejects.toThrow('Este navegador no puede recortar imágenes');
    });

    it('si no puede leer los píxeles conserva la transparencia por las dudas', async () => {
      const { ctx } = canvasFalso();
      ctx.getImageData.mockImplementation(() => { throw new Error('tainted'); });

      const salida = await exportarRecorte(imagen, areaCompleta(imagen), {
        maxLado: 800, maxBytes: MAX_BYTES, tipo: 'image/webp', nombre: 'a.webp',
      });

      expect(salida.type).toBe('image/webp');
    });
  });
});
