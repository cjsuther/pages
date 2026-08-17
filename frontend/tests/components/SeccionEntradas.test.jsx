import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import SeccionEntradas from '../../src/components/SeccionEntradas';

const SIN_CONECTAR = { configurado: false, modo: null, cuenta: null, admite_split: false };

const CONECTADO = {
  configurado: true,
  modo: 'produccion',
  cuenta: '987654321',
  conectado_por: 'oauth',
  admite_split: true,
};

function respuestaDe(cuerpo, ok = true, status = 200) {
  return Promise.resolve({ ok, status, json: () => Promise.resolve(cuerpo) });
}

async function montar({ cobros = SIN_CONECTAR, comision = 10, disponible = true, ruta = '/page/5' } = {}) {
  global.fetch.mockReturnValueOnce(respuestaDe({ cobros, comision, disponible }));

  const vista = renderConProviders(
    <SeccionEntradas pageId={5} apiUrl="https://api.test/api" token="tok" />,
    { route: ruta, path: '/page/:id' }
  );

  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

  return vista;
}

describe('SeccionEntradas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    delete window.location;
    window.location = { href: '' };
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('sin conectar', () => {
    it('explica que sin Mercado Pago sólo hay reservas sin costo', async () => {
      await montar();

      expect(screen.getByText(/sólo podés ofrecer reservas sin costo/)).toBeInTheDocument();
    });

    /**
     * El alta por OAuth es lo único que permite descontar la comisión: con un
     * access token pegado a mano Mercado Pago la ignora sin dar error.
     */
    it('ofrece conectar, no pegar credenciales', async () => {
      await montar();

      expect(screen.getByRole('button', { name: /CONECTAR CON MERCADO PAGO/ })).toBeInTheDocument();
      expect(screen.queryByLabelText(/ACCESS TOKEN/)).not.toBeInTheDocument();
      expect(screen.queryByLabelText(/PUBLIC KEY/)).not.toBeInTheDocument();
    });

    it('aclara que no vemos usuario ni contraseña', async () => {
      await montar();

      expect(screen.getByText(/No vemos ni guardamos tu usuario/)).toBeInTheDocument();
    });

    it('el botón lleva a Mercado Pago', async () => {
      await montar();

      global.fetch.mockReturnValueOnce(respuestaDe({ url: 'https://auth.mercadopago.com.ar/authorization?x=1' }));
      fireEvent.click(screen.getByRole('button', { name: /CONECTAR CON MERCADO PAGO/ }));

      await waitFor(() => {
        expect(window.location.href).toBe('https://auth.mercadopago.com.ar/authorization?x=1');
      });
    });

    it('muestra el motivo si no se pudo iniciar la conexión', async () => {
      await montar();

      global.fetch.mockReturnValueOnce(respuestaDe({ error: 'No podés administrar esta página' }, false));
      fireEvent.click(screen.getByRole('button', { name: /CONECTAR CON MERCADO PAGO/ }));

      expect(await screen.findByText('No podés administrar esta página')).toBeInTheDocument();
    });

    /** Un botón que lleva a un error de Mercado Pago es peor que decirlo antes. */
    it('deshabilita el botón si la plataforma no terminó su integración', async () => {
      await montar({ disponible: false });

      expect(screen.getByRole('button', { name: /CONECTAR CON MERCADO PAGO/ })).toBeDisabled();
      expect(screen.getByText(/todavía no terminó de configurar/)).toBeInTheDocument();
    });
  });

  describe('conectada', () => {
    it('muestra que está conectada y con qué cuenta', async () => {
      await montar({ cobros: CONECTADO });

      expect(screen.getByText('Mercado Pago conectado')).toBeInTheDocument();
      expect(screen.getByText('987654321')).toBeInTheDocument();
    });

    it('no vuelve a ofrecer conectar', async () => {
      await montar({ cobros: CONECTADO });

      expect(screen.queryByRole('button', { name: /CONECTAR CON/ })).not.toBeInTheDocument();
    });

    /** Cobrar de verdad con una cuenta de prueba es un error caro. */
    it('avisa fuerte si es una cuenta de prueba', async () => {
      await montar({ cobros: { ...CONECTADO, modo: 'prueba' } });

      expect(screen.getByText(/los pagos no son reales/)).toBeInTheDocument();
    });

    it('no avisa nada raro con una cuenta real', async () => {
      await montar({ cobros: CONECTADO });

      expect(screen.queryByText(/no son reales/)).not.toBeInTheDocument();
    });

    /**
     * Una cuenta cargada a mano cobra igual pero no descuenta la comisión.
     * Conviene decirlo antes de que aparezca la diferencia en la liquidación.
     */
    it('avisa si la cuenta no permite el descuento automático', async () => {
      await montar({ cobros: { ...CONECTADO, conectado_por: 'manual', admite_split: false } });

      expect(screen.getByText(/no permite el descuento automático/)).toBeInTheDocument();
    });

    it('no avisa nada de eso si la cuenta sí lo permite', async () => {
      await montar({ cobros: CONECTADO });

      expect(screen.queryByText(/no permite el descuento automático/)).not.toBeInTheDocument();
    });
  });

  describe('comisión', () => {
    it('dice el porcentaje que se descuenta', async () => {
      await montar({ comision: 10 });

      expect(screen.getByText(/Comisión de Rezonar: 10%/)).toBeInTheDocument();
    });

    /** Un porcentaje solo no se entiende; un ejemplo en pesos sí. */
    it('lo explica con un ejemplo concreto', async () => {
      await montar({ comision: 3 });

      expect(screen.getByText(/300 de comisión/)).toBeInTheDocument();
    });

    it('el ejemplo acompaña el porcentaje configurado', async () => {
      await montar({ comision: 10 });

      expect(screen.getByText(/1\.000 de comisión/)).toBeInTheDocument();
    });

    it('aclara que el comprador paga una sola vez', async () => {
      await montar({ comision: 10 });

      expect(screen.getByText(/el comprador paga una sola vez/)).toBeInTheDocument();
    });

    /**
     * Sin esto el dueño hace la cuenta con el 3% y no le cierra con lo que ve
     * en su cuenta: Mercado Pago descuenta lo suyo aparte.
     */
    it('avisa que Mercado Pago cobra su propia comisión', async () => {
      await montar({ comision: 3 });

      expect(screen.getByText(/Mercado Pago cobra su\s+propia comisión/)).toBeInTheDocument();
    });

    it('manda a los costos de Mercado Pago en vez de inventar un número', async () => {
      await montar({ comision: 3 });

      expect(screen.getByRole('link', { name: /Costos/ }))
        .toHaveAttribute('href', expect.stringContaining('mercadopago'));
    });

    it('aclara que las reservas sin costo no pagan comisión', async () => {
      await montar({ comision: 10 });

      expect(screen.getByText(/reservas sin costo no pagan nada/)).toBeInTheDocument();
    });

    it('sin comisión configurada no se menciona nada', async () => {
      await montar({ comision: 0 });

      expect(screen.queryByText(/Comisión de Rezonar/)).not.toBeInTheDocument();
      expect(screen.queryByText(/Mercado Pago cobra su/)).not.toBeInTheDocument();
    });
  });

  describe('vuelta desde Mercado Pago', () => {
    it('confirma cuando la conexión salió bien', async () => {
      await montar({ cobros: CONECTADO, ruta: '/page/5?seccion=entradas&conectado=1' });

      expect(screen.getByText(/quedó conectada/)).toBeInTheDocument();
    });

    it('explica en castellano si el dueño canceló', async () => {
      await montar({ ruta: '/page/5?seccion=entradas&error=cancelado' });

      expect(screen.getByText(/No autorizaste la conexión/)).toBeInTheDocument();
    });

    it('explica si el link venció', async () => {
      await montar({ ruta: '/page/5?seccion=entradas&error=estado_invalido' });

      expect(screen.getByText(/link de conexión venció/)).toBeInTheDocument();
    });

    it('un error desconocido no deja la pantalla muda', async () => {
      await montar({ ruta: '/page/5?seccion=entradas&error=algo_raro' });

      expect(screen.getByText(/No se pudo conectar la cuenta/)).toBeInTheDocument();
    });
  });

  describe('desconectar', () => {
    /** Los eventos que ya venden quedarían con el checkout roto. */
    it('pide confirmación si hay eventos cobrando', async () => {
      await montar({ cobros: CONECTADO });

      global.fetch.mockReturnValueOnce(
        respuestaDe({ error: 'Hay 3 evento(s) cobrando entradas con esta cuenta.' }, false, 409)
      );

      fireEvent.click(screen.getByRole('button', { name: 'DESCONECTAR' }));

      await waitFor(() => expect(window.confirm).toHaveBeenCalled());
    });

    it('no borra nada si se cancela la confirmación', async () => {
      await montar({ cobros: CONECTADO });

      global.fetch.mockReturnValueOnce(respuestaDe({ error: 'Hay 3 evento(s)' }, false, 409));
      fireEvent.click(screen.getByRole('button', { name: 'DESCONECTAR' }));

      await waitFor(() => expect(window.confirm).toHaveBeenCalled());
      expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('desconecta sin preguntar si no hay eventos cobrando', async () => {
      await montar({ cobros: CONECTADO });

      global.fetch.mockReturnValueOnce(respuestaDe({ cobros: SIN_CONECTAR }));
      fireEvent.click(screen.getByRole('button', { name: 'DESCONECTAR' }));

      expect(await screen.findByText('Mercado Pago desconectado')).toBeInTheDocument();
      expect(window.confirm).not.toHaveBeenCalled();
    });
  });
});
