import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn(() => Promise.resolve('data:image/png;base64,QR')) },
}));

import PanelInvitados from '../../src/components/PanelInvitados';

const RESUMEN = {
  configurado: true,
  lugares: 0,
  ingresaron: 0,
  capacidad: 100,
  disponibles: 80,
  max_invitados: null,
  quedan_del_limite: null,
  plano: null,
  ocupados: [],
  tipos: null,
};

const ANA = {
  id: 1,
  codigo: 'ABC123DEF456',
  nombre: 'Ana Gómez',
  email: 'ana@test.local',
  telefono: '',
  cantidad: 2,
  ingresadas: 0,
  estado: 'vigente',
  lugares: [],
  tipos: '',
  invitado_por: 'Dueña',
  url: 'https://rezon.ar/entrada/ABC123DEF456',
};

function respuesta(cuerpo, status = 200) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(cuerpo) });
}

/**
 * fetch por método: el mismo endpoint lista (GET), agrega (POST) y fija el
 * límite (PUT). Cada clave es "MÉTODO fragmento-de-url".
 */
function servidor(rutas) {
  const llamadas = [];

  global.fetch = vi.fn((url, opciones = {}) => {
    const metodo = opciones.method || 'GET';
    llamadas.push({ url, metodo, cuerpo: opciones.body ? JSON.parse(opciones.body) : null });

    const clave = Object.keys(rutas).find((k) => {
      const [m, fragmento] = k.split(' ');
      return m === metodo && url.includes(fragmento);
    });

    if (!clave) return Promise.reject(new Error(`fetch no declarado: ${metodo} ${url}`));

    const r = rutas[clave];
    return typeof r === 'function' ? r() : respuesta(r.body, r.status);
  });

  return llamadas;
}

async function montar() {
  render(<PanelInvitados linkId={10} apiUrl="https://api.test/api" token="tok" nombreEvento="La Noche" />);
  await waitFor(() => expect(screen.queryByText('Cargando invitados...')).not.toBeInTheDocument());
}

describe('PanelInvitados', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('muestra cuántos lugares quedan en el evento', async () => {
    servidor({ 'GET invitados.php': { body: { invitados: [ANA], resumen: { ...RESUMEN, lugares: 2, disponibles: 78 } } } });

    await montar();

    expect(screen.getByText('78/100')).toBeInTheDocument();
    expect(screen.getByText('Ana Gómez')).toBeInTheDocument();
    expect(screen.getByText(/Los invitados descuentan de la capacidad/)).toBeInTheDocument();
  });

  it('sin entradas configuradas pide configurarlas primero', async () => {
    servidor({ 'GET invitados.php': { body: { invitados: [], resumen: { ...RESUMEN, configurado: false } } } });

    await montar();

    expect(screen.getByText(/primero configurá las entradas/)).toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Agregar invitado' })).not.toBeInTheDocument();
  });

  it('si el servidor no lo deja, lo dice', async () => {
    servidor({
      'GET invitados.php': { status: 403, body: { error: 'Sólo quien administra la página del evento puede manejar sus invitados' } },
    });

    await montar();

    expect(screen.getByText(/Sólo quien administra la página/)).toBeInTheDocument();
  });

  it('agrega un invitado con sus acompañantes y avisa que le llegó el mail', async () => {
    const llamadas = servidor({
      'GET invitados.php': { body: { invitados: [], resumen: RESUMEN } },
      'POST invitados.php': {
        status: 201,
        body: { invitado: ANA, mail: { enviado: true }, invitados: [ANA], resumen: { ...RESUMEN, lugares: 2, disponibles: 78 } },
      },
    });

    await montar();

    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'Ana Gómez' } });
    fireEvent.change(screen.getByLabelText('Email (opcional)'), { target: { value: 'ana@test.local' } });
    fireEvent.change(screen.getByLabelText('Lugares'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Agregar invitado' }));

    await screen.findByText(/Se la mandamos a ana@test.local/);

    const alta = llamadas.find((l) => l.metodo === 'POST');
    // Nada de precio ni de pago: el servidor lo crea sin costo.
    expect(alta.cuerpo).toEqual({ link_id: 10, nombre: 'Ana Gómez', email: 'ana@test.local', telefono: '', cantidad: 2 });
    expect(screen.getByText('78/100')).toBeInTheDocument();
  });

  it('sin email, sugiere pasarle el link', async () => {
    const sinMail = { ...ANA, email: '' };
    servidor({
      'GET invitados.php': { body: { invitados: [], resumen: RESUMEN } },
      'POST invitados.php': { status: 201, body: { invitado: sinMail, mail: { enviado: false, motivo: 'sin email' }, invitados: [sinMail], resumen: RESUMEN } },
    });

    await montar();

    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'Ana Gómez' } });
    fireEvent.click(screen.getByRole('button', { name: 'Agregar invitado' }));

    expect(await screen.findByText(/Pasale el link por WhatsApp o mostrale el QR/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Reenviar/ })).not.toBeInTheDocument();
  });

  it('sin nombre no manda nada', async () => {
    const llamadas = servidor({ 'GET invitados.php': { body: { invitados: [], resumen: RESUMEN } } });

    await montar();
    fireEvent.click(screen.getByRole('button', { name: 'Agregar invitado' }));

    expect(screen.getByRole('alert')).toHaveTextContent('Falta el nombre');
    expect(llamadas.filter((l) => l.metodo === 'POST')).toHaveLength(0);
  });

  it('muestra el error del servidor cuando no hay lugar', async () => {
    servidor({
      'GET invitados.php': { body: { invitados: [], resumen: RESUMEN } },
      'POST invitados.php': { status: 409, body: { error: 'Sólo quedan 1 lugares en el evento' } },
    });

    await montar();
    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'Ana' } });
    fireEvent.click(screen.getByRole('button', { name: 'Agregar invitado' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Sólo quedan 1 lugares');
  });

  it('con el evento lleno no deja agregar', async () => {
    servidor({ 'GET invitados.php': { body: { invitados: [], resumen: { ...RESUMEN, disponibles: 0 } } } });

    await montar();

    expect(screen.getByText('No quedan lugares en el evento.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Agregar invitado' })).toBeDisabled();
  });

  it('con tipos de entrada, manda el tipo elegido', async () => {
    const tipos = [{ id: 'general', nombre: 'General' }, { id: 'vip', nombre: 'VIP' }];
    const llamadas = servidor({
      'GET invitados.php': { body: { invitados: [], resumen: { ...RESUMEN, tipos } } },
      'POST invitados.php': { status: 201, body: { invitado: ANA, mail: { enviado: true }, invitados: [ANA], resumen: { ...RESUMEN, tipos } } },
    });

    await montar();
    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'Ana' } });
    fireEvent.change(screen.getByLabelText('Tipo de entrada'), { target: { value: 'vip' } });
    fireEvent.click(screen.getByRole('button', { name: 'Agregar invitado' }));

    await waitFor(() => expect(llamadas.some((l) => l.metodo === 'POST')).toBe(true));
    expect(llamadas.find((l) => l.metodo === 'POST').cuerpo.tipo).toBe('vip');
  });

  it('con plano, pide elegir los lugares', async () => {
    const plano = {
      ancho: 10, alto: 6,
      elementos: [{ tipo: 'fila', nombre: 'A', butacas: 4, desde: 1, x: 0, y: 0 }],
    };
    const llamadas = servidor({ 'GET invitados.php': { body: { invitados: [], resumen: { ...RESUMEN, plano } } } });

    await montar();

    expect(screen.queryByLabelText('Lugares')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Nombre y apellido'), { target: { value: 'Ana' } });
    fireEvent.click(screen.getByRole('button', { name: 'Agregar invitado' }));

    expect(screen.getByRole('alert')).toHaveTextContent('Elegí sus lugares en el plano');
    expect(llamadas.filter((l) => l.metodo === 'POST')).toHaveLength(0);
  });

  it('anular pide confirmación y deja al invitado anulado', async () => {
    const llamadas = servidor({
      'GET invitados.php': { body: { invitados: [ANA], resumen: { ...RESUMEN, lugares: 2 } } },
      'POST invitado.php': { body: { anulado: true, invitados: [{ ...ANA, estado: 'anulado' }], resumen: RESUMEN } },
    });

    await montar();
    fireEvent.click(screen.getByRole('button', { name: 'Anular a Ana Gómez' }));

    const dialogo = screen.getByRole('dialog', { name: 'Anular invitado' });
    expect(within(dialogo).getByText(/Su QR deja de valer/)).toBeInTheDocument();
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Anular la entrada' }));

    expect(await screen.findByText(/su QR ya no vale/)).toBeInTheDocument();
    expect(llamadas.find((l) => l.url.includes('invitado.php')).cuerpo).toEqual({ codigo: 'ABC123DEF456', accion: 'anular' });
    expect(screen.getByText('Anulado')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Anular a Ana Gómez' })).not.toBeInTheDocument();
  });

  it('reenvía la entrada por mail', async () => {
    const llamadas = servidor({
      'GET invitados.php': { body: { invitados: [ANA], resumen: RESUMEN } },
      'POST invitado.php': { body: { mail: { enviado: true }, invitados: [ANA], resumen: RESUMEN } },
    });

    await montar();
    fireEvent.click(screen.getByRole('button', { name: 'Reenviar la entrada a Ana Gómez' }));

    expect(await screen.findByText('Le volvimos a mandar la entrada a ana@test.local.')).toBeInTheDocument();
    expect(llamadas.find((l) => l.url.includes('invitado.php')).cuerpo.accion).toBe('reenviar');
  });

  it('ofrece mandar la entrada por WhatsApp con el link', async () => {
    servidor({ 'GET invitados.php': { body: { invitados: [ANA], resumen: RESUMEN } } });

    await montar();

    const whatsapp = screen.getByRole('link', { name: 'Mandarle la entrada por WhatsApp a Ana Gómez' });
    expect(decodeURIComponent(whatsapp.getAttribute('href'))).toContain(ANA.url);
    expect(decodeURIComponent(whatsapp.getAttribute('href'))).toContain('La Noche');
  });

  it('muestra el QR de la entrada para descargarlo', async () => {
    servidor({ 'GET invitados.php': { body: { invitados: [ANA], resumen: RESUMEN } } });

    await montar();
    fireEvent.click(screen.getByRole('button', { name: 'Ver el QR de Ana Gómez' }));

    expect(await screen.findByAltText('Código QR de la entrada de Ana Gómez')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Descargar QR/ })).toHaveAttribute('download', 'entrada-ABC123DEF456.png');
  });

  it('muestra quién ya entró', async () => {
    servidor({ 'GET invitados.php': { body: { invitados: [{ ...ANA, ingresadas: 2 }], resumen: { ...RESUMEN, lugares: 2, ingresaron: 2 } } } });

    await montar();

    expect(screen.getByText('Entró')).toBeInTheDocument();
    expect(screen.getByText('2/2')).toBeInTheDocument();
  });

  it('guarda el límite de invitados', async () => {
    const llamadas = servidor({
      'GET invitados.php': { body: { invitados: [], resumen: RESUMEN } },
      'PUT invitados.php': { body: { invitados: [], resumen: { ...RESUMEN, max_invitados: 30, quedan_del_limite: 30 } } },
    });

    await montar();
    fireEvent.change(screen.getByLabelText('LÍMITE DE INVITADOS'), { target: { value: '30' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(await screen.findByText('Quedan 30')).toBeInTheDocument();
    expect(llamadas.find((l) => l.metodo === 'PUT').cuerpo).toEqual({ link_id: 10, max_invitados: 30 });
  });
});
