import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import ComprarProducto from '../../src/components/ComprarProducto';
import { mockFetch, llamadaA, cuerpoDe } from '../helpers/api';
import { tokenDeDescarga } from '../../src/utils/productos';

/**
 * El formulario de compra de un físico (#43): opciones y cantidad, comprador
 * con teléfono obligatorio, dirección, horarios, y el resumen de total con
 * envío antes de pagar.
 */

const API = 'https://api.test/api';

// Talle S (1), M (2); Color Negro (3), Blanco (4). M/Blanco está agotada.
const remera = (overrides = {}) => ({
  id: 500,
  text: 'Remera',
  precio: 2500,
  tipo: 'fisico',
  costo_envio: 500,
  zona_envio: 'Sólo CABA y GBA',
  caracteristicas: [
    { id: 10, nombre: 'Talle', opciones: [{ id: 1, nombre: 'S' }, { id: 2, nombre: 'M' }] },
    { id: 20, nombre: 'Color', opciones: [{ id: 3, nombre: 'Negro' }, { id: 4, nombre: 'Blanco' }] },
  ],
  agotadas: ['2-4'],
  agotado: false,
  ...overrides,
});

const montar = (producto = remera(), props = {}) => render(
  <ComprarProducto producto={producto} apiUrl={API} onCerrar={vi.fn()} {...props} />
);

const opcion = (grupo, nombre) => within(screen.getByRole('radiogroup', { name: grupo }))
  .getByRole('radio', { name: new RegExp(`^${nombre}`) });

const escribir = (etiqueta, valor) => fireEvent.change(screen.getByLabelText(etiqueta), { target: { value: valor } });

function completar({ opciones = true, horarios = true } = {}) {
  if (opciones) {
    fireEvent.click(opcion('Talle', 'M'));
    fireEvent.click(opcion('Color', 'Negro'));
  }
  escribir('NOMBRE Y APELLIDO', 'Ana Gómez');
  escribir('EMAIL', 'ana@example.com');
  escribir('TELÉFONO', '11 5555-1234');
  escribir('CALLE Y NÚMERO', 'Av. Corrientes 1234');
  escribir('PISO Y DEPTO (OPCIONAL)', '3° B');
  escribir('LOCALIDAD', 'CABA');
  escribir('PROVINCIA', 'Ciudad Autónoma de Buenos Aires');
  escribir('CÓDIGO POSTAL', 'C1043AAZ');
  escribir('INDICACIONES (OPCIONAL)', 'Timbre B');
  if (horarios) {
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sábado' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Lunes' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Tarde' }));
  }
  escribir('COMENTARIO (OPCIONAL)', 'Después de las 18');
}

const enviar = () => fireEvent.submit(screen.getByLabelText('EMAIL').closest('form'));
const resumen = () => screen.getByLabelText('Resumen');

describe('Comprar un producto físico', () => {
  beforeEach(() => {
    delete window.location;
    window.location = { href: '' };
    window.localStorage.clear();
  });

  it('muestra la zona de envío arriba de la dirección', () => {
    montar();

    const zona = screen.getByText(/Zona de envío/).closest('p');
    expect(zona).toHaveTextContent('Sólo CABA y GBA');
    expect(zona.compareDocumentPosition(screen.getByLabelText('CALLE Y NÚMERO')))
      .toBe(Node.DOCUMENT_POSITION_FOLLOWING);
  });

  it('con una sola forma de entrega no pregunta cuál', () => {
    montar();

    expect(screen.queryByRole('group', { name: '¿CÓMO LO RECIBÍS?' })).not.toBeInTheDocument();
    expect(screen.getByLabelText('CALLE Y NÚMERO')).toBeInTheDocument();
  });

  it('sin zona de envío no muestra el aviso', () => {
    montar(remera({ zona_envio: null }));

    expect(screen.queryByText(/Zona de envío/)).not.toBeInTheDocument();
  });

  it('pide todos los datos: teléfono obligatorio, dirección, provincia con las 24 y horarios', () => {
    montar();

    expect(screen.getByLabelText('TELÉFONO')).toBeRequired();
    expect(screen.getByLabelText('CALLE Y NÚMERO')).toBeRequired();
    expect(screen.getByLabelText('PISO Y DEPTO (OPCIONAL)')).not.toBeRequired();
    expect(screen.getByLabelText('LOCALIDAD')).toBeRequired();
    expect(screen.getByLabelText('CÓDIGO POSTAL')).toBeRequired();
    expect(screen.getByLabelText('INDICACIONES (OPCIONAL)')).not.toBeRequired();
    // "Elegí…" y las 24.
    expect(within(screen.getByLabelText('PROVINCIA')).getAllByRole('option')).toHaveLength(25);
    expect(screen.getAllByRole('checkbox')).toHaveLength(10);
    expect(within(screen.getByLabelText('CANTIDAD')).getAllByRole('option').map((o) => o.value))
      .toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
  });

  it('el resumen es precio × cantidad + envío = total, y cambia con la cantidad', () => {
    montar();

    expect(resumen()).toHaveTextContent('$ 2.500 × 1$ 2.500');
    expect(resumen()).toHaveTextContent('Envío$ 500');
    expect(resumen()).toHaveTextContent('Total$ 3.000');

    escribir('CANTIDAD', '3');

    expect(resumen()).toHaveTextContent('$ 2.500 × 3$ 7.500');
    expect(resumen()).toHaveTextContent('Total$ 8.000');
  });

  it('con envío incluido el resumen lo dice', () => {
    montar(remera({ costo_envio: 0 }));

    expect(resumen()).toHaveTextContent('EnvíoIncluido');
    expect(resumen()).toHaveTextContent('Total$ 2.500');
  });

  it('trae marcadas las opciones elegidas en el detalle', () => {
    montar(remera(), { seleccionInicial: { 10: 2, 20: 3 } });

    expect(opcion('Talle', 'M')).toHaveAttribute('aria-checked', 'true');
    expect(opcion('Color', 'Negro')).toHaveAttribute('aria-checked', 'true');
    // M/Blanco está agotada: no se puede elegir.
    expect(opcion('Color', 'Blanco')).toBeDisabled();
  });

  describe('validaciones', () => {
    beforeEach(() => {
      mockFetch({});
    });

    const errorAlEnviar = () => {
      enviar();
      return screen.getByRole('alert');
    };

    it('hay que elegir las opciones', () => {
      montar();
      completar({ opciones: false });

      expect(errorAlEnviar()).toHaveTextContent('Elegí talle y color');
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('el teléfono es obligatorio y tiene que servir', () => {
      montar();
      completar();
      escribir('TELÉFONO', '');
      expect(errorAlEnviar()).toHaveTextContent('Falta el teléfono');

      escribir('TELÉFONO', '123');
      expect(errorAlEnviar()).toHaveTextContent('El teléfono no es válido');
    });

    it('la dirección: calle, localidad, provincia y código postal', () => {
      montar();
      completar();

      escribir('CALLE Y NÚMERO', ' ');
      expect(errorAlEnviar()).toHaveTextContent('Falta la calle y el número');
      escribir('CALLE Y NÚMERO', 'Av. Corrientes 1234');

      escribir('LOCALIDAD', '');
      expect(errorAlEnviar()).toHaveTextContent('Falta la localidad');
      escribir('LOCALIDAD', 'CABA');

      escribir('PROVINCIA', '');
      expect(errorAlEnviar()).toHaveTextContent('Elegí la provincia');
      escribir('PROVINCIA', 'Córdoba');

      escribir('CÓDIGO POSTAL', '50');
      expect(errorAlEnviar()).toHaveTextContent('El código postal no es válido');
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('al menos un día y una franja', () => {
      montar();
      completar({ horarios: false });
      expect(errorAlEnviar()).toHaveTextContent('al menos un día');

      fireEvent.click(screen.getByRole('checkbox', { name: 'Domingo' }));
      expect(errorAlEnviar()).toHaveTextContent('al menos una franja');
      expect(global.fetch).not.toHaveBeenCalled();
    });
  });

  it('con todo completo manda el pedido y lleva a Mercado Pago', async () => {
    const { llamadas } = mockFetch({
      'public/comprar-producto.php': {
        status: 201,
        body: { codigo: 'AAAABBBBCCCC', estado: 'pendiente', url: 'https://mp.test/pagar', total: 5500 },
      },
    });
    montar();
    completar();
    escribir('CANTIDAD', '2');

    expect(screen.getByRole('button', { name: 'IR A PAGAR' })).toBeInTheDocument();
    enviar();

    await vi.waitFor(() => expect(window.location.href).toBe('https://mp.test/pagar'));
    expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php'))).toEqual({
      link_id: 500,
      nombre: 'Ana Gómez',
      email: 'ana@example.com',
      telefono: '11 5555-1234',
      cantidad: 2,
      opciones: [2, 3],
      forma_entrega: 'envio',
      envio: {
        calle: 'Av. Corrientes 1234', piso_depto: '3° B', localidad: 'CABA',
        provincia: 'Ciudad Autónoma de Buenos Aires', codigo_postal: 'C1043AAZ', indicaciones: 'Timbre B',
      },
      // En el orden de la semana, aunque se marcaron al revés.
      horarios: { dias: ['lunes', 'sabado'], franjas: ['tarde'], comentario: 'Después de las 18' },
    });
    expect(tokenDeDescarga('AAAABBBBCCCC')).toBeNull();
  });

  it('un producto sin características no pide elegir nada', async () => {
    const { llamadas } = mockFetch({
      'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', url: 'https://mp.test/pagar' } },
    });
    montar(remera({ caracteristicas: [], agotadas: [] }));
    completar({ opciones: false });
    enviar();

    await vi.waitFor(() => expect(window.location.href).toBe('https://mp.test/pagar'));
    expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php')).opciones).toEqual([]);
  });

  it('gratis y con envío incluido: confirma el pedido sin pasar por Mercado Pago', async () => {
    mockFetch({
      'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', estado: 'pagada', url: null } },
    });
    montar(remera({ precio: 0, costo_envio: 0 }));
    completar();

    expect(resumen()).toHaveTextContent('TotalGratis');
    fireEvent.click(screen.getByRole('button', { name: 'CONFIRMAR PEDIDO' }));

    const listo = await screen.findByRole('status');
    expect(listo).toHaveTextContent('Recibimos tu pedido');
    expect(within(listo).getByRole('link', { name: 'Seguir mi pedido' })).toHaveAttribute('href', '/compra/AAAABBBBCCCC');
  });

  // ------------------------------------------------ formas de entrega (#53)

  describe('formas de entrega', () => {
    const retiro = {
      direccion: 'Av. Corrientes 1234, CABA',
      maps_url: 'https://maps.google.com/?cid=1',
      indicaciones: 'Lunes a viernes de 10 a 18, timbre 3',
    };
    const conTres = (overrides = {}) => remera({ formas_entrega: ['envio', 'retiro', 'coordinar'], retiro, ...overrides });
    const formas = () => screen.getByRole('group', { name: '¿CÓMO LO RECIBÍS?' });
    const elegir = (nombre) => fireEvent.click(within(formas()).getByRole('button', { name: nombre }));

    it('con varias, se elige una con píldoras y hasta entonces no se pide dirección', () => {
      montar(conTres());

      expect(within(formas()).getAllByRole('button').map((b) => b.textContent)).toEqual([
        'Envío a domicilio ($ 500)', 'Retiro en un lugar', 'Coordinar después de la compra',
      ]);
      expect(screen.queryByLabelText('CALLE Y NÚMERO')).not.toBeInTheDocument();

      elegir('Envío a domicilio ($ 500)');
      expect(within(formas()).getByRole('button', { name: 'Envío a domicilio ($ 500)' })).toHaveAttribute('aria-pressed', 'true');
      expect(screen.getByLabelText('CALLE Y NÚMERO')).toBeRequired();
    });

    it('sin elegir la forma no se puede enviar', () => {
      mockFetch({});
      montar(conTres());
      fireEvent.click(opcion('Talle', 'M'));
      fireEvent.click(opcion('Color', 'Negro'));
      escribir('NOMBRE Y APELLIDO', 'Ana Gómez');
      escribir('EMAIL', 'ana@example.com');
      escribir('TELÉFONO', '11 5555-1234');
      enviar();

      expect(screen.getByRole('alert')).toHaveTextContent('Elegí cómo querés recibir el pedido');
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it('con retiro muestra el punto, no pide dirección y no suma el envío', () => {
      montar(conTres());
      elegir('Retiro en un lugar');

      expect(screen.queryByLabelText('CALLE Y NÚMERO')).not.toBeInTheDocument();
      expect(screen.queryByText(/Zona de envío/)).not.toBeInTheDocument();
      expect(screen.getByRole('link', { name: retiro.direccion })).toHaveAttribute('href', retiro.maps_url);
      expect(screen.getByText(retiro.indicaciones)).toBeInTheDocument();
      expect(resumen()).not.toHaveTextContent('Envío');
      expect(resumen()).toHaveTextContent('Total$ 2.500');
    });

    it('con coordinar avisa que quien vende contacta y no suma el envío', () => {
      montar(conTres());
      elegir('Coordinar después de la compra');

      expect(screen.getByText(/quien vende te contacta para coordinar la entrega/)).toBeInTheDocument();
      expect(screen.queryByLabelText('CALLE Y NÚMERO')).not.toBeInTheDocument();
      expect(screen.getByLabelText('TELÉFONO')).toBeRequired();
      expect(resumen()).toHaveTextContent('Total$ 2.500');
    });

    it('el resumen suma el envío sólo si se eligió envío', () => {
      montar(conTres());

      elegir('Envío a domicilio ($ 500)');
      expect(resumen()).toHaveTextContent('Envío$ 500');
      expect(resumen()).toHaveTextContent('Total$ 3.000');

      elegir('Retiro en un lugar');
      expect(resumen()).toHaveTextContent('Total$ 2.500');
    });

    it('con una sola forma que no es envío se usa ésa sin preguntar', () => {
      montar(remera({ formas_entrega: ['retiro'], retiro }));

      expect(screen.queryByRole('group', { name: '¿CÓMO LO RECIBÍS?' })).not.toBeInTheDocument();
      expect(screen.getByRole('link', { name: retiro.direccion })).toBeInTheDocument();
      expect(resumen()).toHaveTextContent('Total$ 2.500');
    });

    it('con retiro manda la forma sin dirección ni horarios', async () => {
      const { llamadas } = mockFetch({
        'public/comprar-producto.php': { status: 201, body: { codigo: 'AAAABBBBCCCC', url: 'https://mp.test/pagar' } },
      });
      montar(conTres());
      elegir('Retiro en un lugar');
      fireEvent.click(opcion('Talle', 'M'));
      fireEvent.click(opcion('Color', 'Negro'));
      escribir('NOMBRE Y APELLIDO', 'Ana Gómez');
      escribir('EMAIL', 'ana@example.com');
      escribir('TELÉFONO', '11 5555-1234');
      enviar();

      await vi.waitFor(() => expect(window.location.href).toBe('https://mp.test/pagar'));
      expect(cuerpoDe(llamadaA(llamadas, 'comprar-producto.php'))).toEqual({
        link_id: 500, nombre: 'Ana Gómez', email: 'ana@example.com', telefono: '11 5555-1234',
        cantidad: 1, opciones: [2, 3], forma_entrega: 'retiro',
      });
    });
  });

  it('si ya no queda stock suficiente, muestra lo que dice la API', async () => {
    mockFetch({
      'public/comprar-producto.php': {
        status: 409,
        body: { error: 'No queda stock suficiente para esa cantidad. Probá con menos unidades o con otra opción.' },
      },
    });
    montar();
    completar();
    enviar();

    expect(await screen.findByRole('alert')).toHaveTextContent('No queda stock suficiente para esa cantidad');
  });
});
