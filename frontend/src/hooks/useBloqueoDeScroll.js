import { useEffect } from 'react';

/**
 * Impide que la página de atrás se desplace mientras hay un diálogo abierto.
 *
 * En el teléfono, sin esto, el dedo mueve la página por debajo del velo: el
 * diálogo está fijo a la pantalla y el contenido se corre, así que parece que
 * el diálogo se salió de lugar. Se restaura el valor anterior en vez de
 * limpiarlo, para que dos diálogos anidados no se pisen.
 */
export function useBloqueoDeScroll(activo) {
  useEffect(() => {
    if (!activo) return undefined;

    const anterior = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = anterior;
    };
  }, [activo]);
}

export default useBloqueoDeScroll;
