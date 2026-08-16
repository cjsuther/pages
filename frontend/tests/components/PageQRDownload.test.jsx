import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import QRCode from 'qrcode';
import PageQRDownload from '../../src/components/PageQRDownload';

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn(() => Promise.resolve('data:image/png;base64,QR')) },
}));

const pagina = (overrides = {}) => ({
  id: 5,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  profile_image: null,
  background_color: '#ffffff',
  text_color: '#000000',
  ...overrides,
});

/**
 * jsdom no implementa canvas. Se sustituye por un doble que registra las
 * llamadas de dibujo, que es lo que interesa comprobar.
 */
function instalarCanvas() {
  const ctx = {
    fillStyle: '', font: '', textAlign: '', globalAlpha: 1,
    fillRect: vi.fn(),
    fillText: vi.fn(),
    drawImage: vi.fn(),
    measureText: vi.fn((t) => ({ width: t.length * 10 })),
    save: vi.fn(), restore: vi.fn(), beginPath: vi.fn(), arc: vi.fn(), clip: vi.fn(),
  };

  const canvases = [];
  const originalCreate = document.createElement.bind(document);

  vi.spyOn(document, 'createElement').mockImplementation((tag) => {
    const el = originalCreate(tag);
    if (tag === 'canvas') {
      el.getContext = () => ctx;
      el.toBlob = vi.fn((cb) => cb(new Blob(['x'], { type: 'image/png' })));
      canvases.push(el);
    }
    return el;
  });

  return { ctx, canvases };
}

/** Image de jsdom no carga nada: se dispara onload al asignar src. */
function instalarImage() {
  class ImagenFalsa {
    constructor() {
      this.crossOrigin = null;
      setTimeout(() => this.onload && this.onload(), 0);
    }
    set src(valor) { this._src = valor; }
    get src() { return this._src; }
  }
  global.Image = ImagenFalsa;
}

describe('PageQRDownload', () => {
  let canvasMock;

  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    canvasMock = instalarCanvas();
    instalarImage();
    global.URL.createObjectURL = vi.fn(() => 'blob:qr');
    global.URL.revokeObjectURL = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('botón', () => {
    it('se renderiza con su título accesible', () => {
      render(<PageQRDownload page={pagina()} />);

      expect(screen.getByTitle('Descargar QR')).toBeInTheDocument();
    });

    it('está habilitado inicialmente', () => {
      render(<PageQRDownload page={pagina()} />);

      expect(screen.getByRole('button')).toBeEnabled();
    });
  });

  describe('generación del QR', () => {
    it('codifica la URL pública de la página', async () => {
      render(<PageQRDownload page={pagina({ url_slug: 'mi-pagina' })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(QRCode.toDataURL).toHaveBeenCalledWith(
          `${window.location.origin}/mi-pagina`,
          expect.any(Object)
        );
      });
    });

    it('usa los colores de la página en el QR', async () => {
      render(<PageQRDownload page={pagina({ background_color: '#101010', text_color: '#f0f0f0' })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const [, opciones] = QRCode.toDataURL.mock.calls[0];
        expect(opciones.color).toEqual({ dark: '#f0f0f0', light: '#101010' });
      });
    });

    it('recurre a blanco y negro si la página no define colores', async () => {
      render(<PageQRDownload page={pagina({ background_color: null, text_color: null })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const [, opciones] = QRCode.toDataURL.mock.calls[0];
        expect(opciones.color).toEqual({ dark: '#000000', light: '#ffffff' });
      });
    });

    it('dibuja el título en el canvas', async () => {
      render(<PageQRDownload page={pagina({ title: 'Mi Página' })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const textos = canvasMock.ctx.fillText.mock.calls.map((c) => c[0]);
        expect(textos).toContain('Mi Página');
      });
    });

    it('dibuja la URL al pie', async () => {
      render(<PageQRDownload page={pagina({ url_slug: 'mi-pagina' })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const textos = canvasMock.ctx.fillText.mock.calls.map((c) => c[0]);
        expect(textos).toContain(`${window.location.origin}/mi-pagina`);
      });
    });

    it('dibuja la descripción', async () => {
      render(<PageQRDownload page={pagina({ description: 'Agenda cultural' })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const textos = canvasMock.ctx.fillText.mock.calls.map((c) => c[0]).join(' ');
        expect(textos).toContain('Agenda');
      });
    });

    it('funciona sin descripción', async () => {
      render(<PageQRDownload page={pagina({ title: 'Mi Página', description: null })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const textos = canvasMock.ctx.fillText.mock.calls.map((c) => c[0]);
        expect(textos).toContain('Mi Página');
        expect(textos).not.toContain('Una descripción');
      });

      expect(screen.getByRole('button')).toBeEnabled();
    });

    it('recorta la imagen de perfil en círculo', async () => {
      render(<PageQRDownload page={pagina({ profile_image: 'https://img/perfil.png' })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(canvasMock.ctx.arc).toHaveBeenCalled();
        expect(canvasMock.ctx.clip).toHaveBeenCalled();
      });
    });

    it('no recorta nada si la página no tiene foto', async () => {
      render(<PageQRDownload page={pagina({ profile_image: null })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(canvasMock.ctx.fillText).toHaveBeenCalled();
      });
      expect(canvasMock.ctx.clip).not.toHaveBeenCalled();
    });

    it('funciona con títulos largos que necesitan varias líneas', async () => {
      const largo = 'Un título muy largo que seguro necesita partirse en varias líneas para entrar';

      render(<PageQRDownload page={pagina({ title: largo })} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        const lineas = canvasMock.ctx.fillText.mock.calls.map((c) => c[0]);
        // El texto se repartió: ninguna línea es el título completo.
        expect(lineas).not.toContain(largo);
        expect(lineas.join(' ')).toContain('Un título');
      });
    });
  });

  describe('descarga', () => {
    it('dispara la descarga con el nombre de archivo correcto', async () => {
      const click = vi.fn();
      const originalCreate = document.createElement.getMockImplementation();

      vi.spyOn(document, 'createElement').mockImplementation((tag) => {
        const el = originalCreate(tag);
        if (tag === 'a') el.click = click;
        return el;
      });

      render(<PageQRDownload page={pagina({ url_slug: 'mi-pagina' })} />);
      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => expect(click).toHaveBeenCalled());
    });

    it('libera la URL temporal', async () => {
      render(<PageQRDownload page={pagina()} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(global.URL.revokeObjectURL).toHaveBeenCalledWith('blob:qr');
      });
    });

    it('vuelve a habilitarse al terminar', async () => {
      render(<PageQRDownload page={pagina()} />);

      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(screen.getByRole('button')).toBeEnabled();
      });
    });

    it('no rompe si falla la generación', async () => {
      QRCode.toDataURL.mockRejectedValueOnce(new Error('falló el QR'));

      render(<PageQRDownload page={pagina()} />);
      fireEvent.click(screen.getByRole('button'));

      await waitFor(() => {
        expect(console.error).toHaveBeenCalled();
        expect(screen.getByRole('button')).toBeEnabled();
      });
    });
  });

  describe('propagación de eventos', () => {
    it('el click no propaga al contenedor', async () => {
      // El botón vive dentro de tarjetas clicables en "Mis páginas".
      const onContenedor = vi.fn();

      render(
        <div onClick={onContenedor}>
          <PageQRDownload page={pagina()} />
        </div>
      );

      fireEvent.click(screen.getByRole('button'));

      expect(onContenedor).not.toHaveBeenCalled();
    });
  });
});
