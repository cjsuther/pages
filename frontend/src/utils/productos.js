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
 * Dónde se guarda el link de descarga de una compra mientras el comprador
 * paga en Mercado Pago, para ofrecerle la descarga apenas vuelve sin ponerlo
 * en ninguna URL. La clave es el código de la orden, que no es una credencial.
 */
const claveDeDescarga = (codigo) => `rz_descarga_${codigo}`;

/** Guarda el token de una compra. En modo privado localStorage puede fallar: no es grave. */
export function guardarTokenDeDescarga(codigo, token) {
  try {
    window.localStorage.setItem(claveDeDescarga(codigo), token);
  } catch (e) {
    // Sin dónde guardarlo, el link igual llega por mail.
  }
}

/** El token guardado de una compra, o null (otro dispositivo, modo privado). */
export function tokenDeDescarga(codigo) {
  try {
    const token = window.localStorage.getItem(claveDeDescarga(codigo));
    return token && /^[a-f0-9]{64}$/.test(token) ? token : null;
  } catch (e) {
    return null;
  }
}

/** El token de la dirección de /descarga: va después del #, que no llega al servidor. */
export function tokenDelHash(hash) {
  const token = String(hash || '').replace(/^#/, '');
  return /^[a-f0-9]{64}$/.test(token) ? token : null;
}

export const MENSAJE_NO_DISPONIBLE = 'Este producto ya no está disponible.';

/**
 * El link público de un producto, para compartir. Como los de los eventos,
 * sale del dominio en el que se está: en un dominio propio el link es de ese
 * dominio, y en rezon.ar es FRONTEND_URL/producto/<id>.
 */
export function linkDeProducto(id, origen = window.location.origin) {
  return `${origen}/producto/${id}`;
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

/**
 * Lo que se le dice a quien compra cuando el pedido no sale, según la
 * respuesta de la API.
 *
 * - 409: el producto se ocultó o se borró mientras tenía la página abierta.
 * - 429: pidió demasiados productos gratis en la última hora.
 * - 400: algún dato no sirve; la API dice cuál.
 */
export function mensajeDeErrorDeCompra(status, error) {
  if (status === 409) return MENSAJE_NO_DISPONIBLE;
  if (status === 429) return error || 'Hiciste demasiados pedidos en poco tiempo. Probá de nuevo en una hora.';
  if (status === 400) return error || 'Revisá los datos: hay algo que no está bien.';
  return error || 'No pudimos iniciar la compra';
}

/**
 * Pide un producto: crea la orden y, si es pago, devuelve a dónde ir a pagar.
 *
 * El token se guarda antes de devolver, o sea antes de salir a Mercado Pago:
 * al volver a /compra/:codigo la pantalla lo busca para ofrecer la descarga.
 * Un producto gratis no trae token: el link llega por mail.
 */
export async function comprarProducto({ apiUrl, linkId, nombre, email, telefono }) {
  const r = await fetch(`${apiUrl}/public/comprar-producto.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ link_id: linkId, nombre, email, telefono }),
  });
  const data = await r.json().catch(() => ({}));

  if (!r.ok) {
    const error = new Error(mensajeDeErrorDeCompra(r.status, data.error));
    error.status = r.status;
    throw error;
  }

  if (data.token && data.codigo) {
    guardarTokenDeDescarga(data.codigo, data.token);
  }

  return data;
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

/**
 * Si un producto está oculto. La API lo devuelve como 0/1 (a veces como
 * texto) y el editor lo guarda como booleano.
 */
export function esOculto(valor) {
  return valor === true || valor === 1 || valor === '1' || valor === 'true';
}
