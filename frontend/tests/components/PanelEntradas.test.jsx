import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import PanelEntradas from '../../src/components/PanelEntradas';

const CONECTADO = { configurado: true, modo: 'produccion', cuenta: '987654321', admite_split: true };
const SIN_CONECTAR = { configurado: false };

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

/** Estado inicial que devuelve el servidor al abrir el panel. */
function alAbrir({ entradas = null, cobros = CONECTADO, ocupadas = 0, comision = 3 } = {}) {
  global.fetch.mockReturnValueOnce(respuestaDe({ entradas, cobros, ocupadas, comision }));
}

async function montar(estado) {
  alAbrir(estado);
  const vista = render(<PanelEntradas linkId={100} apiUrl="https://api.test/api" token="tok" />);
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());
  return vista;
}

const activar = () => fireEvent.click(screen.getByRole('checkbox'));

describe('PanelEntradas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('activación', () => {
    it('arranca desactivado si el evento no vendía', async () => {
      await montar();

      expect(screen.getByRole('checkbox')).not.toBeChecked();
    });

    it('los campos aparecen recién al activar', async () => {
      await montar();

      expect(screen.queryByLabelText('CAPACIDAD MÁXIMA')).not.toBeInTheDocument();

      activar();

      expect(screen.getByLabelText('CAPACIDAD MÁXIMA')).toBeInTheDocument();
    });

    it('precarga lo que el evento ya tenía', async () => {
      await montar({
        entradas: { activo: 1, capacidad: 250, precio: '2500.00', max_por_compra: 4 },
      });

      expect(screen.getByRole('checkbox')).toBeChecked();
      expect(screen.getByLabelText('CAPACIDAD MÁXIMA')).toHaveValue(250);
      expect(screen.getByLabelText('PRECIO POR ENTRADA')).toHaveValue(2500);
      expect(screen.getByLabelText('MÁXIMO POR COMPRA')).toHaveValue(4);
    });
  });

  describe('precio', () => {
    it('explica que en cero es una reserva sin costo', async () => {
      await montar();
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '0' } });

      expect(screen.getByText(/reserva sin costo/)).toBeInTheDocument();
    });

    it('con precio muestra cómo se va a ver', async () => {
      await montar();
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '1500' } });

      expect(screen.getByText(/1\.500/)).toBeInTheDocument();
    });
  });

  describe('sin Mercado Pago conectado', () => {
    /** Cobrar sin credencial deja el checkout roto para el comprador. */
    it('avisa que no se puede cobrar', async () => {
      await montar({ cobros: SIN_CONECTAR });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '1500' } });

      expect(screen.getByText(/conectar Mercado Pago/)).toBeInTheDocument();
    });

    /** Una reserva sin costo no necesita Mercado Pago. */
    it('no avisa nada si la reserva es sin costo', async () => {
      await montar({ cobros: SIN_CONECTAR });
      activar();

      expect(screen.queryByText(/conectar Mercado Pago/)).not.toBeInTheDocument();
    });
  });

  describe('credenciales de prueba', () => {
    it('avisa que los pagos no son reales', async () => {
      await montar({ cobros: { ...CONECTADO, modo: 'prueba' } });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '1500' } });

      expect(screen.getByText(/no son reales/)).toBeInTheDocument();
    });
  });

  describe('entradas ya tomadas', () => {
    it('muestra cuántas hay y cuántas quedan', async () => {
      await montar({
        entradas: { activo: 1, capacidad: 100, precio: '1500.00', max_por_compra: 10 },
        ocupadas: 30,
      });

      expect(screen.getByText(/entradas tomadas/)).toHaveTextContent('30');
      expect(screen.getByText(/Quedan 70 disponibles/)).toBeInTheDocument();
    });

    /** Bajar la capacidad por debajo dejaría el evento sobrevendido. */
    it('avisa que la capacidad no puede bajar de lo tomado', async () => {
      await montar({
        entradas: { activo: 1, capacidad: 100, precio: '1500.00', max_por_compra: 10 },
        ocupadas: 30,
      });

      expect(screen.getByText(/no podés bajar la capacidad por debajo de 30/i)).toBeInTheDocument();
    });

    it('no muestra ese bloque si no hay ninguna vendida', async () => {
      await montar({
        entradas: { activo: 1, capacidad: 100, precio: '1500.00', max_por_compra: 10 },
        ocupadas: 0,
      });

      expect(screen.queryByText(/disponibles/)).not.toBeInTheDocument();
    });
  });

  describe('guardar', () => {
    it('manda la configuración al servidor', async () => {
      await montar();
      activar();

      fireEvent.change(screen.getByLabelText('CAPACIDAD MÁXIMA'), { target: { value: '250' } });
      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '1500' } });

      global.fetch.mockReturnValueOnce(respuestaDe({ entradas: { activo: 1, capacidad: 250 } }));
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      await waitFor(() => {
        const enviado = JSON.parse(global.fetch.mock.calls[1][1].body);

        expect(enviado).toMatchObject({ activo: true, capacidad: 250, precio: 1500 });
      });
    });

    it('confirma cuando terminó', async () => {
      await montar();
      activar();

      global.fetch.mockReturnValueOnce(respuestaDe({ entradas: { activo: 1 } }));
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      expect(await screen.findByText('Guardado')).toBeInTheDocument();
    });

    it('muestra el motivo si el servidor rechaza', async () => {
      await montar();
      activar();

      global.fetch.mockReturnValueOnce(
        respuestaDe({ error: 'Ya hay 30 entradas tomadas: la capacidad no puede ser menor' }, false)
      );
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      expect(await screen.findByText(/la capacidad no puede ser menor/)).toBeInTheDocument();
    });

    it('avisa a quien lo contiene que cambió', async () => {
      const onCambio = vi.fn();
      alAbrir();
      render(<PanelEntradas linkId={100} apiUrl="https://api.test/api" token="tok" onCambio={onCambio} />);
      await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

      global.fetch.mockReturnValueOnce(respuestaDe({ entradas: { activo: 1, capacidad: 50 } }));
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      await waitFor(() => expect(onCambio).toHaveBeenCalledWith({ activo: 1, capacidad: 50 }));
    });
  });

  describe('lo que recibe el dueño', () => {
    /** A la hora de poner precio importa lo que entra, no lo que paga el otro. */
    it('muestra el neto de la comisión de Rezonar', async () => {
      await montar({ comision: 3 });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.getByText(/comisión de Rezonar \(3%\)/)).toBeInTheDocument();
      expect(screen.getByText(/9\.700/)).toBeInTheDocument();
    });

    /**
     * Sin esto el dueño hace la cuenta con el 3% y no le cierra con lo que ve
     * en su cuenta de Mercado Pago.
     */
    it('avisa que Mercado Pago descuenta lo suyo aparte', async () => {
      await montar({ comision: 3 });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.getByText(/Mercado Pago le descuenta aparte/)).toBeInTheDocument();
    });

    it('una reserva sin costo no habla de comisiones', async () => {
      await montar({ comision: 3 });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '0' } });

      expect(screen.queryByText(/comisión de Rezonar/)).not.toBeInTheDocument();
    });

    /** Sin split la comisión no se descuenta: mostrar un neto sería mentir. */
    it('no muestra neto si la cuenta no permite el descuento', async () => {
      await montar({ comision: 3, cobros: { ...CONECTADO, admite_split: false } });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.queryByText(/comisión de Rezonar/)).not.toBeInTheDocument();
    });
  });

});
