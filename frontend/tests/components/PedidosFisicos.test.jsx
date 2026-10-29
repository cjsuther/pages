import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import PanelVentasProductos from '../../src/components/PanelVentasProductos';
import { mockFetch, llamadaA, cuerpoDe, tokenDe } from '../helpers/api';

const API = 'http://api';

const pedido = (overrides = {}) => ({
  id: 1,
  codigo: 'AAAA00000001',
  nombre: 'Ana Compradora',
  email: 'ana@test.local',
  telefono: '11 5555-1234',
  total: 5500,
  moneda: 'ARS',
  estado: 'pagada',
  creado_en: '2026-10-28 10:00:00',
  pagada_en: '2026-10-28 10:05:00',
  descargas: 0,
  producto: 'Remera',
  producto_borrado: false,
  link_id: 100,
  tipo: 'fisico',
  opciones: 'Talle M',
  cantidad: 2,
  sin_stock: false,
  estado_pedido: 'ingresado',
  entrega_fecha: null,
  entrega_franja: null,
  localidad: 'CABA',
  ...overrides,
});

const digital = {
  id: 9, codigo: 'DIGI00000009', nombre: 'Dani Digital', email: 'dani@test.local', telefono: null, total: 1000,
  moneda: 'ARS', estado: 'pagada', creado_en: '2026-10-01 10:00:00', descargas: 1, primera_descarga_en: null,
  producto: 'Guía', producto_borrado: false, link_id: 50, tipo: 'digital',
};

const ventas = (ordenes) => ({
  ordenes,
  resumen: { pagadas: 1, recaudado: 1000, descargaron: 1 },
  resumen_fisicos: { pagadas: 3, recaudado: 16500, descargaron: 0 },
});

const LISTA = [
  pedido(),
  pedido({ id: 2, codigo: 'AAAA00000002', nombre: 'Beto', estado_pedido: 'con_fecha', localidad: 'Rosario', sin_stock: true }),
  pedido({ id: 3, codigo: 'AAAA00000003', nombre: 'Carla', estado_pedido: 'ingresado', opciones: null, cantidad: 1 }),
  pedido({ id: 4, codigo: 'AAAA00000004', nombre: 'Dora', estado: 'pendiente', estado_pedido: null }),
  digital,
];

const DETALLE = {
  codigo: 'AAAA00000001',
  creado_en: '2026-10-28 10:00:00',
  pagada_en: '2026-10-28 10:05:00',
  estado: 'pagada',
  estado_pedido: 'ingresado',
  sin_stock: false,
  nombre: 'Ana Compradora',
  email: 'ana@test.local',
  telefono: '11 5555-1234',
  producto: 'Remera',
  opciones: 'Talle M',
  cantidad: 2,
  precio_unitario: 2500,
  costo_envio: 500,
  total: 5500,
  moneda: 'ARS',
  entrega_fecha: null,
  entrega_franja: null,
  envio_empresa: null,
  envio_seguimiento: null,
  cancelado_motivo: null,
  mp_payment_id: '880001',
  envio: {
    calle: 'Av. Corrientes 1234', piso_depto: '3° B', localidad: 'CABA',
    provincia: 'Ciudad Autónoma de Buenos Aires', codigo_postal: '1043', indicaciones: 'Timbre B',
  },
  horarios: { dias: ['lunes', 'sabado'], franjas: ['tarde'], comentario: 'Después de las 18' },
  historial: [
    { estado: 'ingresado', etiqueta: 'Ingresado', datos: {}, creado_en: '2026-10-28 10:05:00', quien: 'Sistema' },
  ],
  siguientes: ['con_fecha', 'enviado', 'entregado', 'cancelado'],
};

function montarPagina(respuestas = {}) {
  const mock = mockFetch({
    'productos/ventas.php': ventas(LISTA),
    'productos/pedido.php': { pedido: DETALLE },
    ...respuestas,
  });
  render(<PanelVentasProductos pageId={5} apiUrl={API} token="tok-123" nombre="La Sala" />);
  return mock;
}

async function irAFisicos() {
  fireEvent.click(await screen.findByRole('tab', { name: 'Físicos' }));
}

async function abrirElDeAna() {
  await irAFisicos();
  fireEvent.click(screen.getByRole('button', { name: 'Ver el pedido de Ana Compradora' }));
  await screen.findByText('Pedido AAAA00000001');
}

describe('Ventas de productos físicos (#44)', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
  });

  describe('pestañas', () => {
    it('por página separa Digitales y Físicos', async () => {
      montarPagina();

      await screen.findByText('Dani Digital');
      expect(screen.getByRole('tab', { name: 'Digitales' })).toHaveAttribute('aria-selected', 'true');
      expect(screen.queryByText('Ana Compradora')).not.toBeInTheDocument();

      await irAFisicos();

      expect(screen.getByText('Ana Compradora')).toBeInTheDocument();
      expect(screen.queryByText('Dani Digital')).not.toBeInTheDocument();
      expect(screen.getByText('PEDIDOS PAGADOS').nextSibling).toHaveTextContent('3');
    });

    it('en un producto físico va directo a sus pedidos, sin pestañas', async () => {
      const { llamadas } = mockFetch({ 'productos/ventas.php': ventas([pedido()]) });
      render(<PanelVentasProductos linkId={100} tipo="fisico" apiUrl={API} token="tok-123" nombre="Remera" />);

      expect(await screen.findByText('Ana Compradora')).toBeInTheDocument();
      expect(screen.queryByRole('tab')).not.toBeInTheDocument();
      expect(llamadaA(llamadas, 'productos/ventas.php').url).toBe(`${API}/productos/ventas.php?link_id=100`);
      // En un producto no hace falta repetir cuál es.
      expect(screen.getByText('Talle M × 2')).toBeInTheDocument();
    });
  });

  describe('lista y filtros', () => {
    it('tiene las columnas pedidas', async () => {
      montarPagina();
      await irAFisicos();

      expect(screen.getAllByRole('columnheader').map((th) => th.textContent)).toEqual(
        ['Fecha', 'Comprador', 'Producto', 'Total', 'Entrega', 'Estado', 'Acciones']
      );
      const fila = screen.getByText('Beto').closest('tr');
      expect(within(fila).getByText('28/10/2026')).toBeInTheDocument();
      expect(within(fila).getByText('Remera')).toBeInTheDocument();
      expect(within(fila).getByText('Talle M × 2')).toBeInTheDocument();
      expect(within(fila).getByText(/5\.500/)).toBeInTheDocument();
      expect(within(fila).getByText('Rosario')).toBeInTheDocument();
      expect(within(fila).getByText('Con fecha de entrega')).toBeInTheDocument();
      expect(within(fila).getByText('Sin stock')).toBeInTheDocument();
      expect(within(screen.getByText('Carla').closest('tr')).queryByText('Sin stock')).not.toBeInTheDocument();
    });

    it('cuenta los pedidos de cada estado', async () => {
      montarPagina();
      await irAFisicos();

      const filtros = screen.getByRole('group', { name: 'Filtrar por estado' });
      expect(within(filtros).getAllByRole('button').map((b) => b.textContent)).toEqual([
        'Todos 4', 'Ingresados 2', 'Con fecha 1', 'Enviados 0', 'Entregados 0', 'Cancelados 0',
      ]);
    });

    it('filtra por estado', async () => {
      montarPagina();
      await irAFisicos();

      fireEvent.click(screen.getByRole('button', { name: 'Ingresados 2' }));

      expect(screen.getByRole('button', { name: 'Ingresados 2' })).toHaveAttribute('aria-pressed', 'true');
      expect(screen.getByText('Ana Compradora')).toBeInTheDocument();
      expect(screen.getByText('Carla')).toBeInTheDocument();
      expect(screen.queryByText('Beto')).not.toBeInTheDocument();
      expect(screen.queryByText('Dora')).not.toBeInTheDocument();

      fireEvent.click(screen.getByRole('button', { name: 'Enviados 0' }));
      expect(screen.getByText('Ningún pedido en “Enviado”.')).toBeInTheDocument();
    });

    it('uno sin pagar se ve pendiente y no se abre', async () => {
      montarPagina();
      await irAFisicos();

      const fila = screen.getByText('Dora').closest('tr');
      expect(within(fila).getByText('Pendiente')).toBeInTheDocument();
      expect(within(fila).queryByRole('button')).not.toBeInTheDocument();
    });
  });

  describe('Excel', () => {
    it('baja los pedidos con su dirección, con el filtro que se está mirando', async () => {
      const { llamadas } = montarPagina();
      await irAFisicos();
      fireEvent.click(screen.getByRole('button', { name: 'Con fecha 1' }));

      global.URL.createObjectURL = vi.fn(() => 'blob:x');
      global.URL.revokeObjectURL = vi.fn();
      let descargado = null;
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { descargado = this.download; });
      global.fetch = vi.fn((url, opciones) => {
        llamadas.push({ url, options: opciones });
        return Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['x'])) });
      });

      fireEvent.click(screen.getByRole('button', { name: /Exportar pedidos a Excel/ }));

      await waitFor(() => expect(global.URL.createObjectURL).toHaveBeenCalled());
      const llamada = llamadas.find((l) => l.url.includes('formato=excel'));
      expect(llamada.url).toBe(
        `${API}/productos/ventas.php?page_id=5&formato=excel&tipo=fisico&estado_pedido=con_fecha&nombre=La%20Sala`
      );
      expect(tokenDe(llamada)).toBe('Bearer tok-123');
      expect(descargado).toBe('pedidos-la-sala.xlsx');
    });

    it('el de digitales pide sólo los digitales', async () => {
      const { llamadas } = montarPagina();
      await screen.findByText('Dani Digital');
      global.URL.createObjectURL = vi.fn(() => 'blob:x');
      global.URL.revokeObjectURL = vi.fn();
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
      global.fetch = vi.fn((url, opciones) => {
        llamadas.push({ url, options: opciones });
        return Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['x'])) });
      });

      fireEvent.click(screen.getByRole('button', { name: /Exportar Excel/ }));

      await waitFor(() => expect(global.URL.createObjectURL).toHaveBeenCalled());
      expect(llamadas.find((l) => l.url.includes('formato=excel')).url).toContain('tipo=digital');
    });
  });

  describe('detalle', () => {
    it('muestra al comprador, la dirección completa, los horarios y el historial', async () => {
      const { llamadas } = montarPagina();
      await abrirElDeAna();

      expect(llamadaA(llamadas, 'productos/pedido.php').url).toBe(`${API}/productos/pedido.php?codigo=AAAA00000001`);
      expect(tokenDe(llamadaA(llamadas, 'productos/pedido.php'))).toBe('Bearer tok-123');
      expect(screen.getByText(/Av\. Corrientes 1234/)).toHaveTextContent('Av. Corrientes 1234, 3° B');
      expect(screen.getByText('Indicaciones: Timbre B')).toBeInTheDocument();
      expect(screen.getByText(/Lunes y Sábado · Tarde/)).toBeInTheDocument();
      expect(screen.getByText('Después de las 18')).toBeInTheDocument();
      const historial = screen.getByRole('list', { name: 'Historial del pedido' });
      expect(within(historial).getByText('Ingresado')).toBeInTheDocument();
      expect(within(historial).getByText('28/10/2026 10:05 · Sistema')).toBeInTheDocument();
    });

    it('el teléfono abre WhatsApp', async () => {
      montarPagina();
      await abrirElDeAna();

      expect(screen.getByRole('link', { name: 'Escribirle por WhatsApp a Ana Compradora' }))
        .toHaveAttribute('href', 'https://wa.me/5491155551234');
    });

    it('si el teléfono no da para WhatsApp, llama', async () => {
      montarPagina({ 'productos/pedido.php': { pedido: { ...DETALLE, telefono: '4555-12' } } });
      await abrirElDeAna();

      expect(screen.getByText('4555-12').closest('a')).toHaveAttribute('href', 'tel:455512');
    });

    it('ofrece sólo los pasos que el servidor permite', async () => {
      montarPagina({
        'productos/pedido.php': { pedido: { ...DETALLE, estado_pedido: 'enviado', siguientes: ['entregado', 'cancelado'] } },
      });
      await abrirElDeAna();

      expect(screen.getByRole('button', { name: 'Marcar entregado' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Cancelar pedido' })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Poner fecha de entrega' })).not.toBeInTheDocument();
    });

    it('un pedido entregado no tiene acciones', async () => {
      montarPagina({
        'productos/pedido.php': { pedido: { ...DETALLE, estado_pedido: 'entregado', siguientes: [] } },
      });
      await abrirElDeAna();

      expect(screen.queryByText('ACCIONES')).not.toBeInTheDocument();
    });

    it('con fecha ya puesta, el botón es para cambiarla', async () => {
      montarPagina({
        'productos/pedido.php': {
          pedido: { ...DETALLE, estado_pedido: 'con_fecha', entrega_fecha: '2026-11-03', entrega_franja: 'tarde' },
        },
      });
      await abrirElDeAna();

      expect(screen.getByText('martes 03/11 · Tarde')).toBeInTheDocument();
      fireEvent.click(screen.getByRole('button', { name: 'Cambiar la fecha de entrega' }));
      expect(screen.getByLabelText('Fecha de entrega')).toHaveValue('2026-11-03');
    });

    it('vuelve a la lista', async () => {
      montarPagina();
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: /Volver a los pedidos/ }));

      expect(await screen.findByText('Beto')).toBeInTheDocument();
    });
  });

  describe('acciones', () => {
    function conRespuestaAlCambio(cambio) {
      return montarPagina({ 'productos/pedido-estado.php': cambio });
    }

    it('poner fecha exige la fecha antes de mandar nada', async () => {
      const { llamadas } = conRespuestaAlCambio({ pedido: DETALLE, aviso_enviado: true });
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: 'Poner fecha de entrega' }));
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));

      expect(screen.getByRole('alert')).toHaveTextContent('Elegí la fecha de entrega');
      expect(llamadaA(llamadas, 'pedido-estado.php')).toBeNull();
    });

    it('pone la fecha con la franja, avisa y recarga la lista', async () => {
      const actualizado = {
        ...DETALLE,
        estado_pedido: 'con_fecha',
        entrega_fecha: '2026-11-03',
        entrega_franja: 'tarde',
        siguientes: ['con_fecha', 'enviado', 'entregado', 'cancelado'],
        historial: [...DETALLE.historial, {
          estado: 'con_fecha', etiqueta: 'Con fecha de entrega', datos: { fecha: '2026-11-03', franja: 'tarde' },
          creado_en: '2026-10-29 09:00:00', quien: 'Vendedora',
        }],
      };
      const { llamadas } = conRespuestaAlCambio({ pedido: actualizado, aviso_enviado: true, aviso_error: null });
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: 'Poner fecha de entrega' }));
      fireEvent.change(screen.getByLabelText('Fecha de entrega'), { target: { value: '2026-11-03' } });
      fireEvent.change(screen.getByLabelText('Franja (opcional)'), { target: { value: 'tarde' } });
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));

      expect(await screen.findByRole('status')).toHaveTextContent('Listo: le avisamos por mail a ana@test.local.');
      const llamada = llamadaA(llamadas, 'productos/pedido-estado.php');
      expect(llamada.options.method).toBe('POST');
      expect(tokenDe(llamada)).toBe('Bearer tok-123');
      expect(cuerpoDe(llamada)).toEqual({ codigo: 'AAAA00000001', estado: 'con_fecha', fecha: '2026-11-03', franja: 'tarde' });
      expect(screen.getAllByText('Con fecha de entrega').length).toBeGreaterThan(0);
      expect(screen.getByText('29/10/2026 09:00 · Vendedora')).toBeInTheDocument();
      // La lista de atrás se vuelve a pedir, para que los contadores no mientan.
      await waitFor(() => expect(llamadas.filter((l) => l.url.includes('productos/ventas.php'))).toHaveLength(2));
    });

    it('marcar enviado manda la empresa y el seguimiento', async () => {
      const { llamadas } = conRespuestaAlCambio({
        pedido: { ...DETALLE, estado_pedido: 'enviado', envio_empresa: 'Andreani', envio_seguimiento: 'https://seg.test/1', siguientes: ['entregado', 'cancelado'] },
        aviso_enviado: true,
      });
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: 'Marcar enviado' }));
      fireEvent.change(screen.getByLabelText('Empresa o cadete (opcional)'), { target: { value: 'Andreani' } });
      fireEvent.change(screen.getByLabelText('Número de seguimiento o link (opcional)'), { target: { value: 'https://seg.test/1' } });
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));

      await screen.findByRole('status');
      expect(cuerpoDe(llamadaA(llamadas, 'pedido-estado.php'))).toMatchObject({
        estado: 'enviado', empresa: 'Andreani', seguimiento: 'https://seg.test/1',
      });
      expect(screen.getByRole('link', { name: 'https://seg.test/1' })).toHaveAttribute('href', 'https://seg.test/1');
    });

    it('cancelar pide el motivo y explica que el pago se devuelve desde Mercado Pago', async () => {
      const { llamadas } = conRespuestaAlCambio({
        pedido: { ...DETALLE, estado_pedido: 'cancelado', cancelado_motivo: 'No llegó la tela', siguientes: [] },
        aviso_enviado: true,
        stock_devuelto: true,
      });
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: 'Cancelar pedido' }));
      expect(screen.getByText(/Si ya te pagó, hacé la devolución desde Mercado Pago/)).toBeInTheDocument();
      expect(screen.getByRole('link', { name: /Ver la operación 880001/ }))
        .toHaveAttribute('href', 'https://www.mercadopago.com.ar/activities?q=880001');

      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));
      expect(screen.getByRole('alert')).toHaveTextContent('Contá por qué se cancela');
      expect(llamadaA(llamadas, 'pedido-estado.php')).toBeNull();

      fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'No llegó la tela' } });
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));

      await screen.findByRole('status');
      expect(cuerpoDe(llamadaA(llamadas, 'pedido-estado.php'))).toMatchObject({ estado: 'cancelado', motivo: 'No llegó la tela' });
      expect(screen.getByText('No llegó la tela')).toBeInTheDocument();
      expect(screen.queryByText('ACCIONES')).not.toBeInTheDocument();
    });

    it('si el mail no salió, lo avisa', async () => {
      conRespuestaAlCambio({
        pedido: { ...DETALLE, estado_pedido: 'entregado', siguientes: [] },
        aviso_enviado: false,
        aviso_error: 'SMTP caído',
      });
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: 'Marcar entregado' }));
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'El pedido quedó entregado, pero no pudimos mandarle el mail a quien compró (SMTP caído). Avisale vos.'
      );
    });

    it('si el servidor no deja, muestra el motivo', async () => {
      conRespuestaAlCambio({ status: 409, body: { error: 'El pedido ya está entregado: no se puede cambiar' } });
      await abrirElDeAna();

      fireEvent.click(screen.getByRole('button', { name: 'Marcar entregado' }));
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar y avisar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent('El pedido ya está entregado: no se puede cambiar');
    });
  });

  // ------------------------------------------------ formas de entrega (#53)

  describe('formas de entrega', () => {
    const CON_FORMAS = [
      pedido({ forma_entrega: 'envio' }),
      pedido({ id: 2, codigo: 'AAAA00000002', nombre: 'Beto', forma_entrega: 'retiro', estado_pedido: 'enviado', localidad: null }),
      pedido({ id: 3, codigo: 'AAAA00000003', nombre: 'Carla', forma_entrega: 'coordinar', telefono: '11 4444-9999', localidad: null }),
    ];

    const montarConFormas = (detalle = DETALLE) => montarPagina({
      'productos/ventas.php': ventas(CON_FORMAS),
      'productos/pedido.php': { pedido: detalle },
    });

    it('cada pedido dice su forma, y para coordinar el teléfono va a mano', async () => {
      montarConFormas();
      await irAFisicos();

      expect(within(screen.getByText('Ana Compradora').closest('tr')).getByText('Envío a domicilio')).toBeInTheDocument();
      const beto = screen.getByText('Beto').closest('tr');
      expect(within(beto).getByText('Retiro')).toBeInTheDocument();
      expect(within(beto).getByText('Listo para retirar')).toBeInTheDocument();
      const carla = screen.getByText('Carla').closest('tr');
      expect(within(carla).getByText('A coordinar')).toBeInTheDocument();
      expect(within(carla).getByRole('link', { name: 'Escribirle por WhatsApp a Carla' }))
        .toHaveAttribute('href', expect.stringContaining('wa.me/5491144449999'));
    });

    it('filtra por forma, además de por estado', async () => {
      montarConFormas();
      await irAFisicos();

      const formas = screen.getByRole('group', { name: 'Filtrar por forma de entrega' });
      expect(within(formas).getAllByRole('button').map((b) => b.textContent)).toEqual([
        'Todas las formas', 'Envío a domicilio 1', 'Retiro 1', 'A coordinar 1',
      ]);

      fireEvent.click(within(formas).getByRole('button', { name: 'Retiro 1' }));

      expect(screen.getByText('Beto')).toBeInTheDocument();
      expect(screen.queryByText('Ana Compradora')).not.toBeInTheDocument();
      expect(screen.queryByText('Carla')).not.toBeInTheDocument();
      // Mirando retiros, los estados se llaman como en un retiro.
      expect(within(screen.getByRole('group', { name: 'Filtrar por estado' })).getAllByRole('button').map((b) => b.textContent))
        .toEqual(['Todos 1', 'Ingresados 0', 'Con fecha 0', 'Listos para retirar 1', 'Retirados 0', 'Cancelados 0']);
    });

    it('el Excel lleva el filtro de forma', async () => {
      const { llamadas } = montarConFormas();
      await irAFisicos();
      fireEvent.click(screen.getByRole('button', { name: 'A coordinar 1' }));

      global.URL.createObjectURL = vi.fn(() => 'blob:x');
      global.URL.revokeObjectURL = vi.fn();
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
      global.fetch = vi.fn((url, opciones) => {
        llamadas.push({ url, options: opciones });
        return Promise.resolve({ ok: true, blob: () => Promise.resolve(new Blob(['x'])) });
      });

      fireEvent.click(screen.getByRole('button', { name: /Exportar pedidos a Excel/ }));

      await waitFor(() => expect(global.URL.createObjectURL).toHaveBeenCalled());
      expect(llamadas.find((l) => l.url.includes('formato=excel')).url).toContain('&forma_entrega=coordinar');
    });

    const RETIRO = {
      ...DETALLE,
      forma_entrega: 'retiro',
      costo_envio: 0,
      total: 5000,
      envio: null,
      horarios: null,
      retiro: { direccion: 'Humboldt 1500, CABA', maps_url: 'https://maps.google.com/?cid=1', indicaciones: 'Timbre 3' },
    };

    it('el detalle de un retiro muestra el punto y las acciones con sus nombres', async () => {
      montarConFormas(RETIRO);
      await abrirElDeAna();

      expect(screen.getByText('Retiro')).toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'Humboldt 1500, CABA' })).toHaveAttribute('href', 'https://maps.google.com/?cid=1');
      expect(screen.queryByText(/Av\. Corrientes/)).not.toBeInTheDocument();
      expect(screen.queryByText(/Envío/)).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Poner fecha de retiro' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Marcar retirado' })).toBeInTheDocument();

      fireEvent.click(screen.getByRole('button', { name: 'Marcar listo para retirar' }));
      expect(screen.queryByLabelText('Empresa o cadete (opcional)')).not.toBeInTheDocument();
      expect(screen.getByText(/ya puede pasar a buscarlo/)).toBeInTheDocument();
    });

    it('el detalle de coordinar pone el teléfono para coordinar', async () => {
      montarConFormas({ ...DETALLE, forma_entrega: 'coordinar', envio: null, horarios: null, retiro: null });
      await abrirElDeAna();

      expect(screen.getByText('A coordinar')).toBeInTheDocument();
      expect(screen.getByText('Coordiná la entrega con Ana Compradora:')).toBeInTheDocument();
      expect(screen.getAllByRole('link', { name: 'Escribirle por WhatsApp a Ana Compradora' })).toHaveLength(2);
    });
  });
});
