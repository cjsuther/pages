import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { HelmetProvider } from 'react-helmet-async';

// La cámara no existe en jsdom: el escáner falso expone un botón que "lee" un QR.
vi.mock('../../src/components/EscanerQr', () => ({
  default: ({ onLeer, pausado }) => (
    <button type="button" disabled={pausado} onClick={() => onLeer('https://rezon.ar/entrada/ABC123DEF456')}>
      simular QR
    </button>
  ),
}));

import Puerta from '../../src/pages/Puerta';

const CLAVE = 'c'.repeat(32);

const ESTADO = {
  evento: { text: 'Fiesta de fin de año', event_date: '2026-12-31', event_time: '22:00:00', pagina: 'Club' },
  resumen: { entradas: 5, ingresadas: 1, compras: 2 },
  ordenes: [
    { codigo: 'ABC123DEF456', nombre: 'Ana Gómez', cantidad: 2, ingresadas: 0, restantes: 2, ingreso_en: null, lugares: ['f:A:7', 'f:A:8'] },
    { codigo: 'CCC333DDD444', nombre: 'Beto Pérez', cantidad: 3, ingresadas: 1, restantes: 2, ingreso_en: null, lugares: [] },
  ],
};

const VALIDA = { resultado: 'valida', orden: ESTADO.ordenes[0] };

function respuesta(cuerpo, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(cuerpo) });
}

function cuerpoDe(llamada) {
  return JSON.parse(global.fetch.mock.calls[llamada][1].body);
}

async function montar() {
  const vista = render(
    <HelmetProvider>
      <Puerta apiUrl="https://api.test/api" />
    </HelmetProvider>,
  );
  await screen.findByText('Fiesta de fin de año');
  return vista;
}

describe('Puerta', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    window.location.hash = `#${CLAVE}`;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  /** La clave va en el cuerpo del pedido, nunca en la URL. */
  it('manda la clave del link en el cuerpo, no en la dirección', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    expect(global.fetch.mock.calls[0][0]).toBe('https://api.test/api/public/puerta.php');
    expect(cuerpoDe(0)).toEqual({ clave: CLAVE, accion: 'estado' });
  });

  it('muestra cuántos entraron', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    expect(screen.getByText('Entraron').nextSibling.textContent).toBe('1 de 5');
  });

  it('sin clave no pide nada y lo dice', async () => {
    window.location.hash = '';
    render(<HelmetProvider><Puerta apiUrl="https://api.test/api" /></HelmetProvider>);

    expect(await screen.findByText(/link de puerta está incompleto/)).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('un link revocado muestra el error del servidor', async () => {
    global.fetch.mockReturnValueOnce(respuesta({ error: 'Este link de puerta no es válido.' }, false));
    render(<HelmetProvider><Puerta apiUrl="https://api.test/api" /></HelmetProvider>);

    expect(await screen.findByText('Este link de puerta no es válido.')).toBeInTheDocument();
  });

  it('busca en la lista por lugar', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    fireEvent.change(screen.getByLabelText('Buscar por nombre, código o lugar'), { target: { value: 'fila a' } });

    expect(screen.getByText('Ana Gómez')).toBeInTheDocument();
    expect(screen.queryByText('Beto Pérez')).not.toBeInTheDocument();
  });

  it('escanear un QR muestra la entrada y deja marcar el ingreso de todos', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    fireEvent.click(screen.getByRole('button', { name: 'Escanear QR' }));
    global.fetch.mockReturnValueOnce(respuesta(VALIDA));
    fireEvent.click(screen.getByRole('button', { name: 'simular QR' }));

    expect(await screen.findByRole('dialog', { name: 'Entrada válida' })).toBeInTheDocument();
    expect(cuerpoDe(1)).toMatchObject({ accion: 'mirar', codigo: 'ABC123DEF456' });

    global.fetch
      .mockReturnValueOnce(respuesta({ ok: true, resultado: 'ya_entro', orden: { ...VALIDA.orden, ingresadas: 2, restantes: 0 } }))
      .mockReturnValueOnce(respuesta(ESTADO));
    fireEvent.click(screen.getByRole('button', { name: 'Marcar que entraron 2' }));

    expect(await screen.findByRole('dialog', { name: '¡Adelante!' })).toBeInTheDocument();
    expect(cuerpoDe(2)).toMatchObject({ accion: 'ingresar', codigo: 'ABC123DEF456', cantidad: 2 });
  });

  /** Una compra de tres puede llegar en tandas: se elige cuántos pasan ahora. */
  it('se puede marcar sólo una parte de la compra', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    global.fetch.mockReturnValueOnce(respuesta(VALIDA));
    fireEvent.click(screen.getByText('Ana Gómez'));
    await screen.findByRole('dialog', { name: 'Entrada válida' });

    fireEvent.change(screen.getByLabelText('¿Cuántos entran ahora?'), { target: { value: '1' } });
    global.fetch
      .mockReturnValueOnce(respuesta({ ok: true, ...VALIDA }))
      .mockReturnValueOnce(respuesta(ESTADO));
    fireEvent.click(screen.getByRole('button', { name: 'Marcar que entró' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(4));
    expect(cuerpoDe(2).cantidad).toBe(1);
  });

  it('una entrada que ya entró se ve en amarillo con la hora, sin botón para marcar', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    global.fetch.mockReturnValueOnce(respuesta({
      resultado: 'ya_entro',
      orden: { ...VALIDA.orden, ingresadas: 2, restantes: 0, ingreso_en: '2026-12-31 22:14:00' },
    }));
    fireEvent.click(screen.getByText('Ana Gómez'));

    expect(await screen.findByRole('dialog', { name: 'Ya entró' })).toBeInTheDocument();
    expect(screen.getByText('Entraron las 2 a las 22:14.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Marcar que/ })).not.toBeInTheDocument();
  });

  it('una entrada de otro evento no se puede marcar', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    fireEvent.click(screen.getByRole('button', { name: 'Escanear QR' }));
    global.fetch.mockReturnValueOnce(respuesta({ resultado: 'otro_evento', orden: null, evento: 'Otro show' }));
    fireEvent.click(screen.getByRole('button', { name: 'simular QR' }));

    expect(await screen.findByRole('dialog', { name: 'Es de otro evento' })).toBeInTheDocument();
    expect(screen.getByText(/Otro show/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Marcar que/ })).not.toBeInTheDocument();
  });

  /** Con el resultado en pantalla, el mismo QR delante de la cámara no se vuelve a leer. */
  it('mientras se muestra una entrada, el escáner no lee', async () => {
    global.fetch.mockReturnValueOnce(respuesta(ESTADO));
    await montar();

    fireEvent.click(screen.getByRole('button', { name: 'Escanear QR' }));
    global.fetch.mockReturnValueOnce(respuesta(VALIDA));
    fireEvent.click(screen.getByRole('button', { name: 'simular QR' }));
    await screen.findByRole('dialog');

    expect(screen.getByRole('button', { name: 'simular QR' })).toBeDisabled();
  });
});
