import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import Precios from '../../src/pages/Precios';
import { renderConProviders } from '../helpers/render';
import { mockFetch } from '../helpers/api';

/**
 * La página de precios promete tres cosas: qué es gratis, cuánto se cobra por
 * entrada y cuándo llega la plata. Las dos últimas son números del servidor;
 * lo que se prueba es que se muestren tal cual y que, si no llegan, la página
 * siga diciendo lo mismo sin inventar ninguno.
 */
const COSTOS = { comision: 1.5, mercadopago: { porcentaje: 7.25, dias: 3 } };

function montar(costos = COSTOS) {
  const mock = mockFetch({ 'public/costos.php': costos });
  renderConProviders(<Precios />);
  return mock;
}

describe('Precios', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('lo gratis', () => {
    it('lista la página y las herramientas de venta a $0', () => {
      montar();

      expect(screen.getByRole('heading', { name: 'Gratis, para siempre' })).toBeInTheDocument();
      expect(screen.getByText('Avisos a tus seguidores')).toBeInTheDocument();
      expect(screen.getByText('Control en la puerta')).toBeInTheDocument();
      expect(screen.getByText('Reservas sin costo')).toBeInTheDocument();
    });
  });

  describe('la venta de entradas', () => {
    it('muestra la comisión como se escribe acá', async () => {
      montar();

      expect(await screen.findByText('1,5%')).toBeInTheDocument();
    });

    it('muestra lo que cobra Mercado Pago aparte', async () => {
      montar();

      expect(await screen.findByText(/Mercado Pago cobra 7,25%/)).toBeInTheDocument();
    });

    it('hace la cuenta con una entrada de ejemplo', async () => {
      montar();

      expect(await screen.findByText('$9.125')).toBeInTheDocument();
    });

    it('si el servidor no responde, habla de la comisión sin inventar un número', async () => {
      const { fetch } = mockFetch({ 'public/costos.php': { status: 500, body: {} } });
      renderConProviders(<Precios />);

      await waitFor(() => expect(fetch).toHaveBeenCalled());
      expect(screen.getByText(/Ves el porcentaje exacto antes de activar la venta/)).toBeInTheDocument();
      expect(screen.queryByText(/\d%/)).not.toBeInTheDocument();
    });
  });

  describe('cuándo se cobra', () => {
    it('dice a cuántos días de la compra llega la plata', async () => {
      montar();

      expect(await screen.findByRole('heading', { name: /La plata te llega a los 3 días de cada compra\. No después del evento\./ }))
        .toBeInTheDocument();
    });

    it('lo contrasta con cobrar después del evento', () => {
      montar();

      expect(screen.getByText('En otras ticketeras')).toBeInTheDocument();
      expect(screen.getByText(/después de que termina el evento/)).toBeInTheDocument();
    });

    it('un día se dice en singular', async () => {
      montar({ comision: 1.5, mercadopago: { porcentaje: 7.25, dias: 1 } });

      expect(await screen.findByRole('heading', { name: /a los 1 día de cada compra/ })).toBeInTheDocument();
    });

    it('sin el plazo del servidor no inventa un número de días', async () => {
      montar({ comision: 1.5, mercadopago: null });

      await screen.findByText('1,5%');
      expect(screen.getByRole('heading', { name: /a los pocos días de cada compra/ })).toBeInTheDocument();
    });
  });
});
