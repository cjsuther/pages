import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ComprarEntradas from '../../src/components/ComprarEntradas';

const EVENTO = { id: 100, text: 'Fiesta de fin de año' };

const ENTRADAS = {
  activo: true,
  es_gratis: false,
  precio: 1500,
  moneda: 'ARS',
  disponibles: 50,
  max_por_compra: 6,
};

function montar(overrides = {}) {
  const props = {
    evento: EVENTO,
    entradas: ENTRADAS,
    apiUrl: 'https://api.test/api',
    onCerrar: vi.fn(),
    ...overrides,
  };

  return { ...render(<ComprarEntradas {...props} />), props };
}

function completarFormulario() {
  fireEvent.change(screen.getByLabelText('NOMBRE Y APELLIDO'), { target: { value: 'Ana Gómez' } });
  fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'ana@example.com' } });
  fireEvent.change(screen.getByLabelText('TELÉFONO'), { target: { value: '1122334455' } });
}

function respuesta(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

describe('ComprarEntradas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    // Ir a Mercado Pago es una navegación real; en jsdom se espía.
    delete window.location;
    window.location = { href: '' };
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('formulario', () => {
    /** Los cuatro datos que pide el negocio, ni más ni menos. */
    it('pide nombre, email, teléfono y cantidad', () => {
      montar();

      expect(screen.getByLabelText('NOMBRE Y APELLIDO')).toBeInTheDocument();
      expect(screen.getByLabelText('EMAIL')).toBeInTheDocument();
      expect(screen.getByLabelText('TELÉFONO')).toBeInTheDocument();
      expect(screen.getByLabelText('CANTIDAD')).toBeInTheDocument();
    });

    it('los cuatro campos son obligatorios', () => {
      montar();

      expect(screen.getByLabelText('NOMBRE Y APELLIDO')).toBeRequired();
      expect(screen.getByLabelText('EMAIL')).toBeRequired();
      expect(screen.getByLabelText('TELÉFONO')).toBeRequired();
    });

    it('el email usa el teclado de email en el teléfono', () => {
      montar();

      expect(screen.getByLabelText('EMAIL')).toHaveAttribute('type', 'email');
      expect(screen.getByLabelText('TELÉFONO')).toHaveAttribute('type', 'tel');
    });

    it('muestra el nombre del evento', () => {
      montar();

      expect(screen.getByText('Fiesta de fin de año')).toBeInTheDocument();
    });

    /** Ofrecer más de lo que queda lleva a un error recién al confirmar. */
    it('sólo ofrece las cantidades que quedan', () => {
      montar({ entradas: { ...ENTRADAS, max_por_compra: 10, disponibles: 3 } });

      expect(screen.getAllByRole('option')).toHaveLength(3);
    });

    it('avisa cuando quedan pocas', () => {
      montar({ entradas: { ...ENTRADAS, disponibles: 4 } });

      expect(screen.getByText(/Quedan 4 entradas/)).toBeInTheDocument();
    });

    it('no mete presión cuando hay lugar de sobra', () => {
      montar({ entradas: { ...ENTRADAS, disponibles: 50 } });

      expect(screen.queryByText(/Quedan/)).not.toBeInTheDocument();
    });
  });

  describe('total', () => {
    it('muestra el total a pagar', () => {
      montar();

      expect(screen.getByText(/1\.500/)).toBeInTheDocument();
    });

    it('el total acompaña la cantidad elegida', async () => {
      montar();

      fireEvent.change(screen.getByLabelText('CANTIDAD'), { target: { value: '3' } });

      expect(await screen.findByText(/4\.500/)).toBeInTheDocument();
    });

    it('una reserva sin costo no muestra total', () => {
      montar({ entradas: { ...ENTRADAS, es_gratis: true, precio: 0 } });

      expect(screen.queryByText('Total')).not.toBeInTheDocument();
    });
  });

  describe('con cobro', () => {
    it('el botón dice que se va a pagar', () => {
      montar();

      expect(screen.getByRole('button', { name: 'IR A PAGAR' })).toBeInTheDocument();
    });

    it('avisa que el lugar queda reservado 15 minutos', () => {
      montar();

      expect(screen.getByText(/reservado 15 minutos/)).toBeInTheDocument();
    });

    it('manda los datos del comprador al servidor', async () => {
      global.fetch.mockReturnValue(respuesta({ codigo: 'ABC123', url: 'https://mp.test/pagar' }));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      await waitFor(() => {
        const enviado = JSON.parse(global.fetch.mock.calls[0][1].body);

        expect(enviado).toMatchObject({
          link_id: 100,
          nombre: 'Ana Gómez',
          email: 'ana@example.com',
          telefono: '1122334455',
          cantidad: 1,
        });
      });
    });

    it('lleva al comprador a Mercado Pago', async () => {
      global.fetch.mockReturnValue(respuesta({ codigo: 'ABC123', url: 'https://mp.test/pagar' }));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      await waitFor(() => {
        expect(window.location.href).toBe('https://mp.test/pagar');
      });
    });
  });

  describe('reserva sin costo', () => {
    const gratis = { ...ENTRADAS, es_gratis: true, precio: 0 };

    it('el botón dice que se reserva, no que se paga', () => {
      montar({ entradas: gratis });

      expect(screen.getByRole('button', { name: 'CONFIRMAR RESERVA' })).toBeInTheDocument();
    });

    it('no menciona Mercado Pago', () => {
      montar({ entradas: gratis });

      expect(screen.queryByText(/Mercado Pago/)).not.toBeInTheDocument();
    });

    /** Sin cobro no hay checkout: queda confirmada sin salir de la página. */
    it('muestra el código sin redirigir a ningún lado', async () => {
      global.fetch.mockReturnValue(respuesta({ codigo: 'ABC123DEF456', url: null }));
      montar({ entradas: gratis });
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'CONFIRMAR RESERVA' }));

      expect(await screen.findByText('ABC123DEF456')).toBeInTheDocument();
      expect(window.location.href).toBe('');
    });

    it('ofrece ver la reserva', async () => {
      global.fetch.mockReturnValue(respuesta({ codigo: 'ABC123DEF456', url: null }));
      montar({ entradas: gratis });
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'CONFIRMAR RESERVA' }));

      expect(await screen.findByRole('link', { name: 'VER MI RESERVA' }))
        .toHaveAttribute('href', '/entrada/ABC123DEF456');
    });
  });

  describe('cuando algo sale mal', () => {
    it('muestra el motivo que da el servidor', async () => {
      global.fetch.mockReturnValue(respuesta({ error: 'Se agotaron las entradas' }, false));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      expect(await screen.findByText('Se agotaron las entradas')).toBeInTheDocument();
    });

    it('no redirige si el servidor rechazó la compra', async () => {
      global.fetch.mockReturnValue(respuesta({ error: 'Se agotaron' }, false));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      await screen.findByText('Se agotaron');
      expect(window.location.href).toBe('');
    });

    it('deja volver a intentar después de un error', async () => {
      global.fetch.mockReturnValue(respuesta({ error: 'Se agotaron' }, false));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));
      await screen.findByText('Se agotaron');

      expect(screen.getByRole('button', { name: 'IR A PAGAR' })).not.toBeDisabled();
    });

    it('una caída de red se explica en castellano', async () => {
      global.fetch.mockRejectedValue(new Error('network'));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      expect(await screen.findByText(/No pudimos conectarnos/)).toBeInTheDocument();
    });
  });

  describe('cerrar', () => {
    it('se puede cerrar con la cruz', () => {
      const { props } = montar();

      fireEvent.click(screen.getByRole('button', { name: 'Cerrar' }));

      expect(props.onCerrar).toHaveBeenCalled();
    });

    /** Un click adentro del formulario no puede cerrar lo que se está llenando. */
    it('un click dentro del formulario no lo cierra', () => {
      const { props } = montar();

      fireEvent.click(screen.getByLabelText('NOMBRE Y APELLIDO'));

      expect(props.onCerrar).not.toHaveBeenCalled();
    });
  });
});
