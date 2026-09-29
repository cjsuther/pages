import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import ParaArtistas from '../../src/pages/ParaArtistas';
import { renderConProviders } from '../helpers/render';
import { mockFetch } from '../helpers/api';

/**
 * Quien está pensando si vender entradas por acá tiene que poder hacer la
 * cuenta sin registrarse. Los números salen del servidor: lo que se prueba es
 * que se muestren tal cual y que, si no llegan, no se invente ninguno.
 */
function montar(costos = { comision: 1.5, mercadopago: { porcentaje: 7.25, dias: 3 } }) {
  const mock = mockFetch({ 'public/costos.php': costos });
  renderConProviders(<ParaArtistas />);
  return mock;
}

describe('ParaArtistas: cuánto cuesta vender entradas', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  it('pide los costos sin sesión', async () => {
    const { llamadas } = montar();

    await screen.findByText('Cuánto cuesta vender entradas');
    const pedido = llamadas.find((l) => l.url.includes('public/costos.php'));
    expect(pedido.options.headers).toBeUndefined();
  });

  it('muestra la comisión de Rezonar como se escribe acá', async () => {
    montar();

    expect(await screen.findByText(/Comisión de Rezonar: 1,5%/)).toBeInTheDocument();
  });

  it('muestra lo que cobra Mercado Pago y cuándo libera la plata', async () => {
    montar();

    expect(await screen.findByText(/Mercado Pago: 7,25%/)).toBeInTheDocument();
    expect(screen.getByText(/a los 3 días de la/)).toBeInTheDocument();
  });

  it('hace la cuenta con una entrada de ejemplo', async () => {
    montar();

    await screen.findByText('Cuánto cuesta vender entradas');
    expect(screen.getByText('−$150')).toBeInTheDocument();
    expect(screen.getByText('−$725')).toBeInTheDocument();
    expect(screen.getByText('$9.125')).toBeInTheDocument();
  });

  it('sin dato de Mercado Pago muestra sólo la comisión nuestra', async () => {
    montar({ comision: 1.5, mercadopago: null });

    await screen.findByText(/Comisión de Rezonar: 1,5%/);
    expect(screen.queryByText(/Mercado Pago: /)).not.toBeInTheDocument();
    expect(screen.getByText('$9.850')).toBeInTheDocument();
  });

  it('si el servidor no responde, no muestra números inventados', async () => {
    const { fetch } = mockFetch({ 'public/costos.php': { status: 500, body: { error: 'nope' } } });
    renderConProviders(<ParaArtistas />);

    await waitFor(() => expect(fetch).toHaveBeenCalled());
    expect(screen.queryByText('Cuánto cuesta vender entradas')).not.toBeInTheDocument();
  });
});
