import React from 'react';
import { Download } from 'lucide-react';

/**
 * El botón que baja el archivo de una compra.
 *
 * Es un formulario común y no un fetch: con fetch el archivo entero tendría
 * que entrar en la memoria de la pestaña antes de guardarse, y 50 MB en un
 * celular es justo lo que hace fallar la descarga. Así lo guarda el navegador
 * a medida que llega. El token va como campo del POST, nunca en la URL.
 */
function BotonDescarga({ apiUrl, token }) {
  return (
    <form method="post" action={`${apiUrl}/public/descargar.php`}>
      <input type="hidden" name="token" value={token} />
      <button
        type="submit"
        className="inline-flex items-center justify-center gap-2 w-full px-6 py-4 bg-tinta text-white font-bold hover:bg-black transition-colors"
      >
        <Download className="w-5 h-5" />
        Descargar
      </button>
    </form>
  );
}

export default BotonDescarga;
