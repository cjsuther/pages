import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { HelmetProvider } from 'react-helmet-async';
import Hoy from '../../src/carcajada/Hoy';
import { renderConProviders } from '../helpers/render';

const ESTADO = {
  show: { ciclo: 'JaJaJaJueves', fecha: '2026-10-02', hora: '21:00:00', lugar: 'Humboldt 1574', es_hoy: true },
  comediantes: [
    { nombre: 'Ana Gómez', foto_url: 'https://rezon.ar/uploads/ana.jpg', instagram: 'anagomez', url_slug: 'anagomez' },
    { nombre: 'Beto Pérez', foto_url: null, instagram: null, url_slug: 'betoperez' },
  ],
};

function montar(cuerpo = ESTADO) {
  global.fetch.mockReturnValue(Promise.resolve({ ok: true, json: () => Promise.resolve(cuerpo) }));
  return render(<HelmetProvider><Hoy apiUrl="https://api.test/api" /></HelmetProvider>);
}

describe('Hoy (la pantalla del QR)', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /** Es un cartel en la pared: no puede pedir sesión ni clave. */
  it('no manda ninguna credencial', async () => {
    montar();

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(global.fetch.mock.calls[0][1]).toBeUndefined();
  });

  it('muestra el show y a cada comediante con su acceso', async () => {
    montar();

    expect(await screen.findByText('JaJaJaJueves')).toBeInTheDocument();
    expect(screen.getByText('Esta noche')).toBeInTheDocument();
    // Con página en Rezonar, el nombre lleva a sus fechas.
    expect(screen.getByRole('link', { name: 'Ana Gómez' })).toHaveAttribute('href', 'https://rezon.ar/anagomez');
    expect(screen.getByRole('link', { name: '@anagomez' })).toHaveAttribute('href', 'https://instagram.com/anagomez');
  });

  /** Foto arriba, nombre abajo y en la tercera línea el Instagram. */
  it('cada tarjeta va foto, nombre e Instagram, en ese orden', async () => {
    montar();

    const nombre = await screen.findByText('Ana Gómez');
    const tarjeta = nombre.closest('li');
    const foto = tarjeta.querySelector('img');
    const instagram = screen.getByRole('link', { name: '@anagomez' });

    expect(foto.compareDocumentPosition(nombre) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(nombre.compareDocumentPosition(instagram) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(tarjeta.parentElement.className).toContain('grid-cols-3');
  });

  it('sin página en Rezonar el nombre no es un link', async () => {
    montar({ ...ESTADO, comediantes: [{ nombre: 'Pepe Invitado', foto_url: null, instagram: 'pepe', url_slug: null }] });

    expect(await screen.findByText('Pepe Invitado')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Pepe Invitado' })).not.toBeInTheDocument();
  });

  /** Sin foto, la inicial: un círculo vacío no dice quién es. */
  it('sin foto muestra la inicial', async () => {
    montar();

    expect(await screen.findByText('B')).toBeInTheDocument();
  });

  it('si la fecha no es hoy lo dice', async () => {
    montar({ ...ESTADO, show: { ...ESTADO.show, es_hoy: false } });

    expect(await screen.findByText('Próxima fecha')).toBeInTheDocument();
  });

  it('sin fechas cargadas no deja la pantalla vacía', async () => {
    montar({ show: null, comediantes: [] });

    expect(await screen.findByText(/No hay ninguna fecha cargada/)).toBeInTheDocument();
  });

  describe('la página de una fecha', () => {
    const COMPLETO = {
      show: { id: 7, ...ESTADO.show, descripcion: '<p>Noche de <strong>stand up</strong></p>' },
      comediantes: ESTADO.comediantes,
      otras: [
        { id: 8, ciclo: 'Corta la Semana', fecha: '2026-10-07', hora: '21:30:00', lugar: 'Devoto' },
        { id: 9, ciclo: 'JaJaJaJueves', fecha: '2026-10-09', hora: null, lugar: null },
      ],
    };

    function montarEn(ruta, cuerpo = COMPLETO, estado = 200) {
      global.fetch.mockReturnValue(Promise.resolve({
        ok: estado === 200, status: estado, json: () => Promise.resolve(cuerpo),
      }));

      return renderConProviders(<Hoy apiUrl="https://api.test/api" />, {
        route: ruta,
        path: ruta.startsWith('/fecha') ? '/fecha/:id' : '/hoy',
      });
    }

    it('muestra la descripción con su formato', async () => {
      montarEn('/hoy');

      const fuerte = await screen.findByText('stand up');
      expect(fuerte.tagName).toBe('STRONG');
    });

    /** El orden que se pidió: de qué va la noche, quiénes están, y qué más viene. */
    it('va descripción, comediantes y otras fechas, en ese orden', async () => {
      montarEn('/hoy');

      const descripcion = (await screen.findByText('stand up')).closest('section');
      const lineup = screen.getByRole('heading', { name: 'Comediantes' });
      const otras = screen.getByRole('heading', { name: 'Otras fechas de Carcajada' });

      expect(descripcion.compareDocumentPosition(lineup) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(lineup.compareDocumentPosition(otras) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('cada otra fecha lleva a su página', async () => {
      montarEn('/hoy');

      const link = await screen.findByRole('link', { name: /Corta la Semana/ });
      expect(link).toHaveAttribute('href', '/fecha/8');
      expect(link).toHaveTextContent('Devoto');
    });

    it('una fecha se pide por su id', async () => {
      montarEn('/fecha/8');

      await waitFor(() => expect(global.fetch).toHaveBeenCalled());
      expect(global.fetch.mock.calls[0][0]).toBe('https://api.test/api/public/carcajada-hoy.php?id=8');
    });

    it('sin descripción ni otras fechas no deja huecos', async () => {
      montarEn('/hoy', { ...COMPLETO, show: { ...COMPLETO.show, descripcion: null }, otras: [] });

      await screen.findByText('JaJaJaJueves');
      expect(screen.queryByLabelText('Sobre la fecha')).not.toBeInTheDocument();
      expect(screen.queryByRole('heading', { name: 'Otras fechas de Carcajada' })).not.toBeInTheDocument();
    });

    it('una fecha que no existe lo dice y ofrece la próxima', async () => {
      montarEn('/fecha/999', { error: 'Esa fecha no existe' }, 404);

      expect(await screen.findByText('Esa fecha no existe o ya no está publicada.')).toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'Ver la próxima fecha' })).toHaveAttribute('href', '/hoy');
    });
  });
});
