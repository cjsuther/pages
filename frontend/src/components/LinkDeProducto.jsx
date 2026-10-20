import React, { useState } from 'react';
import { Copy, Check, ExternalLink } from 'lucide-react';
import { linkDeProducto } from '../utils/productos';

/**
 * El link público de un producto, para que quien organiza lo copie y lo
 * comparta por WhatsApp o redes. Es /producto/<id>, como /evento/<id>.
 *
 * Mientras el producto esté oculto o no tenga archivo, el link existe pero
 * dice "no disponible": se avisa, para que no lo mande antes de tiempo.
 */
function LinkDeProducto({ linkId, oculto, conArchivo }) {
  const [copiado, setCopiado] = useState(false);
  const [error, setError] = useState(null);
  const url = linkDeProducto(linkId);

  const copiar = async () => {
    setError(null);
    try {
      await navigator.clipboard.writeText(url);
      setCopiado(true);
    } catch (e) {
      setError('No se pudo copiar: seleccioná el link y copialo a mano.');
    }
  };

  const aviso = oculto && !conArchivo
    ? 'El producto está oculto y todavía no tiene archivo: hasta que lo muestres y le subas el archivo, el link dice que no está disponible.'
    : oculto
      ? 'El producto está oculto: hasta que lo vuelvas a mostrar, el link dice que no está disponible.'
      : !conArchivo
        ? 'El producto todavía no tiene archivo: hasta que lo subas, el link dice que no está disponible.'
        : null;

  return (
    <div>
      <label htmlFor="link-producto" className="block text-sm font-semibold text-tinta mb-1.5">
        Link para compartir
      </label>
      <div className="flex gap-2">
        <input
          id="link-producto"
          type="text"
          readOnly
          value={url}
          onFocus={(e) => e.target.select()}
          className="flex-1 min-w-0 px-3 py-3 bg-papel-hueso border border-borde text-tinta text-sm font-mono rounded-xl"
        />
        <button
          type="button"
          onClick={copiar}
          className="shrink-0 inline-flex items-center gap-1.5 px-4 rounded-xl bg-tinta text-white text-sm font-semibold"
        >
          {copiado ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
          {copiado ? 'Link copiado' : 'Copiar link'}
        </button>
      </div>
      {aviso ? (
        <p className="text-xs text-red-700 mt-1">{aviso}</p>
      ) : (
        <p className="text-xs text-tinta-suave mt-1">
          Quien lo abra ve el producto con los colores de tu página y lo puede comprar ahí mismo.{' '}
          <a href={url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-verde-oscuro hover:underline">
            <ExternalLink className="w-3 h-3" /> Abrir
          </a>
        </p>
      )}
      {error && <p role="alert" className="text-xs text-red-700 mt-1">{error}</p>}
    </div>
  );
}

export default LinkDeProducto;
