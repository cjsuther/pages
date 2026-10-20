import { describirPeso } from './imagenes';

/**
 * Productos digitales: el tope del archivo y la subida.
 *
 * El tope es el mismo que valida la API en api/lib/ProductosDigitales.php:
 * acá sólo se avisa antes de mandar 80 MB para que los rechacen al final.
 */
export const MAX_BYTES_PRODUCTO = 50 * 1024 * 1024;

export const AYUDA_ARCHIVO =
  'Un solo archivo de hasta 50 MB. Si tu producto tiene varios archivos, comprimilos en un ZIP y subí el ZIP.';

const MENSAJE_MAXIMO =
  'El archivo pesa más de 50 MB, que es el máximo. Si son varios archivos, comprimilos en un ZIP.';

/** Mensaje para un archivo que pasa el tope, o null si entra. */
export function excesoArchivoProducto(file) {
  if (file.size <= MAX_BYTES_PRODUCTO) return null;
  return `El archivo pesa ${describirPeso(file.size)}; el máximo es 50 MB. Si son varios archivos, comprimilos en un ZIP.`;
}

/** Si con este precio hay que cobrar. Vacío o 0 es gratis. */
export const esPago = (precio) => Number(precio) > 0;

/** El precio como se lee en la lista: "Gratis" o "$ 2.500". */
export function describirPrecio(precio) {
  if (!esPago(precio)) return 'Gratis';
  return `$ ${Number(precio).toLocaleString('es-AR', { maximumFractionDigits: 2 })}`;
}

/**
 * Sube el archivo de un producto que ya existe.
 *
 * Devuelve el producto actualizado, o tira un Error con lo que hay que
 * mostrar. Un 413 puede llegar del servidor web antes de que PHP conteste, y
 * ahí no hay JSON: igual se dice el máximo, no un error genérico.
 */
export async function subirArchivoDeProducto({ apiUrl, token, linkId, file }) {
  const exceso = excesoArchivoProducto(file);
  if (exceso) throw new Error(exceso);

  const formData = new FormData();
  formData.append('archivo', file);

  const r = await fetch(`${apiUrl}/productos/archivo.php?link_id=${linkId}`, {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${token}` },
    body: formData
  });
  const data = await r.json().catch(() => ({}));

  if (!r.ok) {
    throw new Error(data.error || (r.status === 413 ? MENSAJE_MAXIMO : 'No pudimos subir el archivo'));
  }

  return data.link;
}
