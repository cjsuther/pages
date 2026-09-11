import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent, waitFor } from '@testing-library/react';
import ParaAsistentes from '../../src/pages/ParaAsistentes';
import ParaArtistas from '../../src/pages/ParaArtistas';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch } from '../helpers/api';

/**
 * Lo que se prueba de un instructivo no es el diseño: es que lo que hay que
 * copiar esté, que esté bien, y que se pueda llegar desde donde dijimos.
 *
 * La dirección del servidor es el dato que hace o rompe la página entera: si
 * sale mal escrita, la persona pega algo que no conecta y no tiene forma de
 * darse cuenta.
 */
const URL_MCP = 'https://rezon.ar/mcp';

/** El asistente habla con la API cuando hay sesión: claves y conexiones. */
function rutasDeLaSesion() {
  return mockFetch({
    'users/claves.php': { claves: [] },
    'oauth/conexiones.php': { conexiones: [] },
    'notifications/index.php': { notifications: [], unread_count: 0 },
    'users/profile.php': { user: usuarioDePrueba() },
    'users/location.php': { latitude: null, longitude: null },
  });
}

function montar({ conSesion = false } = {}) {
  const mock = rutasDeLaSesion();
  const auth = conSesion
    ? crearAuth({ token: 'tok-123', user: usuarioDePrueba() })
    : crearAuth();

  return { ...renderConProviders(<ParaAsistentes />, { auth }), ...mock };
}

describe('ParaAsistentes', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('la dirección del servidor', () => {
    it('la muestra para copiar', () => {
      montar();

      expect(screen.getAllByText(URL_MCP).length).toBeGreaterThan(0);
    });

    /**
     * Una sola barra y sin barra final: así está el rewrite en el .htaccess.
     * Cualquier otra forma le da un 404 a quien la pegue.
     */
    it('la escribe tal cual la sirve el servidor', () => {
      montar();

      const direcciones = screen.getAllByText(/rezon\.ar\/mcp/);
      direcciones.forEach((d) => {
        expect(d.textContent).not.toContain('/mcp/');
        expect(d.textContent).not.toContain('/api/mcp');
      });
    });

    it('se copia de un toque', async () => {
      const escribir = vi.fn();
      Object.assign(navigator, { clipboard: { writeText: escribir } });
      montar();

      fireEvent.click(screen.getAllByLabelText(`Copiar ${URL_MCP}`)[0]);

      expect(escribir).toHaveBeenCalledWith(URL_MCP);
      expect(await screen.findByLabelText('Copiado')).toBeInTheDocument();
    });
  });

  describe('los pasos', () => {
    it('cubre las cuatro aplicaciones', () => {
      montar();

      ['Claude', 'ChatGPT', 'Le Chat, de Mistral', 'Gemini'].forEach((nombre) => {
        expect(screen.getByRole('heading', { name: nombre })).toBeInTheDocument();
      });
    });

    /**
     * Es el dato que evita que alguien se pase media hora buscando un menú que
     * su plan no tiene.
     */
    it('avisa que ChatGPT lo pide en un plan pago', () => {
      montar();

      expect(screen.getByText(/Hace falta un plan pago/)).toBeInTheDocument();
    });

    it('avisa que la app de Gemini todavía no lo hace', () => {
      montar();

      expect(screen.getByText(/La aplicación de Gemini para celular y web todavía no/))
        .toBeInTheDocument();
    });

    /** Los menús de estas apps se mueven: lo que queda fijo es la dirección. */
    it('dice qué buscar si el menú cambió de lugar', () => {
      montar();

      expect(screen.getByText(/Si el tuyo no está donde dice acá/)).toBeInTheDocument();
    });

    it('el comando de Gemini se puede copiar entero', () => {
      montar();

      expect(screen.getByLabelText('Copiar gemini mcp add -t http rezonar https://rezon.ar/mcp'))
        .toBeInTheDocument();
    });
  });

  describe('las claves', () => {
    /** Sin sesión no hay nada que crear: lo que corresponde es mandar a entrar. */
    it('sin sesión ofrece entrar a la cuenta', () => {
      montar();

      expect(screen.getByRole('link', { name: 'Entrar a mi cuenta' }))
        .toHaveAttribute('href', '/login');
      expect(screen.queryByRole('heading', { name: 'Claves de API' })).not.toBeInTheDocument();
    });

    it('con sesión se pueden crear ahí mismo', async () => {
      montar({ conSesion: true });

      expect(await screen.findByRole('heading', { name: 'Claves de API' })).toBeInTheDocument();
    });

    /** Quien viene a cortar un acceso lo encuentra en la misma página. */
    it('con sesión también se puede desconectar lo conectado', async () => {
      const { llamadas } = montar({ conSesion: true });

      await waitFor(() => {
        expect(llamadas.some((l) => l.url.includes('oauth/conexiones.php'))).toBe(true);
      });
    });
  });

  describe('por dónde sigue', () => {
    it('sin sesión manda a armar la página', () => {
      montar();

      expect(screen.getAllByRole('link', { name: /Armar mi página gratis/ })[0])
        .toHaveAttribute('href', '/register');
    });

    it('con sesión manda a las páginas propias', async () => {
      montar({ conSesion: true });

      expect(screen.getAllByRole('link', { name: /Ir a mis páginas/ })[0])
        .toHaveAttribute('href', '/my-pages');
    });
  });
});

/**
 * El pedido era explícito: esta página se llega desde la de los creadores. Un
 * instructivo al que nadie puede llegar no sirve de nada, así que el enlace se
 * prueba igual que el contenido.
 */
describe('el enlace desde la página para artistas', () => {
  beforeEach(() => {
    mockFetch({
      'notifications/index.php': { notifications: [], unread_count: 0 },
      'users/location.php': { latitude: null, longitude: null },
    });
  });

  it('lleva a la página de asistentes', () => {
    renderConProviders(<ParaArtistas />);

    expect(screen.getByRole('link', { name: /Cómo se conecta, paso por paso/ }))
      .toHaveAttribute('href', '/asistentes');
  });

  it('nombra a los asistentes que la gente reconoce', () => {
    renderConProviders(<ParaArtistas />);

    expect(screen.getByText(/Si ya usás ChatGPT o Claude/)).toBeInTheDocument();
  });
});
