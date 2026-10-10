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
  fireEvent.change(screen.getByLabelText('TELÉFONO (OPCIONAL)'), { target: { value: '1122334455' } });
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
      expect(screen.getByLabelText('TELÉFONO (OPCIONAL)')).toBeInTheDocument();
      expect(screen.getByLabelText('Cantidad')).toBeInTheDocument();
    });

    it('el nombre y el email son obligatorios', () => {
      montar();

      expect(screen.getByLabelText('NOMBRE Y APELLIDO')).toBeRequired();
      expect(screen.getByLabelText('EMAIL')).toBeRequired();
    });

    /**
     * La entrada llega por mail y ahí termina el circuito: exigir el teléfono
     * sólo espantaba compras. Queda como dato de contacto, no como requisito.
     */
    it('el teléfono es opcional', () => {
      montar();

      expect(screen.getByLabelText('TELÉFONO (OPCIONAL)')).not.toBeRequired();
    });

    it('el email usa el teclado de email en el teléfono', () => {
      montar();

      expect(screen.getByLabelText('EMAIL')).toHaveAttribute('type', 'email');
      expect(screen.getByLabelText('TELÉFONO (OPCIONAL)')).toHaveAttribute('type', 'tel');
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

      fireEvent.change(screen.getByLabelText('Cantidad'), { target: { value: '3' } });

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

      expect(screen.getByRole('button', { name: 'Confirmar reserva' })).toBeInTheDocument();
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

      fireEvent.click(screen.getByRole('button', { name: 'Confirmar reserva' }));

      expect(await screen.findByText('ABC123DEF456')).toBeInTheDocument();
      expect(window.location.href).toBe('');
    });

    it('ofrece ver la reserva', async () => {
      global.fetch.mockReturnValue(respuesta({ codigo: 'ABC123DEF456', url: null }));
      montar({ entradas: gratis });
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'Confirmar reserva' }));

      expect(await screen.findByRole('link', { name: 'Ver mi reserva' }))
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

  describe('validación del email', () => {
    /**
     * El type="email" del navegador acepta asd@asd, así que sin esto el único
     * aviso llegaba después de ir y volver del servidor.
     */
    it('avisa al salir del campo si el email está mal', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'asd@asd' } });
      fireEvent.blur(campo);

      expect(screen.getByRole('alert')).toHaveTextContent(/Revisá el email/);
    });

    it('no molesta mientras se está escribiendo', () => {
      montar();

      fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'ana@' } });

      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('no avisa nada si el campo quedó vacío', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: '' } });
      fireEvent.blur(campo);

      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('el aviso desaparece al corregirlo', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'asd@asd' } });
      fireEvent.blur(campo);
      fireEvent.change(campo, { target: { value: 'ana@example.com' } });

      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('marca el campo como inválido para quien usa lector de pantalla', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'asd@asd' } });
      fireEvent.blur(campo);

      expect(campo).toHaveAttribute('aria-invalid', 'true');
    });

    /** La confirmación va por mail: no puede salir con el email mal escrito. */
    it('no manda la compra si el email es inválido', () => {
      montar();

      fireEvent.change(screen.getByLabelText('NOMBRE Y APELLIDO'), { target: { value: 'Ana' } });
      fireEvent.change(screen.getByLabelText('EMAIL'), { target: { value: 'asd@asd' } });
      fireEvent.change(screen.getByLabelText('TELÉFONO (OPCIONAL)'), { target: { value: '1122334455' } });

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      expect(global.fetch).not.toHaveBeenCalled();
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    it('con el email bien sí sale', async () => {
      global.fetch.mockReturnValue(respuesta({ codigo: 'ABC', url: 'https://mp.test/x' }));
      montar();
      completarFormulario();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    });
  });

  describe('dominio sospechoso', () => {
    /** gmail.co pasa cualquier validación de formato y no llega nunca. */
    it('pregunta si quiso decir gmail.com', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'ana@gmail.co' } });
      fireEvent.blur(campo);

      expect(screen.getByText(/¿Quisiste decir/)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'ana@gmail.com' })).toBeInTheDocument();
    });

    it('al aceptarla se corrige el campo', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'ana@gmail.co' } });
      fireEvent.blur(campo);
      fireEvent.click(screen.getByRole('button', { name: 'ana@gmail.com' }));

      expect(screen.getByLabelText('EMAIL')).toHaveValue('ana@gmail.com');
    });

    /** .co es un dominio real: se pregunta, no se corrige solo. */
    it('no cambia el email por su cuenta', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'ana@gmail.co' } });
      fireEvent.blur(campo);

      expect(screen.getByLabelText('EMAIL')).toHaveValue('ana@gmail.co');
    });

    it('un dominio normal no dispara ninguna sugerencia', () => {
      montar();

      const campo = screen.getByLabelText('EMAIL');
      fireEvent.change(campo, { target: { value: 'ana@mi-empresa.com.ar' } });
      fireEvent.blur(campo);

      expect(screen.queryByText(/¿Quisiste decir/)).not.toBeInTheDocument();
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

  describe('con plano', () => {
    const PLANO = {
      ancho: 10,
      alto: 6,
      elementos: [{ tipo: 'fila', nombre: 'A', desde: 1, butacas: 4, x: 0, y: 0 }],
    };

    const conPlano = (overrides = {}) => montar({
      entradas: { ...ENTRADAS, plano: PLANO, ocupados: ['f:A:2'], max_por_compra: 2, ...overrides },
    });

    it('se eligen lugares en vez de cantidad', () => {
      conPlano();

      expect(screen.queryByLabelText('Cantidad')).not.toBeInTheDocument();
      expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' })).toBeInTheDocument();
    });

    it('un lugar ocupado no se puede elegir', () => {
      conPlano();

      const ocupado = screen.getByRole('checkbox', { name: 'Fila A, butaca 2, ocupado' });
      fireEvent.click(ocupado);

      expect(ocupado).toHaveAttribute('aria-checked', 'false');
    });

    it('no deja elegir más que el máximo por compra', () => {
      conPlano();

      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' }));
      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 3' }));
      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 4' }));

      expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 4' })).toHaveAttribute('aria-checked', 'false');
      expect(screen.getByText('Fila A: 1, 3')).toBeInTheDocument();
    });

    it('el total sale de los lugares elegidos', () => {
      conPlano();

      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' }));
      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 3' }));

      expect(screen.getByText(/3\.000/)).toBeInTheDocument();
    });

    it('sin lugares elegidos no se puede confirmar', () => {
      conPlano();

      expect(screen.getByRole('button', { name: 'IR A PAGAR' })).toBeDisabled();
    });

    it('manda los lugares elegidos', async () => {
      global.fetch.mockReturnValueOnce(respuesta({ codigo: 'X', url: 'https://mp.test/pagar' }));
      conPlano();
      completarFormulario();

      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 3' }));
      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      await waitFor(() => expect(global.fetch).toHaveBeenCalled());
      const cuerpo = JSON.parse(global.fetch.mock.calls[0][1].body);

      expect(cuerpo.lugares).toEqual(['f:A:3']);
      expect(cuerpo.cantidad).toBe(1);
    });

    /**
     * Si alguien se adelantó, el lugar se marca ocupado y se saca de lo
     * elegido: la persona elige otro sobre el plano de ahora.
     */
    it('si alguien se adelantó, el lugar pasa a ocupado', async () => {
      global.fetch.mockReturnValueOnce(respuesta(
        { error: 'Alguien acaba de tomar Fila A: 3. Elegí otro.', ocupados: ['f:A:2', 'f:A:3'] },
        false,
      ));
      conPlano();
      completarFormulario();

      fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 3' }));
      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      expect(await screen.findByText(/Alguien acaba de tomar/)).toBeInTheDocument();
      expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 3, ocupado' })).toHaveAttribute('aria-checked', 'false');
    });
  });

  describe('con tipos de entrada', () => {
    const TIPOS = [
      { id: 'general', nombre: 'General', precio: 8000, disponibles: 50, agotado: false },
      { id: 'jubilados', nombre: 'Jubilados', precio: 5000, disponibles: 1, agotado: false },
      { id: 'invitado', nombre: 'Invitado', precio: 0, disponibles: 50, agotado: false },
    ];

    const conTipos = (overrides = {}) => montar({
      entradas: { ...ENTRADAS, precio: 5000, tipos: TIPOS, ...overrides },
    });

    it('se elige cuántas de cada tipo en vez de una cantidad', () => {
      conTipos();

      expect(screen.queryByLabelText('Cantidad')).not.toBeInTheDocument();
      expect(screen.getByLabelText(/General/)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'IR A PAGAR' })).toBeDisabled();
    });

    /** Un tipo no ofrece más de lo que le queda. */
    it('cada tipo ofrece hasta lo que le queda', () => {
      conTipos();

      const jubilados = screen.getByLabelText(/Jubilados/);
      expect(Array.from(jubilados.options).map((o) => o.value)).toEqual(['0', '1']);
    });

    it('un tipo agotado no se puede elegir', () => {
      conTipos({ tipos: [TIPOS[0], { ...TIPOS[1], disponibles: 0, agotado: true }] });

      expect(screen.getByText('AGOTADO')).toBeInTheDocument();
      expect(screen.queryByRole('combobox', { name: /Jubilados/ })).not.toBeInTheDocument();
    });

    it('el total suma lo de cada tipo y manda cuántas de cada uno', async () => {
      global.fetch.mockReturnValueOnce(respuesta({ codigo: 'X', url: 'https://mp.test/pagar' }));
      conTipos();
      completarFormulario();

      fireEvent.change(screen.getByLabelText(/General/), { target: { value: '2' } });
      fireEvent.change(screen.getByLabelText(/Jubilados/), { target: { value: '1' } });

      expect(screen.getByText(/21\.000/)).toBeInTheDocument();
      expect(screen.getByText('2 × General')).toBeInTheDocument();

      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

      await waitFor(() => expect(global.fetch).toHaveBeenCalled());
      const cuerpo = JSON.parse(global.fetch.mock.calls[0][1].body);

      expect(cuerpo.tipos).toEqual({ general: 2, jubilados: 1 });
      expect(cuerpo.cantidad).toBe(3);
    });

    /** Sólo entradas sin costo: no hay nada que pagar, se confirma acá. */
    it('una compra que no suma nada se confirma sin ir a pagar', () => {
      conTipos();

      fireEvent.change(screen.getByLabelText(/Invitado/), { target: { value: '2' } });

      expect(screen.getByRole('button', { name: 'Confirmar reserva' })).toBeEnabled();
    });

    describe('con una promo', () => {
      const PROMO = { id: '2x1', nombre: 'Promo 2x1', precio: 8000, personas: 2, disponibles: 10, agotado: false };

      it('dice para cuántos es el precio', () => {
        conTipos({ tipos: [TIPOS[0], PROMO] });

        expect(screen.getByText(/cada 2/)).toBeInTheDocument();
      });

      it('tres personas pagan dos promos y avisa que entra una más', () => {
        conTipos({ tipos: [TIPOS[0], PROMO] });

        fireEvent.change(screen.getByLabelText(/Promo 2x1/), { target: { value: '3' } });

        expect(screen.getAllByText(/16\.000/).length).toBeGreaterThan(0);
        expect(screen.getByText(/Por el mismo precio entra 1 persona más en Promo 2x1/)).toBeInTheDocument();
      });

      it('con plano, un lugar solo en la zona paga la promo y avisa que elija otro', () => {
        conTipos({
          tipos: [TIPOS[0], PROMO],
          ocupados: [],
          plano: {
            ancho: 10, alto: 6,
            elementos: [{ tipo: 'fila', nombre: 'A', desde: 1, butacas: 4, x: 0, y: 0, entrada: '2x1' }],
          },
        });

        fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' }));
        expect(screen.getByText(/elegí otro lugar de esa zona/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 4' }));
        expect(screen.queryByText(/elegí otro lugar de esa zona/)).not.toBeInTheDocument();
        expect(screen.getByText('2 × Promo 2x1')).toBeInTheDocument();
      });
    });

    describe('con plano', () => {
      const PLANO = {
        ancho: 10,
        alto: 6,
        elementos: [
          { tipo: 'fila', nombre: 'A', desde: 1, butacas: 2, x: 0, y: 0, entrada: 'jubilados' },
          { tipo: 'fila', nombre: 'B', desde: 1, butacas: 2, x: 0, y: 1 },
        ],
      };

      it('cada lugar paga el tipo de su zona', async () => {
        global.fetch.mockReturnValueOnce(respuesta({ codigo: 'X', url: 'https://mp.test/pagar' }));
        conTipos({ plano: PLANO, ocupados: [] });
        completarFormulario();

        fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Fila B, butaca 1' }));

        expect(screen.getByText(/13\.000/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));

        await waitFor(() => expect(global.fetch).toHaveBeenCalled());
        const cuerpo = JSON.parse(global.fetch.mock.calls[0][1].body);

        expect(cuerpo.lugares).toEqual(['f:A:1', 'f:B:1']);
        expect(cuerpo.tipos).toBeUndefined();
      });

      /** El plano tiene lugar, pero ese tipo ya no se vende. */
      it('las butacas de un tipo agotado se ven ocupadas', () => {
        conTipos({
          plano: PLANO,
          ocupados: [],
          tipos: [TIPOS[0], { ...TIPOS[1], disponibles: 0, agotado: true }],
        });

        expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 1, ocupado' })).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Fila B, butaca 1' })).toBeInTheDocument();
      });
    });
  });

  describe('pixel de Meta', () => {
    const PIXEL_PAGINA = '1111111111111111';
    const PIXEL_DUENO = '2222222222222222';

    const empezarLaCompra = async (props) => {
      global.fetch.mockReturnValueOnce(respuesta({ codigo: 'X', url: 'https://mp.test/pagar' }));
      montar(props);
      completarFormulario();
      fireEvent.click(screen.getByRole('button', { name: 'IR A PAGAR' }));
      await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    };

    it('el arranque del checkout va al pixel de la página', async () => {
      window.fbq = vi.fn();

      await empezarLaCompra({ pixelId: PIXEL_PAGINA });

      expect(window.fbq).toHaveBeenCalledWith('trackSingle', PIXEL_PAGINA, 'InitiateCheckout', expect.objectContaining({
        value: 1500,
        currency: 'ARS',
        num_items: 1,
      }));
    });

    /**
     * Las entradas de un evento colaborado las vende la página dueña, y la
     * compra se registra en su pixel: el arranque tiene que ir al mismo, o
     * ninguna de las dos cuentas puede comparar quién empezó y quién compró.
     */
    it('en un evento colaborado va al pixel de la página que vende', async () => {
      window.fbq = vi.fn();

      await empezarLaCompra({
        evento: { ...EVENTO, source_page_pixel: PIXEL_DUENO },
        pixelId: PIXEL_PAGINA,
      });

      expect(window.fbq).toHaveBeenCalledWith('trackSingle', PIXEL_DUENO, 'InitiateCheckout', expect.anything());
    });

    it('sin pixel no se mide nada', async () => {
      window.fbq = vi.fn();

      await empezarLaCompra();

      expect(window.fbq).not.toHaveBeenCalled();
    });
  });
});
