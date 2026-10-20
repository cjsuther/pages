import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderConProviders } from '../helpers/render';
import { mockFetch, llamadaA, cuerpoDe } from '../helpers/api';
import Descarga from '../../src/pages/Descarga';

const API = 'https://api.test/api';
const TOKEN = '3f'.repeat(32);

const PRODUCTO = {
  titulo: 'Guía de viaje',
  imagen: 'https://rezonar.test/api/uploads/tapa.jpg',
  archivo_nombre: 'guía.pdf',
  archivo_bytes: 5 * 1024 * 1024,
  pagina: 'La Sala',
  url_slug: 'la-sala',
};

async function montar(hash, rutas) {
  window.history.replaceState(null, '', `/descarga${hash}`);
  const api = mockFetch(rutas);

  renderConProviders(<Descarga apiUrl={API} />, { route: '/descarga' });
  await waitFor(() => expect(screen.queryByText(/Cargando/)).not.toBeInTheDocument());

  return api;
}

describe('Descarga', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/');
  });

  afterEach(() => {
    window.history.replaceState(null, '', '/');
    delete global.fetch;
  });

  it('muestra el producto y el archivo que se va a bajar', async () => {
    await montar(`#${TOKEN}`, { 'public/descarga.php': { producto: PRODUCTO } });

    expect(screen.getByRole('heading', { name: 'Guía de viaje' })).toBeInTheDocument();
    expect(screen.getByText('guía.pdf')).toBeInTheDocument();
    expect(screen.getByText('5 MB')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Descargar/ })).toBeInTheDocument();
  });

  /** El token va en el cuerpo de un POST, nunca en una URL que llegue al servidor. */
  it('le pide los datos a la API mandando el token por POST', async () => {
    const { llamadas } = await montar(`#${TOKEN}`, { 'public/descarga.php': { producto: PRODUCTO } });

    const llamada = llamadaA(llamadas, 'public/descarga.php');
    expect(llamada.options.method).toBe('POST');
    expect(cuerpoDe(llamada)).toEqual({ token: TOKEN });
    expect(llamadas.every((l) => !l.url.includes(TOKEN))).toBe(true);
  });

  /**
   * El botón es un formulario común: el navegador guarda el archivo a medida
   * que llega, sin cargar 50 MB en la memoria de la pestaña.
   */
  it('el botón descarga con un formulario POST, con el token como campo', async () => {
    await montar(`#${TOKEN}`, { 'public/descarga.php': { producto: PRODUCTO } });

    const formulario = screen.getByRole('button', { name: /Descargar/ }).closest('form');
    expect(formulario).toHaveAttribute('method', 'post');
    expect(formulario).toHaveAttribute('action', `${API}/public/descargar.php`);
    expect(formulario.getAttribute('action')).not.toContain(TOKEN);
    expect(formulario.querySelector('input[name="token"]')).toHaveValue(TOKEN);
  });

  it('un link que la API no reconoce muestra un mensaje claro, sin detalles', async () => {
    await montar(`#${TOKEN}`, { 'public/descarga.php': { status: 404, body: { error: 'Este link de descarga no es válido.' } } });

    expect(screen.getByText('Este link de descarga no es válido')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Descargar/ })).not.toBeInTheDocument();
  });

  it('sin token ni siquiera consulta', async () => {
    const { llamadas } = await montar('', {});

    expect(screen.getByText('Este link de descarga no es válido')).toBeInTheDocument();
    expect(llamadas).toHaveLength(0);
  });

  it('un token con otra forma tampoco', async () => {
    const { llamadas } = await montar('#ABC123DEF456', {});

    expect(screen.getByText('Este link de descarga no es válido')).toBeInTheDocument();
    expect(llamadas).toHaveLength(0);
  });

  it('si no hay conexión no muestra una pantalla rota', async () => {
    await montar(`#${TOKEN}`, {});

    expect(screen.getByText('Este link de descarga no es válido')).toBeInTheDocument();
  });

  it('ofrece volver a la página de quien vende', async () => {
    await montar(`#${TOKEN}`, { 'public/descarga.php': { producto: PRODUCTO } });

    expect(screen.getByRole('link', { name: /Volver a La Sala/ })).toHaveAttribute('href', '/la-sala');
  });
});
