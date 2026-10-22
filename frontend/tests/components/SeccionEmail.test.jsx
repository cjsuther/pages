import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';
import SeccionEmail from '../../src/components/SeccionEmail';

const PREDETERMINADOS = { remitente_nombre: 'La Sala', responder_a: 'hola@lasala.com' };

const GUARDADA = {
  host: 'smtp.gmail.com',
  port: 465,
  seguridad: 'ssl',
  usuario: 'lasala@gmail.com',
  tiene_contrasena: true,
  remitente_email: 'lasala@gmail.com',
  remitente_nombre: null,
  responder_a: null,
  verificado_en: null,
};

async function montar(rutas = {}) {
  const mock = mockFetch({
    'pages/email.php': { disponible: true, cuenta: null, predeterminados: PREDETERMINADOS },
    ...rutas,
  });

  renderConProviders(<SeccionEmail pageId="5" apiUrl="https://api.test/api" token="tok" />, {
    route: '/page/5?c=email',
    path: '/page/:id',
  });

  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());

  return mock;
}

/** Arma un fetch que contesta distinto según método y archivo. */
function porMetodo(respuestas) {
  const llamadas = [];
  global.fetch = vi.fn((url, opciones = {}) => {
    llamadas.push({ url, options: opciones });
    const metodo = opciones.method || 'GET';
    const clave = Object.keys(respuestas).find((k) => {
      const [m, archivo] = k.split(' ');
      return m === metodo && url.includes(archivo);
    });
    if (!clave) return Promise.reject(new Error(`fetch no declarado: ${metodo} ${url}`));
    const { status = 200, body } = respuestas[clave];
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
  });
  return llamadas;
}

async function montarCon(respuestas) {
  const llamadas = porMetodo(respuestas);
  renderConProviders(<SeccionEmail pageId="5" apiUrl="https://api.test/api" token="tok" />, {
    route: '/page/5?c=email',
    path: '/page/:id',
  });
  await waitFor(() => expect(screen.queryByText('Cargando...')).not.toBeInTheDocument());
  return llamadas;
}

function completar() {
  fireEvent.change(screen.getByLabelText('Servidor'), { target: { value: 'smtp.gmail.com' } });
  fireEvent.change(screen.getByLabelText('Usuario'), { target: { value: 'lasala@gmail.com' } });
  fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: 'abcd efgh ijkl mnop' } });
  fireEvent.change(screen.getByLabelText('Email del remitente'), { target: { value: 'lasala@gmail.com' } });
}

describe('SeccionEmail', () => {
  beforeEach(() => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('pide la configuración de la página', async () => {
    const { llamadas } = await montar();

    expect(llamadaA(llamadas, 'pages/email.php').url).toContain('page_id=5');
    expect(llamadaA(llamadas, 'pages/email.php').options.headers.Authorization).toBe('Bearer tok');
  });

  it('sugiere el título de la página como nombre y el email de contacto como respuesta', async () => {
    await montar();

    expect(screen.getByLabelText('Nombre del remitente')).toHaveAttribute('placeholder', 'La Sala');
    expect(screen.getByLabelText('Responder a (opcional)')).toHaveAttribute('placeholder', 'hola@lasala.com');
  });

  it('al elegir la seguridad sugiere el puerto', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Seguridad'), { target: { value: 'ssl' } });
    expect(screen.getByLabelText('Puerto')).toHaveValue(465);

    fireEvent.change(screen.getByLabelText('Seguridad'), { target: { value: 'tls' } });
    expect(screen.getByLabelText('Puerto')).toHaveValue(587);
  });

  it('explica que la contraseña normal de Gmail no funciona', async () => {
    await montar();

    expect(screen.getByText(/La contraseña normal de Gmail/)).toHaveTextContent('contraseña de aplicación');
    expect(screen.getByText(/smtp.hostinger.com/)).toBeInTheDocument();
    expect(screen.getByText(/smtp-mail.outlook.com/)).toBeInTheDocument();
  });

  it('guarda lo que se cargó', async () => {
    const llamadas = await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: null, predeterminados: PREDETERMINADOS } },
      'PUT email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
    });

    completar();
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(await screen.findByText('Guardado')).toBeInTheDocument();
    const put = llamadas.find((l) => l.options.method === 'PUT');
    expect(cuerpoDe(put)).toMatchObject({
      page_id: 5,
      host: 'smtp.gmail.com',
      port: 587,
      seguridad: 'tls',
      usuario: 'lasala@gmail.com',
      contrasena: 'abcd efgh ijkl mnop',
      remitente_email: 'lasala@gmail.com',
    });
    // Después de guardar, la contraseña no queda en el formulario.
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
  });

  it('con una cuenta guardada, la contraseña vacía conserva la anterior', async () => {
    const llamadas = await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
      'PUT email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
    });

    expect(screen.getByLabelText('Servidor')).toHaveValue('smtp.gmail.com');
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
    expect(screen.getByText(/Dejala vacía para mantener/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    await screen.findByText('Guardado');
    expect(cuerpoDe(llamadas.find((l) => l.options.method === 'PUT')).contrasena).toBe('');
  });

  it('muestra el error de validación de la API', async () => {
    await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: null, predeterminados: PREDETERMINADOS } },
      'PUT email.php': { status: 400, body: { error: 'Usá uno de los puertos de correo: 25, 465, 587 o 2525' } },
    });

    completar();
    fireEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('25, 465, 587 o 2525');
  });

  it('sin cuenta guardada no se puede mandar la prueba ni quitar', async () => {
    await montar();

    expect(screen.getByRole('button', { name: /Enviar prueba/ })).toBeDisabled();
    expect(screen.queryByRole('button', { name: /Quitar cuenta/ })).not.toBeInTheDocument();
  });

  it('la prueba exitosa avisa a dónde se mandó', async () => {
    const llamadas = await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
      'POST email-prueba.php': { body: { enviado: true, mensaje: 'Te mandamos un mail de prueba a duena@lasala.com' } },
    });

    fireEvent.click(screen.getByRole('button', { name: /Enviar prueba/ }));

    expect(await screen.findByRole('status')).toHaveTextContent('duena@lasala.com');
    expect(cuerpoDe(llamadas.find((l) => l.url.includes('email-prueba.php')))).toEqual({ page_id: 5 });
  });

  it('la prueba fallida muestra el error del servidor en castellano', async () => {
    await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
      'POST email-prueba.php': {
        status: 502,
        body: { error: 'El servidor rechazó el usuario o la contraseña.', motivo: 'autenticacion' },
      },
    });

    fireEvent.click(screen.getByRole('button', { name: /Enviar prueba/ }));

    expect(await screen.findByRole('alert')).toHaveTextContent('El servidor rechazó el usuario o la contraseña.');
  });

  it('quitar la cuenta pide confirmación y vacía el formulario', async () => {
    const llamadas = await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
      'DELETE email.php': { body: { quitada: true } },
    });

    fireEvent.click(screen.getByRole('button', { name: /Quitar cuenta/ }));

    expect(await screen.findByText('Se quitó la cuenta de email')).toBeInTheDocument();
    expect(window.confirm).toHaveBeenCalled();
    expect(llamadas.find((l) => l.options.method === 'DELETE').url).toContain('page_id=5');
    expect(screen.getByLabelText('Servidor')).toHaveValue('');
  });

  it('si no confirma, no quita nada', async () => {
    window.confirm.mockReturnValue(false);
    const llamadas = await montarCon({
      'GET email.php': { body: { disponible: true, cuenta: GUARDADA, predeterminados: PREDETERMINADOS } },
    });

    fireEvent.click(screen.getByRole('button', { name: /Quitar cuenta/ }));

    expect(llamadas.some((l) => l.options.method === 'DELETE')).toBe(false);
    expect(screen.getByLabelText('Servidor')).toHaveValue('smtp.gmail.com');
  });

  it('sin la clave de cifrado en el servidor avisa que no está disponible', async () => {
    await montar({
      'pages/email.php': { status: 503, body: { error: 'El envío por SMTP propio no está disponible en este momento.', disponible: false } },
    });

    expect(screen.getByRole('alert')).toHaveTextContent('no está disponible');
    expect(screen.queryByLabelText('Servidor')).not.toBeInTheDocument();
  });
});
