import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, act } from '@testing-library/react';
import LocationIndicator from '../../src/components/LocationIndicator';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const sinUbicacion = { latitude: null, longitude: null, location_name: null };
const conUbicacion = {
  latitude: -34.6037,
  longitude: -58.3816,
  location_name: 'Buenos Aires',
  last_update: '2026-01-01 10:00:00',
};

/** Simula la API de geolocalización del navegador. */
function instalarGeolocalizacion({ exito = true, coords = { latitude: -34.6, longitude: -58.4 } } = {}) {
  const getCurrentPosition = vi.fn((onOk, onError) => {
    if (exito) {
      onOk({ coords });
    } else {
      onError({ code: 1, message: 'denegado' });
    }
  });

  Object.defineProperty(navigator, 'geolocation', {
    value: { getCurrentPosition },
    writable: true,
    configurable: true,
  });

  return getCurrentPosition;
}

/** Simula la API de Google Places para el autocompletado. */
function instalarGoogleMaps() {
  const listeners = {};
  const autocomplete = {
    addListener: vi.fn((evento, cb) => { listeners[evento] = cb; }),
    getPlace: vi.fn(() => ({
      formatted_address: 'Av. Corrientes 1234',
      url: 'https://maps.google.com/x',
      geometry: { location: { lat: () => -34.6037, lng: () => -58.3816 } },
    })),
  };

  window.google = {
    maps: {
      places: { Autocomplete: vi.fn(() => autocomplete) },
      event: { clearInstanceListeners: vi.fn() },
    },
  };

  return listeners;
}

describe('LocationIndicator', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    window.alert = vi.fn();
  });

  afterEach(() => {
    window.google = undefined;
    delete navigator.geolocation;
  });

  describe('indicador', () => {
    it('marca en rojo si no hay ubicación configurada', async () => {
      mockFetch({ 'users/location.php': sinUbicacion });

      const { container } = renderConProviders(<LocationIndicator />, { auth: autenticado() });

      await waitFor(() => {
        expect(container.querySelector('.bg-red-500')).toBeInTheDocument();
      });
    });

    it('no marca nada si ya hay ubicación', async () => {
      mockFetch({ 'users/location.php': conUbicacion });

      const { container } = renderConProviders(<LocationIndicator />, { auth: autenticado() });

      await waitFor(() => {
        expect(container.querySelector('.bg-red-500')).toBeNull();
      });
    });

    it('consulta la ubicación al montar', async () => {
      const { llamadas } = mockFetch({ 'users/location.php': sinUbicacion });

      renderConProviders(<LocationIndicator />, { auth: autenticado() });

      await waitFor(() => {
        expect(llamadas[0].url).toContain('/users/location.php');
        expect(llamadas[0].options.headers.Authorization).toBe('Bearer tok-123');
      });
    });

    it('cierra sesión si la API responde 401', async () => {
      mockFetch({ 'users/location.php': { status: 401, body: {} } });
      const auth = autenticado();

      renderConProviders(<LocationIndicator />, { auth });

      await waitFor(() => {
        expect(auth.logout).toHaveBeenCalled();
      });
    });

    it('no rompe si falla la consulta', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<LocationIndicator />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('popup', () => {
    async function abrir(respuesta = sinUbicacion) {
      const mock = mockFetch({ 'users/location.php': respuesta });
      renderConProviders(<LocationIndicator />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);
      return mock;
    }

    it('se abre al tocar el indicador', async () => {
      await abrir();

      expect(screen.getByRole('heading', { name: 'UBICACIÓN' })).toBeInTheDocument();
    });

    it('explica para qué sirve', async () => {
      await abrir();

      expect(screen.getByText(/eventos cercanos/)).toBeInTheDocument();
    });

    it('ofrece las dos formas de cargar la ubicación', async () => {
      await abrir();

      expect(screen.getByRole('button', { name: /USAR MI UBICACIÓN ACTUAL/ })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'GUARDAR DIRECCIÓN' })).toBeInTheDocument();
    });

    it('el botón de guardar arranca deshabilitado', async () => {
      await abrir();

      expect(screen.getByRole('button', { name: 'GUARDAR DIRECCIÓN' })).toBeDisabled();
    });

    it('se cierra con la X', async () => {
      await abrir();

      const cerrar = screen.getAllByRole('button')[1];
      fireEvent.click(cerrar);

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'UBICACIÓN' })).not.toBeInTheDocument();
      });
    });
  });

  describe('ubicación actual del navegador', () => {
    async function abrirYUsarGPS(opciones = {}) {
      const mock = mockFetch({ 'users/location.php': sinUbicacion });
      renderConProviders(<LocationIndicator />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);

      const gps = instalarGeolocalizacion(opciones);
      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: /USAR MI UBICACIÓN ACTUAL/ }));
      });
      return { ...mock, gps };
    }

    it('pide las coordenadas al navegador', async () => {
      const { gps } = await abrirYUsarGPS();

      expect(gps).toHaveBeenCalled();
    });

    it('envía las coordenadas obtenidas', async () => {
      const { llamadas } = await abrirYUsarGPS({
        coords: { latitude: -34.5, longitude: -58.5 },
      });

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({ latitude: -34.5, longitude: -58.5 });
      });
    });

    it('confirma al usuario', async () => {
      await abrirYUsarGPS();

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Ubicación actualizada correctamente');
      });
    });

    it('cierra el popup al guardar', async () => {
      await abrirYUsarGPS();

      await waitFor(() => {
        expect(screen.queryByRole('heading', { name: 'UBICACIÓN' })).not.toBeInTheDocument();
      });
    });

    it('avisa si el usuario deniega el permiso', async () => {
      await abrirYUsarGPS({ exito: false });

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith(
          'No se pudo obtener tu ubicación. Verifica los permisos del navegador.'
        );
      });
    });

    it('avisa si el navegador no soporta geolocalización', async () => {
      const mock = mockFetch({ 'users/location.php': sinUbicacion });
      renderConProviders(<LocationIndicator />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);

      delete navigator.geolocation;
      fireEvent.click(screen.getByRole('button', { name: /USAR MI UBICACIÓN ACTUAL/ }));

      expect(window.alert).toHaveBeenCalledWith('Tu navegador no soporta geolocalización');
    });
  });

  describe('dirección escrita', () => {
    async function abrirConGoogle() {
      const listeners = instalarGoogleMaps();
      const mock = mockFetch({ 'users/location.php': sinUbicacion });
      renderConProviders(<LocationIndicator />, { auth: autenticado() });
      await waitFor(() => expect(mock.llamadas.length).toBeGreaterThan(0));
      fireEvent.click(screen.getAllByRole('button')[0]);
      return { ...mock, listeners };
    }

    it('habilita guardar al elegir una dirección de las sugerencias', async () => {
      const { listeners } = await abrirConGoogle();

      act(() => listeners.place_changed());

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'GUARDAR DIRECCIÓN' })).toBeEnabled();
      });
    });

    it('envía la dirección con sus coordenadas', async () => {
      const { listeners, llamadas } = await abrirConGoogle();

      act(() => listeners.place_changed());
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR DIRECCIÓN' }));

      await waitFor(() => {
        const post = llamadas.find((l) => l.options.method === 'POST');
        expect(cuerpoDe(post)).toEqual({
          latitude: -34.6037,
          longitude: -58.3816,
          address: 'Av. Corrientes 1234',
        });
      });
    });

    it('confirma al guardar', async () => {
      const { listeners } = await abrirConGoogle();

      act(() => listeners.place_changed());
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR DIRECCIÓN' }));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Ubicación guardada correctamente');
      });
    });

    it('avisa si falla el guardado', async () => {
      const { listeners } = await abrirConGoogle();

      act(() => listeners.place_changed());
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));
      fireEvent.click(screen.getByRole('button', { name: 'GUARDAR DIRECCIÓN' }));

      await waitFor(() => {
        expect(window.alert).toHaveBeenCalledWith('Error al guardar la ubicación');
      });
    });
  });
});
