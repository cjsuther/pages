import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { screen, fireEvent, within } from '@testing-library/react';
import GaleriaProductos from '../../src/components/GaleriaProductos';
import { renderConProviders } from '../helpers/render';
import { mockFetch, llamadaA, cuerpoDe } from '../helpers/api';
import { paleta } from '../../src/utils/colores';
import { tokenDeDescarga } from '../../src/utils/productos';

const colores = paleta({ background_color: '#ffffff', text_color: '#000000', primary_color: '#ff8800' });

const producto = (overrides = {}) => ({
  id: 400,
  text: 'Guía de viaje',
  description: 'Todo lo que hay que saber',
  image_url: 'https://img/guia.jpg',
  precio: 2500,
  archivo_nombre: 'guia.pdf',
  archivo_bytes: 1024 * 1024,
  ...overrides,
});

const tres = () => [
  producto(),
  producto({ id: 401, text: 'Mapa' }),
  producto({ id: 402, text: 'Pack de fotos', precio: 0 }),
];

function montar(productos = tres()) {
  return renderConProviders(<GaleriaProductos productos={productos} titulo="Tienda" colores={colores} />);
}

/** La pista del carrusel, con un ancho y un scroll que jsdom no calcula. */
function pistaCon(container, { ancho = 300 } = {}) {
  const pista = container.querySelector('[tabindex="0"]');
  Object.defineProperty(pista, 'clientWidth', { configurable: true, value: ancho });
  pista.scrollTo = vi.fn();
  return pista;
}

const punto = (n) => screen.getByRole('button', { name: `Ver producto ${n} de 3` });

describe('GaleriaProductos', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
  });

  describe('carrusel', () => {
    it('un solo producto se muestra sin flechas ni puntos', () => {
      montar([producto()]);

      expect(screen.getByText('Guía de viaje')).toBeInTheDocument();
      expect(screen.queryByRole('region')).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Producto siguiente' })).not.toBeInTheDocument();
    });

    it('sin productos no dibuja nada', () => {
      const { container } = montar([]);

      expect(container).toBeEmptyDOMElement();
    });

    it('con varios, cada uno es una diapositiva rotulada', () => {
      montar();

      const carrusel = screen.getByRole('region', { name: 'Tienda' });
      expect(carrusel).toHaveAttribute('aria-roledescription', 'carrusel');
      const diapositivas = within(carrusel).getAllByRole('group');
      expect(diapositivas.map((d) => d.getAttribute('aria-label'))).toEqual(['1 de 3', '2 de 3', '3 de 3']);
    });

    it('las flechas son botones con nombre y llegan hasta las puntas', () => {
      const { container } = montar();
      const pista = pistaCon(container);

      const anterior = screen.getByRole('button', { name: 'Producto anterior' });
      const siguiente = screen.getByRole('button', { name: 'Producto siguiente' });
      expect(anterior).toBeDisabled();

      fireEvent.click(siguiente);
      expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 300, behavior: 'smooth' });
      expect(punto(2)).toHaveAttribute('aria-current', 'true');

      fireEvent.click(siguiente);
      expect(punto(3)).toHaveAttribute('aria-current', 'true');
      expect(siguiente).toBeDisabled();

      fireEvent.click(anterior);
      expect(punto(2)).toHaveAttribute('aria-current', 'true');
    });

    it('los puntos llevan al producto que indican', () => {
      const { container } = montar();
      const pista = pistaCon(container);

      fireEvent.click(punto(3));

      expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 600, behavior: 'smooth' });
      expect(punto(3)).toHaveAttribute('aria-current', 'true');
      expect(punto(1)).not.toHaveAttribute('aria-current');
    });

    it('se recorre con las flechas del teclado', () => {
      const { container } = montar();
      pistaCon(container);
      const carrusel = screen.getByRole('region', { name: 'Tienda' });

      fireEvent.keyDown(carrusel, { key: 'ArrowRight' });
      expect(punto(2)).toHaveAttribute('aria-current', 'true');

      fireEvent.keyDown(carrusel, { key: 'ArrowLeft' });
      fireEvent.keyDown(carrusel, { key: 'ArrowLeft' });
      expect(punto(1)).toHaveAttribute('aria-current', 'true');
    });

    it('la pista se puede enfocar para usar el teclado', () => {
      const { container } = montar();

      expect(container.querySelector('[tabindex="0"]')).not.toBeNull();
    });

    /** Deslizar con el dedo lo hace el navegador (scroll-snap); el punto lo sigue. */
    it('al deslizar, los puntos acompañan', () => {
      const { container } = montar();
      const pista = pistaCon(container);

      pista.scrollLeft = 610;
      fireEvent.scroll(pista);

      expect(punto(3)).toHaveAttribute('aria-current', 'true');
    });

    it('desliza con scroll-snap, sin librería', () => {
      const { container } = montar();

      const pista = container.querySelector('[tabindex="0"]');
      expect(pista.className).toContain('snap-x');
      expect(pista.className).toContain('snap-mandatory');
      expect(pista.firstElementChild.className).toContain('snap-center');
    });

    describe('con menos movimiento', () => {
      beforeEach(() => {
        window.matchMedia = vi.fn().mockImplementation((consulta) => ({
          matches: consulta === '(prefers-reduced-motion: reduce)',
          media: consulta,
          addEventListener: vi.fn(),
          removeEventListener: vi.fn(),
        }));
      });

      afterEach(() => {
        delete window.matchMedia;
      });

      it('salta sin animar', () => {
        const { container } = montar();
        const pista = pistaCon(container);

        fireEvent.click(screen.getByRole('button', { name: 'Producto siguiente' }));

        expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 300, behavior: 'auto' });
      });

      it('el desplazamiento suave sólo se pide si se acepta movimiento', () => {
        const { container } = montar();

        const pista = container.querySelector('[tabindex="0"]');
        expect(pista.className).toContain('motion-safe:scroll-smooth');
        expect(pista.className).not.toMatch(/(^|\s)scroll-smooth/);
      });
    });
  });

  describe('cada producto', () => {
    it('muestra imagen, título, descripción, precio y archivo', () => {
      montar([producto()]);

      expect(screen.getByAltText('Guía de viaje')).toHaveAttribute('src', 'https://img/guia.jpg');
      expect(screen.getByText('Todo lo que hay que saber')).toBeInTheDocument();
      expect(screen.getByText(/2\.500/)).toBeInTheDocument();
      expect(screen.getByText('guia.pdf')).toBeInTheDocument();
      expect(screen.getByText(/1 MB/)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Comprar' })).toBeInTheDocument();
    });

    it('uno gratis dice Gratis y se descarga gratis', () => {
      montar([producto({ precio: 0 })]);

      expect(screen.getByText('Gratis')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Descargar gratis' })).toBeInTheDocument();
    });

    it('sin imagen no deja una imagen rota', () => {
      montar([producto({ image_url: null })]);

      expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });
  });

  describe('compra', () => {
    beforeEach(() => {
      delete window.location;
      window.location = { href: '' };
    });

    const completar = () => {
      fireEvent.change(screen.getByLabelText('NOMBRE Y APELLIDO'), { target: { value: 'Ana Gómez' } });
      fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'ana@example.com' } });
      fireEvent.change(screen.getByLabelText('TELÉFONO (OPCIONAL)'), { target: { value: '1122334455' } });
    };

    const enviar = () => fireEvent.submit(screen.getByLabelText('EMAIL').closest('form'));

    it('un producto pago guarda el token y lleva a Mercado Pago', async () => {
      const TOKEN = 'ab'.repeat(32);
      const { llamadas } = mockFetch({
        'public/comprar-producto.php': {
          status: 201,
          body: { codigo: 'AAAABBBBCCCC', estado: 'pendiente', url: 'https://mp.test/pagar', token: TOKEN },
        },
      });
      montar([producto()]);

      fireEvent.click(screen.getByRole('button', { name: 'Comprar' }));
      completar();
      enviar();

      await vi.waitFor(() => expect(window.location.href).toBe('https://mp.test/pagar'));
      expect(tokenDeDescarga('AAAABBBBCCCC')).toBe(TOKEN);
      expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php'))).toEqual({
        link_id: 400, nombre: 'Ana Gómez', email: 'ana@example.com', telefono: '1122334455',
      });
    });

    it('uno gratis avisa que el link va por mail y no ofrece la descarga', async () => {
      mockFetch({
        'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', estado: 'pagada', url: null } },
      });
      montar([producto({ precio: 0 })]);

      fireEvent.click(screen.getByRole('button', { name: 'Descargar gratis' }));
      completar();
      enviar();

      expect(await screen.findByText('Te mandamos el link a tu email')).toBeInTheDocument();
      expect(window.location.href).toBe('');
      const dialogo = screen.getByRole('dialog');
      expect(within(dialogo).queryByRole('link')).not.toBeInTheDocument();
      expect(within(dialogo).queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
    });

    it.each([
      [409, { error: 'x' }, 'Este producto ya no está disponible.'],
      [429, { error: 'Hiciste demasiados pedidos en poco tiempo. Probá de nuevo en una hora.' }, /demasiados pedidos/],
      [400, { error: 'El email no es válido' }, 'El email no es válido'],
    ])('un %i se explica con claridad', async (status, body, mensaje) => {
      mockFetch({ 'public/comprar-producto.php': { status, body } });
      montar([producto()]);

      fireEvent.click(screen.getByRole('button', { name: 'Comprar' }));
      completar();
      enviar();

      expect(await screen.findByRole('alert')).toHaveTextContent(mensaje);
      expect(window.location.href).toBe('');
    });

    it('sin conexión lo dice', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('red')));
      montar([producto()]);

      fireEvent.click(screen.getByRole('button', { name: 'Comprar' }));
      completar();
      enviar();

      expect(await screen.findByRole('alert')).toHaveTextContent(/conectarnos/);
    });

    it('con un email mal escrito no manda nada', () => {
      const { llamadas } = mockFetch({});
      montar([producto()]);

      fireEvent.click(screen.getByRole('button', { name: 'Comprar' }));
      completar();
      fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'ana@' } });
      enviar();

      expect(screen.getByRole('alert')).toHaveTextContent(/Revisá el email/);
      expect(llamadas).toHaveLength(0);
    });

    it('se cierra con Escape', () => {
      montar([producto()]);

      fireEvent.click(screen.getByRole('button', { name: 'Comprar' }));
      fireEvent.keyDown(document, { key: 'Escape' });

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
  });
});
