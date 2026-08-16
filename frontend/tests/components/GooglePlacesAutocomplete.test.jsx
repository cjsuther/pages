import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import GooglePlacesAutocomplete from '../../src/components/GooglePlacesAutocomplete';

/** Simula la API de Google Places y expone el listener registrado. */
function instalarGoogleMaps() {
  const listeners = {};
  const autocomplete = {
    addListener: vi.fn((evento, cb) => {
      listeners[evento] = cb;
    }),
    getPlace: vi.fn(),
  };

  window.google = {
    maps: {
      places: {
        Autocomplete: vi.fn(() => autocomplete),
      },
      event: {
        clearInstanceListeners: vi.fn(),
      },
    },
  };

  return { autocomplete, listeners };
}

const lugar = (overrides = {}) => ({
  formatted_address: 'Av. Corrientes 1234, Buenos Aires',
  name: 'Teatro',
  url: 'https://maps.google.com/?cid=123',
  geometry: {
    location: {
      lat: () => -34.6037,
      lng: () => -58.3816,
    },
  },
  ...overrides,
});

describe('GooglePlacesAutocomplete', () => {
  afterEach(() => {
    window.google = undefined;
  });

  describe('sin Google Maps cargado', () => {
    it('igual renderiza el input', () => {
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={vi.fn()} />);

      expect(screen.getByRole('textbox')).toBeInTheDocument();
    });

    it('no rompe al escribir', () => {
      const onChange = vi.fn();
      render(<GooglePlacesAutocomplete value="" onChange={onChange} onPlaceSelect={vi.fn()} />);

      fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Corrientes' } });

      expect(onChange).toHaveBeenCalled();
    });
  });

  describe('con Google Maps cargado', () => {
    let google;

    beforeEach(() => {
      google = instalarGoogleMaps();
    });

    it('inicializa el autocompletado sobre el input', () => {
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={vi.fn()} />);

      expect(window.google.maps.places.Autocomplete).toHaveBeenCalledOnce();

      const [, opciones] = window.google.maps.places.Autocomplete.mock.calls[0];
      expect(opciones.types).toEqual(['geocode', 'establishment']);
      expect(opciones.fields).toContain('geometry');
      expect(opciones.fields).toContain('formatted_address');
    });

    it('avisa con la dirección y las coordenadas al elegir un lugar', () => {
      const onPlaceSelect = vi.fn();
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={onPlaceSelect} />);

      google.autocomplete.getPlace.mockReturnValue(lugar());
      act(() => google.listeners.place_changed());

      expect(onPlaceSelect).toHaveBeenCalledWith({
        address: 'Av. Corrientes 1234, Buenos Aires',
        latitude: -34.6037,
        longitude: -58.3816,
        mapsUrl: 'https://maps.google.com/?cid=123',
      });
    });

    it('usa el nombre del lugar si no hay dirección formateada', () => {
      const onPlaceSelect = vi.fn();
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={onPlaceSelect} />);

      google.autocomplete.getPlace.mockReturnValue(lugar({ formatted_address: null }));
      act(() => google.listeners.place_changed());

      expect(onPlaceSelect.mock.calls[0][0].address).toBe('Teatro');
    });

    it('arma una URL de mapa si el lugar no trae una', () => {
      const onPlaceSelect = vi.fn();
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={onPlaceSelect} />);

      google.autocomplete.getPlace.mockReturnValue(lugar({ url: null }));
      act(() => google.listeners.place_changed());

      expect(onPlaceSelect.mock.calls[0][0].mapsUrl).toBe(
        'https://www.google.com/maps/search/?api=1&query=-34.6037,-58.3816'
      );
    });

    it('ignora lugares sin geometría', () => {
      const onPlaceSelect = vi.fn();
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={onPlaceSelect} />);

      google.autocomplete.getPlace.mockReturnValue({ name: 'Sin coordenadas' });
      act(() => google.listeners.place_changed());

      expect(onPlaceSelect).not.toHaveBeenCalled();
    });

    it('limpia los listeners al desmontar', () => {
      const { unmount } = render(
        <GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={vi.fn()} />
      );

      unmount();

      expect(window.google.maps.event.clearInstanceListeners).toHaveBeenCalledWith(google.autocomplete);
    });
  });

  describe('aviso de dirección sin validar', () => {
    const AVISO = 'Debes seleccionar una dirección de las sugerencias de Google Maps';

    it('no aparece si el campo no es obligatorio', () => {
      render(<GooglePlacesAutocomplete value="Corrientes" onChange={vi.fn()} onPlaceSelect={vi.fn()} />);

      expect(screen.queryByText(AVISO)).not.toBeInTheDocument();
    });

    it('no aparece con el campo vacío', () => {
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={vi.fn()} required />);

      expect(screen.queryByText(AVISO)).not.toBeInTheDocument();
    });

    it('no aparece si el valor vino ya cargado', () => {
      // Al editar un evento existente la dirección ya estaba validada.
      render(<GooglePlacesAutocomplete value="Corrientes 1234" onChange={vi.fn()} onPlaceSelect={vi.fn()} required />);

      expect(screen.queryByText(AVISO)).not.toBeInTheDocument();
    });

    it('aparece al escribir a mano sin elegir sugerencia', () => {
      render(<GooglePlacesAutocomplete value="Corrientes" onChange={vi.fn()} onPlaceSelect={vi.fn()} required />);

      fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Corrientes 12' } });

      expect(screen.getByText(AVISO)).toBeInTheDocument();
    });
  });

  describe('input', () => {
    it('usa el placeholder por defecto', () => {
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={vi.fn()} />);

      expect(screen.getByPlaceholderText('Buscar dirección...')).toBeInTheDocument();
    });

    it('acepta un placeholder propio', () => {
      render(
        <GooglePlacesAutocomplete
          value=""
          onChange={vi.fn()}
          onPlaceSelect={vi.fn()}
          placeholder="¿Dónde es?"
        />
      );

      expect(screen.getByPlaceholderText('¿Dónde es?')).toBeInTheDocument();
    });

    it('refleja el valor recibido', () => {
      render(<GooglePlacesAutocomplete value="Corrientes 1234" onChange={vi.fn()} onPlaceSelect={vi.fn()} />);

      expect(screen.getByRole('textbox')).toHaveValue('Corrientes 1234');
    });

    it('marca el input como obligatorio cuando corresponde', () => {
      render(<GooglePlacesAutocomplete value="" onChange={vi.fn()} onPlaceSelect={vi.fn()} required />);

      expect(screen.getByRole('textbox')).toBeRequired();
    });

    it('propaga cada cambio al padre', () => {
      const onChange = vi.fn();
      render(<GooglePlacesAutocomplete value="" onChange={onChange} onPlaceSelect={vi.fn()} />);

      fireEvent.change(screen.getByRole('textbox'), { target: { value: 'abc' } });

      expect(onChange).toHaveBeenCalledOnce();
    });
  });
});
