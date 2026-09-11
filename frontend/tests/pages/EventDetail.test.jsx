import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import EventDetail from '../../src/pages/EventDetail';
import { renderConProviders, API_URL } from '../helpers/render';
import { mockFetch } from '../helpers/api';

const evento = (overrides = {}) => ({
  id: 200,
  text: 'Recital de Rock',
  description: 'Una noche inolvidable',
  image_url: 'https://img/evento.jpg',
  url: 'https://entradas.com',
  url_text: null,
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  event_maps_url: 'https://maps.google.com/x',
  page_id: 5,
  page_title: 'Mi Página',
  page_slug: 'mi-pagina',
  page_image: 'https://img/pagina.png',
  ...overrides,
});

function render(id = '200') {
  return renderConProviders(<EventDetail />, { route: `/evento/${id}`, path: '/evento/:id' });
}

describe('EventDetail', () => {
  describe('carga', () => {
    it('muestra el estado de carga', () => {
      mockFetch({ 'public/event.php': { event: evento() } });

      render();

      expect(screen.getByText('Cargando...')).toBeInTheDocument();
    });

    it('pide el evento por su id', async () => {
      const { llamadas } = mockFetch({ 'public/event.php': { event: evento() } });

      render('345');

      await waitFor(() => {
        expect(llamadas[0].url).toBe(`${API_URL}/public/event.php?id=345`);
      });
    });
  });

  describe('errores', () => {
    it('muestra el error que devuelve la API', async () => {
      mockFetch({ 'public/event.php': { error: 'Evento no encontrado' } });

      render();

      expect(await screen.findByText('Evento no encontrado')).toBeInTheDocument();
    });

    it('ofrece volver al inicio', async () => {
      mockFetch({ 'public/event.php': { error: 'Evento no encontrado' } });

      render();

      expect(await screen.findByRole('link', { name: 'Ir al inicio' })).toHaveAttribute('href', '/');
    });

    it('muestra un mensaje si falla la red', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      render();

      expect(await screen.findByText('Error al cargar el evento')).toBeInTheDocument();
    });

    it('muestra el fallback si la API responde sin evento', async () => {
      mockFetch({ 'public/event.php': {} });

      render();

      expect(await screen.findByText('Evento no encontrado')).toBeInTheDocument();
    });
  });

  describe('detalle', () => {
    async function renderEvento(overrides = {}) {
      mockFetch({ 'public/event.php': { event: evento(overrides) } });
      render();
      return screen.findByRole('heading', { name: 'Recital de Rock' });
    }

    it('muestra el título', async () => {
      await renderEvento();

      expect(screen.getByRole('heading', { name: 'Recital de Rock' })).toBeInTheDocument();
    });

    it('muestra la descripción', async () => {
      await renderEvento();

      expect(screen.getByText('Una noche inolvidable')).toBeInTheDocument();
    });

    it('omite la descripción si no la hay', async () => {
      await renderEvento({ description: null });

      expect(screen.queryByText('Una noche inolvidable')).not.toBeInTheDocument();
    });

    it('muestra la imagen del evento', async () => {
      await renderEvento();

      expect(screen.getByAltText('Recital de Rock')).toHaveAttribute('src', 'https://img/evento.jpg');
    });

    /**
     * Sin afiche no se pone nada.
     *
     * Antes se rellenaba con una foto de stock de un banco de imágenes
     * ajeno: en la página de un artista eso se lee como si fuera suya, y no
     * tiene nada que ver con su fecha.
     */
    it('sin afiche no inventa una imagen', async () => {
      await renderEvento({ image_url: null });

      expect(screen.queryByAltText('Recital de Rock')).not.toBeInTheDocument();
    });

    it('muestra la fecha en castellano', async () => {
      await renderEvento();

      // Formato es-AR: "martes, 01 de diciembre de 2026, 20:00"
      expect(screen.getByText(/diciembre/)).toBeInTheDocument();
    });

    it('omite la fecha si no la hay', async () => {
      await renderEvento({ event_date: null, event_time: null });

      expect(screen.queryByText(/diciembre/)).not.toBeInTheDocument();
    });

    it('enlaza la dirección al mapa', async () => {
      await renderEvento();

      const enlace = screen.getByRole('link', { name: 'Av. Corrientes 1234' });
      expect(enlace).toHaveAttribute('href', 'https://maps.google.com/x');
      expect(enlace).toHaveAttribute('target', '_blank');
      expect(enlace).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('muestra la dirección sin enlace si no hay mapa', async () => {
      await renderEvento({ event_maps_url: null });

      expect(screen.getByText('Av. Corrientes 1234')).toBeInTheDocument();
      expect(screen.queryByRole('link', { name: 'Av. Corrientes 1234' })).not.toBeInTheDocument();
    });

    it('omite la ubicación si no hay dirección', async () => {
      await renderEvento({ event_address: null });

      expect(screen.queryByText('Av. Corrientes 1234')).not.toBeInTheDocument();
    });
  });

  /**
   * Esta pantalla es a la que se llega desde un enlace directo —el que se
   * comparte por WhatsApp o se pega en una historia—, así que muchas veces es
   * lo primero que alguien ve de esa página. Estaba blanca con el verde de
   * Rezonar: quien llegaba por ahí no tenía cómo reconocer de quién era.
   */
  describe('los colores son los de la página', () => {
    const conPaleta = (overrides = {}) => evento({
      background_color: '#070708',
      text_color: '#ffffff',
      primary_color: '#ff0055',
      secondary_color: '#00d4ff',
      title_color: '#ffcc00',
      ...overrides,
    });

    async function renderPintado(overrides = {}) {
      mockFetch({ 'public/event.php': { event: conPaleta(overrides) } });
      render();
      return screen.findByRole('heading', { name: 'Recital de Rock' });
    }

    it('el fondo es el de la página, no blanco', async () => {
      await renderPintado();

      // La misma caja que usan las plantillas: se reconoce por su ancho.
      const caja = document.querySelector('[class*="max-w-[580px]"]');
      expect(caja).toHaveStyle({ backgroundColor: '#070708' });
    });

    it('el título usa el color de títulos', async () => {
      await renderPintado();

      expect(screen.getByRole('heading', { name: 'Recital de Rock' }))
        .toHaveStyle({ color: '#ffcc00' });
    });

    /** El botón sale del color de botones de la página, no de uno nuestro. */
    it('el botón usa el color de la página', async () => {
      await renderPintado();

      expect(screen.getByRole('link', { name: /Más información/ }))
        .toHaveStyle({ backgroundColor: '#00d4ff' });
    });

    it('la imagen de fondo de la página también se respeta', async () => {
      await renderPintado({ background_image: 'https://img/fondo.png' });

      const capa = document.querySelector('[style*="background-image"]');
      expect(capa.style.backgroundImage).toBe('url("https://img/fondo.png")');
    });

    /** Una página que nunca tocó los colores se sigue viendo como antes. */
    it('sin colores propios no rompe', async () => {
      mockFetch({ 'public/event.php': { event: evento() } });
      render();

      expect(await screen.findByRole('heading', { name: 'Recital de Rock' })).toBeInTheDocument();
    });
  });

  describe('enlaces', () => {
    async function renderEvento(overrides = {}) {
      mockFetch({ 'public/event.php': { event: evento(overrides) } });
      render();
      return screen.findByRole('heading', { name: 'Recital de Rock' });
    }

    it('usa el texto por defecto del botón', async () => {
      await renderEvento();

      expect(screen.getByRole('link', { name: /Más información/ })).toHaveAttribute(
        'href',
        'https://entradas.com'
      );
    });

    it('respeta el texto propio del botón', async () => {
      await renderEvento({ url_text: 'Comprar entradas' });

      expect(screen.getByRole('link', { name: /Comprar entradas/ })).toBeInTheDocument();
    });

    it('omite el botón si el evento no tiene URL', async () => {
      await renderEvento({ url: null });

      expect(screen.queryByText(/Más información/)).not.toBeInTheDocument();
    });

    it('enlaza a la página organizadora', async () => {
      await renderEvento();

      const enlaces = screen.getAllByRole('link').filter(
        (a) => a.getAttribute('href') === '/mi-pagina'
      );
      expect(enlaces.length).toBeGreaterThan(0);
    });

    it('muestra el nombre y la foto de la página', async () => {
      await renderEvento();

      expect(screen.getByText('Mi Página')).toBeInTheDocument();
      expect(screen.getByAltText('Mi Página')).toHaveAttribute('src', 'https://img/pagina.png');
    });

    it('omite el bloque de página si no hay título', async () => {
      await renderEvento({ page_title: null });

      expect(screen.queryByText('Mi Página')).not.toBeInTheDocument();
    });
  });
});
