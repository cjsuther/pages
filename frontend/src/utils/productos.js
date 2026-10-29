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

// Se comparte igual que un evento (#51): vive en utils/compartir.
export { compartirLink } from './compartir';

/**
 * Lo que se le dice a quien compra cuando el pedido no sale, según la
 * respuesta de la API.
 *
 * - 409: el producto se ocultó o se borró mientras tenía la página abierta.
 *   En un físico (#43) también es el stock: no alcanza para la cantidad, o
 *   se agotó. Ahí se dice lo que manda la API.
 * - 429: pidió demasiados productos gratis en la última hora.
 * - 400: algún dato no sirve; la API dice cuál.
 */
export function mensajeDeErrorDeCompra(status, error, fisico = false) {
  if (status === 409) return (fisico && error) || MENSAJE_NO_DISPONIBLE;
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
export async function comprarProducto({ apiUrl, linkId, nombre, email, telefono, pedido }) {
  const r = await fetch(`${apiUrl}/public/comprar-producto.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    // Un físico (#43) suma cantidad, opciones, envío y horarios.
    body: JSON.stringify({ link_id: linkId, nombre, email, telefono, ...(pedido || {}) }),
  });
  const data = await r.json().catch(() => ({}));

  if (!r.ok) {
    const error = new Error(mensajeDeErrorDeCompra(r.status, data.error, Boolean(pedido)));
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
 *
 * En el editor se dice "Inactivo" (#42): es la misma columna.
 */
export function esOculto(valor) {
  return valor === true || valor === 1 || valor === '1' || valor === 'true';
}

// ------------------------------------------------------- productos físicos (#42)

/** Si un producto es físico: sin archivo, con envío, características y stock. */
export const esFisico = (producto) => producto?.tipo === 'fisico';

/** Los nombres que se sugieren para una característica. */
export const SUGERENCIAS_DE_CARACTERISTICA = ['Talle', 'Color', 'Otra'];

export const MAX_CARACTERISTICAS = 3;

/** El costo de envío como se lee: 0 es "Envío incluido". */
export function describirEnvio(costo) {
  if (!(Number(costo) > 0)) return 'Envío incluido';
  return `Envío: $ ${Number(costo).toLocaleString('es-AR', { maximumFractionDigits: 2 })}`;
}

// ---------------------------------------------- formas de entrega (#53)

/**
 * Las formas de entrega de un físico, en el orden en que se ofrecen, con la
 * columna que dice si quien vende la habilitó. Las mismas que
 * ProductosFisicos::FORMAS_DE_ENTREGA en la API.
 */
export const FORMAS_DE_ENTREGA = [
  { clave: 'envio', columna: 'entrega_envio', nombre: 'Envío a domicilio' },
  { clave: 'retiro', columna: 'entrega_retiro', nombre: 'Retiro en un lugar' },
  { clave: 'coordinar', columna: 'entrega_coordinar', nombre: 'Coordinar después de la compra' },
];

/** Cómo se lee la forma de un pedido en el panel y en /compra/:codigo. */
export const NOMBRES_DE_FORMAS = { envio: 'Envío a domicilio', retiro: 'Retiro', coordinar: 'A coordinar' };

/** Lo que dice quien compra cuando elige coordinar. */
export const AVISO_COORDINAR = 'Después de la compra, quien vende te contacta para coordinar la entrega';

const habilitada = (valor) => valor === true || valor === 1 || valor === '1' || valor === 'true';

/**
 * Si una forma está tildada en el editor. La API manda 0/1 (o como texto); un
 * producto sin la columna es de antes de #53, y esos sólo tienen envío.
 */
export function formaHabilitada(item, forma) {
  const valor = item?.[forma.columna];
  if (valor === undefined || valor === null) return forma.clave === 'envio';
  return habilitada(valor);
}

/**
 * Las claves de las formas habilitadas de un producto. El público ya las trae
 * en formas_entrega; el del editor, en sus columnas.
 */
export function formasDeEntrega(producto) {
  if (Array.isArray(producto?.formas_entrega)) return producto.formas_entrega;
  return FORMAS_DE_ENTREGA.filter((f) => formaHabilitada(producto, f)).map((f) => f.clave);
}

/**
 * Qué falta en las formas de entrega del editor, o null. La API valida lo
 * mismo: esto es para avisar sin ir y volver.
 */
export function problemaDeFormas(item) {
  const formas = formasDeEntrega(item);
  if (!formas.length) return 'Elegí al menos una forma de entrega';
  if (formas.includes('retiro')) {
    if (!String(item.retiro_direccion || '').trim()) return 'Falta la dirección del retiro';
    if (!item.retiro_latitud || !item.retiro_longitud) {
      return 'Elegí la dirección del retiro de las sugerencias de Google Maps';
    }
  }
  return null;
}

/** "Envío a domicilio ($ 500)" o "Envío a domicilio (incluido)". */
export function describirFormaDeEnvio(costo) {
  return `Envío a domicilio (${Number(costo) > 0 ? pesos(costo) : 'incluido'})`;
}

/**
 * Cómo se entrega, en la lista del editor: con sólo envío, el costo como
 * siempre ("Envío incluido"); con más, las formas ("Envío · Retiro · A coordinar").
 */
export function describirEntregas(producto) {
  const formas = formasDeEntrega(producto);
  if (formas.length === 1 && formas[0] === 'envio') return describirEnvio(producto.costo_envio);
  return formas.map((f) => (f === 'envio' ? 'Envío' : NOMBRES_DE_FORMAS[f])).join(' · ');
}

/** Lo que se cobra de envío con esta forma: sólo si se manda. */
export const costoDeEnvio = (producto, forma) => (forma === 'envio' ? producto.costo_envio : 0);

/** La clave de una combinación, como la arma la API: ids de menor a mayor, con guiones. */
export function claveDeCombinacion(opcionIds) {
  return opcionIds.map(Number).sort((a, b) => a - b).join('-');
}

/**
 * Todas las combinaciones de opciones, en el orden de las características:
 * [[S, Rojo], [S, Azul], [M, Rojo], ...]. Sin características, ninguna.
 */
export function combinaciones(caracteristicas) {
  if (!caracteristicas?.length) return [];
  return caracteristicas.reduce(
    (parciales, c) => parciales.flatMap((p) => (c.opciones || []).map((o) => [...p, o])),
    [[]],
  );
}

/**
 * Si una opción se puede elegir en el detalle público, con lo que ya se
 * eligió en las otras características: alguna combinación que la incluya
 * tiene que no estar agotada. `seleccion` es { [caracteristicaId]: opcionId }.
 */
export function opcionDisponible(producto, caracteristicaId, opcionId, seleccion = {}) {
  const agotadas = new Set(producto.agotadas || []);
  const elegidas = { ...seleccion, [caracteristicaId]: opcionId };

  return combinaciones(producto.caracteristicas)
    .filter((combo) => combo.every((o, i) => {
      const id = producto.caracteristicas[i].id;
      return elegidas[id] === undefined || elegidas[id] === o.id;
    }))
    .some((combo) => !agotadas.has(claveDeCombinacion(combo.map((o) => o.id))));
}

/**
 * Las características que llegan de la API, listas para el editor: cada
 * característica y cada opción con una clave local que no cambia al
 * renombrarla. La de lo que ya existe sale de su id; la de lo nuevo, de un
 * contador.
 */
let contadorDeClaves = 0;
export const claveNueva = () => `n${++contadorDeClaves}`;

export function caracteristicasParaEditar(caracteristicas = []) {
  return (caracteristicas || []).map((c) => ({
    clave: `c${c.id}`,
    id: c.id,
    nombre: c.nombre,
    opciones: (c.opciones || []).map((o) => ({ clave: `o${o.id}`, id: o.id, nombre: o.nombre })),
  }));
}

/**
 * La clave de una fila de la grilla de stock: las claves locales de sus
 * opciones, ordenadas, así no cambia al reordenar las características.
 */
export const claveDeFila = (combo) => combo.map((o) => o.clave).sort().join('|');

/** El stock por combinación de la API ({ clave: '3-7', stock }) como lo usa la grilla. */
export function stockParaEditar(variantes = []) {
  const grilla = {};
  (variantes || []).forEach((v) => {
    const clave = String(v.clave).split('-').map((id) => `o${id}`).sort().join('|');
    grilla[clave] = v.stock === null || v.stock === undefined ? '' : String(v.stock);
  });
  return grilla;
}

/**
 * Lo que se le manda a la API de las características y del stock: las
 * opciones por nombre, que es como la API reconoce las nuevas, y sólo las
 * combinaciones con stock cargado.
 */
export function caracteristicasParaGuardar(caracteristicas, grilla) {
  const lista = caracteristicas.map((c) => ({
    ...(c.id ? { id: c.id } : {}),
    nombre: c.nombre.trim(),
    opciones: c.opciones.map((o) => ({ ...(o.id ? { id: o.id } : {}), nombre: o.nombre.trim() })),
  }));

  const variantes = combinaciones(caracteristicas)
    .map((combo) => ({ opciones: combo.map((o) => o.nombre.trim()), stock: grilla[claveDeFila(combo)] ?? '' }))
    .filter((v) => v.stock !== '');

  return { caracteristicas: lista, variantes };
}

/**
 * Qué está mal en las características antes de mandarlas, o null. La API
 * valida lo mismo; esto es para avisar sin ir y volver.
 */
export function problemaDeCaracteristicas(caracteristicas) {
  const nombres = new Set();
  for (const c of caracteristicas) {
    const nombre = c.nombre.trim();
    if (!nombre) return 'Cada característica necesita un nombre';
    if (nombres.has(nombre.toLowerCase())) return `Hay dos características que se llaman "${nombre}"`;
    nombres.add(nombre.toLowerCase());
    if (!c.opciones.length) return `"${nombre}" necesita al menos una opción`;
    const opciones = new Set();
    for (const o of c.opciones) {
      const opcion = o.nombre.trim();
      if (!opcion) return `Hay una opción vacía en "${nombre}"`;
      if (opciones.has(opcion.toLowerCase())) return `La opción "${opcion}" está repetida en "${nombre}"`;
      opciones.add(opcion.toLowerCase());
    }
  }
  return null;
}

/** Un stock válido: vacío (sin límite) o un entero >= 0. */
export const stockValido = (valor) => valor === '' || valor === null || valor === undefined || /^\d+$/.test(String(valor).trim());

// ------------------------------------------------ pedido de un físico (#43)

/** Lo mismo que valida la API en api/lib/PedidosFisicos.php. */
export const MAX_CANTIDAD = 10;

/** Las 23 provincias y la Ciudad de Buenos Aires. */
export const PROVINCIAS = [
  'Buenos Aires', 'Ciudad Autónoma de Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
  'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
  'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe',
  'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
];

export const DIAS = [
  { clave: 'lunes', nombre: 'Lunes' }, { clave: 'martes', nombre: 'Martes' },
  { clave: 'miercoles', nombre: 'Miércoles' }, { clave: 'jueves', nombre: 'Jueves' },
  { clave: 'viernes', nombre: 'Viernes' }, { clave: 'sabado', nombre: 'Sábado' },
  { clave: 'domingo', nombre: 'Domingo' },
];

export const FRANJAS = [
  { clave: 'manana', nombre: 'Mañana' }, { clave: 'tarde', nombre: 'Tarde' }, { clave: 'noche', nombre: 'Noche' },
];

/** Cómo se lee una lista de claves de DIAS o FRANJAS: "Lunes, Martes y Sábado". */
export function describirClaves(claves = [], lista = DIAS) {
  const nombres = lista.filter((d) => (claves || []).includes(d.clave)).map((d) => d.nombre);
  if (nombres.length <= 1) return nombres.join('');
  return `${nombres.slice(0, -1).join(', ')} y ${nombres[nombres.length - 1]}`;
}

/** Los datos de entrega de un formulario vacío. */
export const entregaVacia = () => ({
  calle: '', piso_depto: '', localidad: '', provincia: '', codigo_postal: '', indicaciones: '',
  dias: [], franjas: [], comentario: '',
});

/** Un monto en pesos: "$ 5.500". */
export const pesos = (monto) => `$ ${Number(monto || 0).toLocaleString('es-AR', { maximumFractionDigits: 2 })}`;

const centavos = (monto) => Math.round(Number(monto || 0) * 100);

/** Precio × cantidad + envío, como lo cobra la API. */
export function totalDelPedido(precio, cantidad, costoEnvio) {
  const subtotal = centavos(precio) * Number(cantidad || 0);
  const envio = centavos(costoEnvio);
  return { subtotal: subtotal / 100, envio: envio / 100, total: (subtotal + envio) / 100 };
}

/** 4 números (1414) o el CPA de 8 caracteres (C1414BAD). */
export const codigoPostalValido = (cp) => /^(\d{4}|[A-Za-z]\d{4}[A-Za-z]{3})$/.test(String(cp || '').replace(/\s/g, ''));

/**
 * Qué falta o está mal en el pedido de un físico antes de mandarlo, o null.
 * La API valida lo mismo: esto es para avisar sin ir y volver.
 */
export function problemaDelPedido({ producto, seleccion = {}, telefono, entrega, forma = 'envio' }) {
  const falta = (producto.caracteristicas || []).filter((c) => seleccion[c.id] === undefined);
  if (falta.length) return `Elegí ${falta.map((c) => c.nombre.toLowerCase()).join(' y ')}`;

  if (!forma) return 'Elegí cómo querés recibir el pedido';

  const digitos = String(telefono || '').replace(/\D/g, '');
  if (!String(telefono || '').trim()) return 'Falta el teléfono: lo necesitamos para coordinar la entrega';
  if (digitos.length < 6) return 'El teléfono no es válido';

  // La dirección y los horarios son del envío (#53).
  if (forma !== 'envio') return null;

  if (!entrega.calle.trim()) return 'Falta la calle y el número';
  if (!entrega.localidad.trim()) return 'Falta la localidad';
  if (!PROVINCIAS.includes(entrega.provincia)) return 'Elegí la provincia';
  if (!entrega.codigo_postal.trim()) return 'Falta el código postal';
  if (!codigoPostalValido(entrega.codigo_postal)) {
    return 'El código postal no es válido: son 4 números (1414) o 8 caracteres (C1414BAD)';
  }
  if (!entrega.dias.length) return 'Elegí al menos un día para recibir el pedido';
  if (!entrega.franjas.length) return 'Elegí al menos una franja: mañana, tarde o noche';

  return null;
}

/**
 * Lo que se le manda a la API del pedido de un físico. La dirección y los
 * horarios van sólo con envío (#53).
 */
export function cuerpoDelPedido({ producto, seleccion = {}, cantidad, entrega, forma = 'envio' }) {
  const cuerpo = {
    cantidad: Number(cantidad),
    opciones: (producto.caracteristicas || []).map((c) => seleccion[c.id]),
    forma_entrega: forma,
  };

  if (forma !== 'envio') return cuerpo;

  return {
    ...cuerpo,
    envio: {
      calle: entrega.calle.trim(),
      piso_depto: entrega.piso_depto.trim(),
      localidad: entrega.localidad.trim(),
      provincia: entrega.provincia,
      codigo_postal: entrega.codigo_postal.trim(),
      indicaciones: entrega.indicaciones.trim(),
    },
    horarios: { dias: entrega.dias, franjas: entrega.franjas, comentario: entrega.comentario.trim() },
  };
}
