import React, { useEffect, useRef, useState } from 'react';

function GooglePlacesAutocomplete({ value, onChange, onPlaceSelect, placeholder, required = false }) {
  const inputRef = useRef(null);
  const autocompleteRef = useRef(null);
  const [hasSelectedPlace, setHasSelectedPlace] = useState(false);

  useEffect(() => {
    if (!window.google || !inputRef.current) return;

    autocompleteRef.current = new window.google.maps.places.Autocomplete(inputRef.current, {
      types: ['geocode', 'establishment'],
      fields: ['formatted_address', 'geometry', 'name', 'url']
    });

    autocompleteRef.current.addListener('place_changed', () => {
      const place = autocompleteRef.current.getPlace();

      if (place.geometry) {
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();
        const address = place.formatted_address || place.name;
        const mapsUrl = place.url || `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;

        setHasSelectedPlace(true);
        onPlaceSelect({
          address,
          latitude: lat,
          longitude: lng,
          mapsUrl
        });
      }
    });

    return () => {
      if (autocompleteRef.current && window.google) {
        window.google.maps.event.clearInstanceListeners(autocompleteRef.current);
      }
    };
  }, [onPlaceSelect]);

  useEffect(() => {
    if (value && value.length > 0) {
      setHasSelectedPlace(true);
    } else {
      setHasSelectedPlace(false);
    }
  }, [value]);

  const handleChange = (e) => {
    setHasSelectedPlace(false);
    onChange(e);
  };

  return (
    <div>
      <input
        ref={inputRef}
        type="text"
        value={value}
        onChange={handleChange}
        placeholder={placeholder || "Buscar dirección..."}
        className="w-full px-4 py-3 bg-black border border-gray-800 text-white placeholder-gray-500 focus:outline-none focus:border-gray-600"
        required={required}
      />
      {required && !hasSelectedPlace && value && (
        <p className="text-xs text-yellow-500 mt-2">
          Debes seleccionar una dirección de las sugerencias de Google Maps
        </p>
      )}
    </div>
  );
}

export default GooglePlacesAutocomplete;
