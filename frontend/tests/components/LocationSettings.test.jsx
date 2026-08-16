import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, fireEvent, waitFor, act } from '@testing-library/react';
import LocationSettings from '../../src/components/LocationSettings';
import { renderConProviders, crearAuth, usuarioDePrueba } from '../helpers/render';
import { mockFetch, cuerpoDe, llamadaA } from '../helpers/api';

const autenticado = () => crearAuth({ token: 'tok-123', user: usuarioDePrueba() });

const sinUbicacion = { latitude: null, longitude: null, location_name: null };
const conUbicacion = {
  latitude: -34.6037,
  longitude: -58.3816,
  location_name: 'Buenos Aires',
  last_update: '2026-01-01',
};

function instalarGeolocalizacion({ exito = true, coords = { latitude: -34.6037, longitude: -58.3816 } } = {}) {
  const getCurrentPosition = vi.fn((onOk, onError) => {
    if (exito) onOk({ coords });
    else onError({ code: 1 });
  });

  Object.defineProperty(navigator, 'geolocation', {
    value: { getCurrentPosition },
    writable: true,
    configurable: true,
  });

  return getCurrentPosition;
}

function instalarGoogleMaps() {
  const listeners = {};
  const autocomplete = {
    addListener: vi.fn((evento, cb) => { listeners[evento] = cb; }),
    getPlace: vi.fn(() => ({
      formatted_address: 'Av. Corrientes 1234',
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

async function render(respuesta = sinUbicacion, extra = {}) {
  const mock = mockFetch({ 'users/location.php': respuesta, ...extra });
  const resultado = renderConProviders(<LocationSettings />, { auth: autenticado() });
  await screen.findByRole('heading', { name: 'Mi Ubicación' });
  return { ...resultado, ...mock };
}

describe('LocationSettings', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    window.google = undefined;
    delete navigator.geolocation;
  });

  describe('carga inicial', () => {
    it('consulta la ubicación guardada', async () => {
      const { llamadas } = await render();

      expect(llamadas[0].url).toContain('/users/location.php');
      expect(llamadas[0].options.headers.Authorization).toBe('Bearer tok-123');
    });

    it('no muestra ubicación seleccionada si no hay ninguna', async () => {
      await render(sinUbicacion);

      expect(screen.queryByText('Ubicación seleccionada:')).not.toBeInTheDocument();
    });

    it('precarga la ubicación existente', async () => {
      await render(conUbicacion);

      expect(await screen.findByText('Ubicación seleccionada:')).toBeInTheDocument();
      expect(screen.getByText('Buenos Aires')).toBeInTheDocument();
      expect(screen.getByRole('textbox')).toHaveValue('Buenos Aires');
    });

    it('muestra las coordenadas con seis decimales', async () => {
      await render(conUbicacion);

      expect(await screen.findByText('Lat: -34.603700, Lng: -58.381600')).toBeInTheDocument();
    });

    it('muestra "Sin nombre" si no hay etiqueta', async () => {
      await render({ latitude: -34.6, longitude: -58.4, location_name: null });

      expect(await screen.findByText('Sin nombre')).toBeInTheDocument();
    });

    it('no rompe si falla la consulta', async () => {
      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));

      renderConProviders(<LocationSettings />, { auth: autenticado() });

      await waitFor(() => expect(console.error).toHaveBeenCalled());
    });
  });

  describe('botón de guardar', () => {
    it('arranca deshabilitado sin ubicación', async () => {
      await render(sinUbicacion);

      expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeDisabled();
    });

    it('se habilita con una ubicación cargada', async () => {
      await render(conUbicacion);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeEnabled();
      });
    });
  });

  describe('ubicación actual del navegador', () => {
    it('avisa si el navegador no la soporta', async () => {
      await render();
      delete navigator.geolocation;

      fireEvent.click(screen.getByRole('button', { name: 'Usar mi ubicación actual' }));

      expect(await screen.findByText('Tu navegador no soporta geolocalización')).toBeInTheDocument();
    });

    it('avisa si el usuario deniega el permiso', async () => {
      await render();
      instalarGeolocalizacion({ exito: false });

      fireEvent.click(screen.getByRole('button', { name: 'Usar mi ubicación actual' }));

      expect(
        await screen.findByText('No se pudo obtener tu ubicación. Verifica los permisos.')
      ).toBeInTheDocument();
    });

    it('usa el nombre que devuelve el geocoder inverso', async () => {
      await render(sinUbicacion, {
        'maps.googleapis.com/maps/api/geocode': {
          results: [{ formatted_address: 'Av. Corrientes 1234, CABA' }],
        },
      });
      instalarGeolocalizacion();

      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Usar mi ubicación actual' }));
      });

      expect(await screen.findByText('Ubicación obtenida correctamente')).toBeInTheDocument();
      expect(screen.getByText('Av. Corrientes 1234, CABA')).toBeInTheDocument();
    });

    it('recurre a las coordenadas si el geocoder no devuelve nada', async () => {
      await render(sinUbicacion, {
        'maps.googleapis.com/maps/api/geocode': { results: [] },
      });
      instalarGeolocalizacion({ coords: { latitude: -34.6037, longitude: -58.3816 } });

      await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Usar mi ubicación actual' }));
      });

      // Sin nombre: se muestra la ubicación igual, identificada por coordenadas.
      expect(await screen.findByText('Ubicación seleccionada:')).toBeInTheDocument();
      expect(screen.getByText('Lat: -34.603700, Lng: -58.381600')).toBeInTheDocument();
    });
  });

  describe('dirección elegida en el autocompletado', () => {
    it('carga la dirección y sus coordenadas', async () => {
      const listeners = instalarGoogleMaps();
      await render(sinUbicacion);

      act(() => listeners.place_changed());

      expect(await screen.findByText('Av. Corrientes 1234')).toBeInTheDocument();
      expect(screen.getByText('Lat: -34.603700, Lng: -58.381600')).toBeInTheDocument();
    });

    it('habilita el guardado', async () => {
      const listeners = instalarGoogleMaps();
      await render(sinUbicacion);

      act(() => listeners.place_changed());

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeEnabled();
      });
    });
  });

  describe('guardado', () => {
    /**
     * El endpoint acepta POST con `address`. Antes se enviaba PUT con
     * `location_name` y el backend lo ignoraba, así que nunca persistía.
     */
    it('envía POST con latitude, longitude y address', async () => {
      const { llamadas } = await render(conUbicacion);
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeEnabled();
      });

      fireEvent.click(screen.getByRole('button', { name: 'Guardar Ubicación' }));

      await waitFor(() => {
        const guardado = llamadas.find((l) => l.options.method === 'POST');
        expect(guardado).toBeDefined();
        expect(cuerpoDe(guardado)).toEqual({
          latitude: -34.6037,
          longitude: -58.3816,
          address: 'Buenos Aires',
        });
      });
    });

    it('no usa PUT', async () => {
      const { llamadas } = await render(conUbicacion);
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeEnabled();
      });

      fireEvent.click(screen.getByRole('button', { name: 'Guardar Ubicación' }));

      await waitFor(() => {
        expect(llamadas.find((l) => l.options.method === 'POST')).toBeDefined();
      });
      expect(llamadas.find((l) => l.options.method === 'PUT')).toBeUndefined();
    });

    it('confirma al usuario', async () => {
      await render(conUbicacion);
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeEnabled();
      });

      fireEvent.click(screen.getByRole('button', { name: 'Guardar Ubicación' }));

      expect(await screen.findByText('Ubicación guardada correctamente')).toBeInTheDocument();
    });

    it('muestra el error que devuelve la API', async () => {
      // La carga inicial trae una ubicación (200) y el guardado falla (400).
      let primera = true;
      global.fetch = vi.fn(() =>
        Promise.resolve(
          primera
            ? ((primera = false), {
                ok: true, status: 200,
                json: () => Promise.resolve(conUbicacion),
              })
            : {
                ok: false, status: 400,
                json: () => Promise.resolve({ error: 'Coordenadas inválidas' }),
              }
        )
      );

      renderConProviders(<LocationSettings />, { auth: autenticado() });
      await screen.findByRole('heading', { name: 'Mi Ubicación' });

      const guardar = await screen.findByRole('button', { name: 'Guardar Ubicación' });
      await waitFor(() => expect(guardar).toBeEnabled());

      fireEvent.click(guardar);

      expect(await screen.findByText('Coordenadas inválidas')).toBeInTheDocument();
    });

    it('avisa si falla la red', async () => {
      await render(conUbicacion);
      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Guardar Ubicación' })).toBeEnabled();
      });

      global.fetch = vi.fn(() => Promise.reject(new Error('sin red')));
      fireEvent.click(screen.getByRole('button', { name: 'Guardar Ubicación' }));

      expect(await screen.findByText('Error al guardar ubicación')).toBeInTheDocument();
    });
  });
});
