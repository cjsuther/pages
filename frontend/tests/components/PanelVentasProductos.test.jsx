import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import PanelVentasProductos, { describirDescargas, nombreDelExcel } from '../../src/components/PanelVentasProductos';
import { mockFetch, llamadaA, cuerpoDe, tokenDe } from '../helpers/api';

const API = 'http://api';

const orden = (overrides = {}) => ({
  id: 1,
  codigo: 'AAAABBBBCCCC',
  nombre: 'Ana Compradora',
  email: 'ana@test.local',
  telefono: '11 5555-1234',
  total: 2500,
  moneda: 'ARS',
  estado: 'pagada',
  creado_en: '2026-10-01 10:00:00',
  pagada_en: '2026-10-01 10:05:00',
  descargas: 0,
  primera_descarga_en: null,
  ultima_descarga_en: null,
  producto: 'Guía de viaje',
  producto_borrado: false,
  link_id: 100,
  ...overrides,
});

const ventas = (ordenes = [orden()], resumen = { pagadas: 1, recaudado: 2500, descargaron: 0 }) => ({ ordenes, resumen });

function montar(props = { linkId: 100 }, respuestas = {}) {
  const mock = mockFetch({
    'productos/ventas.php': ventas(),
    ...respuestas,
  });
  render(<PanelVentasProductos apiUrl={API} token="tok-123" nombre="Guía de viaje" {...props} />);
  return mock;
}

describe('PanelVentasProductos', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
  });

  describe('lista de un producto', () => {
    it('pide las ventas del producto con la sesión', async () => {
      const { llamadas } = montar();

      await screen.findByText('Ana Compradora');
      const llamada = llamadaA(llamadas, 'productos/ventas.php');
      expect(llamada.url).toBe(`${API}/productos/ventas.php?link_id=100`);
      expect(tokenDe(llamada)).toBe('Bearer tok-123');
    });

    it('tiene las columnas pedidas, sin la de producto', async () => {
      montar();

      await screen.findByText('Ana Compradora');
      const encabezados = screen.getAllByRole('columnheader').map((th) => th.textContent);
      expect(encabezados).toEqual(['Estado', 'Nombre', 'Contacto', 'Total', 'Fecha', 'Descargó', 'Acciones']);
    });

    it('muestra estado, nombre, contacto, total y fecha', async () => {
      montar();

      const fila = (await screen.findByText('Ana Compradora')).closest('tr');
      expect(within(fila).getByText('Pagada')).toBeInTheDocument();
      expect(within(fila).getByText('ana@test.local')).toHaveAttribute('href', 'mailto:ana@test.local');
      expect(within(fila).getByText('11 5555-1234')).toBeInTheDocument();
      expect(within(fila).getByText(/2\.500/)).toBeInTheDocument();
      expect(within(fila).getByText('01/10/2026')).toBeInTheDocument();
    });

    it('"Descargó" dice No hasta que baja el archivo', async () => {
      montar();

      const fila = (await screen.findByText('Ana Compradora')).closest('tr');
      expect(within(fila).getByText('No')).toBeInTheDocument();
    });

    it('"Descargó" dice cuántas veces y desde cuándo', async () => {
      montar({ linkId: 100 }, {
        'productos/ventas.php': ventas([orden({ descargas: 3, primera_descarga_en: '2026-10-02 09:00:00' })]),
      });

      const fila = (await screen.findByText('Ana Compradora')).closest('tr');
      expect(within(fila).getByText('Sí · 3 veces')).toBeInTheDocument();
      expect(within(fila).getByText(/la primera el 02\/10\/2026/)).toBeInTheDocument();
    });

    it('arriba van las pagadas, lo recaudado y cuántos ya descargaron', async () => {
      montar({ linkId: 100 }, {
        'productos/ventas.php': ventas(
          [orden(), orden({ id: 2, codigo: 'B', nombre: 'Beto', descargas: 1 })],
          { pagadas: 2, recaudado: 5000, descargaron: 1 }
        ),
      });

      await screen.findByText('Beto');
      expect(screen.getByText('VENTAS PAGADAS').nextSibling).toHaveTextContent('2');
      expect(screen.getByText('RECAUDADO').nextSibling).toHaveTextContent(/5\.000/);
      expect(screen.getByText('YA DESCARGARON').nextSibling).toHaveTextContent('1/2');
    });

    it('un producto gratis dice Gratis en el total', async () => {
      montar({ linkId: 100 }, { 'productos/ventas.php': ventas([orden({ total: 0 })]) });

      const fila = (await screen.findByText('Ana Compradora')).closest('tr');
      expect(within(fila).getByText('Gratis')).toBeInTheDocument();
    });

    it('nunca muestra un link de descarga', async () => {
      montar();

      await screen.findByText('Ana Compradora');
      const enlaces = [...document.querySelectorAll('a')].map((a) => a.getAttribute('href'));
      expect(enlaces.some((h) => h.includes('descarga'))).toBe(false);
    });

    it('sin ventas lo dice', async () => {
      montar({ linkId: 100 }, { 'productos/ventas.php': ventas([], { pagadas: 0, recaudado: 0, descargaron: 0 }) });

      expect(await screen.findByText('Todavía no hay ventas de este producto.')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Exportar/ })).not.toBeInTheDocument();
    });

    it('un 403 se muestra como error, sin datos', async () => {
      montar({ linkId: 100 }, {
        'productos/ventas.php': { status: 403, body: { error: 'No podés ver las ventas de este producto' } },
      });

      expect(await screen.findByText('No podés ver las ventas de este producto')).toBeInTheDocument();
      expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
  });

  describe('vista por página', () => {
    it('pide por página y agrega la columna Producto, con los borrados marcados', async () => {
      const { llamadas } = montar({ pageId: 5 }, {
        'productos/ventas.php': ventas([
          orden(),
          orden({ id: 2, codigo: 'B', nombre: 'Beto', producto: 'Pack viejo', producto_borrado: true, link_id: null }),
        ]),
      });

      await screen.findByText('Beto');
      expect(llamadaA(llamadas, 'productos/ventas.php').url).toContain('page_id=5');
      expect(screen.getAllByRole('columnheader').map((th) => th.textContent)).toContain('Producto');
      const fila = screen.getByText('Beto').closest('tr');
      expect(within(fila).getByText('Pack viejo')).toBeInTheDocument();
      expect(within(fila).getByText('Producto borrado')).toBeInTheDocument();
      expect(within(screen.getByText('Ana Compradora').closest('tr')).queryByText('Producto borrado')).not.toBeInTheDocument();
    });
  });

  describe('exportar', () => {
    it('baja el Excel con la sesión', async () => {
      const { llamadas } = montar();
      await screen.findByText('Ana Compradora');
      global.URL.createObjectURL = vi.fn(() => 'blob:x');
      global.URL.revokeObjectURL = vi.fn();
      // jsdom no navega: el click del enlace de descarga sólo se registra.
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
      const anterior = global.fetch;
      global.fetch = vi.fn((url, opciones) => {
        llamadas.push({ url, options: opciones });
        return Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['x'])) });
      });

      fireEvent.click(screen.getByRole('button', { name: /Exportar Excel/ }));

      await waitFor(() => expect(global.URL.createObjectURL).toHaveBeenCalled());
      const llamada = llamadas.find((l) => l.url.includes('formato=excel'));
      expect(llamada.url).toContain('link_id=100');
      expect(tokenDe(llamada)).toBe('Bearer tok-123');
      global.fetch = anterior;
    });
  });

  describe('reenviar el link', () => {
    it('sólo se ofrece en las pagadas', async () => {
      montar({ linkId: 100 }, {
        'productos/ventas.php': ventas([
          orden(),
          orden({ id: 2, codigo: 'B', nombre: 'Beto', estado: 'pendiente' }),
          orden({ id: 3, codigo: 'C', nombre: 'Carla', estado: 'cancelada' }),
        ]),
      });

      await screen.findByText('Beto');
      expect(screen.getAllByRole('button', { name: /Reenviar link/ })).toHaveLength(1);
      expect(screen.getByRole('button', { name: 'Reenviar link a Ana Compradora' })).toBeInTheDocument();
      expect(screen.getByText('Pendiente')).toBeInTheDocument();
    });

    it('pide el reenvío por el código y avisa', async () => {
      const { llamadas } = montar({ linkId: 100 }, {
        'productos/reenviar.php': { enviado: true, mensaje: 'Le reenviamos el link a ana@test.local' },
      });

      fireEvent.click(await screen.findByRole('button', { name: 'Reenviar link a Ana Compradora' }));

      expect(await screen.findByRole('status')).toHaveTextContent('Le reenviamos el link a ana@test.local');
      const llamada = llamadaA(llamadas, 'productos/reenviar.php');
      expect(llamada.options.method).toBe('POST');
      expect(cuerpoDe(llamada)).toEqual({ codigo: 'AAAABBBBCCCC' });
      expect(tokenDe(llamada)).toBe('Bearer tok-123');
    });

    it('si no se puede, muestra el motivo sin perder la lista', async () => {
      montar({ linkId: 100 }, {
        'productos/reenviar.php': { status: 429, body: { error: 'El link se mandó hace instantes.' } },
      });

      fireEvent.click(await screen.findByRole('button', { name: 'Reenviar link a Ana Compradora' }));

      expect(await screen.findByRole('alert')).toHaveTextContent('El link se mandó hace instantes.');
      expect(screen.getByText('Ana Compradora')).toBeInTheDocument();
    });
  });

  describe('ayudas', () => {
    it('describe las descargas como la columna del Excel', () => {
      expect(describirDescargas(0)).toBe('No');
      expect(describirDescargas(1)).toBe('Sí · 1 vez');
      expect(describirDescargas('4')).toBe('Sí · 4 veces');
    });

    it('arma el nombre del Excel', () => {
      expect(nombreDelExcel('Guía de viaje')).toBe('ventas-guia-de-viaje.xlsx');
      expect(nombreDelExcel('')).toBe('ventas-productos.xlsx');
    });
  });
});
