import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SeccionEntradas from '../../src/components/SeccionEntradas';

const TOKEN = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';
const CLAVE = 'APP_USR-9876543210987654-081612-fedcba9876543210fedcba98765432-321';

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

const SIN_CONECTAR = { configurado: false, modo: null, token_ultimos4: null };
const CONECTADO = { configurado: true, modo: 'produccion', token_ultimos4: 'x123' };

async function montar(cobros = SIN_CONECTAR) {
  global.fetch.mockReturnValueOnce(respuestaDe({ cobros }));

  const vista = render(<SeccionEntradas pageId={5} apiUrl="https://api.test/api" token="tok" />);
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

  return vista;
}

function completar() {
  fireEvent.change(screen.getByLabelText('ACCESS TOKEN'), { target: { value: TOKEN } });
  fireEvent.change(screen.getByLabelText('PUBLIC KEY'), { target: { value: CLAVE } });
}

describe('SeccionEntradas', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('sin conectar', () => {
    it('explica que sin Mercado Pago sólo hay reservas sin costo', async () => {
      await montar();

      expect(screen.getByText(/sólo podés ofrecer reservas sin costo/)).toBeInTheDocument();
    });

    it('pide las dos credenciales', async () => {
      await montar();

      expect(screen.getByLabelText('ACCESS TOKEN')).toBeInTheDocument();
      expect(screen.getByLabelText('PUBLIC KEY')).toBeInTheDocument();
    });

    /** El access token es un secreto: no se muestra mientras se escribe. */
    it('el access token va enmascarado', async () => {
      await montar();

      expect(screen.getByLabelText('ACCESS TOKEN')).toHaveAttribute('type', 'password');
    });

    it('no se puede enviar con un campo vacío', async () => {
      await montar();

      fireEvent.change(screen.getByLabelText('ACCESS TOKEN'), { target: { value: TOKEN } });

      expect(screen.getByRole('button', { name: 'CONECTAR' })).toBeDisabled();
    });

    it('se habilita con las dos cargadas', async () => {
      await montar();
      completar();

      expect(screen.getByRole('button', { name: 'CONECTAR' })).not.toBeDisabled();
    });

    it('dice dónde encontrar las credenciales', async () => {
      await montar();

      expect(screen.getByRole('link', { name: /Credenciales/ }))
        .toHaveAttribute('href', expect.stringContaining('mercadopago'));
    });
  });

  describe('conectada', () => {
    it('muestra que está conectada', async () => {
      await montar(CONECTADO);

      expect(screen.getByText('Mercado Pago conectado')).toBeInTheDocument();
    });

    /** Para que el dueño reconozca cuál cargó sin devolverle el secreto. */
    it('muestra sólo los últimos cuatro caracteres', async () => {
      await montar(CONECTADO);

      expect(screen.getByText('…x123')).toBeInTheDocument();
    });

    it('el botón pasa a ser de reemplazo', async () => {
      await montar(CONECTADO);

      expect(screen.getByRole('button', { name: 'REEMPLAZAR CREDENCIALES' })).toBeInTheDocument();
    });

    it('no avisa nada raro con credenciales de producción', async () => {
      await montar(CONECTADO);

      expect(screen.queryByText(/no son reales/)).not.toBeInTheDocument();
    });

    /** Cobrar de verdad con credenciales de prueba es un error caro. */
    it('avisa fuerte si son credenciales de prueba', async () => {
      await montar({ ...CONECTADO, modo: 'prueba' });

      expect(screen.getByText(/los pagos no son reales/)).toBeInTheDocument();
    });
  });

  describe('guardar', () => {
    it('manda las dos credenciales al servidor', async () => {
      await montar();
      completar();

      global.fetch.mockReturnValueOnce(respuestaDe({ cobros: CONECTADO, cuenta: 'MIBANDA' }));
      fireEvent.click(screen.getByRole('button', { name: 'CONECTAR' }));

      await waitFor(() => {
        const enviado = JSON.parse(global.fetch.mock.calls[1][1].body);

        expect(enviado).toEqual({ access_token: TOKEN, public_key: CLAVE });
      });
    });

    it('confirma con el nombre de la cuenta', async () => {
      await montar();
      completar();

      global.fetch.mockReturnValueOnce(respuestaDe({ cobros: CONECTADO, cuenta: 'MIBANDA' }));
      fireEvent.click(screen.getByRole('button', { name: 'CONECTAR' }));

      expect(await screen.findByText(/Conectado a la cuenta MIBANDA/)).toBeInTheDocument();
    });

    /** Que no quede el secreto en el formulario después de guardarlo. */
    it('limpia los campos al guardar', async () => {
      await montar();
      completar();

      global.fetch.mockReturnValueOnce(respuestaDe({ cobros: CONECTADO, cuenta: 'X' }));
      fireEvent.click(screen.getByRole('button', { name: 'CONECTAR' }));

      await waitFor(() => expect(screen.getByLabelText('ACCESS TOKEN')).toHaveValue(''));
    });

    it('muestra el motivo si Mercado Pago rechaza la credencial', async () => {
      await montar();
      completar();

      global.fetch.mockReturnValueOnce(
        respuestaDe({ error: 'Mercado Pago rechazó la credencial' }, false)
      );
      fireEvent.click(screen.getByRole('button', { name: 'CONECTAR' }));

      expect(await screen.findByText('Mercado Pago rechazó la credencial')).toBeInTheDocument();
    });

    it('explica que se verifica antes de guardar', async () => {
      await montar();

      expect(screen.getByText(/comprobamos contra Mercado Pago/)).toBeInTheDocument();
    });
  });

  describe('desconectar', () => {
    /** Los eventos que ya venden quedarían con el checkout roto. */
    it('pide confirmación si hay eventos cobrando', async () => {
      await montar(CONECTADO);

      global.fetch.mockReturnValueOnce(
        respuestaDe({ error: 'Hay 3 evento(s) cobrando entradas con esta credencial.' }, false)
          .then((r) => ({ ...r, status: 409 }))
      );

      fireEvent.click(screen.getByRole('button', { name: 'DESCONECTAR' }));

      await waitFor(() => expect(window.confirm).toHaveBeenCalled());
    });

    it('no borra nada si el dueño cancela la confirmación', async () => {
      await montar(CONECTADO);

      global.fetch.mockReturnValueOnce(
        respuestaDe({ error: 'Hay 3 evento(s) cobrando' }, false).then((r) => ({ ...r, status: 409 }))
      );

      fireEvent.click(screen.getByRole('button', { name: 'DESCONECTAR' }));

      await waitFor(() => expect(window.confirm).toHaveBeenCalled());
      expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('desconecta sin preguntar si no hay eventos cobrando', async () => {
      await montar(CONECTADO);

      global.fetch.mockReturnValueOnce(respuestaDe({ cobros: SIN_CONECTAR }));
      fireEvent.click(screen.getByRole('button', { name: 'DESCONECTAR' }));

      expect(await screen.findByText('Mercado Pago desconectado')).toBeInTheDocument();
      expect(window.confirm).not.toHaveBeenCalled();
    });
  });
});
