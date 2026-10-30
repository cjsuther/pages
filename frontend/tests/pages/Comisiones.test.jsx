import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import Comisiones, { mesLegible } from '../../src/pages/Comisiones';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const reporte = (overrides = {}) => ({
  resumen: {
    ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 1050,
    diferencia: 0, sin_dato: 0,
  },
  meses: [{ mes: '2026-09', ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 1050, diferencia: 0 }],
  paginas: [{
    id: 26, title: 'El Ferro eventos', url_slug: 'elferro',
    ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 1050, diferencia: 0,
  }],
  revisar: [],
  ...overrides,
});

function montar({ datos = reporte(), status = 200 } = {}) {
  const mock = mockFetch({
    'plataforma/comisiones.php': status === 200 ? datos : { status, body: { error: 'nope' } },
    'users/profile.php': { user: usuarioDePrueba(), es_plataforma: true },
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'users/location.php': { latitude: null, longitude: null },
  });

  renderConProviders(<Comisiones />, { auth: autenticado() });

  return mock;
}

describe('Comisiones', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('acceso', () => {
    /**
     * El servidor contesta 404 a quien no administra la plataforma: para esa
     * persona el reporte no existe. La pantalla tiene que sostener esa
     * respuesta y no delatar que había algo detrás de la URL.
     */
    it('un 404 se muestra como página inexistente', async () => {
      montar({ status: 404 });

      expect(await screen.findByText('No encontramos esa página')).toBeInTheDocument();
      expect(screen.queryByText('Comisiones cobradas')).not.toBeInTheDocument();
    });

    it('no filtra ningún número cuando no hay acceso', async () => {
      montar({ status: 404 });

      await screen.findByText('No encontramos esa página');
      expect(screen.queryByText(/70\.000/)).not.toBeInTheDocument();
    });
  });

  describe('el reporte', () => {
    it('muestra lo cobrado', async () => {
      montar();

      await screen.findByRole('heading', { name: 'Comisiones cobradas' });
      // El título está antes que los datos: hay que esperar el reporte.
      expect((await screen.findAllByText('Cobrado')).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/1\.050/).length).toBeGreaterThan(0);
    });

    it('agrupa por mes y por página', async () => {
      montar();

      expect(await screen.findByText('septiembre 2026')).toBeInTheDocument();
      expect(screen.getByText('El Ferro eventos')).toBeInTheDocument();
    });

    /** Es la razón de ser del reporte: plata que se pidió y no entró. */
    it('avisa cuando se pidió comisión que no se cobró', async () => {
      montar({
        datos: reporte({
          resumen: { ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 600, diferencia: 450, sin_dato: 0 },
        }),
      });

      expect(await screen.findByText(/Se pidieron .*450 que Mercado Pago no cobró/))
        .toBeInTheDocument();
    });

    it('sin diferencias no muestra el aviso', async () => {
      montar();

      await screen.findByRole('heading', { name: 'Comisiones cobradas' });
      expect(screen.queryByText(/que Mercado Pago no cobró/)).not.toBeInTheDocument();
    });

    /** Un total con ventas sin desglose no está confirmado y hay que decirlo. */
    it('avisa si faltan desgloses de Mercado Pago', async () => {
      montar({
        datos: reporte({
          resumen: { ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 1050, diferencia: 0, sin_dato: 2 },
        }),
      });

      expect(await screen.findByText(/De 2 ventas todavía no tenemos el desglose/))
        .toBeInTheDocument();
    });

    it('lista las ventas donde no se cobró', async () => {
      montar({
        datos: reporte({
          revisar: [{
            codigo: 'ABC123', pagada_en: '2026-09-07 18:07:00', total: 20000,
            pedida: 300, cobrada: 0, evento: 'Peluferro', pagina: 'El Ferro', url_slug: 'elferro',
          }],
        }),
      });

      expect(await screen.findByText('Ventas donde no se cobró la comisión')).toBeInTheDocument();
      expect(screen.getByText('Peluferro')).toBeInTheDocument();
      expect(screen.getByText('07/09/2026')).toBeInTheDocument();
    });
  });

  describe('productos digitales', () => {
    const conProductos = () => reporte({
      resumen: { ventas: 5, recaudado: 75000, pedida: 1125, cobrada: 1125, diferencia: 0, sin_dato: 0 },
      por_tipo: {
        entradas: { ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 1050, diferencia: 0, sin_dato: 0 },
        productos: { ventas: 2, recaudado: 5000, pedida: 75, cobrada: 75, diferencia: 0, sin_dato: 0 },
      },
      paginas: [{
        id: 26, title: 'El Ferro eventos', url_slug: 'elferro', ventas_productos: 2,
        ventas: 5, recaudado: 75000, pedida: 1125, cobrada: 1125, diferencia: 0,
      }],
    });

    it('separa lo de entradas de lo de productos', async () => {
      montar({ datos: conProductos() });

      expect(await screen.findByText('Entradas y productos')).toBeInTheDocument();
      expect(screen.getByText('Productos digitales')).toBeInTheDocument();
      // Lo de entradas, tal cual: 70.000 recaudados.
      expect(screen.getAllByText(/70\.000/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/5\.000/).length).toBeGreaterThan(0);
    });

    it('dice cuántas ventas de una página son de productos', async () => {
      montar({ datos: conProductos() });

      expect(await screen.findByText(/\(2 digitales\)/)).toBeInTheDocument();
    });

    it('en la lista a revisar dice si es entrada o producto', async () => {
      montar({
        datos: reporte({
          revisar: [
            { codigo: 'A', tipo: 'producto', pagada_en: '2026-09-08 10:00:00', total: 2500, pedida: 37.5, cobrada: 0, evento: 'Guía', pagina: 'El Ferro' },
            { codigo: 'B', tipo: 'entrada', pagada_en: '2026-09-07 10:00:00', total: 20000, pedida: 300, cobrada: 0, evento: 'Peluferro', pagina: 'El Ferro' },
          ],
        }),
      });

      expect(await screen.findByText('Guía')).toBeInTheDocument();
      expect(screen.getByText('Digital')).toBeInTheDocument();
      expect(screen.getByText('Entrada')).toBeInTheDocument();
    });

    /** Una API de antes, sin por_tipo, se sigue viendo como siempre. */
    it('sin el desglose por tipo no muestra la tabla', async () => {
      montar();

      await screen.findByText('septiembre 2026');
      expect(screen.queryByText('Entradas y productos')).not.toBeInTheDocument();
    });
  });

  describe('productos físicos', () => {
    const conFisicos = () => reporte({
      resumen: { ventas: 6, recaudado: 80500, pedida: 1207.5, cobrada: 1207.5, diferencia: 0, sin_dato: 0 },
      por_tipo: {
        entradas: { ventas: 3, recaudado: 70000, pedida: 1050, cobrada: 1050, diferencia: 0, sin_dato: 0 },
        productos: { ventas: 2, recaudado: 5000, pedida: 75, cobrada: 75, diferencia: 0, sin_dato: 0 },
        fisicos: { ventas: 1, recaudado: 5500, pedida: 82.5, cobrada: 82.5, diferencia: 0, sin_dato: 0 },
      },
      paginas: [{
        id: 26, title: 'El Ferro eventos', url_slug: 'elferro', ventas_productos: 2, ventas_fisicos: 1,
        ventas: 6, recaudado: 80500, pedida: 1207.5, cobrada: 1207.5, diferencia: 0,
      }],
    });

    it('tienen su propia fila, aparte de los digitales', async () => {
      montar({ datos: conFisicos() });

      const fila = (await screen.findByText('Productos físicos')).closest('tr');
      expect(fila).toHaveTextContent(/5\.500/);
      expect(fila).not.toHaveTextContent(/5\.000/);
      expect(screen.getByText('Productos digitales').closest('tr')).toHaveTextContent(/5\.000/);
    });

    it('dice cuántas ventas de una página son digitales y cuántas físicas', async () => {
      montar({ datos: conFisicos() });

      expect(await screen.findByText(/\(2 digitales · 1 físico\)/)).toBeInTheDocument();
    });

    it('en la lista a revisar los marca como físicos', async () => {
      montar({
        datos: reporte({
          revisar: [
            { codigo: 'C', tipo: 'fisico', pagada_en: '2026-09-08 10:00:00', total: 5500, pedida: 82.5, cobrada: 0, evento: 'Remera', pagina: 'El Ferro' },
          ],
        }),
      });

      expect(await screen.findByText('Remera')).toBeInTheDocument();
      expect(screen.getByText('Físico')).toBeInTheDocument();
    });
  });

  describe('rango de fechas', () => {
    it('pide el reporte filtrado', async () => {
      const { llamadas } = montar();
      await screen.findByRole('heading', { name: 'Comisiones cobradas' });

      fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-09-01' } });

      // llamadaA devuelve la primera, que es la del reporte sin filtrar.
      await waitFor(() => {
        const filtrada = llamadas.filter((l) => l.url.includes('desde=2026-09-01'));
        expect(filtrada).toHaveLength(1);
      });
    });
  });

  describe('mesLegible', () => {
    it('traduce el mes a algo que se lee', () => {
      expect(mesLegible('2026-09')).toBe('septiembre 2026');
    });

    it('deja pasar lo que no reconoce', () => {
      expect(mesLegible('cualquier cosa')).toBe('cualquier cosa');
    });
  });
});
