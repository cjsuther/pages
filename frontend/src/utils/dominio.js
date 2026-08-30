/**
 * La página que un dominio propio muestra en su raíz.
 *
 * En maxipeque.com la dirección no dice qué página mostrar: lo resuelve
 * index.php contra el Host y lo deja acá, así el SPA no repite esa consulta.
 *
 * Se lee en cada render y no al cargar el módulo: si se leyera una sola vez,
 * el valor quedaría congelado antes de que index.php lo escriba.
 */
export function paginaDelDominio() {
  if (typeof window === 'undefined') return null;

  return window.__PAGINA_DEL_DOMINIO__ || null;
}
