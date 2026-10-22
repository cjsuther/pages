import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Routes, Route, useParams } from 'react-router';
import { renderConProviders, API_URL } from '../helpers/render';
import { mockFetch, llamadaA } from '../helpers/api';
import Sugerencias, { fechaCorta } from '../../src/components/Sugerencias';

const EVENTO = {
  tipo: 'evento', id: 301, text: 'Noche de jazz en el sótano', image_url: 'https://img.test/jazz.jpg',
  event_date: '2026-10-24', event_time: '21:00:00', page_title: 'La Sala',
};
const PRODUCTO = {
  tipo: 'producto', id: 201, text: 'Guía de viaje', image_url: null, precio: 11900, page_title: 'La Sala',
};
const GRATIS = { ...PRODUCTO, id: 202, text: 'Fanzine', precio: 0 };

function Destino() {
  const { id } = useParams();
  return <p>Destino {id}</p>;
}

function montar(props = {}, rutas = { 'public/sugerencias.php': { sugerencias: [PRODUCTO, EVENTO, GRATIS] } }) {
  const red = mockFetch(rutas);
  const vista = renderConProviders(
    <Routes>
      <Route path="/" element={<Sugerencias tipo="evento" id={100} {...props} />} />
      <Route path="/evento/:id" element={<Destino />} />
      <Route path="/producto/:id" element={<Destino />} />
    </Routes>
  );

  return { ...vista, ...red };
}

describe('Sugerencias', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('pide las sugerencias de X, con la página donde se ve', async () => {
    const { llamadas } = montar({ pagina: 7 });

    await screen.findByText('También te puede interesar');

    expect(llamadaA(llamadas, 'sugerencias.php').url)
      .toBe(`${API_URL}/public/sugerencias.php?tipo=evento&id=100&pagina=7`);
  });

  it('sin página no la manda', async () => {
    const { llamadas } = montar({ tipo: 'producto', id: 55 });

    await screen.findByText('También te puede interesar');

    expect(llamadaA(llamadas, 'sugerencias.php').url).toBe(`${API_URL}/public/sugerencias.php?tipo=producto&id=55`);
  });

  it('muestra una grilla de tres con título, y fecha corta o precio', async () => {
    montar();

    const lista = await screen.findByRole('list');
    expect(lista).toHaveClass('grid-cols-3');

    const tarjetas = within(lista).getAllByRole('link');
    expect(tarjetas).toHaveLength(3);
    expect(tarjetas[0]).toHaveTextContent('Guía de viaje');
    expect(tarjetas[0]).toHaveTextContent('$ 11.900');
    expect(tarjetas[1]).toHaveTextContent('Noche de jazz en el sótano');
    expect(tarjetas[1]).toHaveTextContent('sáb 24/10');
    expect(tarjetas[2]).toHaveTextContent('Gratis');
  });

  it('cada tarjeta lleva a su link propio', async () => {
    montar();

    await screen.findByRole('list');

    expect(screen.getByRole('link', { name: /Guía de viaje/ })).toHaveAttribute('href', '/producto/201');
    expect(screen.getByRole('link', { name: /Noche de jazz/ })).toHaveAttribute('href', '/evento/301');
  });

  it('al tocar una se va a su detalle', async () => {
    const usuario = userEvent.setup();
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    montar();

    await usuario.click(await screen.findByRole('link', { name: /Noche de jazz/ }));

    expect(screen.getByText('Destino 301')).toBeInTheDocument();
  });

  it('con una sola muestra esa', async () => {
    montar({}, { 'public/sugerencias.php': { sugerencias: [EVENTO] } });

    const lista = await screen.findByRole('list');

    expect(within(lista).getAllByRole('link')).toHaveLength(1);
  });

  it('sin sugerencias no muestra nada, ni el título', async () => {
    const { llamadas } = montar({}, { 'public/sugerencias.php': { sugerencias: [] } });

    await waitFor(() => expect(llamadas).toHaveLength(1));

    expect(screen.queryByText('También te puede interesar')).not.toBeInTheDocument();
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
  });

  it('si la API falla no muestra nada', async () => {
    const { llamadas } = montar({}, { 'public/sugerencias.php': { status: 500, body: { error: 'x' } } });

    await waitFor(() => expect(llamadas).toHaveLength(1));

    expect(screen.queryByText('También te puede interesar')).not.toBeInTheDocument();
  });

  it('si no hay red no muestra nada', async () => {
    const { llamadas } = montar({}, {});

    await waitFor(() => expect(llamadas).toHaveLength(1));

    expect(screen.queryByText('También te puede interesar')).not.toBeInTheDocument();
  });

  it('mientras carga no muestra nada', () => {
    global.fetch = vi.fn(() => new Promise(() => {}));
    renderConProviders(<Sugerencias tipo="evento" id={100} />);

    expect(screen.queryByText('También te puede interesar')).not.toBeInTheDocument();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('sin id de X no pide nada', () => {
    const { llamadas } = montar({ id: null });

    expect(llamadas).toHaveLength(0);
  });

  it('nunca muestra más de tres', async () => {
    montar({}, { 'public/sugerencias.php': { sugerencias: [PRODUCTO, EVENTO, GRATIS, { ...EVENTO, id: 302 }] } });

    const lista = await screen.findByRole('list');

    expect(within(lista).getAllByRole('link')).toHaveLength(3);
  });

  it('respeta los colores de la página', async () => {
    montar({ colores: { texto: '#ffffff', bordeTarjeta: 'rgba(255, 255, 255, 0.14)' } });

    const tarjeta = await screen.findByRole('link', { name: /Guía de viaje/ });

    expect(tarjeta).toHaveStyle({ borderColor: 'rgba(255, 255, 255, 0.14)' });
  });
});

describe('fechaCorta', () => {
  it('es el día de la semana y el día/mes, sin correrse por la zona horaria', () => {
    expect(fechaCorta('2026-10-24')).toBe('sáb 24/10');
    expect(fechaCorta('2026-10-23')).toBe('vie 23/10');
    expect(fechaCorta('2027-01-03')).toBe('dom 03/01');
  });

  it('sin fecha no dice nada', () => {
    expect(fechaCorta(null)).toBe('');
    expect(fechaCorta('')).toBe('');
  });
});
