import React, { useEffect, useRef, useState } from 'react';
import { MapPin, RefreshCw } from 'lucide-react';

function EventsMap({ events, userLocation, onEventClick }) {
  const mapRef = useRef(null);
  const mapInstanceRef = useRef(null);
  const markersRef = useRef([]);
  const markerClustererRef = useRef(null);
  const [error, setError] = useState(null);
  const [retryKey, setRetryKey] = useState(0);

  const zoomInitial = 14

  const handleRetry = () => {
    setError(null);
    mapInstanceRef.current = null;
    setRetryKey(prev => prev + 1);
  };

  useEffect(() => {
    if (!window.google) {
      setError('Google Maps no está disponible');
      return;
    }

    setError(null);

    if (!mapRef.current) return;

    const center = userLocation
      ? { lat: userLocation.lat, lng: userLocation.lng }
      : { lat: -34.6037, lng: -58.3816 };

    if (!mapInstanceRef.current) {
      mapInstanceRef.current = new window.google.maps.Map(mapRef.current, {
        center,
        zoom: userLocation ? zoomInitial : zoomInitial - 2,
        disableDefaultUI: true,
        zoomControl: true,
        styles: [
          {
            "featureType": "all",
            "elementType": "labels",
            "stylers": [{ "visibility": "on" }]
          },
          {
            "featureType": "all",
            "elementType": "labels.text",
            "stylers": [{ "visibility": "simplified" }]
          },
          {
            "featureType": "administrative",
            "elementType": "all",
            "stylers": [{ "visibility": "off" }]
          },
          {
            "featureType": "landscape",
            "elementType": "geometry",
            "stylers": [{ "color": "#1a1a1a" }]
          },
          {
            "featureType": "poi",
            "elementType": "all",
            "stylers": [{ "visibility": "off" }]
          },
          {
            "featureType": "road",
            "elementType": "geometry",
            "stylers": [{ "color": "#2c2c2c" }]
          },
          {
            "featureType": "road",
            "elementType": "labels.text.fill",
            "stylers": [{ "color": "#6a6a6a" }]
          },
          {
            "featureType": "road",
            "elementType": "labels.icon",
            "stylers": [{ "visibility": "off" }]
          },
          {
            "featureType": "transit",
            "elementType": "all",
            "stylers": [{ "visibility": "off" }]
          },
          {
            "featureType": "water",
            "elementType": "geometry",
            "stylers": [{ "color": "#0a0a0a" }]
          },
          {
            "featureType": "water",
            "elementType": "labels.text.fill",
            "stylers": [{ "color": "#4a4a4a" }]
          }
        ]
      });
    } else if (userLocation) {
      mapInstanceRef.current.setCenter(center);
      mapInstanceRef.current.setZoom(zoomInitial);
    }

    if (markerClustererRef.current) {
      markerClustererRef.current.clearMarkers();
    }

    markersRef.current.forEach(marker => marker.setMap(null));
    markersRef.current = [];

    if (userLocation) {
      new window.google.maps.Marker({
        position: { lat: userLocation.lat, lng: userLocation.lng },
        map: mapInstanceRef.current,
        icon: {
          path: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
          fillColor: '#3b82f6',
          fillOpacity: 1,
          strokeColor: '#1e40af',
          strokeWeight: 2,
          scale: 2,
          anchor: new window.google.maps.Point(12, 21)
        },
        title: 'Tu ubicación',
        zIndex: 1000
      });
    }

    const eventsByLocation = {};
    events.forEach(event => {
      if (event.event_latitude && event.event_longitude) {
        const key = `${event.event_latitude},${event.event_longitude}`;
        if (!eventsByLocation[key]) {
          eventsByLocation[key] = [];
        }
        eventsByLocation[key].push(event);
      }
    });

    Object.entries(eventsByLocation).forEach(([locationKey, locationEvents]) => {
      const [lat, lng] = locationKey.split(',').map(parseFloat);

      const marker = new window.google.maps.Marker({
        position: { lat, lng },
        map: mapInstanceRef.current,
        icon: {
          path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z',
          fillColor: '#10b981',
          fillOpacity: 1,
          strokeColor: '#065f46',
          strokeWeight: 2,
          scale: 1.8,
          anchor: new window.google.maps.Point(12, 22)
        },
        title: locationEvents.length > 1
          ? `${locationEvents.length} eventos en esta ubicación`
          : locationEvents[0].text
      });

      let infoWindow;

      if (locationEvents.length === 1) {
        const event = locationEvents[0];
        const imageUrl = event.image_url || 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=400';
        infoWindow = new window.google.maps.InfoWindow({
          content: `
            <div style="color: #000; max-width: 280px;">
              <img
                src="${imageUrl}"
                alt="${event.text}"
                style="width: 100%; height: 150px; object-fit: cover; border-radius: 8px 8px 0 0; margin: -12px -12px 12px -12px;"
              />
              <div style="padding: 0 12px 12px 12px;">
                <h3 style="font-weight: bold; margin-bottom: 8px; font-size: 16px;">${event.text}</h3>
                <p style="font-size: 13px; color: #666; margin-bottom: 6px;">
                  ${new Date(event.event_date + ' ' + event.event_time).toLocaleDateString('es-AR', {
                    timeZone: 'America/Argentina/Buenos_Aires',
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                  })}
                </p>
                ${event.event_address ? `
                  <p style="font-size: 13px; color: #666; margin-bottom: 8px;">
                    📍 ${event.event_address}
                  </p>
                ` : ''}
                <button
                  onclick="window.open('/${event.page_slug}', '_blank')"
                  style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; margin-top: 8px; width: 100%;"
                >
                  Ver evento
                </button>
              </div>
            </div>
          `
        });
      } else {
        const eventsHtml = locationEvents.map(event => {
          const imageUrl = event.image_url || 'https://images.pexels.com/photos/1190298/pexels-photo-1190298.jpeg?auto=compress&cs=tinysrgb&w=200';
          return `
            <div style="padding: 12px; border-bottom: 1px solid #e5e7eb; cursor: pointer; display: flex; gap: 12px; align-items: center;"
                 onclick="window.open('/${event.page_slug}', '_blank')">
              <img
                src="${imageUrl}"
                alt="${event.text}"
                style="width: 60px; height: 60px; object-fit: cover; border-radius: 6px; flex-shrink: 0;"
              />
              <div style="flex: 1;">
                <h4 style="font-weight: bold; margin-bottom: 4px; font-size: 14px;">${event.text}</h4>
                <p style="font-size: 12px; color: #666;">
                  ${new Date(event.event_date + ' ' + event.event_time).toLocaleDateString('es-AR', {
                    timeZone: 'America/Argentina/Buenos_Aires',
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                  })}
                </p>
              </div>
            </div>
          `;
        }).join('');

        infoWindow = new window.google.maps.InfoWindow({
          content: `
            <div style="color: #000; max-width: 300px; max-height: 400px; overflow-y: auto;">
              <h3 style="font-weight: bold; padding: 12px; border-bottom: 2px solid #10b981; font-size: 16px;">
                ${locationEvents.length} eventos en esta ubicación
              </h3>
              ${eventsHtml}
            </div>
          `
        });
      }

      marker.addListener('mouseover', () => {
        infoWindow.open(mapInstanceRef.current, marker);
      });

      marker.addListener('click', () => {
        infoWindow.open(mapInstanceRef.current, marker);
      });

      markersRef.current.push(marker);
    });

    if (window.markerClusterer && markersRef.current.length > 0) {
      markerClustererRef.current = new markerClusterer.MarkerClusterer({
        map: mapInstanceRef.current,
        markers: markersRef.current,
        renderer: {
          render: ({ count, position }) => {
            return new window.google.maps.Marker({
              position,
              icon: {
                path: window.google.maps.SymbolPath.CIRCLE,
                scale: 15 + (count * 2),
                fillColor: '#10b981',
                fillOpacity: 0.9,
                strokeColor: '#065f46',
                strokeWeight: 3
              },
              label: {
                text: String(count),
                color: '#ffffff',
                fontSize: '14px',
                fontWeight: 'bold'
              },
              zIndex: Number(window.google.maps.Marker.MAX_ZINDEX) + count
            });
          }
        }
      });
    }

  }, [events, userLocation, onEventClick, retryKey]);

  if (error) {
    return (
      <div className="bg-gray-900 border border-gray-800 p-12 text-center">
        <MapPin className="w-12 h-12 text-gray-700 mx-auto mb-4" />
        <p className="text-gray-500 mb-4">{error}</p>
        <button
          onClick={handleRetry}
          className="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-6 py-3 transition-colors"
        >
          <RefreshCw className="w-4 h-4" />
          Cargar mapa
        </button>
      </div>
    );
  }

  return (
    <div className="relative">
      <div
        ref={mapRef}
        className="w-full h-[500px] bg-gray-900 border border-gray-800"
      />
    </div>
  );
}

export default EventsMap;
