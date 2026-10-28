import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import DialogoEnvio from '../../src/components/DialogoEnvio';

const GUARDADO = {
  id: 2,
  nombre: 'Nueva fecha',
  asunto: '¡Volvemos, {primer_nombre}!',
  cuerpo: 'Hola {primer_nombre},\nTe esperamos.',
  reemplazo_nombre: '',
};

/** fetch que contesta según método y archivo, y anota las llamadas. */
function servidor(respuestas) {
  const llamadas = [];
  global.fetch = vi.fn((url, opciones = {}) => {
    const metodo = opciones.method || 'GET';
    llamadas.push({ url, metodo, cuerpo: opciones.body ? JSON.parse(opciones.body) : null });
    const clave = Object.keys(respuestas).find((k) => {
      const [m, archivo] = k.split(' ');
      return m === metodo && url.includes(archivo);
    });
    if (!clave) return Promise.reject(new Error(`fetch no declarado: ${metodo} ${url}`));
    const { status = 200, body } = respuestas[clave];
    return Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
  });
  return llamadas;
}

async function montar(props = {}, respuestas = {}) {
  const llamadas = servidor({
    'GET mensajes.php': { body: { mensajes: [GUARDADO], cuenta_configurada: true } },
    ...respuestas,
  });
  const alEnviar = vi.fn();

  renderConProviders(
    <DialogoEnvio
      abierto
      alCerrar={vi.fn()}
      pageId={5}
      apiUrl="https://api.test/api"
      token="tok"
      cantidad={2}
      seleccion={{ modo: 'emails', emails: ['ana@example.com', 'x@example.com'] }}
      muestra={[{ email: 'ana@example.com', nombre: 'Ana Gómez' }, { email: 'x@example.com', nombre: '' }]}
      alEnviar={alEnviar}
      {...props}
    />
  );

  await waitFor(() => expect(screen.queryByText('Cargando mensajes…')).not.toBeInTheDocument());

  return { llamadas, alEnviar };
}

describe('DialogoEnvio', () => {
  beforeEach(() => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('carga un mensaje guardado y lo muestra en la vista previa con un cliente real', async () => {
    await montar();

    fireEvent.change(screen.getByLabelText('Mensaje guardado'), { target: { value: '2' } });

    expect(screen.getByLabelText('Asunto')).toHaveValue('¡Volvemos, {primer_nombre}!');
    expect(screen.getByTestId('vista-asunto')).toHaveTextContent('¡Volvemos, Ana!');
    expect(screen.getByTestId('vista-cuerpo').textContent).toBe('Hola Ana,\nTe esperamos.');
    expect(screen.getByText('Para: ana@example.com')).toBeInTheDocument();
  });

  it('la vista previa de un cliente sin nombre usa el reemplazo o limpia el espacio', async () => {
    await montar();
    fireEvent.change(screen.getByLabelText('Mensaje guardado'), { target: { value: '2' } });

    fireEvent.change(screen.getByLabelText('Cliente de la vista previa'), { target: { value: '1' } });
    expect(screen.getByTestId('vista-cuerpo').textContent).toBe('Hola,\nTe esperamos.');

    fireEvent.change(screen.getByLabelText('Si el cliente no tiene nombre, poner'), { target: { value: 'amiga' } });
    expect(screen.getByTestId('vista-cuerpo').textContent).toBe('Hola amiga,\nTe esperamos.');
  });

  it('"Insertar nombre" pone la variable donde está el cursor', async () => {
    await montar();
    const cuerpo = screen.getByLabelText('Mensaje');

    fireEvent.change(cuerpo, { target: { value: 'Hola , te esperamos' } });
    cuerpo.setSelectionRange(5, 5);
    fireEvent.click(screen.getByRole('button', { name: /Insertar nombre/ }));

    expect(cuerpo).toHaveValue('Hola {nombre}, te esperamos');

    cuerpo.setSelectionRange(0, 0);
    fireEvent.click(screen.getByRole('button', { name: 'Insertar primer nombre' }));
    expect(cuerpo).toHaveValue('{primer_nombre}Hola {nombre}, te esperamos');
  });

  it('guarda un mensaje nuevo', async () => {
    const { llamadas } = await montar({}, {
      'POST mensajes.php': { status: 201, body: { mensaje: { id: 7, nombre: 'Aviso', asunto: 'Hola', cuerpo: 'Texto', reemplazo_nombre: '' } } },
    });

    fireEvent.change(screen.getByLabelText('Nombre del mensaje (sólo lo ves vos)'), { target: { value: 'Aviso' } });
    fireEvent.change(screen.getByLabelText('Asunto'), { target: { value: 'Hola' } });
    fireEvent.change(screen.getByLabelText('Mensaje'), { target: { value: 'Texto' } });
    fireEvent.click(screen.getByRole('button', { name: /Guardar mensaje/ }));

    expect(await screen.findByText('Mensaje guardado')).toBeInTheDocument();
    expect(llamadas.find((l) => l.metodo === 'POST').cuerpo).toEqual({
      nombre: 'Aviso', asunto: 'Hola', cuerpo: 'Texto', reemplazo_nombre: '', page_id: 5,
    });
    expect(screen.getByLabelText('Mensaje guardado')).toHaveValue('7');
  });

  it('edita y borra un mensaje guardado', async () => {
    const { llamadas } = await montar({}, {
      'PUT mensajes.php': { body: { mensaje: { ...GUARDADO, asunto: 'Otro' } } },
      'DELETE mensajes.php': { body: { borrado: true } },
    });

    fireEvent.change(screen.getByLabelText('Mensaje guardado'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Asunto'), { target: { value: 'Otro' } });
    fireEvent.click(screen.getByRole('button', { name: /Guardar cambios/ }));
    await screen.findByText('Mensaje guardado');
    expect(llamadas.find((l) => l.metodo === 'PUT').cuerpo).toMatchObject({ id: 2, asunto: 'Otro' });

    fireEvent.click(screen.getByRole('button', { name: /Borrar/ }));
    await screen.findByText('Mensaje borrado');
    expect(llamadas.find((l) => l.metodo === 'DELETE').url).toContain('id=2');
    expect(screen.queryByRole('option', { name: 'Nueva fecha' })).not.toBeInTheDocument();
  });

  it('manda el envío con el mensaje y la selección', async () => {
    const { llamadas, alEnviar } = await montar({}, {
      'POST envios.php': { status: 201, body: { total: 2, de_baja: 0, ignorados: 0, envio: { id: 9 } } },
    });

    fireEvent.change(screen.getByLabelText('Mensaje guardado'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar a 2 clientes' }));

    await waitFor(() => expect(alEnviar).toHaveBeenCalledWith(expect.objectContaining({ total: 2 })));
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('2 clientes'));
    expect(llamadas.find((l) => l.url.includes('envios.php')).cuerpo).toEqual({
      page_id: 5,
      mensaje_id: 2,
      mensaje: { asunto: GUARDADO.asunto, cuerpo: GUARDADO.cuerpo, reemplazo_nombre: '' },
      seleccion: { modo: 'emails', emails: ['ana@example.com', 'x@example.com'] },
    });
  });

  it('muestra el error del servidor al mandar', async () => {
    const { alEnviar } = await montar({}, {
      'POST envios.php': { status: 409, body: { error: 'Primero cargá la cuenta de email de la página en Configuración → Email.' } },
    });

    fireEvent.change(screen.getByLabelText('Mensaje guardado'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar a 2 clientes' }));

    expect(await screen.findByText(/Primero cargá la cuenta/)).toBeInTheDocument();
    expect(alEnviar).not.toHaveBeenCalled();
  });

  it('sin cuenta SMTP avisa y no deja mandar', async () => {
    await montar({}, { 'GET mensajes.php': { body: { mensajes: [GUARDADO], cuenta_configurada: false } } });

    fireEvent.change(screen.getByLabelText('Mensaje guardado'), { target: { value: '2' } });

    expect(screen.getByText(/primero cargá la cuenta de email/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Enviar a 2 clientes' })).toBeDisabled();
  });
});
