import React, { useState } from 'react';
import { Share2 } from 'lucide-react';
import { compartirLink } from '../utils/compartir';

/**
 * Compartir el link propio de un producto o un evento: el menú del sistema
 * donde exista y, si no, copiarlo con un aviso.
 */
export default function BotonCompartir({ url, titulo }) {
  const [aviso, setAviso] = useState(null);

  const compartir = async () => {
    try {
      const resultado = await compartirLink({ url, titulo });
      setAviso(resultado === 'copiado' ? 'Link copiado' : null);
    } catch (e) {
      setAviso('No se pudo copiar el link');
    }
  };

  return (
    <span className="inline-flex items-center gap-2">
      <button
        type="button"
        onClick={compartir}
        className="inline-flex items-center gap-1.5 text-sm font-semibold opacity-80 hover:opacity-100 transition"
      >
        <Share2 className="w-4 h-4 shrink-0" aria-hidden="true" />
        Compartir
      </button>
      <span role="status" className="text-sm opacity-70">{aviso}</span>
    </span>
  );
}
