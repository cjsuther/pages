import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import MinimalTemplate from '../../../src/components/templates/MinimalTemplate';
import ModernTemplate from '../../../src/components/templates/ModernTemplate';
import CardsTemplate from '../../../src/components/templates/CardsTemplate';
import CondensedTemplate from '../../../src/components/templates/CondensedTemplate';
import { renderConProviders } from '../../helpers/render';

/**
 * Las cuatro plantillas abren los mismos dos modales (evento y galería) con el
 * mismo contenido; sólo cambia la maqueta. Estas comprobaciones corren contra
 * todas para que una no se quede atrás cuando se toque otra.
 */
const PLANTILLAS = [
  ['MinimalTemplate', MinimalTemplate],
  ['ModernTemplate', ModernTemplate],
  ['CardsTemplate', CardsTemplate],
  ['CondensedTemplate', CondensedTemplate],
];

const pagina = (groups = []) => ({
  id: 5,
  title: 'Mi Página',
  description: null,
  background_color: '#ffffff',
  text_color: '#000000',
  primary_color: '#3b82f6',
  follower_count: 0,
  groups,
});

const evento = (overrides = {}) => ({
  id: 200,
  text: 'Mi Evento',
  url: 'https://entradas.com',
  url_text: null,
  description: 'Vení a bailar',
  image_url: 'https://img/evento.jpg',
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  event_maps_url: 'https://maps.google.com/x',
  collaborators: [],
  ...overrides,
});

const imagen = (overrides = {}) => ({
  id: 300,
  text: 'Foto uno',
  image_url: 'https://img/1.jpg',
  url: null,
  ...overrides,
});

const grupoEventos = (links) => [
  { id: 20, title: 'Agenda', type: 'eventos', links, collaborated_events: [] },
];
const grupoGaleria = (links) => [{ id: 30, title: 'Fotos', type: 'galeria', links }];

describe.each(PLANTILLAS)('%s — modales', (nombre, Plantilla) => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  describe('modal de evento', () => {
    function abrir(overrides = {}) {
      renderConProviders(<Plantilla page={pagina(grupoEventos([evento(overrides)]))} />);
      fireEvent.click(screen.getByText('Mi Evento'));
    }

    it('se abre al tocar el evento', () => {
      abrir();

      expect(screen.getByText('🗓️ Fecha y hora:')).toBeInTheDocument();
    });

    it('muestra la descripción', () => {
      abrir();

      expect(screen.getByText('Vení a bailar')).toBeInTheDocument();
    });

    it('omite la descripción si no la hay', () => {
      abrir({ description: null });

      expect(screen.queryByText('Vení a bailar')).not.toBeInTheDocument();
    });

    it('muestra la imagen del evento', () => {
      abrir();

      const imagenes = screen.getAllByAltText('Mi Evento');
      expect(imagenes.some((i) => i.getAttribute('src') === 'https://img/evento.jpg')).toBe(true);
    });

    it('enlaza la dirección al mapa', () => {
      abrir();

      const enlace = screen.getByRole('link', { name: 'Av. Corrientes 1234' });
      expect(enlace).toHaveAttribute('href', 'https://maps.google.com/x');
      expect(enlace).toHaveAttribute('target', '_blank');
      expect(enlace).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('muestra la dirección sin enlace si no hay URL de mapa', () => {
      abrir({ event_maps_url: null });

      expect(screen.getByText('Av. Corrientes 1234')).toBeInTheDocument();
      expect(screen.queryByRole('link', { name: 'Av. Corrientes 1234' })).not.toBeInTheDocument();
    });

    it('omite la ubicación si el evento no tiene dirección', () => {
      abrir({ event_address: null });

      expect(screen.queryByText('📍 Ubicación:')).not.toBeInTheDocument();
    });

    it('usa el texto por defecto del botón de la URL', () => {
      abrir();

      expect(screen.getByRole('link', { name: 'Más información →' })).toHaveAttribute(
        'href',
        'https://entradas.com'
      );
    });

    it('respeta el texto propio del botón', () => {
      abrir({ url_text: 'Comprar entradas' });

      expect(screen.getByRole('link', { name: 'Comprar entradas' })).toBeInTheDocument();
      expect(screen.queryByText('Más información →')).not.toBeInTheDocument();
    });

    it('omite el botón si el evento no tiene URL', () => {
      abrir({ url: null });

      expect(screen.queryByText('Más información →')).not.toBeInTheDocument();
    });

    it('omite la fecha si el evento no la tiene', () => {
      abrir({ event_date: null, event_time: null });

      expect(screen.queryByText('🗓️ Fecha y hora:')).not.toBeInTheDocument();
    });

    it('muestra los colaboradores del evento', () => {
      abrir({
        collaborators: [
          { page_id: 7, page_slug: 'otra', page_title: 'Otra Página', page_image: null },
        ],
      });

      // Los colaboradores salen en la tarjeta y también dentro del modal:
      // se comprueba puntualmente el modal, que es el último overlay abierto.
      const overlays = document.querySelectorAll('.fixed.inset-0');
      const modal = within(overlays[overlays.length - 1]);

      expect(modal.getByText('Colabora:')).toBeInTheDocument();
      expect(modal.getByRole('link', { name: 'Otra Página' })).toHaveAttribute('href', '/otra');
    });

    it('se cierra con la ×', async () => {
      abrir();

      fireEvent.click(screen.getByRole('button', { name: '×' }));

      await waitFor(() => {
        expect(screen.queryByText('🗓️ Fecha y hora:')).not.toBeInTheDocument();
      });
    });

    it('se cierra al tocar el fondo', async () => {
      abrir();

      const fondos = document.querySelectorAll('.fixed.inset-0');
      fireEvent.click(fondos[fondos.length - 1]);

      await waitFor(() => {
        expect(screen.queryByText('🗓️ Fecha y hora:')).not.toBeInTheDocument();
      });
    });

    it('no se cierra al tocar el contenido', () => {
      abrir();

      fireEvent.click(screen.getByText('Vení a bailar'));

      expect(screen.getByText('🗓️ Fecha y hora:')).toBeInTheDocument();
    });

    it('el click en el enlace del mapa no cierra el modal', () => {
      abrir();

      fireEvent.click(screen.getByRole('link', { name: 'Av. Corrientes 1234' }));

      expect(screen.getByText('🗓️ Fecha y hora:')).toBeInTheDocument();
    });
  });

  describe('modal de galería', () => {
    function abrir(overrides = {}) {
      renderConProviders(<Plantilla page={pagina(grupoGaleria([imagen(overrides)]))} />);
      fireEvent.click(screen.getAllByAltText('Foto uno')[0]);
    }

    it('se abre al tocar la imagen', () => {
      abrir();

      expect(screen.getByRole('heading', { name: 'Foto uno' })).toBeInTheDocument();
    });

    it('muestra la imagen ampliada', () => {
      abrir();

      const imagenes = screen.getAllByAltText('Foto uno');
      expect(imagenes.length).toBeGreaterThan(1);
    });

    it('ofrece "Ver más" si la imagen tiene URL', () => {
      abrir({ url: 'https://destino.com' });

      expect(screen.getByRole('link', { name: 'Ver más →' })).toHaveAttribute(
        'href',
        'https://destino.com'
      );
    });

    it('no ofrece "Ver más" sin URL', () => {
      abrir();

      expect(screen.queryByText('Ver más →')).not.toBeInTheDocument();
    });

    it('se cierra con la ×', async () => {
      abrir();

      fireEvent.click(screen.getByRole('button', { name: '×' }));

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Foto uno' })).not.toBeInTheDocument();
      });
    });

    it('se cierra al tocar el fondo', async () => {
      abrir();

      const fondos = document.querySelectorAll('.fixed.inset-0');
      fireEvent.click(fondos[fondos.length - 1]);

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'Foto uno' })).not.toBeInTheDocument();
      });
    });

    it('el click en "Ver más" no cierra el modal', () => {
      abrir({ url: 'https://destino.com' });

      fireEvent.click(screen.getByRole('link', { name: 'Ver más →' }));

      expect(screen.getByRole('heading', { name: 'Foto uno' })).toBeInTheDocument();
    });
  });
});
