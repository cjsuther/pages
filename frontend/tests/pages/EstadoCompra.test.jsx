import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import EstadoCompra from '../../src/pages/EstadoCompra';
import { guardarTokenDeDescarga } from '../../src/utils/productos';

const API = 'https://api.test/api';
const CODIGO = 'AAAABBBBCCCC';
const TOKEN = '7c'.repeat(32);

const ORDEN = {
  codigo: CODIGO,
  estado: 'pagada',
  total: 2500,
  moneda: 'ARS',
  producto: 'Guía de viaje',
  pagina: 'La Sala',
  url_slug: 'la-sala',
};

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

async function montar(orden = ORDEN, ok = true) {
  global.fetch.mockReturnValue(respuestaDe(ok ? { orden } : { error: 'No encontramos esa orden' }, ok));

  const vista = renderConProviders(<EstadoCompra apiUrl={API} />, {
    route: `/compra/${CODIGO}`,
    path: '/compra/:codigo',
  });

  await waitFor(() => expect(screen.queryByText(/Cargando/)).not.toBeInTheDocument());

  return vista;
}

describe('EstadoCompra', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    window.localStorage.clear();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    window.localStorage.clear();
  });

  it('consulta la orden por su código, sin mandar el token', async () => {
    guardarTokenDeDescarga(CODIGO, TOKEN);

    await montar();

    const url = global.fetch.mock.calls[0][0];
    expect(url).toBe(`${API}/public/orden-producto.php?codigo=${CODIGO}`);
    expect(url).not.toContain(TOKEN);
  });

  describe('pagada', () => {
    it('lo confirma y muestra el producto', async () => {
      await montar();

      expect(screen.getByText('¡Listo!')).toBeInTheDocument();
      expect(screen.getByText('Guía de viaje')).toBeInTheDocument();
      expect(screen.getByText(/2\.500/)).toBeInTheDocument();
    });

    /** El token se guardó en este navegador antes de salir a pagar. */
    it('con el token guardado se puede descargar ahí mismo', async () => {
      guardarTokenDeDescarga(CODIGO, TOKEN);

      await montar();

      const formulario = screen.getByRole('button', { name: /Descargar/ }).closest('form');
      expect(formulario).toHaveAttribute('method', 'post');
      expect(formulario).toHaveAttribute('action', `${API}/public/descargar.php`);
      expect(formulario.querySelector('input[name="token"]')).toHaveValue(TOKEN);
      expect(screen.queryByText(/Te mandamos el link/)).not.toBeInTheDocument();
    });

    it('desde otro dispositivo, sin token, avisa que el link llegó por mail', async () => {
      await montar();

      expect(screen.getByText('Te mandamos el link de descarga a tu email.')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
    });

    it('el token de otra compra no sirve para esta', async () => {
      guardarTokenDeDescarga('OTRACOMPRA12', TOKEN);

      await montar();

      expect(screen.queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
    });

    it('registra la compra en el pixel de quien vende', async () => {
      window.fbq = vi.fn();

      await montar({ ...ORDEN, meta_pixel_id: '1234567890123456' });

      await waitFor(() => expect(window.fbq).toHaveBeenCalledWith('trackSingle', '1234567890123456', 'Purchase', {
        content_type: 'product',
        content_name: 'Guía de viaje',
        num_items: 1,
        value: 2500,
        currency: 'ARS',
      }));
    });
  });

  describe('pendiente', () => {
    beforeEach(() => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    it('explica que se está confirmando y no ofrece descargar todavía', async () => {
      guardarTokenDeDescarga(CODIGO, TOKEN);

      await montar({ ...ORDEN, estado: 'pendiente' });

      expect(screen.getByText(/Estamos confirmando tu pago/)).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
    });

    it('vuelve a consultar sola', async () => {
      await montar({ ...ORDEN, estado: 'pendiente' });

      const iniciales = global.fetch.mock.calls.length;

      for (let vuelta = 0; vuelta < 20 && global.fetch.mock.calls.length <= iniciales; vuelta++) {
        await vi.advanceTimersByTimeAsync(3000);
      }

      expect(global.fetch.mock.calls.length).toBeGreaterThan(iniciales);
    });
  });

  describe('otros finales', () => {
    it('un pago rechazado lo dice claro', async () => {
      guardarTokenDeDescarga(CODIGO, TOKEN);

      await montar({ ...ORDEN, estado: 'rechazada' });

      expect(screen.getByText('El pago fue rechazado')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
    });

    it('una compra vencida se explica', async () => {
      await montar({ ...ORDEN, estado: 'vencida' });

      expect(screen.getByText('La compra no se completó')).toBeInTheDocument();
    });

    it('un código inexistente no muestra una pantalla rota', async () => {
      await montar(null, false);

      expect(screen.getByText('No encontramos tu compra')).toBeInTheDocument();
    });
  });

  describe('un pedido de un físico (#43)', () => {
    const PEDIDO = {
      ...ORDEN,
      tipo: 'fisico',
      producto: 'Remera',
      opciones: 'Talle M · Color Negro',
      cantidad: 2,
      precio_unitario: 2500,
      costo_envio: 500,
      total: 5500,
      estado_pedido: 'ingresado',
      archivo_nombre: null,
      envio: { calle: 'Av. Corrientes 1…', localidad: 'CABA', provincia: 'Ciudad Autónoma de Buenos Aires', codigo_postal: '1043' },
      horarios: { dias: ['lunes', 'sabado'], franjas: ['tarde', 'noche'], comentario: 'Después de las 18' },
    };

    it('muestra el detalle, la dirección recortada, los horarios y el estado', async () => {
      await montar(PEDIDO);

      expect(screen.getByText('¡Recibimos tu pedido!')).toBeInTheDocument();
      expect(screen.getByText('Talle M · Color Negro')).toBeInTheDocument();
      expect(screen.getByText(/× 2/)).toBeInTheDocument();
      expect(screen.getByText(/5\.500/)).toBeInTheDocument();
      expect(screen.getByText('Av. Corrientes 1…')).toBeInTheDocument();
      expect(screen.getByText(/CABA, Ciudad Autónoma de Buenos Aires \(1043\)/)).toBeInTheDocument();
      expect(screen.getByText(/Lunes y Sábado · Tarde y Noche/)).toBeInTheDocument();
      expect(screen.getByText('Después de las 18')).toBeInTheDocument();
      expect(screen.getByText('Estado: Ingresado')).toBeInTheDocument();
    });

    it('no ofrece descarga, aunque haya un token guardado', async () => {
      guardarTokenDeDescarga(CODIGO, TOKEN);

      await montar(PEDIDO);

      expect(screen.queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
      expect(screen.queryByText(/link de descarga/)).not.toBeInTheDocument();
    });

    it('con envío incluido lo dice', async () => {
      await montar({ ...PEDIDO, costo_envio: 0, total: 5000 });

      expect(screen.getByText('Incluido')).toBeInTheDocument();
    });

    it('mientras se confirma el pago no muestra estado del pedido', async () => {
      await montar({ ...PEDIDO, estado: 'pendiente', estado_pedido: null });

      expect(screen.getByText('Estamos confirmando tu pago')).toBeInTheDocument();
      expect(screen.queryByText(/^Estado:/)).not.toBeInTheDocument();
    });
  });
});
