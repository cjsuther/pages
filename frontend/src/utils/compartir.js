/**
 * Compartir el link público de un evento o un producto desde la página
 * pública (#51): los dos se comparten igual.
 */

/**
 * El link público de un evento. Como el de los productos, sale del dominio en
 * el que se está: en un dominio propio el link es de ese dominio.
 */
export function linkDeEvento(id, origen = window.location.origin) {
  return `${origen}/evento/${id}`;
}

/**
 * Comparte un link con el menú del sistema donde exista (sobre todo en el
 * celular) y, si no, lo copia al portapapeles.
 *
 * Devuelve 'compartido', 'copiado' o 'cancelado' (cerró el menú sin elegir).
 * Si no se pudo ni compartir ni copiar, tira el error.
 */
export async function compartirLink({ url, titulo }) {
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ title: titulo, url });
      return 'compartido';
    } catch (e) {
      if (e && e.name === 'AbortError') return 'cancelado';
      // Algunos navegadores lo tienen pero no lo dejan usar: se copia.
    }
  }

  await navigator.clipboard.writeText(url);
  return 'copiado';
}
