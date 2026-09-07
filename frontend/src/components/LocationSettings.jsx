import React, { useState, useEffect, useContext } from 'react';
import { MapPin, Crosshair, Check } from 'lucide-react';
import { AuthContext } from '../App';
import GooglePlacesAutocomplete from './GooglePlacesAutocomplete';
import { Aviso, Boton, Etiqueta, Tarjeta } from './ui';

/**
 * La ubicación de referencia de la persona.
 *
 * Se usa para decidir qué eventos entran en "cerca mío" y para las alertas de
 * fechas cercanas. Es un punto, no un seguimiento: se guarda una vez y no se
 * vuelve a preguntar.
 */
function LocationSettings({ alGuardar = () => {} }) {
  const { token, apiUrl } = useContext(AuthContext);

  const [location, setLocation] = useState({ latitude: null, longitude: null, location_name: '' });
  const [searchValue, setSearchValue] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    fetchLocation();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const fetchLocation = async () => {
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      if (data.latitude && data.longitude) {
        setLocation({
          latitude: Number(data.latitude),
          longitude: Number(data.longitude),
          location_name: data.location_name || ''
        });
        setSearchValue(data.location_name || '');
      }
    } catch (err) {
      console.error('Error fetching location:', err);
    }
  };

  const getCurrentLocation = () => {
    setLoading(true);
    setError('');
    setMessage('');

    if (!navigator.geolocation) {
      setError('Tu navegador no puede darnos la ubicación.');
      setLoading(false);
      return;
    }

    navigator.geolocation.getCurrentPosition(
      async (position) => {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        const nombre = (await reverseGeocode(lat, lng)) || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;

        setLocation({ latitude: lat, longitude: lng, location_name: nombre });
        setSearchValue(nombre);
        setMessage('Listo. Acordate de guardar.');
        setLoading(false);
      },
      () => {
        setError('No pudimos acceder a tu ubicación. Revisá los permisos del navegador.');
        setLoading(false);
      }
    );
  };

  const reverseGeocode = async (lat, lng) => {
    try {
      const response = await fetch(
        `https://maps.googleapis.com/maps/api/geocode/json?latlng=${lat},${lng}&key=${import.meta.env.VITE_GOOGLE_MAPS_API_KEY}`
      );
      const data = await response.json();
      if (data.results && data.results[0]) {
        return data.results[0].formatted_address;
      }
    } catch (err) {
      console.error('Error in reverse geocoding:', err);
    }
    return null;
  };

  const handlePlaceSelect = (place) => {
    setLocation({
      latitude: place.latitude,
      longitude: place.longitude,
      location_name: place.address
    });
    setSearchValue(place.address);
    setMessage('');
    setError('');
  };

  const saveLocation = async () => {
    if (!location.latitude || !location.longitude) {
      setError('Elegí una ubicación antes de guardar.');
      return;
    }

    setLoading(true);
    setError('');
    setMessage('');

    try {
      // El endpoint acepta POST (no PUT) y espera el nombre en `address`.
      // Antes se enviaba un PUT con `location_name`, que el backend ignoraba:
      // guardar la ubicación desde el perfil nunca llegaba a persistir nada.
      const response = await fetch(`${apiUrl}/users/location.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          latitude: location.latitude,
          longitude: location.longitude,
          address: location.location_name
        })
      });

      const data = await response.json();

      if (response.ok) {
        setMessage('Ubicación guardada.');
        alGuardar();
      } else {
        setError(data.error || 'No se pudo guardar la ubicación.');
      }
    } catch (err) {
      setError('No se pudo guardar la ubicación.');
      console.error('Error saving location:', err);
    } finally {
      setLoading(false);
    }
  };

  const tieneUbicacion = location.latitude !== null && location.longitude !== null;

  return (
    <Tarjeta className="p-6 space-y-5">
      <div>
        <Etiqueta htmlFor="buscar-ubicacion">Buscá tu ciudad o dirección</Etiqueta>
        <GooglePlacesAutocomplete
          id="buscar-ubicacion"
          value={searchValue}
          onChange={(e) => setSearchValue(e.target.value)}
          onPlaceSelect={handlePlaceSelect}
          placeholder="Por ejemplo: Villa Crespo, CABA"
        />
      </div>

      <div className="flex items-center gap-4">
        <span className="flex-1 h-px bg-borde" />
        <span className="text-sm text-tinta-suave">o</span>
        <span className="flex-1 h-px bg-borde" />
      </div>

      <Boton variante="secundario" onClick={getCurrentLocation} disabled={loading} className="w-full">
        <Crosshair className="w-4 h-4" />
        Usar mi ubicación actual
      </Boton>

      {tieneUbicacion && (
        <div className="flex items-start gap-3 p-4 rounded-xl bg-papel-hueso border border-borde">
          <MapPin className="w-4 h-4 text-verde-oscuro flex-shrink-0 mt-0.5" />
          <div className="min-w-0">
            <p className="font-semibold text-tinta text-sm">
              {location.location_name || 'Ubicación sin nombre'}
            </p>
            <p className="text-xs text-tinta-suave mt-0.5 tabular-nums">
              {Number(location.latitude).toFixed(4)}, {Number(location.longitude).toFixed(4)}
            </p>
          </div>
        </div>
      )}

      <Boton onClick={saveLocation} disabled={loading || !tieneUbicacion} className="w-full">
        {loading ? 'Guardando...' : 'Guardar ubicación'}
      </Boton>

      {message && (
        <Aviso tipo="ok">
          <span className="flex items-center gap-2"><Check className="w-4 h-4" /> {message}</span>
        </Aviso>
      )}
      {error && <Aviso tipo="error">{error}</Aviso>}
    </Tarjeta>
  );
}

export default LocationSettings;
