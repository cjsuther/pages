import React from 'react';
import { MapPin } from 'lucide-react';

/** Sólo un link http o https se puede abrir: la API lo valida, y acá no se confía. */
const esLinkWeb = (url) => /^https?:\/\//i.test(String(url || '').trim());

/**
 * El punto de retiro de un físico (#53): la dirección, que abre Google Maps,
 * y debajo los días, horarios e indicaciones. Es de quien vende: se muestra
 * entero, en el detalle, en la compra y en /compra/:codigo.
 */
function PuntoDeRetiro({ retiro, prefijo = 'Retiro en', className = '' }) {
  if (!retiro?.direccion) return null;

  return (
    <div className={`flex items-start gap-2 text-sm ${className}`}>
      <MapPin className="w-4 h-4 shrink-0 mt-0.5" aria-hidden="true" />
      <span>
        {prefijo}{' '}
        {esLinkWeb(retiro.maps_url) ? (
          <a href={retiro.maps_url} target="_blank" rel="noopener noreferrer" className="underline font-semibold">
            {retiro.direccion}
          </a>
        ) : (
          <span className="font-semibold">{retiro.direccion}</span>
        )}
        {retiro.indicaciones && <span className="block opacity-80">{retiro.indicaciones}</span>}
      </span>
    </div>
  );
}

export default PuntoDeRetiro;
