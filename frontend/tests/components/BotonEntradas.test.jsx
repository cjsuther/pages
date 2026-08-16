import React from 'react';
import { describe, it, expect } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import BotonEntradas, { vendeEntradas } from '../../src/components/BotonEntradas';

const EVENTO = { id: 100, text: 'Fiesta de fin de año' };

const CON_VENTA = {
  activo: true,
  es_gratis: false,
  precio: 1500,
  moneda: 'ARS',
  disponibles: 50,
  max_por_compra: 6,
  agotado: false,
};

function montar(entradas) {
  return renderConProviders(<BotonEntradas evento={{ ...EVENTO, entradas }} />);
}

describe('BotonEntradas', () => {
  describe('cuándo aparece', () => {
    /** La inmensa mayoría de los eventos no vende entradas. */
    it('no ocupa espacio si el evento no vende', () => {
      const { container } = montar(null);

      expect(container).toBeEmptyDOMElement();
    });

    it('tampoco si la venta está desactivada', () => {
      const { container } = montar({ ...CON_VENTA, activo: false });

      expect(container).toBeEmptyDOMElement();
    });

    it('no rompe si el evento ni siquiera trae el campo', () => {
      const { container } = renderConProviders(<BotonEntradas evento={EVENTO} />);

      expect(container).toBeEmptyDOMElement();
    });

    it('aparece cuando el evento vende', () => {
      montar(CON_VENTA);

      expect(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ })).toBeInTheDocument();
    });
  });

  describe('qué dice', () => {
    it('con precio invita a comprar', () => {
      montar(CON_VENTA);

      expect(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ })).toBeInTheDocument();
    });

    it('sin precio invita a reservar', () => {
      montar({ ...CON_VENTA, es_gratis: true, precio: 0 });

      expect(screen.getByRole('button', { name: /RESERVAR LUGAR/ })).toBeInTheDocument();
    });

    it('muestra el precio por entrada', () => {
      montar(CON_VENTA);

      expect(screen.getByText(/1\.500.*por entrada/)).toBeInTheDocument();
    });

    it('una reserva sin costo no muestra precio', () => {
      montar({ ...CON_VENTA, es_gratis: true, precio: 0 });

      expect(screen.queryByText(/por entrada/)).not.toBeInTheDocument();
    });

    it('avisa cuando quedan pocas', () => {
      montar({ ...CON_VENTA, disponibles: 3 });

      expect(screen.getByText(/Quedan 3 entradas/)).toBeInTheDocument();
    });

    it('usa el singular con una sola', () => {
      montar({ ...CON_VENTA, disponibles: 1 });

      expect(screen.getByText(/Quedan 1 entrada$/)).toBeInTheDocument();
    });
  });

  describe('agotado', () => {
    it('lo dice y no deja comprar', () => {
      montar({ ...CON_VENTA, agotado: true, disponibles: 0 });

      expect(screen.getByText('AGOTADO')).toBeInTheDocument();
      expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
  });

  describe('abrir la compra', () => {
    it('el click abre el formulario', () => {
      montar(CON_VENTA);

      fireEvent.click(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ }));

      expect(screen.getByLabelText('NOMBRE Y APELLIDO')).toBeInTheDocument();
    });

    it('el formulario no está abierto de entrada', () => {
      montar(CON_VENTA);

      expect(screen.queryByLabelText('NOMBRE Y APELLIDO')).not.toBeInTheDocument();
    });

    /** El botón vive dentro del modal del evento, que cierra al hacer click. */
    it('el click no se propaga al modal que lo contiene', () => {
      let cerroElModal = false;

      renderConProviders(
        <div onClick={() => { cerroElModal = true; }}>
          <BotonEntradas evento={{ ...EVENTO, entradas: CON_VENTA }} />
        </div>
      );

      fireEvent.click(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ }));

      expect(cerroElModal).toBe(false);
    });

    it('se puede cerrar el formulario y volver al botón', () => {
      montar(CON_VENTA);

      fireEvent.click(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ }));
      fireEvent.click(screen.getByRole('button', { name: 'Cerrar' }));

      expect(screen.queryByLabelText('NOMBRE Y APELLIDO')).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ })).toBeInTheDocument();
    });
  });

  describe('vendeEntradas', () => {
    /** Es lo que usan las plantillas para ocultar el link cargado a mano. */
    it('es verdadero sólo con la venta activa', () => {
      expect(vendeEntradas({ entradas: CON_VENTA })).toBe(true);
      expect(vendeEntradas({ entradas: { ...CON_VENTA, activo: false } })).toBe(false);
      expect(vendeEntradas({ entradas: null })).toBe(false);
      expect(vendeEntradas({})).toBe(false);
      expect(vendeEntradas(null)).toBe(false);
    });
  });
});
