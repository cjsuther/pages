import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import ModificarCompra from '../../src/components/ModificarCompra';

const ORDEN = {
  codigo: 'ABC123DEF456',
  nombre: 'Ana Gómez',
  email: 'ana@example.com',
  evento: 'Noche de Prueba',
  cantidad: 2,
  ingresadas: 0,
  total: 3000,
  moneda: 'ARS',
  lugares: [],
  items: [],
};

const PLANO = {
  ancho: 10, alto: 6,
  elementos: [{ tipo: 'fila', nombre: 'A', butacas: 4, desde: 1, x: 0, y: 0 }],
};

function respuestaDe(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

function datos(extra = {}) {
  return { orden: ORDEN, disponibles: 5, plano: null, ocupados: [], tipos: null, ...extra };
}

async function montar(cuerpo = datos(), props = {}) {
  global.fetch.mockReturnValueOnce(respuestaDe(cuerpo));
  const onModificada = vi.fn();
  const onCerrar = vi.fn();

  render(
    <ModificarCompra
      codigo="ABC123DEF456"
      apiUrl="https://api.test/api"
      token="tok"
      onCerrar={onCerrar}
      onModificada={onModificada}
      {...props}
    />
  );
  await waitFor(() => expect(screen.queryByText('Cargando la compra...')).not.toBeInTheDocument());

  return { onModificada, onCerrar };
}

/** El cuerpo del POST que se mandó. */
function enviado() {
  const llamada = global.fetch.mock.calls.find(([, opciones]) => opciones && opciones.method === 'POST');
  return JSON.parse(llamada[1].body);
}

describe('ModificarCompra', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('pide la compra con el código', async () => {
    await montar();

    expect(global.fetch.mock.calls[0][0]).toBe('https://api.test/api/entradas/modificar.php?codigo=ABC123DEF456');
    expect(screen.getByText(/Ana Gómez/)).toBeInTheDocument();
  });

  it('avisa que no se cobra y que le llega un mail a quien compró', async () => {
    await montar();

    expect(screen.getByText(/No se cobra ni se devuelve nada/)).toHaveTextContent('ana@example.com');
  });

  it('sube la cantidad y la guarda', async () => {
    const { onModificada } = await montar();
    const respuesta = { modificada: true, mail: { enviado: true }, ventas: { ordenes: [], resumen: {} } };
    global.fetch.mockReturnValueOnce(respuestaDe(respuesta));

    fireEvent.click(screen.getByRole('button', { name: 'Más Entradas' }));
    fireEvent.click(screen.getByRole('button', { name: 'Guardar y avisar' }));

    await waitFor(() => expect(onModificada).toHaveBeenCalledWith(respuesta));
    expect(enviado()).toEqual({ codigo: 'ABC123DEF456', cantidad: 3 });
  });

  it('no deja subir más de lo disponible', async () => {
    await montar(datos({ disponibles: 2 }));

    expect(screen.getByRole('button', { name: 'Más Entradas' })).toBeDisabled();
  });

  it('no deja bajar por debajo de las personas que ya entraron', async () => {
    await montar(datos({ orden: { ...ORDEN, cantidad: 3, ingresadas: 3 } }));

    expect(screen.getByRole('button', { name: 'Menos Entradas' })).toBeDisabled();
    expect(screen.getByText(/Ya entraron 3 personas/)).toBeInTheDocument();
  });

  it('con tipos manda cuántas de cada uno, en personas', async () => {
    await montar(datos({
      orden: { ...ORDEN, items: [{ tipo: 'general', nombre: 'General', precio: 8000, personas: 1, cantidad: 2 }] },
      tipos: [
        { id: 'general', nombre: 'General', precio: 8000, personas: 1, disponibles: 5 },
        { id: 'promo', nombre: 'Promo 2x1', precio: 10000, personas: 2, disponibles: 4 },
      ],
    }));
    global.fetch.mockReturnValueOnce(respuestaDe({ mail: { enviado: true }, ventas: {} }));

    fireEvent.click(screen.getByRole('button', { name: 'Menos General' }));
    fireEvent.click(screen.getByRole('button', { name: 'Más Promo 2x1' }));
    fireEvent.click(screen.getByRole('button', { name: 'Guardar y avisar' }));

    await waitFor(() => expect(enviado()).toEqual({ codigo: 'ABC123DEF456', tipos: { general: 1, promo: 2 } }));
  });

  it('con plano parte de los lugares de la compra y manda los nuevos', async () => {
    await montar(datos({ plano: PLANO, ocupados: ['f:A:3'], orden: { ...ORDEN, lugares: ['f:A:1', 'f:A:2'] } }));
    global.fetch.mockReturnValueOnce(respuestaDe({ mail: { enviado: true }, ventas: {} }));

    expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 3, ocupado' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 1' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 4' }));
    fireEvent.click(screen.getByRole('button', { name: 'Guardar y avisar' }));

    await waitFor(() => expect(enviado()).toEqual({ codigo: 'ABC123DEF456', lugares: ['f:A:2', 'f:A:4'] }));
  });

  it('si alguien tomó un lugar, muestra el error y lo suelta', async () => {
    await montar(datos({ plano: PLANO, orden: { ...ORDEN, lugares: ['f:A:1'] } }));
    global.fetch.mockReturnValueOnce(respuestaDe({ error: 'Ese lugar ya es de otra compra: A4. Elegí otro.', ocupados: ['f:A:4'] }, false));

    fireEvent.click(screen.getByRole('checkbox', { name: 'Fila A, butaca 4' }));
    fireEvent.click(screen.getByRole('button', { name: 'Guardar y avisar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Ese lugar ya es de otra compra');
    expect(screen.getByRole('checkbox', { name: 'Fila A, butaca 4, ocupado' })).toHaveAttribute('aria-checked', 'false');
  });

  it('muestra por qué no se pudo abrir la compra', async () => {
    global.fetch.mockReturnValueOnce(respuestaDe({ error: 'Sólo se pueden modificar compras pagadas o reservas confirmadas' }, false));

    render(<ModificarCompra codigo="X" apiUrl="https://api.test/api" token="tok" onCerrar={() => {}} onModificada={() => {}} />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Sólo se pueden modificar compras pagadas');
    expect(screen.queryByRole('button', { name: 'Guardar y avisar' })).not.toBeInTheDocument();
  });

  it('volver cierra sin guardar', async () => {
    const { onCerrar } = await montar();

    fireEvent.click(screen.getByRole('button', { name: 'Volver' }));

    expect(onCerrar).toHaveBeenCalled();
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });
});
