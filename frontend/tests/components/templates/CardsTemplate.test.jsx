import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { screen } from '@testing-library/react';
import CardsTemplate from '../../../src/components/templates/CardsTemplate';
import { renderConProviders } from '../../helpers/render';

/**
 * CardsTemplate es la única plantilla que apoya el contenido sobre tarjetas
 * opacas. Esa superficie no se configura aparte: acompaña al fondo elegido
 * para la página y se delimita con un borde tenue, no cambiando de color.
 *
 * Cuando estuvo clavada en blanco, una página de fondo oscuro —con
 * tipografía clara, que es lo que corresponde a ese fondo— quedaba con el
 * texto invisible dentro de cada tarjeta.
 */
const pagina = (overrides = {}) => ({
  id: 5,
  title: 'Mi Página',
  url_slug: 'mi-pagina',
  background_color: '#ffffff',
  text_color: '#000000',
  primary_color: '#3b82f6',
  groups: [{
    id: 20,
    title: 'Agenda',
    type: 'eventos',
    collaborated_events: [],
    links: [{
      id: 200,
      text: 'Mi Evento',
      url: 'https://entradas.com',
      event_date: '2026-12-01',
      event_time: '20:00:00',
      collaborators: [],
    }],
  }],
  ...overrides,
});

/** La tarjeta es el ancestro con estilo propio del título del evento. */
const tarjetaDelEvento = () => screen.getByText('Mi Evento').closest('[style]');

const canales = (color) => (/(\d+), (\d+), (\d+)/.exec(color) || []).slice(1).map(Number);

describe('CardsTemplate: color de las tarjetas', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  const oscura = { background_color: '#0a0a0b', text_color: '#f7f7f7' };

  it('la tarjeta no se pinta de blanco sobre una página oscura', () => {
    renderConProviders(<CardsTemplate page={pagina(oscura)} />);

    expect(tarjetaDelEvento().style.backgroundColor).not.toBe('rgb(255, 255, 255)');
  });

  /** El pedido: la tarjeta acompaña al fondo, no trae un color nuevo. */
  it('la tarjeta se queda cerca del fondo elegido', () => {
    renderConProviders(<CardsTemplate page={pagina(oscura)} />);

    const tarjeta = canales(tarjetaDelEvento().style.backgroundColor);

    canales('rgb(10, 10, 11)').forEach((canal, i) => {
      expect(Math.abs(tarjeta[i] - canal)).toBeLessThan(30);
    });
  });

  /** Pero tiene que despegarse algo, o no se ve dónde empieza. */
  it('la tarjeta no queda idéntica al fondo', () => {
    renderConProviders(<CardsTemplate page={pagina(oscura)} />);

    expect(tarjetaDelEvento().style.backgroundColor).not.toBe('rgb(10, 10, 11)');
  });

  it('un borde tenue la delimita', () => {
    renderConProviders(<CardsTemplate page={pagina(oscura)} />);

    expect(tarjetaDelEvento().style.border).toContain('rgba(247, 247, 247');
  });

  /**
   * El texto es siempre el color elegido, sin corregirlo por contraste: la
   * tarjeta ya no impone un color, así que no hay de qué protegerlo.
   */
  it('el texto es exactamente la tipografía elegida', () => {
    renderConProviders(<CardsTemplate page={pagina(oscura)} />);

    expect(tarjetaDelEvento().style.color).toBe('rgb(247, 247, 247)');
  });

  it('respeta la tipografía elegida también sobre una página clara', () => {
    renderConProviders(
      <CardsTemplate page={pagina({ background_color: '#f3f4f6', text_color: '#111827' })} />
    );

    expect(tarjetaDelEvento().style.color).toBe('rgb(17, 24, 39)');
  });

  it('no rompe si la página no tiene colores cargados', () => {
    expect(() =>
      renderConProviders(
        <CardsTemplate page={pagina({ background_color: null, text_color: null })} />
      )
    ).not.toThrow();
  });
});
