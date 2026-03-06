import React, { useState, useEffect, useContext } from 'react';
import { useNavigate } from 'react-router-dom';
import { MapPin, X, Loader } from 'lucide-react';
import { AuthContext } from '../App';
import GooglePlacesAutocomplete from './GooglePlacesAutocomplete';
import { handleApiResponse } from '../utils/apiHandler';

function LocationIndicator() {
  const { token, apiUrl, logout } = useContext(AuthContext);
  const navigate = useNavigate();
  const [hasLocation, setHasLocation] = useState(false);
  const [showPopup, setShowPopup] = useState(false);
  const [loading, setLoading] = useState(false);
  const [address, setAddress] = useState('');
  const [coordinates, setCoordinates] = useState(null);
  const [currentLocation, setCurrentLocation] = useState(null);

  useEffect(() => {
    checkUserLocation();
  }, []);

  useEffect(() => {
    if (showPopup && currentLocation) {
      setAddress(currentLocation.address || '');
      if (currentLocation.latitude && currentLocation.longitude) {
        setCoordinates({
          lat: currentLocation.latitude,
          lng: currentLocation.longitude
        });
      }
    }
  }, [showPopup, currentLocation]);

  const checkUserLocation = async () => {
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      await handleApiResponse(response, navigate, logout);
      const data = await response.json();
      const locationExists = !!(data.latitude && data.longitude);
      setHasLocation(locationExists);
      if (locationExists) {
        setCurrentLocation({ latitude: data.latitude, longitude: data.longitude });
      }
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        console.error('Error checking location:', err);
      }
    }
  };

  const handleUseCurrentLocation = () => {
    if ('geolocation' in navigator) {
      setLoading(true);
      navigator.geolocation.getCurrentPosition(
        async (position) => {
          try {
            const response = await fetch(`${apiUrl}/users/location.php`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
              },
              body: JSON.stringify({
                latitude: position.coords.latitude,
                longitude: position.coords.longitude
              })
            });

            await handleApiResponse(response, navigate, logout);

            if (response.ok) {
              setHasLocation(true);
              setShowPopup(false);
              setAddress('');
              setCoordinates(null);
              await checkUserLocation();
              alert('Ubicación actualizada correctamente');
            }
          } catch (err) {
            if (err.message !== 'Unauthorized') {
              console.error('Error saving location:', err);
              alert('Error al guardar la ubicación');
            }
          } finally {
            setLoading(false);
          }
        },
        (error) => {
          console.error('Error getting location:', error);
          alert('No se pudo obtener tu ubicación. Verifica los permisos del navegador.');
          setLoading(false);
        }
      );
    } else {
      alert('Tu navegador no soporta geolocalización');
    }
  };

  const handleSaveAddress = async () => {
    if (!coordinates) {
      alert('Por favor selecciona una dirección válida');
      return;
    }

    setLoading(true);
    try {
      const response = await fetch(`${apiUrl}/users/location.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
          latitude: coordinates.lat,
          longitude: coordinates.lng,
          address: address
        })
      });

      await handleApiResponse(response, navigate, logout);

      if (response.ok) {
        setHasLocation(true);
        setShowPopup(false);
        setAddress('');
        setCoordinates(null);
        await checkUserLocation();
        alert('Ubicación guardada correctamente');
      }
    } catch (err) {
      if (err.message !== 'Unauthorized') {
        console.error('Error saving location:', err);
        alert('Error al guardar la ubicación');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <button
        onClick={() => setShowPopup(true)}
        className="relative text-gray-400 hover:text-white transition"
      >
        <MapPin className="w-5 h-5" />
        {!hasLocation && (
          <span className="absolute -top-1 -right-1 w-3 h-3 bg-red-500 rounded-full"></span>
        )}
      </button>

      {showPopup && (
        <div className="fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center p-4 z-50">
          <div className="bg-gray-900 border border-gray-800 max-w-lg w-full p-8">
            <div className="flex justify-between items-center mb-6">
              <h2 className="text-2xl font-bold">UBICACIÓN</h2>
              <button
                onClick={() => setShowPopup(false)}
                className="text-gray-400 hover:text-white transition"
              >
                <X className="w-6 h-6" />
              </button>
            </div>

            <p className="text-gray-400 mb-6">
              Configura tu ubicación para recibir notificaciones de eventos cercanos
            </p>

            <div className="space-y-4">
              <button
                onClick={handleUseCurrentLocation}
                disabled={loading}
                className="w-full bg-white text-black px-6 py-4 font-bold hover:bg-gray-200 transition disabled:opacity-50 flex items-center justify-center gap-2"
              >
                {loading ? (
                  <>
                    <Loader className="w-5 h-5 animate-spin" />
                    Obteniendo ubicación...
                  </>
                ) : (
                  <>
                    <MapPin className="w-5 h-5" />
                    USAR MI UBICACIÓN ACTUAL
                  </>
                )}
              </button>

              <div className="relative">
                <div className="absolute inset-0 flex items-center">
                  <div className="w-full border-t border-gray-700"></div>
                </div>
                <div className="relative flex justify-center text-sm">
                  <span className="px-4 bg-gray-900 text-gray-500">O</span>
                </div>
              </div>

              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-bold text-gray-400 mb-2">
                    INGRESA TU DIRECCIÓN
                  </label>
                  <GooglePlacesAutocomplete
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                    onPlaceSelect={(place) => {
                      setAddress(place.address);
                      setCoordinates({
                        lat: place.latitude,
                        lng: place.longitude
                      });
                    }}
                    placeholder="Buscar dirección..."
                  />
                </div>

                <button
                  onClick={handleSaveAddress}
                  disabled={loading || !coordinates}
                  className="w-full bg-gray-800 text-white px-6 py-3 font-bold hover:bg-gray-700 transition disabled:opacity-50"
                >
                  GUARDAR DIRECCIÓN
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default LocationIndicator;
