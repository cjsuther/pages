import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import EventsMap from '../../src/components/EventsMap';

/** Doble de la API de Google Maps que registra mapas, marcadores e infowindows. */
function instalarGoogleMaps() {
  const mapas = [];
  const marcadores = [];
  const infoWindows = [];

  class Map {
    constructor(el, opciones) {
      this.el = el;
      this.opciones = opciones;
      this.setCenter = vi.fn();
      this.setZoom = vi.fn();
      mapas.push(this);
    }
  }

  class Marker {
    constructor(opciones) {
      this.opciones = opciones;
      this.listeners = {};
      this.setMap = vi.fn();
      this.addListener = vi.fn((evento, cb) => { this.listeners[evento] = cb; });
      marcadores.push(this);
    }
  }

  class InfoWindow {
    constructor(opciones) {
      this.content = opciones.content;
      this.open = vi.fn();
      infoWindows.push(this);
    }
  }

  window.google = {
    maps: {
      Map,
      Marker: Object.assign(Marker, { MAX_ZINDEX: 1000 }),
      InfoWindow,
      Point: class { constructor(x, y) { this.x = x; this.y = y; } },
      SymbolPath: { CIRCLE: 'circle' },
    },
  };

  return { mapas, marcadores, infoWindows };
}

const evento = (overrides = {}) => ({
  id: 100,
  text: 'Recital de Rock',
  image_url: null,
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  event_latitude: '-34.6037',
  event_longitude: '-58.3816',
  ...overrides,
});

describe('EventsMap', () => {
  let maps;

  beforeEach(() => {
    maps = instalarGoogleMaps();
  });

  afterEach(() => {
    window.google = undefined;
    window.markerClusterer = undefined;
  });

  describe('sin Google Maps disponible', () => {
    beforeEach(() => {
      window.google = undefined;
    });

    it('muestra el mensaje de error', () => {
      render(<EventsMap events={[]} userLocation={null} />);

      expect(screen.getByText('Google Maps no está disponible')).toBeInTheDocument();
    });

    it('ofrece reintentar', () => {
      render(<EventsMap events={[]} userLocation={null} />);

      expect(screen.getByRole('button', { name: /Cargar mapa/ })).toBeInTheDocument();
    });

    it('el reintento vuelve a intentar la carga', () => {
      render(<EventsMap events={[]} userLocation={null} />);

      instalarGoogleMaps();
      fireEvent.click(screen.getByRole('button', { name: /Cargar mapa/ }));

      expect(screen.queryByText('Google Maps no está disponible')).not.toBeInTheDocument();
    });
  });

  describe('inicialización del mapa', () => {
    it('crea el mapa', () => {
      render(<EventsMap events={[]} userLocation={null} />);

      expect(maps.mapas).toHaveLength(1);
    });

    it('centra en Buenos Aires si no hay ubicación del usuario', () => {
      render(<EventsMap events={[]} userLocation={null} />);

      expect(maps.mapas[0].opciones.center).toEqual({ lat: -34.6037, lng: -58.3816 });
    });

    it('centra en la ubicación del usuario si la hay', () => {
      render(<EventsMap events={[]} userLocation={{ lat: -31.42, lng: -64.18 }} />);

      expect(maps.mapas[0].opciones.center).toEqual({ lat: -31.42, lng: -64.18 });
    });

    it('acerca más el zoom cuando conoce la ubicación', () => {
      const { unmount } = render(<EventsMap events={[]} userLocation={{ lat: -31.42, lng: -64.18 }} />);
      const conUbicacion = maps.mapas[0].opciones.zoom;
      unmount();

      maps = instalarGoogleMaps();
      render(<EventsMap events={[]} userLocation={null} />);

      expect(conUbicacion).toBeGreaterThan(maps.mapas[0].opciones.zoom);
    });

    it('no crea un mapa nuevo al cambiar los eventos', () => {
      const { rerender } = render(<EventsMap events={[]} userLocation={null} />);

      rerender(<EventsMap events={[evento()]} userLocation={null} />);

      expect(maps.mapas).toHaveLength(1);
    });
  });

  describe('marcador del usuario', () => {
    it('no lo dibuja sin ubicación', () => {
      render(<EventsMap events={[]} userLocation={null} />);

      expect(maps.marcadores).toHaveLength(0);
    });

    it('lo dibuja con la etiqueta correcta', () => {
      render(<EventsMap events={[]} userLocation={{ lat: -34.6, lng: -58.4 }} />);

      expect(maps.marcadores[0].opciones.title).toBe('Tu ubicación');
    });

    it('queda por encima de los eventos', () => {
      render(<EventsMap events={[evento()]} userLocation={{ lat: -34.6, lng: -58.4 }} />);

      expect(maps.marcadores[0].opciones.zIndex).toBe(1000);
    });
  });

  describe('marcadores de eventos', () => {
    it('dibuja uno por evento', () => {
      render(
        <EventsMap
          events={[
            evento({ id: 1, event_latitude: '-34.60', event_longitude: '-58.38' }),
            evento({ id: 2, event_latitude: '-34.70', event_longitude: '-58.48' }),
          ]}
          userLocation={null}
        />
      );

      expect(maps.marcadores).toHaveLength(2);
    });

    it('ignora eventos sin coordenadas', () => {
      render(
        <EventsMap
          events={[evento({ event_latitude: null, event_longitude: null })]}
          userLocation={null}
        />
      );

      expect(maps.marcadores).toHaveLength(0);
    });

    it('usa el nombre del evento como título', () => {
      render(<EventsMap events={[evento({ text: 'Recital de Rock' })]} userLocation={null} />);

      expect(maps.marcadores[0].opciones.title).toBe('Recital de Rock');
    });

    it('agrupa los eventos que comparten ubicación en un solo marcador', () => {
      render(
        <EventsMap
          events={[
            evento({ id: 1, text: 'Uno', event_latitude: '-34.60', event_longitude: '-58.38' }),
            evento({ id: 2, text: 'Dos', event_latitude: '-34.60', event_longitude: '-58.38' }),
          ]}
          userLocation={null}
        />
      );

      expect(maps.marcadores).toHaveLength(1);
      expect(maps.marcadores[0].opciones.title).toBe('2 eventos en esta ubicación');
    });

    it('limpia los marcadores anteriores al cambiar los eventos', () => {
      const { rerender } = render(<EventsMap events={[evento({ id: 1 })]} userLocation={null} />);
      const primero = maps.marcadores[0];

      rerender(<EventsMap events={[evento({ id: 2, event_latitude: '-34.70' })]} userLocation={null} />);

      expect(primero.setMap).toHaveBeenCalledWith(null);
    });
  });

  describe('ventana de información', () => {
    it('incluye el nombre del evento', () => {
      render(<EventsMap events={[evento({ text: 'Recital de Rock' })]} userLocation={null} />);

      expect(maps.infoWindows[0].content).toContain('Recital de Rock');
    });

    it('enlaza al detalle del evento', () => {
      render(<EventsMap events={[evento({ id: 100 })]} userLocation={null} />);

      expect(maps.infoWindows[0].content).toContain('/evento/100');
    });

    it('incluye la dirección si la hay', () => {
      render(<EventsMap events={[evento({ event_address: 'Av. Corrientes 1234' })]} userLocation={null} />);

      expect(maps.infoWindows[0].content).toContain('Av. Corrientes 1234');
    });

    it('funciona sin dirección', () => {
      render(<EventsMap events={[evento({ event_address: null })]} userLocation={null} />);

      expect(maps.infoWindows[0].content).toContain('Recital de Rock');
    });

    it('usa una imagen por defecto', () => {
      render(<EventsMap events={[evento({ image_url: null })]} userLocation={null} />);

      expect(maps.infoWindows[0].content).toContain('pexels.com');
    });

    it('lista todos los eventos cuando comparten ubicación', () => {
      render(
        <EventsMap
          events={[
            evento({ id: 1, text: 'Uno' }),
            evento({ id: 2, text: 'Dos' }),
          ]}
          userLocation={null}
        />
      );

      expect(maps.infoWindows[0].content).toContain('Uno');
      expect(maps.infoWindows[0].content).toContain('Dos');
      expect(maps.infoWindows[0].content).toContain('2 eventos en esta ubicación');
    });

    it('se abre al pasar el mouse por el marcador', () => {
      render(<EventsMap events={[evento()]} userLocation={null} />);

      maps.marcadores[0].listeners.mouseover();

      expect(maps.infoWindows[0].open).toHaveBeenCalled();
    });

    it('se abre al hacer click en el marcador', () => {
      render(<EventsMap events={[evento()]} userLocation={null} />);

      maps.marcadores[0].listeners.click();

      expect(maps.infoWindows[0].open).toHaveBeenCalled();
    });

    /**
     * El contenido se arma concatenando HTML con datos que carga el dueño de la
     * página. Hoy no se escapan, así que un título con etiquetas se inyecta tal
     * cual en la ventana que ven los demás visitantes (XSS almacenado).
     * Queda fijado para que el día que se escape el test avise.
     */
    it('hoy no escapa el HTML del título del evento', () => {
      render(
        <EventsMap
          events={[evento({ text: '<img src=x onerror="alert(1)">' })]}
          userLocation={null}
        />
      );

      expect(maps.infoWindows[0].content).toContain('onerror="alert(1)"');
    });
  });

  describe('agrupamiento de marcadores', () => {
    it('no agrupa si la librería no está cargada', () => {
      render(<EventsMap events={[evento()]} userLocation={null} />);

      expect(window.markerClusterer).toBeUndefined();
    });

    it('agrupa cuando la librería está disponible', () => {
      const MarkerClusterer = vi.fn();
      window.markerClusterer = { MarkerClusterer };

      render(<EventsMap events={[evento()]} userLocation={null} />);

      expect(MarkerClusterer).toHaveBeenCalledWith(
        expect.objectContaining({ markers: expect.any(Array) })
      );
    });

    it('no agrupa si no hay marcadores', () => {
      const MarkerClusterer = vi.fn();
      window.markerClusterer = { MarkerClusterer };

      render(<EventsMap events={[]} userLocation={null} />);

      expect(MarkerClusterer).not.toHaveBeenCalled();
    });
  });
});
