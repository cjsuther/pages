import React, { useState, useEffect, useContext } from 'react';
import { AuthContext } from '../App';
import GooglePlacesAutocomplete from './GooglePlacesAutocomplete';

function LocationSettings() {
  const { token, apiUrl } = useContext(AuthContext);
  const [location, setLocation] = useState({
    latitude: null,
    longitude: null,
    location_name: ''
  });
  const [searchValue, setSearchValue] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    fetchLocation();
  }, []);

  const fetchLocation = async () => {
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await response.json();
      if (data.latitude && data.longitude) {
        setLocation({
          latitude: data.latitude,
          longitude: data.longitude,
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
      setError('Tu navegador no soporta geolocalización');
      setLoading(false);
      return;
    }

    navigator.geolocation.getCurrentPosition(
      async (position) => {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;

        try {
          const locationName = await reverseGeocode(lat, lng);
          setLocation({
            latitude: lat,
            longitude: lng,
            location_name: locationName
          });
          setSearchValue(locationName);
          setMessage('Ubicación obtenida correctamente');
        } catch (err) {
          const locName = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
          setLocation({
            latitude: lat,
            longitude: lng,
            location_name: locName
          });
          setSearchValue(locName);
          setMessage('Ubicación obtenida (sin nombre)');
        }
        setLoading(false);
      },
      (error) => {
        setError('No se pudo obtener tu ubicación. Verifica los permisos.');
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
  };

  const saveLocation = async () => {
    if (!location.latitude || !location.longitude) {
      setError('Debes seleccionar una ubicación primero');
      return;
    }

    setLoading(true);
    setError('');
    setMessage('');

    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(location)
      });

      const data = await response.json();

      if (response.ok) {
        setMessage('Ubicación guardada correctamente');
      } else {
        setError(data.error || 'Error al guardar ubicación');
      }
    } catch (err) {
      setError('Error al guardar ubicación');
      console.error('Error saving location:', err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="bg-gray-900 border border-gray-800 p-8">
      <h2 className="text-2xl font-bold mb-4">Mi Ubicación</h2>
      <p className="text-gray-400 mb-8">
        Configura tu ubicación principal para recibir notificaciones de eventos cercanos.
      </p>

      <div className="space-y-6">
        <div>
          <label className="block text-sm font-bold text-gray-300 mb-3">
            Buscar ubicación
          </label>
          <GooglePlacesAutocomplete
            value={searchValue}
            onChange={(e) => setSearchValue(e.target.value)}
            onPlaceSelect={handlePlaceSelect}
            placeholder="Ingresa una dirección o ciudad..."
          />
        </div>

        <div className="flex items-center gap-4">
          <div className="flex-1 h-px bg-gray-800"></div>
          <span className="text-sm text-gray-500">o</span>
          <div className="flex-1 h-px bg-gray-800"></div>
        </div>

        <button
          onClick={getCurrentLocation}
          disabled={loading}
          className="w-full bg-white text-black py-3 font-bold hover:bg-gray-200 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed transition-colors"
        >
          Usar mi ubicación actual
        </button>

        {location.latitude && location.longitude && (
          <div className="bg-black border border-gray-800 p-6">
            <h3 className="font-bold mb-3">Ubicación seleccionada:</h3>
            <p className="text-sm text-gray-400 mb-2">{location.location_name || 'Sin nombre'}</p>
            <p className="text-xs text-gray-500">
              Lat: {location.latitude.toFixed(6)}, Lng: {location.longitude.toFixed(6)}
            </p>
          </div>
        )}

        <button
          onClick={saveLocation}
          disabled={loading || !location.latitude || !location.longitude}
          className="w-full bg-white text-black py-3 font-bold hover:bg-gray-200 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed transition-colors"
        >
          {loading ? 'Guardando...' : 'Guardar Ubicación'}
        </button>

        {message && (
          <div className="bg-green-900 border border-green-700 text-green-300 px-4 py-3">
            {message}
          </div>
        )}

        {error && (
          <div className="bg-red-900 border border-red-700 text-red-300 px-4 py-3">
            {error}
          </div>
        )}
      </div>
    </div>
  );
}

export default LocationSettings;
