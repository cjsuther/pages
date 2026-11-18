import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import ParaArtistas from '../../src/pages/ParaArtistas';
import { renderConProviders } from '../helpers/render';
import { mockFetch } from '../helpers/api';
import { calcularCargos } from '../../src/utils/cargos';

/**
 * Quien está pensando si usar Rezonar tiene que poder ver qué ofrece y hacer
 * la cuenta sin registrarse. Los números salen del servidor: lo que se prueba
 * es que se muestren tal cual, que la página no prometa nada distinto de
 * /precios y que, si no llegan, no se invente ninguno.
 */
const SIN_CARGOS = { comision: 1.5, mercadopago: { porcentaje: 7.25, dias: 3 }, cargos: null };

// Las mismas tasas que usa Precios.test.jsx.
const TASAS = { mp_porcentaje: 4.39, mp_fijo: 0, mp_iva: 21, plataforma_porcentaje: 1, plataforma_iva: 0 };
const CON_CARGOS = { comision: 1, mercadopago: { porcentaje: 4.39, dias: 10 }, cargos: TASAS };

function montar(costos = SIN_CARGOS) {
  const mock = mockFetch({ 'public/costos.php': costos });
  renderConProviders(<ParaArtistas />);
  return mock;
}

/** "$10.531,05" o "$100": como lo escribe la página. */
const plata = (n) => '$' + n.toLocaleString('es-AR', Number.isInteger(n)
  ? { maximumFractionDigits: 0 }
  : { minimumFractionDigits: 2, maximumFractionDigits: 2 });

describe('ParaArtistas', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('qué ofrece', () => {
    it('cuenta la venta de entradas de punta a punta', () => {
      montar();

      expect(screen.getByRole('heading', { name: /Vendé tus entradas/ })).toBeInTheDocument();
      for (const titulo of [
        'Tipos de entrada y promos',
        'Butacas y mesas',
        'Entrada con QR por mail',
        'Control en la puerta',
        'Invitados sin costo',
        'Cambios sin volver a cobrar',
        'Cómo va la venta',
        'Todo en Excel',
      ]) {
        expect(screen.getByRole('heading', { name: titulo })).toBeInTheDocument();
      }
    });

    it('cuenta lo nuevo: invitados, recorte de imágenes y colaboraciones automáticas', () => {
      montar();

      expect(screen.getByText(/sin pagar ni dejar comisión/)).toBeInTheDocument();
      expect(screen.getByText(/Recortás cada imagen al subirla/)).toBeInTheDocument();
      expect(screen.getByText(/marcás “Aceptar siempre” y se publican solas/)).toBeInTheDocument();
      expect(screen.getByText('¿Puedo regalar entradas?')).toBeInTheDocument();
    });

    it('cuenta que se venden productos digitales y físicos', () => {
      montar();

      expect(screen.getByRole('heading', { name: 'Productos digitales' })).toBeInTheDocument();
      expect(screen.getByRole('heading', { name: 'Productos físicos' })).toBeInTheDocument();
      expect(screen.getByText(/Pagan la misma comisión que una entrada, a cargo de quien vende/)).toBeInTheDocument();
    });

    it('cuenta que se trabaja en equipo', () => {
      montar();

      expect(screen.getByRole('heading', { name: 'Tu equipo' })).toBeInTheDocument();
      expect(screen.getByRole('heading', { name: 'Fechas en conjunto' })).toBeInTheDocument();
    });

    it('manda a /precios y a /asistentes', async () => {
      montar();

      await screen.findByText('Cuánto cuesta vender entradas y productos');
      // La respuesta a "¿Cuánto sale?" manda al detalle (el menú tiene otro "Precios").
    const respuesta = screen.getByText('¿Cuánto sale?').nextElementSibling;
    expect(within(respuesta).getByRole('link', { name: 'Precios' })).toHaveAttribute('href', '/precios');
      expect(screen.getByRole('link', { name: /Ver precios y cuándo cobrás/ })).toHaveAttribute('href', '/precios');
      expect(screen.getByRole('link', { name: 'la página de asistentes' })).toHaveAttribute('href', '/asistentes');
    });
  });

  describe('cuánto cuesta vender, sin comisiones a cargo de quien compra', () => {
    it('pide los costos sin sesión', async () => {
      const { llamadas } = montar();

      await screen.findByText('Cuánto cuesta vender entradas y productos');
      const pedido = llamadas.find((l) => l.url.includes('public/costos.php'));
      expect(pedido.options.headers).toBeUndefined();
    });

    it('muestra la comisión de Rezonar y la de Mercado Pago', async () => {
      montar();

      expect(await screen.findByText(/Comisión de Rezonar: 1,5%/)).toBeInTheDocument();
      expect(screen.getByText(/Mercado Pago: 7,25%/)).toBeInTheDocument();
      expect(screen.getByText(/Cobrás a los 3 días de cada compra/)).toBeInTheDocument();
    });

    it('no ofrece que pague quien compra y hace la cuenta descontando', async () => {
      montar();

      await screen.findByText('Cuánto cuesta vender entradas y productos');
      expect(screen.queryByText(/las puede pagar quien compra/)).not.toBeInTheDocument();
      expect(screen.queryByText(/los eventos nuevos arrancan/)).not.toBeInTheDocument();
      expect(screen.getByText('−$150')).toBeInTheDocument();
      expect(screen.getByText('−$725')).toBeInTheDocument();
      expect(screen.getByText('$9.125')).toBeInTheDocument();
    });

    it('sin dato de Mercado Pago muestra sólo la comisión nuestra', async () => {
      montar({ comision: 1.5, mercadopago: null, cargos: null });

      await screen.findByText(/Comisión de Rezonar: 1,5%/);
      expect(screen.queryByText(/Mercado Pago: /)).not.toBeInTheDocument();
      expect(screen.getByText('$9.850')).toBeInTheDocument();
      expect(screen.getByText(/Cobrás a los pocos días de cada compra/)).toBeInTheDocument();
    });

    it('las reservas, los invitados y los productos gratis no pagan nada', async () => {
      montar();

      expect(await screen.findByText('Las reservas sin costo, los invitados y los productos gratis no pagan nada.')).toBeInTheDocument();
    });
  });

  describe('cuánto cuesta vender, con comisiones a cargo de quien compra', () => {
    it('cuenta que los eventos nuevos arrancan con las comisiones a cargo de quien compra', async () => {
      montar(CON_CARGOS);

      expect(await screen.findByText(/las puede pagar quien compra/)).toBeInTheDocument();
      expect(screen.getByText(/Los eventos nuevos arrancan así/)).toBeInTheDocument();
      expect(screen.getByText(/En los productos, la comisión la paga quien vende/)).toBeInTheDocument();
      expect(screen.getByText(/Mercado Pago: 4,39% \+ IVA/)).toBeInTheDocument();
      expect(screen.getByText(/los eventos nuevos arrancan con esas comisiones a cargo de quien compra/)).toBeInTheDocument();
    });

    it('hace la cuenta con la misma función que el checkout', async () => {
      montar(CON_CARGOS);
      const cuenta = calcularCargos(10000, TASAS);

      expect(await screen.findByText('Quien compra paga')).toBeInTheDocument();
      expect(screen.getByText(`≈ ${plata(cuenta.total)}`)).toBeInTheDocument();
      expect(screen.getByText(`+${plata(cuenta.plataforma)}`)).toBeInTheDocument();
      expect(screen.getByText(/Vos recibís el precio completo: \$10\.000/)).toBeInTheDocument();
      expect(screen.queryByText('Te queda')).not.toBeInTheDocument();
    });

    it('sin comisión de Rezonar no nombra ese renglón', async () => {
      montar({ ...CON_CARGOS, cargos: { ...TASAS, plataforma_porcentaje: 0 } });

      await screen.findByText('Quien compra paga');
      expect(screen.queryByText('Comisión de Rezon.ar')).not.toBeInTheDocument();
    });
  });

  it('si el servidor no responde, no muestra números inventados', async () => {
    const { fetch } = mockFetch({ 'public/costos.php': { status: 500, body: { error: 'nope' } } });
    renderConProviders(<ParaArtistas />);

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    expect(screen.queryByText('Cuánto cuesta vender entradas y productos')).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Cuánto cuesta' })).toBeInTheDocument();
    expect(screen.queryByText(/\d%/)).not.toBeInTheDocument();
    expect(screen.queryByText(/los eventos nuevos arrancan/)).not.toBeInTheDocument();
  });
});
