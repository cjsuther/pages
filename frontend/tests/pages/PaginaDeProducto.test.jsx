import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import PaginaDeProducto from '../../src/pages/PaginaDeProducto';
import { renderConProviders, API_URL } from '../helpers/render';
import { mockFetch, llamadaA, cuerpoDe } from '../helpers/api';

/** /producto/:id: el link para compartir un producto digital (#27). */

const producto = (overrides = {}) => ({
  id: 400,
  group_id: 10,
  text: 'Guía de viaje',
  description: 'Todo lo que hay que saber',
  image_url: 'https://img/guia.jpg',
  position: 0,
  precio: 2500,
  archivo_nombre: 'guia.pdf',
  archivo_bytes: 1024 * 1024,
  page_title: 'La Sala',
  page_slug: 'la-sala',
  page_image: 'https://img/logo.jpg',
  primary_color: '#ff8800',
  secondary_color: '#112233',
  card_color: null,
  title_color: '#aa0000',
  background_color: '#000000',
  text_color: '#ffffff',
  template: 'cards',
  background_image: null,
  ...overrides,
});

function render(id = '400') {
  return renderConProviders(<PaginaDeProducto />, {
    route: `/producto/${id}`,
    path: '/producto/:id',
    rutasExtra: [{ path: '/:slug', element: <p>Página pública</p> }],
  });
}

describe('PaginaDeProducto', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
    delete navigator.share;
    delete navigator.clipboard;
  });

  it('muestra la carga mientras pide el producto por su id', async () => {
    const { llamadas } = mockFetch({ 'public/producto.php': { producto: producto() } });

    render('345');

    expect(screen.getByText('Cargando...')).toBeInTheDocument();
    await waitFor(() => expect(llamadas[0].url).toBe(`${API_URL}/public/producto.php?id=345`));
  });

  it('muestra imagen, título, descripción, precio y archivo', async () => {
    mockFetch({ 'public/producto.php': { producto: producto() } });

    render();

    const titulo = await screen.findByRole('heading', { level: 1, name: 'Guía de viaje' });
    expect(titulo).toHaveStyle({ color: '#aa0000' });
    expect(screen.getByAltText('Guía de viaje')).toHaveAttribute('src', 'https://img/guia.jpg');
    expect(screen.getByText('Todo lo que hay que saber')).toBeInTheDocument();
    expect(screen.getByText('$ 2.500')).toBeInTheDocument();
    expect(screen.getByText('guia.pdf')).toBeInTheDocument();
    expect(screen.getByText('· 1 MB')).toBeInTheDocument();
  });

  it('no tiene carrusel: es un solo producto', async () => {
    mockFetch({ 'public/producto.php': { producto: producto() } });

    render();

    await screen.findByRole('heading', { name: 'Guía de viaje' });
    expect(screen.queryByRole('button', { name: 'Producto siguiente' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Ver producto/ })).not.toBeInTheDocument();
  });

  it('arriba va el nombre de la página, con link a ella', async () => {
    mockFetch({ 'public/producto.php': { producto: producto() } });

    render();

    expect(await screen.findByRole('link', { name: /La Sala/ })).toHaveAttribute('href', '/la-sala');
    expect(screen.getByRole('link', { name: 'Ver la página' })).toHaveAttribute('href', '/la-sala');
  });

  it('se pinta con los colores de la página', async () => {
    mockFetch({ 'public/producto.php': { producto: producto() } });

    render();

    const boton = await screen.findByRole('button', { name: 'Comprar' });
    expect(boton).toHaveStyle({ backgroundColor: '#112233' });
  });

  describe('comprar', () => {
    const ubicacionOriginal = window.location;

    beforeEach(() => {
      delete window.location;
      window.location = { href: '', origin: ubicacionOriginal.origin };
    });

    afterEach(() => {
      window.location = ubicacionOriginal;
    });

    const completar = () => {
      fireEvent.change(screen.getByLabelText('NOMBRE Y APELLIDO'), { target: { value: 'Ana Gómez' } });
      fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'ana@example.com' } });
      fireEvent.submit(screen.getByLabelText('EMAIL').closest('form'));
    };

    it('un producto pago abre el formulario de compra y va a Mercado Pago', async () => {
      const { llamadas } = mockFetch({
        'public/producto.php': { producto: producto() },
        'public/comprar-producto.php': {
          status: 201,
          body: { codigo: 'AAAABBBBCCCC', estado: 'pendiente', url: 'https://mp.test/pagar', token: 'ab'.repeat(32) },
        },
      });

      render();
      fireEvent.click(await screen.findByRole('button', { name: 'Comprar' }));
      completar();

      await waitFor(() => expect(window.location.href).toBe('https://mp.test/pagar'));
      expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php'))).toMatchObject({
        link_id: 400, nombre: 'Ana Gómez', email: 'ana@example.com',
      });
    });

    it('un producto gratis se pide sin Mercado Pago y avisa que llega por mail', async () => {
      const { llamadas } = mockFetch({
        'public/producto.php': { producto: producto({ precio: 0 }) },
        'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', estado: 'pagada', url: null } },
      });

      render();
      fireEvent.click(await screen.findByRole('button', { name: 'Descargar gratis' }));
      completar();

      expect(await screen.findByText('Te mandamos el link a tu email')).toBeInTheDocument();
      expect(window.location.href).toBe('');
      expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php')).link_id).toBe(400);
    });
  });

  describe('no disponible', () => {
    it('con 404 dice que no está disponible y nada más', async () => {
      mockFetch({ 'public/producto.php': { status: 404, body: { error: 'Este producto ya no está disponible.' } } });

      render();

      expect(await screen.findByText('Este producto ya no está disponible.')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Comprar|Descargar/ })).not.toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'Ir al inicio' })).toHaveAttribute('href', '/');
    });

    it('si falla la red lo dice', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      render();

      expect(await screen.findByText('Error al cargar el producto')).toBeInTheDocument();
    });
  });

  describe('compartir', () => {
    it('con navigator.share abre el menú del sistema con el link', async () => {
      const share = vi.fn(() => Promise.resolve());
      Object.defineProperty(navigator, 'share', { configurable: true, value: share });
      mockFetch({ 'public/producto.php': { producto: producto() } });

      render();
      fireEvent.click(await screen.findByRole('button', { name: 'Compartir' }));

      await waitFor(() => expect(share).toHaveBeenCalledWith({
        title: 'Guía de viaje',
        url: `${window.location.origin}/producto/400`,
      }));
      expect(screen.queryByText('Link copiado')).not.toBeInTheDocument();
    });

    it('sin navigator.share copia el link y avisa', async () => {
      const writeText = vi.fn(() => Promise.resolve());
      Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
      mockFetch({ 'public/producto.php': { producto: producto() } });

      render();
      fireEvent.click(await screen.findByRole('button', { name: 'Compartir' }));

      expect(await screen.findByText('Link copiado')).toBeInTheDocument();
      expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/producto/400`);
    });
  });
});
