import React, { useCallback, useRef, useState } from 'react';
import RecortarImagen from '../components/RecortarImagen';
import { DESTINOS } from '../utils/recorte';

/**
 * Intercala el recorte entre elegir un archivo y subirlo.
 *
 *   const { recortar, dialogo } = useRecorte();
 *   const archivo = await recortar(e.target.files[0], 'perfil');
 *   if (archivo) subir(archivo);
 *   ...
 *   return <>{...}{dialogo}</>;
 *
 * Devuelve el archivo recortado, el original si la persona eligió usarlo sin
 * recortar, o null si canceló. Lo que no es una imagen pasa derecho: lo
 * rechaza la validación de siempre con su mensaje de siempre.
 */
export function useRecorte() {
  const [pedido, setPedido] = useState(null);
  const pendiente = useRef(null);

  const recortar = useCallback((archivo, destino) => {
    if (!archivo || !(archivo.type || '').startsWith('image/')) {
      return Promise.resolve(archivo || null);
    }

    // Si quedó un pedido abierto, se da por cancelado.
    if (pendiente.current) pendiente.current(null);

    return new Promise((resolve) => {
      pendiente.current = resolve;
      setPedido({ archivo, destino: DESTINOS[destino] || DESTINOS.galeria });
    });
  }, []);

  const terminar = useCallback((resultado) => {
    const resolver = pendiente.current;
    pendiente.current = null;
    setPedido(null);
    if (resolver) resolver(resultado);
  }, []);

  const dialogo = pedido ? (
    <RecortarImagen
      archivo={pedido.archivo}
      destino={pedido.destino}
      onListo={terminar}
      onCancelar={() => terminar(null)}
    />
  ) : null;

  return { recortar, dialogo };
}

export default useRecorte;
