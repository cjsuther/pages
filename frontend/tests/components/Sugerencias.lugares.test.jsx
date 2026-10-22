import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import MinimalTemplate from '../../src/components/templates/MinimalTemplate';
import ModernTemplate from '../../src/components/templates/ModernTemplate';
import CardsTemplate from '../../src/components/templates/CardsTemplate';
import CondensedTemplate from '../../src/components/templates/CondensedTemplate';
import AfichesTemplate from '../../src/components/templates/AfichesTemplate';
import DetalleProducto from '../../src/components/DetalleProducto';
import EventDetail from '../../src/pages/EventDetail';
import PaginaDeProducto from '../../src/pages/PaginaDeProducto';
import EstadoOrden from '../../src/pages/EstadoOrden';
import EstadoCompra from '../../src/pages/EstadoCompra';
import { renderConProviders } from '../helpers/render';
import { mockFetch } from '../helpers/api';
import { paleta } from '../../src/utils/colores';

/**
 * "También te puede interesar" en cada lugar que pide el issue #30: el modal
 * de evento de las cinco plantillas, el detalle de productos, /evento/:id,
 * /producto/:id y las dos pantallas de vuelta de una compra aprobada.
 */
const TITULO = 'También te puede interesar';

const SUGERENCIAS = [
  { tipo: 'producto', id: 901, text: 'Fanzine', image_url: null, precio: 0, page_title: 'Mi Página' },
  { tipo: 'evento', id: 902, text: 'Otra fecha', image_url: null, event_date: '2026-12-05', event_time: '21:00:00', page_title: 'Mi Página' },
];

/** Las URLs de sugerencias que se pidieron, como URLSearchParams. */
function pedidas(llamadas) {
  return llamadas
    .filter((l) => l.url.includes('public/sugerencias.php'))
    .map((l) => new URL(l.url).searchParams);
}

async function grilla() {
  const seccion = (await screen.findByRole('heading', { name: TITULO })).closest('section');
  return within(seccion).getAllByRole('link');
}

const pagina = {
  id: 5,
  title: 'Mi Página',
  background_color: '#ffffff',
  text_color: '#000000',
  primary_color: '#3b82f6',
  follower_count: 0,
  groups: [{
    id: 20,
    title: 'Agenda',
    type: 'eventos',
    links: [{
      id: 200, text: 'Mi Evento', url: null, description: 'Vení a bailar', image_url: 'https://img/e.jpg',
      event_date: '2026-12-01', event_time: '20:00:00', event_address: null, collaborators: [],
    }],
    collaborated_events: [],
  }],
};

const PLANTILLAS = [
  ['MinimalTemplate', MinimalTemplate],
  ['ModernTemplate', ModernTemplate],
  ['CardsTemplate', CardsTemplate],
  ['CondensedTemplate', CondensedTemplate],
  ['AfichesTemplate', AfichesTemplate],
];

describe('Sugerencias en cada lugar', () => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
  });

  describe.each(PLANTILLAS)('modal de evento de %s', (nombre, Plantilla) => {
    it('muestra la grilla con X y la página que se está viendo', async () => {
      const { llamadas } = mockFetch({ 'public/sugerencias.php': { sugerencias: SUGERENCIAS } });
      renderConProviders(<Plantilla page={pagina} />);

      expect(screen.queryByText(TITULO)).not.toBeInTheDocument();
      fireEvent.click(screen.getAllByAltText('Mi Evento')[0]);

      expect(await grilla()).toHaveLength(2);
      const [params] = pedidas(llamadas);
      expect(params.get('tipo')).toBe('evento');
      expect(params.get('id')).toBe('200');
      expect(params.get('pagina')).toBe('5');
    });

    it('sin sugerencias no muestra nada', async () => {
      const { llamadas } = mockFetch({ 'public/sugerencias.php': { sugerencias: [] } });
      renderConProviders(<Plantilla page={pagina} />);

      fireEvent.click(screen.getAllByAltText('Mi Evento')[0]);

      await waitFor(() => expect(pedidas(llamadas)).toHaveLength(1));
      expect(screen.queryByText(TITULO)).not.toBeInTheDocument();
    });
  });

  describe('detalle de productos', () => {
    const productos = [
      { id: 400, text: 'Guía de viaje', precio: 2500, image_url: null },
      { id: 401, text: 'Mapa', precio: 0, image_url: null },
    ];

    it('pide las del producto que se ve y las recalcula al pasar a otro', async () => {
      const { llamadas } = mockFetch({ 'public/sugerencias.php': { sugerencias: SUGERENCIAS } });
      renderConProviders(
        <DetalleProducto productos={productos} colores={paleta({})} modalStyle={{}} onCerrar={vi.fn()} />
      );

      await grilla();
      expect(pedidas(llamadas).map((p) => [p.get('tipo'), p.get('id')])).toEqual([['producto', '400']]);

      fireEvent.click(screen.getByRole('button', { name: 'Producto siguiente' }));

      await waitFor(() => expect(pedidas(llamadas)).toHaveLength(2));
      expect(pedidas(llamadas)[1].get('id')).toBe('401');
      expect(await grilla()).toHaveLength(2);
    });
  });

  describe('/evento/:id', () => {
    it('muestra la grilla debajo del evento, sin página', async () => {
      const { llamadas } = mockFetch({
        'public/event.php': { event: { id: 77, text: 'Show', page_title: 'Mi Página', page_slug: 'mi-pagina' } },
        'public/sugerencias.php': { sugerencias: SUGERENCIAS },
      });
      renderConProviders(<EventDetail />, { route: '/evento/77', path: '/evento/:id' });

      expect(await grilla()).toHaveLength(2);
      const [params] = pedidas(llamadas);
      expect(params.get('tipo')).toBe('evento');
      expect(params.get('id')).toBe('77');
      expect(params.has('pagina')).toBe(false);
    });
  });

  describe('/producto/:id', () => {
    it('muestra la grilla debajo del producto', async () => {
      const { llamadas } = mockFetch({
        'public/producto.php': { producto: { id: 345, text: 'Guía', precio: 0, page_title: 'Mi Página', page_slug: 'mi-pagina' } },
        'public/sugerencias.php': { sugerencias: SUGERENCIAS },
      });
      renderConProviders(<PaginaDeProducto />, { route: '/producto/345', path: '/producto/:id' });

      expect(await grilla()).toHaveLength(2);
      const [params] = pedidas(llamadas);
      expect(params.get('tipo')).toBe('producto');
      expect(params.get('id')).toBe('345');
    });
  });

  describe('vuelta de una compra', () => {
    const API = 'https://api.test/api';

    it('/entrada/:codigo aprobada sugiere a partir del evento comprado', async () => {
      const { llamadas } = mockFetch({
        'public/orden.php': { orden: { codigo: 'ABC', estado: 'pagada', evento: 'Fiesta', link_id: 77, cantidad: 1, total: 0 } },
        'public/sugerencias.php': { sugerencias: SUGERENCIAS },
      });
      renderConProviders(<EstadoOrden apiUrl={API} />, { route: '/entrada/ABC', path: '/entrada/:codigo' });

      expect(await grilla()).toHaveLength(2);
      const sugerencias = llamadas.find((l) => l.url.includes('sugerencias.php'));
      expect(sugerencias.url).toBe(`${API}/public/sugerencias.php?tipo=evento&id=77`);
    });

    it('/entrada/:codigo sin aprobar todavía no sugiere nada', async () => {
      const { llamadas } = mockFetch({
        'public/orden.php': { orden: { codigo: 'ABC', estado: 'rechazada', evento: 'Fiesta', link_id: 77, cantidad: 1, total: 0 } },
      });
      renderConProviders(<EstadoOrden apiUrl={API} />, { route: '/entrada/ABC', path: '/entrada/:codigo' });

      await screen.findByText('El pago fue rechazado');
      expect(pedidas(llamadas)).toHaveLength(0);
    });

    it('/compra/:codigo aprobada sugiere a partir del producto comprado', async () => {
      const { llamadas } = mockFetch({
        'public/orden-producto.php': { orden: { codigo: 'ABC', estado: 'pagada', producto: 'Guía', link_id: 345, total: 0 } },
        'public/sugerencias.php': { sugerencias: SUGERENCIAS },
      });
      renderConProviders(<EstadoCompra apiUrl={API} />, { route: '/compra/ABC', path: '/compra/:codigo' });

      expect(await grilla()).toHaveLength(2);
      const sugerencias = llamadas.find((l) => l.url.includes('sugerencias.php'));
      expect(sugerencias.url).toBe(`${API}/public/sugerencias.php?tipo=producto&id=345`);
    });

    it('/compra/:codigo de un producto borrado no pide sugerencias', async () => {
      const { llamadas } = mockFetch({
        'public/orden-producto.php': { orden: { codigo: 'ABC', estado: 'pagada', producto: 'Guía', link_id: null, total: 0 } },
      });
      renderConProviders(<EstadoCompra apiUrl={API} />, { route: '/compra/ABC', path: '/compra/:codigo' });

      await screen.findByText('¡Listo!');
      expect(pedidas(llamadas)).toHaveLength(0);
    });
  });
});
