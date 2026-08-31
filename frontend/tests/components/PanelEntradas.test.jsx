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
// A propósito distintos de los reales (4,39% y 10 días): los valores vienen
// del servidor, y así un test que pase porque el número quedó escrito en el
// componente se cae.
const MERCADO_PAGO = { porcentaje: 7.25, dias: 3 };

function alAbrir({
  entradas = null,
  cobros = CONECTADO,
  ocupadas = 0,
  comision = 3,
  mercadopago = MERCADO_PAGO,
} = {}) {
  global.fetch.mockReturnValueOnce(respuestaDe({ entradas, cobros, ocupadas, comision, mercadopago }));
}

async function montar(estado) {
  alAbrir(estado);
  const vista = render(<PanelEntradas linkId={100} apiUrl="https://api.test/api" token="tok" />);
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());
  return vista;
}

/** Elegir vender por Rezonar: es lo que abre los campos de la venta interna. */
const activar = () => fireEvent.click(screen.getByRole('radio', { name: /Acá, con Rezonar/ }));

/** La otra respuesta a la misma pregunta: se venden en otro lado. */
const enOtroLado = () => fireEvent.click(screen.getByRole('radio', { name: /En otro lado/ }));

describe('PanelEntradas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('dónde se consiguen las entradas', () => {
    /**
     * La mayoría de los eventos se venden en otro lado. Arrancar en "acá" haría
     * que la respuesta más común quede a un clic de distancia y la menos común
     * a ninguno.
     */
    it('un evento que no vendía por Rezonar arranca en "en otro lado"', async () => {
      await montar();

      expect(screen.getByRole('radio', { name: /En otro lado/ })).toBeChecked();
      expect(screen.getByLabelText('LINK (OPCIONAL)')).toBeInTheDocument();
    });

    it('los campos de la venta interna aparecen recién al elegirla', async () => {
      await montar();

      expect(screen.queryByLabelText('CAPACIDAD MÁXIMA')).not.toBeInTheDocument();

      activar();

      expect(screen.getByLabelText('CAPACIDAD MÁXIMA')).toBeInTheDocument();
      expect(screen.queryByLabelText('LINK (OPCIONAL)')).not.toBeInTheDocument();
    });

    /** Con la venta interna prendida, el público no ve el link: manda ella. */
    it('un evento que ya vendía por Rezonar abre en "acá"', async () => {
      await montar({
        entradas: { activo: 1, capacidad: 250, precio: '2500.00', max_por_compra: 4 },
      });

      expect(screen.getByRole('radio', { name: /Acá, con Rezonar/ })).toBeChecked();
      expect(screen.getByLabelText('CAPACIDAD MÁXIMA')).toHaveValue(250);
      expect(screen.getByLabelText('PRECIO POR ENTRADA')).toHaveValue(2500);
      expect(screen.getByLabelText('MÁXIMO POR COMPRA')).toHaveValue(4);
    });

    /** Con la venta apagada el link es lo único que hay: no hay conflicto. */
    it('un evento con la venta apagada abre en "en otro lado"', async () => {
      await montar({
        entradas: { activo: 0, capacidad: 250, precio: '2500.00', max_por_compra: 4 },
      });

      expect(screen.getByRole('radio', { name: /En otro lado/ })).toBeChecked();
    });

    it('precarga el link que ya tenía el evento', async () => {
      alAbrir();
      render(
        <PanelEntradas
          linkId={100}
          apiUrl="https://api.test/api"
          token="tok"
          enlace={{ url: 'https://venta.test/show', url_text: 'Comprar' }}
          onGuardarEnlace={vi.fn()}
        />
      );
      await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

      expect(screen.getByLabelText('LINK (OPCIONAL)')).toHaveValue('https://venta.test/show');
      expect(screen.getByLabelText('TEXTO DEL BOTÓN (OPCIONAL)')).toHaveValue('Comprar');
    });
  });

  describe('guardar el link', () => {
    async function montarConLink(estado) {
      const onGuardarEnlace = vi.fn().mockResolvedValue(undefined);

      alAbrir(estado);
      render(
        <PanelEntradas
          linkId={100}
          apiUrl="https://api.test/api"
          token="tok"
          enlace={{ url: '', url_text: '' }}
          onGuardarEnlace={onGuardarEnlace}
        />
      );
      await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

      return onGuardarEnlace;
    }

    it('guarda el link y su texto', async () => {
      const onGuardarEnlace = await montarConLink();

      fireEvent.change(screen.getByLabelText('LINK (OPCIONAL)'), {
        target: { value: 'https://venta.test/show' },
      });
      fireEvent.change(screen.getByLabelText('TEXTO DEL BOTÓN (OPCIONAL)'), {
        target: { value: 'Comprar' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      await waitFor(() => expect(onGuardarEnlace)
        .toHaveBeenCalledWith({ url: 'https://venta.test/show', url_text: 'Comprar' }));
    });

    /**
     * Si la venta interna queda prendida, el detalle del evento sigue
     * ofreciendo el botón de compra y el link no se ve nunca.
     */
    it('elegir "en otro lado" apaga la venta por Rezonar', async () => {
      await montarConLink({ entradas: { activo: 1, capacidad: 50, precio: '0', max_por_compra: 4 } });

      enOtroLado();

      global.fetch.mockReturnValueOnce(respuestaDe({ entradas: { activo: 0 } }));
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      await waitFor(() => {
        const enviado = JSON.parse(global.fetch.mock.calls[1][1].body);

        expect(enviado).toMatchObject({ activo: false });
      });
    });

    /** Un evento que nunca vendió por Rezonar no tiene nada que apagar. */
    it('no toca la venta interna si nunca estuvo prendida', async () => {
      await montarConLink();

      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      await waitFor(() => expect(screen.getByText('Guardado')).toBeInTheDocument());

      expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    /**
     * Apagar la venta después de guardar el link, y no antes: si el link falla,
     * el evento no puede quedarse sin ninguna de las dos formas de conseguir
     * entradas.
     */
    it('si el link falla no apaga la venta', async () => {
      const onGuardarEnlace = vi.fn().mockRejectedValue(new Error('No se pudo guardar el link'));

      alAbrir({ entradas: { activo: 1, capacidad: 50, precio: '0', max_por_compra: 4 } });
      render(
        <PanelEntradas
          linkId={100}
          apiUrl="https://api.test/api"
          token="tok"
          enlace={{ url: '', url_text: '' }}
          onGuardarEnlace={onGuardarEnlace}
        />
      );
      await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

      enOtroLado();
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR ENTRADAS' }));

      expect(await screen.findByText('No se pudo guardar el link')).toBeInTheDocument();
      expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('avisa que al guardar se deja de vender por Rezonar', async () => {
      await montarConLink({ entradas: { activo: 1, capacidad: 50, precio: '0', max_por_compra: 4 } });

      enOtroLado();

      expect(screen.getByText(/se deja de vender por Rezonar/)).toBeInTheDocument();
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

      activar();

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
     * Sin esto el dueño hace la cuenta sólo con nuestra comisión y no le cierra
     * con lo que ve en su cuenta de Mercado Pago.
     */
    it('dice cuánto descuenta Mercado Pago aparte', async () => {
      await montar({ comision: 1.5 });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.getByText(/descuenta aparte 7,25%/)).toBeInTheDocument();
    });

    /** Poner precio es decidir cuánto entra y cuándo: el plazo es parte. */
    it('dice a los cuántos días se libera la plata', async () => {
      await montar({ comision: 1.5 });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.getByText(/a los 3 días\s+de la compra/)).toBeInTheDocument();
    });

    /** Sin el dato del servidor no inventamos un porcentaje. */
    it('no habla de Mercado Pago si el servidor no lo informa', async () => {
      await montar({ comision: 1.5, mercadopago: null });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.getByText(/Menos la comisión de Rezonar/)).toBeInTheDocument();
      expect(screen.queryByText(/Mercado Pago/)).not.toBeInTheDocument();
    });

    it('escribe los decimales de la comisión como se escriben acá', async () => {
      await montar({ comision: 1.5 });
      activar();

      fireEvent.change(screen.getByLabelText('PRECIO POR ENTRADA'), { target: { value: '10000' } });

      expect(screen.getByText(/comisión de Rezonar \(1,5%\)/)).toBeInTheDocument();
      expect(screen.getByText(/9\.850/)).toBeInTheDocument();
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
