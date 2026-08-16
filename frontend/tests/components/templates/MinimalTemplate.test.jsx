import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import MinimalTemplate from '../../../src/components/templates/MinimalTemplate';
import ModernTemplate from '../../../src/components/templates/ModernTemplate';
import CardsTemplate from '../../../src/components/templates/CardsTemplate';
import CondensedTemplate from '../../../src/components/templates/CondensedTemplate';
import { renderConProviders } from '../../helpers/render';

/**
 * Lo que comparten las cuatro plantillas está en contrato.test.jsx y
 * modales.test.jsx. Acá queda sólo lo que hoy hace distinto MinimalTemplate:
 * es la única que envía eventos a analytics.
 */

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

const evento = {
  id: 200,
  text: 'Mi Evento',
  url: 'https://entradas.com',
  description: 'Vení a bailar',
  image_url: 'https://img/evento.jpg',
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  event_maps_url: null,
  collaborators: [],
};

const imagen = { id: 300, text: 'Foto uno', image_url: 'https://img/1.jpg', url: null };

const conEventos = () => pagina([
  { id: 20, title: 'Agenda', type: 'eventos', links: [evento], collaborated_events: [] },
]);
const conGaleria = () => pagina([{ id: 30, title: 'Fotos', type: 'galeria', links: [imagen] }]);
const conLink = () => pagina([
  { id: 10, title: 'Links', type: 'links', links: [{ id: 1, text: 'Instagram', url: 'https://ig' }] },
]);

const OTRAS = [
  ['ModernTemplate', ModernTemplate],
  ['CardsTemplate', CardsTemplate],
  ['CondensedTemplate', CondensedTemplate],
];

describe('MinimalTemplate — analytics', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  describe('lo que MinimalTemplate sí registra', () => {
    it('registra la apertura del modal de evento', () => {
      renderConProviders(<MinimalTemplate page={conEventos()} />);

      fireEvent.click(screen.getByText('Mi Evento'));

      expect(window.gtag).toHaveBeenCalledWith('event', 'view_event_modal', {
        event_title: 'Mi Evento',
      });
    });

    it('registra la apertura del modal de imagen', () => {
      renderConProviders(<MinimalTemplate page={conGaleria()} />);

      fireEvent.click(screen.getAllByAltText('Foto uno')[0]);

      expect(window.gtag).toHaveBeenCalledWith('event', 'view_image_modal', {
        image_title: 'Foto uno',
      });
    });

    it('registra el click en un link', () => {
      renderConProviders(<MinimalTemplate page={conLink()} />);

      fireEvent.click(screen.getByText('Instagram').closest('a'));

      expect(window.gtag).toHaveBeenCalledWith('event', 'click_link', {
        link_url: 'https://ig',
        link_title: 'Instagram',
      });
    });
  });

  /**
   * Las otras tres plantillas no envían nada a analytics. Es una inconsistencia
   * conocida: quien elige otra plantilla queda sin métricas. Se fija tal como
   * está hoy para que el día que se unifique el comportamiento el test avise.
   */
  describe('inconsistencia conocida: las otras plantillas no registran nada', () => {
    it.each(OTRAS)('%s no registra el click en un link', (nombre, Plantilla) => {
      renderConProviders(<Plantilla page={conLink()} />);

      const enlace = screen.getAllByRole('link').find((a) => a.getAttribute('href') === 'https://ig');
      fireEvent.click(enlace);

      expect(window.gtag.mock.calls.filter((c) => c[1] === 'click_link')).toHaveLength(0);
    });

    it.each(OTRAS)('%s no registra la apertura del modal de evento', (nombre, Plantilla) => {
      renderConProviders(<Plantilla page={conEventos()} />);

      fireEvent.click(screen.getByText('Mi Evento'));

      expect(window.gtag.mock.calls.filter((c) => c[1] === 'view_event_modal')).toHaveLength(0);
    });

    it.each(OTRAS)('%s no registra la apertura del modal de imagen', (nombre, Plantilla) => {
      renderConProviders(<Plantilla page={conGaleria()} />);

      fireEvent.click(screen.getAllByAltText('Foto uno')[0]);

      expect(window.gtag.mock.calls.filter((c) => c[1] === 'view_image_modal')).toHaveLength(0);
    });
  });
});
