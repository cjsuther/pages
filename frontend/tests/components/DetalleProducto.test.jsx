import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { act, screen, fireEvent, within } from '@testing-library/react';
import DetalleProducto, { BotonComprar, tarjetaQueAbre } from '../../src/components/DetalleProducto';
import { renderConProviders } from '../helpers/render';
import { mockFetch, llamadaA, cuerpoDe } from '../helpers/api';
import { paleta } from '../../src/utils/colores';
import { tokenDeDescarga } from '../../src/utils/productos';

const colores = paleta({ background_color: '#ffffff', text_color: '#000000', primary_color: '#ff8800' });
const modalStyle = { color: '#000000', backgroundColor: '#fafafa' };

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

function montar({ productos = tres(), inicial = 0, comprar = false, onCerrar = vi.fn() } = {}) {
  const resultado = renderConProviders(
    <DetalleProducto
      productos={productos}
      inicial={inicial}
      comprar={comprar}
      colores={colores}
      modalStyle={modalStyle}
      onCerrar={onCerrar}
    />
  );
  return { ...resultado, onCerrar };
}

/** El detalle: el diálogo que lleva el nombre del producto que se ve. */
const detalle = () => screen.getAllByRole('dialog').find((d) => d.getAttribute('aria-label'));

/** La pista del carrusel, con un ancho y un scroll que jsdom no calcula. */
function pistaCon({ ancho = 300 } = {}) {
  const pista = within(detalle()).getAllByRole('group')[0].parentElement;
  Object.defineProperty(pista, 'clientWidth', { configurable: true, value: ancho });
  pista.scrollTo = vi.fn();
  return pista;
}

const punto = (n) => screen.getByRole('button', { name: `Ver producto ${n} de 3` });
const siguiente = () => screen.getByRole('button', { name: 'Producto siguiente' });
const anterior = () => screen.getByRole('button', { name: 'Producto anterior' });

describe('DetalleProducto', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
  });

  describe('al abrir', () => {
    it('abre en el producto que se tocó', () => {
      montar({ inicial: 1 });

      expect(detalle()).toHaveAttribute('aria-label', 'Mapa');
      expect(punto(2)).toHaveAttribute('aria-current', 'true');
      expect(anterior()).toBeEnabled();
    });

    it('muestra imagen completa, título, descripción, precio y archivo', () => {
      montar({ productos: [producto({ description: 'Línea uno\nLínea dos' })] });
      const caja = detalle();

      expect(within(caja).getByAltText('Guía de viaje')).toHaveAttribute('src', 'https://img/guia.jpg');
      expect(within(caja).getByRole('heading', { name: 'Guía de viaje' })).toBeInTheDocument();
      const descripcion = within(caja).getByText(/Línea uno/);
      expect(descripcion.className).toContain('whitespace-pre-line');
      expect(descripcion.textContent).toBe('Línea uno\nLínea dos');
      expect(within(caja).getByText('$ 2.500')).toBeInTheDocument();
      expect(within(caja).getByText('guia.pdf')).toBeInTheDocument();
      expect(within(caja).getByText('· 1 MB')).toBeInTheDocument();
      expect(within(caja).getByRole('button', { name: 'Comprar' })).toBeInTheDocument();
    });

    it('uno gratis dice Gratis y se descarga gratis', () => {
      montar({ productos: [producto({ precio: 0 })] });

      expect(within(detalle()).getByText('Gratis')).toBeInTheDocument();
      expect(within(detalle()).getByRole('button', { name: 'Descargar gratis' })).toBeInTheDocument();
    });

    it('sin imagen ni archivo no deja huecos', () => {
      montar({ productos: [producto({ image_url: null, archivo_nombre: null, archivo_bytes: null })] });

      expect(within(detalle()).queryByRole('img')).not.toBeInTheDocument();
      expect(within(detalle()).queryByText(/MB/)).not.toBeInTheDocument();
    });

    it('lleva el estilo del modal de la plantilla', () => {
      montar();

      const caja = detalle().firstElementChild;
      expect(caja.getAttribute('style')).toContain('rgb(250, 250, 250)');
    });

    it('un solo producto se muestra sin flechas ni puntos', () => {
      montar({ productos: [producto()] });

      expect(screen.queryByRole('button', { name: 'Producto siguiente' })).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Ver producto/ })).not.toBeInTheDocument();
    });

    it('bloquea el scroll de la página de atrás', () => {
      const { unmount } = montar();

      expect(document.body.style.overflow).toBe('hidden');
      unmount();
      expect(document.body.style.overflow).toBe('');
    });
  });

  describe('cerrar', () => {
    it('con la ×', () => {
      const { onCerrar } = montar();

      fireEvent.click(screen.getByRole('button', { name: 'Cerrar' }));

      expect(onCerrar).toHaveBeenCalled();
    });

    it('al tocar el fondo, y no al tocar el contenido', () => {
      const { onCerrar } = montar();

      fireEvent.click(within(detalle()).getAllByText('Mapa')[0]);
      expect(onCerrar).not.toHaveBeenCalled();

      fireEvent.click(detalle().parentElement);
      expect(onCerrar).toHaveBeenCalled();
    });

    it('con Escape', () => {
      const { onCerrar } = montar();

      fireEvent.keyDown(document, { key: 'Escape' });

      expect(onCerrar).toHaveBeenCalled();
    });
  });

  describe('pasar de un producto a otro', () => {
    it('cada uno es una diapositiva rotulada', () => {
      montar();

      const carrusel = within(detalle()).getByRole('region', { name: 'Productos' });
      expect(carrusel).toHaveAttribute('aria-roledescription', 'carrusel');
      const diapositivas = within(carrusel).getAllByRole('group');
      expect(diapositivas.map((d) => d.getAttribute('aria-label'))).toEqual(['1 de 3', '2 de 3', '3 de 3']);
    });

    it('las flechas llegan hasta las puntas', () => {
      montar();
      const pista = pistaCon();

      expect(anterior()).toBeDisabled();

      fireEvent.click(siguiente());
      expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 300, behavior: 'smooth' });
      expect(punto(2)).toHaveAttribute('aria-current', 'true');
      expect(detalle()).toHaveAttribute('aria-label', 'Mapa');

      fireEvent.click(siguiente());
      expect(punto(3)).toHaveAttribute('aria-current', 'true');
      expect(siguiente()).toBeDisabled();

      fireEvent.click(anterior());
      expect(punto(2)).toHaveAttribute('aria-current', 'true');
    });

    it('los puntos llevan al producto que indican', () => {
      montar();
      const pista = pistaCon();

      fireEvent.click(punto(3));

      expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 600, behavior: 'smooth' });
      expect(punto(3)).toHaveAttribute('aria-current', 'true');
      expect(punto(1)).not.toHaveAttribute('aria-current');
    });

    it('con las flechas del teclado', () => {
      montar({ inicial: 1 });
      pistaCon();

      fireEvent.keyDown(document, { key: 'ArrowRight' });
      expect(punto(3)).toHaveAttribute('aria-current', 'true');

      fireEvent.keyDown(document, { key: 'ArrowLeft' });
      fireEvent.keyDown(document, { key: 'ArrowLeft' });
      fireEvent.keyDown(document, { key: 'ArrowLeft' });
      expect(punto(1)).toHaveAttribute('aria-current', 'true');
    });

    /** Deslizar con el dedo lo hace el navegador (scroll-snap); el punto lo sigue. */
    it('al deslizar, los puntos acompañan', () => {
      montar();
      const pista = pistaCon();

      pista.scrollLeft = 610;
      fireEvent.scroll(pista);

      expect(punto(3)).toHaveAttribute('aria-current', 'true');
      expect(detalle()).toHaveAttribute('aria-label', 'Pack de fotos');
    });

    it('desliza con scroll-snap, sin librería', () => {
      montar();

      const pista = pistaCon();
      expect(pista.className).toContain('snap-x');
      expect(pista.className).toContain('snap-mandatory');
      expect(pista.firstElementChild.className).toContain('snap-center');
    });

    /** Mientras anima, el scroll pasa por posiciones que redondean al anterior. */
    describe('mientras se desliza solo', () => {
      const desplazarA = (pista, scrollLeft) => {
        pista.scrollLeft = scrollLeft;
        fireEvent.scroll(pista);
      };

      it('un scroll intermedio no pisa el destino', () => {
        montar();
        const pista = pistaCon();

        fireEvent.click(siguiente());
        desplazarA(pista, 100);

        expect(punto(2)).toHaveAttribute('aria-current', 'true');
        expect(anterior()).toBeEnabled();
      });

      it('dos clics seguidos en siguiente avanzan dos', () => {
        montar();
        const pista = pistaCon();

        fireEvent.click(siguiente());
        desplazarA(pista, 100);
        fireEvent.click(siguiente());
        desplazarA(pista, 200);

        expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 600, behavior: 'smooth' });
        expect(punto(3)).toHaveAttribute('aria-current', 'true');
      });

      it('al llegar al destino, deslizar con el dedo vuelve a mover los puntos', () => {
        montar();
        const pista = pistaCon();

        fireEvent.click(siguiente());
        desplazarA(pista, 300);
        desplazarA(pista, 610);

        expect(punto(3)).toHaveAttribute('aria-current', 'true');
      });

      it('con scrollend también se da por llegado', () => {
        montar();
        const pista = pistaCon();

        fireEvent.click(siguiente());
        desplazarA(pista, 100);
        fireEvent(pista, new Event('scrollend'));
        desplazarA(pista, 0);

        expect(punto(1)).toHaveAttribute('aria-current', 'true');
      });

      /** No todos los navegadores emiten scrollend. */
      it('sin scrollend, se suelta solo al rato', () => {
        vi.useFakeTimers();
        try {
          montar();
          const pista = pistaCon();

          fireEvent.click(siguiente());
          desplazarA(pista, 100);
          act(() => { vi.advanceTimersByTime(2000); });
          desplazarA(pista, 0);

          expect(punto(1)).toHaveAttribute('aria-current', 'true');
        } finally {
          vi.useRealTimers();
        }
      });
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
        montar();
        const pista = pistaCon();

        fireEvent.click(siguiente());

        expect(pista.scrollTo).toHaveBeenLastCalledWith({ left: 300, behavior: 'auto' });
      });

      it('el desplazamiento suave sólo se pide si se acepta movimiento', () => {
        montar();

        const pista = pistaCon();
        expect(pista.className).toContain('motion-safe:scroll-smooth');
        expect(pista.className).not.toMatch(/(^|\s)scroll-smooth/);
      });
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

    /** El formulario de compra: el diálogo sin nombre de producto. */
    const formulario = () => screen.getAllByRole('dialog').find((d) => !d.getAttribute('aria-label'));

    it('desde el detalle se compra el producto que se está viendo', async () => {
      const TOKEN = 'ab'.repeat(32);
      const { llamadas } = mockFetch({
        'public/comprar-producto.php': {
          status: 201,
          body: { codigo: 'AAAABBBBCCCC', estado: 'pendiente', url: 'https://mp.test/pagar', token: TOKEN },
        },
      });
      montar({ inicial: 1 });

      const diapositiva = within(detalle()).getByRole('group', { name: '2 de 3' });
      fireEvent.click(within(diapositiva).getByRole('button', { name: 'Comprar' }));
      completar();
      enviar();

      await vi.waitFor(() => expect(window.location.href).toBe('https://mp.test/pagar'));
      expect(tokenDeDescarga('AAAABBBBCCCC')).toBe(TOKEN);
      expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php'))).toEqual({
        link_id: 401, nombre: 'Ana Gómez', email: 'ana@example.com', telefono: '1122334455',
      });
    });

    it('con el formulario abierto, Escape lo cierra a él y el detalle sigue', () => {
      const { onCerrar } = montar({ productos: [producto()] });

      fireEvent.click(within(detalle()).getByRole('button', { name: 'Comprar' }));
      expect(screen.getByLabelText('EMAIL')).toBeInTheDocument();

      fireEvent.keyDown(document, { key: 'Escape' });

      expect(screen.queryByLabelText('EMAIL')).not.toBeInTheDocument();
      expect(detalle()).toBeInTheDocument();
      expect(onCerrar).not.toHaveBeenCalled();
    });

    it('con el formulario abierto, las flechas no mueven el detalle', () => {
      montar();
      pistaCon();

      fireEvent.click(within(detalle()).getByRole('group', { name: '1 de 3' }).querySelector('button'));
      fireEvent.keyDown(document, { key: 'ArrowRight' });

      expect(punto(1)).toHaveAttribute('aria-current', 'true');
    });

    describe('directo, desde el botón de la tarjeta', () => {
      it('abre el formulario sin pasar por el detalle', () => {
        montar({ inicial: 2, comprar: true });

        expect(screen.getAllByRole('dialog')).toHaveLength(1);
        expect(screen.getByText('Descargar gratis')).toBeInTheDocument();
        expect(screen.getByText(/Pack de fotos/)).toBeInTheDocument();
      });

      it('al cerrar el formulario vuelve a la página', () => {
        const { onCerrar } = montar({ comprar: true });

        fireEvent.click(screen.getByRole('button', { name: 'Cerrar' }));

        expect(onCerrar).toHaveBeenCalled();
      });

      it('uno gratis avisa que el link va por mail y no ofrece la descarga', async () => {
        mockFetch({
          'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', estado: 'pagada', url: null } },
        });
        montar({ productos: [producto({ precio: 0 })], comprar: true });

        completar();
        enviar();

        expect(await screen.findByText('Te mandamos el link a tu email')).toBeInTheDocument();
        expect(window.location.href).toBe('');
        expect(within(formulario()).queryByRole('link')).not.toBeInTheDocument();
        expect(within(formulario()).queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
      });
    });

    it.each([
      [409, { error: 'x' }, 'Este producto ya no está disponible.'],
      [429, { error: 'Hiciste demasiados pedidos en poco tiempo. Probá de nuevo en una hora.' }, /demasiados pedidos/],
      [400, { error: 'El email no es válido' }, 'El email no es válido'],
    ])('un %i se explica con claridad', async (status, body, mensaje) => {
      mockFetch({ 'public/comprar-producto.php': { status, body } });
      montar({ productos: [producto()], comprar: true });

      completar();
      enviar();

      expect(await screen.findByRole('alert')).toHaveTextContent(mensaje);
      expect(window.location.href).toBe('');
    });

    it('sin conexión lo dice', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('red')));
      montar({ productos: [producto()], comprar: true });

      completar();
      enviar();

      expect(await screen.findByRole('alert')).toHaveTextContent(/conectarnos/);
    });

    it('con un email mal escrito no manda nada', () => {
      const { llamadas } = mockFetch({});
      montar({ productos: [producto()], comprar: true });

      completar();
      fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'ana@' } });
      enviar();

      expect(screen.getByRole('alert')).toHaveTextContent(/Revisá el email/);
      expect(llamadas).toHaveLength(0);
    });

    it('después de pedir uno gratis, Listo vuelve al detalle', async () => {
      mockFetch({
        'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', estado: 'pagada', url: null } },
      });
      montar({ productos: [producto({ precio: 0 })] });

      fireEvent.click(within(detalle()).getByRole('button', { name: 'Descargar gratis' }));
      completar();
      enviar();
      fireEvent.click(await screen.findByRole('button', { name: 'Listo' }));

      expect(screen.getAllByRole('dialog')).toHaveLength(1);
      expect(detalle()).toBeInTheDocument();
    });
  });
});

describe('tarjetaQueAbre', () => {
  function Tarjeta({ abrir, comprar }) {
    return (
      <div {...tarjetaQueAbre(abrir)} aria-label="Guía de viaje">
        <BotonComprar producto={producto()} colores={colores} onComprar={comprar} />
      </div>
    );
  }

  it('es un botón que se enfoca y se abre con click, Enter o Espacio', () => {
    const abrir = vi.fn();
    renderConProviders(<Tarjeta abrir={abrir} comprar={vi.fn()} />);
    const tarjeta = screen.getByRole('button', { name: 'Guía de viaje' });

    expect(tarjeta).toHaveAttribute('tabindex', '0');
    fireEvent.click(tarjeta);
    fireEvent.keyDown(tarjeta, { key: 'Enter' });
    fireEvent.keyDown(tarjeta, { key: ' ' });
    fireEvent.keyDown(tarjeta, { key: 'a' });

    expect(abrir).toHaveBeenCalledTimes(3);
  });

  it('el botón de comprar de adentro no abre la tarjeta', () => {
    const abrir = vi.fn();
    const comprar = vi.fn();
    renderConProviders(<Tarjeta abrir={abrir} comprar={comprar} />);
    const boton = screen.getByRole('button', { name: 'Comprar' });

    fireEvent.click(boton);
    fireEvent.keyDown(boton, { key: 'Enter' });

    expect(comprar).toHaveBeenCalledTimes(1);
    expect(abrir).not.toHaveBeenCalled();
  });
});

describe('BotonComprar', () => {
  it('sólo con el ícono conserva su nombre', () => {
    renderConProviders(<BotonComprar producto={producto({ precio: 0 })} colores={colores} onComprar={vi.fn()} soloIcono />);

    const boton = screen.getByRole('button', { name: 'Descargar gratis' });
    expect(boton).toHaveAttribute('title', 'Descargar gratis');
    expect(boton.textContent).toBe('');
  });

  it('con otro fondo, el texto se elige para que se lea', () => {
    renderConProviders(<BotonComprar producto={producto()} colores={colores} onComprar={vi.fn()} fondo="#000000" />);

    const estilo = screen.getByRole('button', { name: 'Comprar' }).getAttribute('style');
    expect(estilo).toContain('background-color: rgb(0, 0, 0)');
    expect(estilo).toContain('color: rgb(255, 255, 255)');
  });
});
