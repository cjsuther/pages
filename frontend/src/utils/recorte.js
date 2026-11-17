import { MAX_BYTES, MAX_BYTES_EVENTO } from './imagenes';

/**
 * Recorte de imágenes antes de subirlas.
 *
 * La API sigue recibiendo un archivo de imagen común (api/upload/image.php):
 * el recorte pasa entero en el navegador. Acá vive lo que no es pantalla:
 * qué proporción y tamaño le corresponde a cada lugar, y cómo se dibuja el
 * recorte en un canvas para volverlo archivo.
 */

/** La proporción natural de la imagen, sin forzar ninguna. */
export const ORIGINAL = 'original';

const P = {
  original: { etiqueta: 'Original', valor: ORIGINAL },
  cuadrada: { etiqueta: '1:1', valor: 1 },
  afiche: { etiqueta: '4:5', valor: 4 / 5 },
  apaisada: { etiqueta: '16:9', valor: 16 / 9 },
  vertical: { etiqueta: '9:16', valor: 9 / 16 },
};

/**
 * Cada lugar donde se sube una imagen.
 *
 * maxLado: el lado mayor del archivo exportado. Se elige por cómo se muestra:
 * nada de lo que se publica se ve más grande que esto, y subir más es peso
 * que paga quien mira la página desde el celular.
 *
 * maxBytes: el mismo tope que valida UploadHandler para ese destino. El
 * recorte baja la calidad o el tamaño hasta entrar.
 */
export const DESTINOS = {
  // Se muestra en círculo en casi todas las plantillas.
  perfil: {
    titulo: 'Recortá la imagen de perfil',
    proporciones: [P.cuadrada],
    forma: 'round',
    maxLado: 800,
    maxBytes: MAX_BYTES,
  },
  // Ocupa el fondo de toda la página, que puede ser un celular parado o una
  // pantalla acostada: se deja elegir.
  fondo: {
    titulo: 'Recortá la imagen de fondo',
    proporciones: [P.original, P.vertical, P.apaisada],
    maxLado: 2400,
    maxBytes: MAX_BYTES,
  },
  // Las plantillas Afiches y Tarjetas muestran el afiche en 1080×1350.
  evento: {
    titulo: 'Recortá el afiche del evento',
    proporciones: [P.afiche],
    maxLado: 1350,
    maxBytes: MAX_BYTES_EVENTO,
  },
  producto: {
    titulo: 'Recortá la imagen del producto',
    proporciones: [P.afiche],
    maxLado: 1350,
    maxBytes: MAX_BYTES,
  },
  // Miniatura cuadrada en la mayoría de las plantillas; Afiches la muestra
  // vertical, por eso también se ofrece 4:5.
  link: {
    titulo: 'Recortá la imagen del link',
    proporciones: [P.cuadrada, P.afiche],
    maxLado: 1080,
    maxBytes: MAX_BYTES,
  },
  // En la galería cada pieza conserva su forma: se abre entera en el visor.
  galeria: {
    titulo: 'Recortá la imagen',
    proporciones: [P.original, P.cuadrada, P.afiche, P.apaisada],
    maxLado: 2048,
    maxBytes: MAX_BYTES,
  },
  // Carcajada: cuadrada con bordes redondeados en la grilla del show.
  comediante: {
    titulo: 'Recortá la foto',
    proporciones: [P.cuadrada],
    maxLado: 800,
    maxBytes: MAX_BYTES,
  },
};

/** El destino de la imagen de un item según el tipo de su grupo. */
export function destinoDeGrupo(tipo) {
  if (tipo === 'eventos') return 'evento';
  if (tipo === 'productos') return 'producto';
  if (tipo === 'galeria') return 'galeria';
  return 'link';
}

/** Los formatos que acepta la API. */
export const TIPOS_ACEPTADOS = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];

/** Formatos que pueden traer transparencia. */
const TIPOS_CON_ALFA = ['image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

/**
 * Si el archivo se puede subir tal como está para ese destino.
 *
 * "Usar sin recortar" manda el original cuando entra (así un GIF animado
 * sigue animado); si no, se lo vuelve a codificar entero para que entre.
 */
export function sirveTalCual(archivo, destino) {
  return TIPOS_ACEPTADOS.includes(archivo.type) && archivo.size <= destino.maxBytes;
}

/**
 * Carga la imagen para dibujarla en el canvas.
 *
 * La orientación EXIF de las fotos del celular la aplica el navegador: desde
 * Chrome 81, Safari 13.1 y Firefox 77 la imagen se decodifica ya girada
 * (image-orientation: from-image), tanto para naturalWidth/naturalHeight
 * como para drawImage. El recortador muestra esa misma imagen, así que las
 * coordenadas del recorte y el dibujo coinciden.
 */
export function cargarImagen(url) {
  return new Promise((resolve, reject) => {
    const imagen = new Image();
    imagen.decoding = 'async';
    imagen.onload = () => resolve(imagen);
    imagen.onerror = () => reject(new Error('No pudimos abrir la imagen'));
    imagen.src = url;
  });
}

/** El área que cubre la imagen entera, para exportarla sin recortar. */
export function areaCompleta(imagen) {
  return { x: 0, y: 0, width: imagen.naturalWidth, height: imagen.naturalHeight };
}

/** Las medidas de salida: el área, achicada para que el lado mayor no pase de maxLado. */
export function medidasDeSalida(area, maxLado) {
  const escala = Math.min(1, maxLado / Math.max(area.width, area.height));
  return {
    ancho: Math.max(1, Math.round(area.width * escala)),
    alto: Math.max(1, Math.round(area.height * escala)),
  };
}

function canvasABlob(canvas, tipo, calidad) {
  return new Promise((resolve) => canvas.toBlob(resolve, tipo, calidad));
}

/** Si algún píxel dibujado no es opaco. */
function tieneTransparencia(ctx, ancho, alto) {
  try {
    const { data } = ctx.getImageData(0, 0, ancho, alto);
    for (let i = 3; i < data.length; i += 4) {
      if (data[i] < 255) return true;
    }
    return false;
  } catch {
    // Sin poder leer los píxeles se conserva el canal alfa por las dudas.
    return true;
  }
}

const EXTENSION = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };

function nombreCon(nombre, tipo) {
  const base = (nombre || 'imagen').replace(/\.[^.]+$/, '') || 'imagen';
  return `${base}.${EXTENSION[tipo]}`;
}

/**
 * Dibuja el área elegida en un canvas y la devuelve como archivo.
 *
 * Con transparencia (un logo en PNG) sale en WebP o, si el navegador no sabe
 * escribir WebP, en PNG; sin transparencia, en JPEG. Si pasa el tope del
 * destino se baja la calidad y, si no alcanza, el tamaño. Si ni así entra se
 * devuelve el más liviano: quien sube lo valida y avisa con el mensaje de
 * siempre.
 *
 * @param imagen   HTMLImageElement ya cargada
 * @param area     { x, y, width, height } en píxeles de la imagen
 * @param opciones { maxLado, maxBytes, tipo (del original), nombre }
 * @returns {Promise<File>}
 */
export async function exportarRecorte(imagen, area, { maxLado, maxBytes, tipo, nombre }) {
  let { ancho, alto } = medidasDeSalida(area, maxLado);
  let conAlfa = null;
  let masLiviano = null;

  for (let intento = 0; intento < 6; intento += 1) {
    const canvas = document.createElement('canvas');
    canvas.width = ancho;
    canvas.height = alto;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Este navegador no puede recortar imágenes');

    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(imagen, area.x, area.y, area.width, area.height, 0, 0, ancho, alto);

    if (conAlfa === null) {
      conAlfa = TIPOS_CON_ALFA.includes(tipo) && tieneTransparencia(ctx, ancho, alto);
    }

    const formatos = conAlfa
      ? [['image/webp', 0.9], ['image/webp', 0.75], ['image/png']]
      : [['image/jpeg', 0.88], ['image/jpeg', 0.78], ['image/jpeg', 0.68]];

    for (const [formato, calidad] of formatos) {
      const blob = await canvasABlob(canvas, formato, calidad);
      // Un navegador que no escribe WebP devuelve PNG: se sigue con el
      // próximo formato de la lista en lugar de mentir con la extensión.
      if (!blob || blob.type !== formato) continue;

      if (!masLiviano || blob.size < masLiviano.size) masLiviano = blob;
      if (!maxBytes || blob.size <= maxBytes) {
        return new File([blob], nombreCon(nombre, formato), { type: formato });
      }
    }

    ancho = Math.max(1, Math.round(ancho * 0.8));
    alto = Math.max(1, Math.round(alto * 0.8));
  }

  if (!masLiviano) throw new Error('No pudimos procesar la imagen');
  return new File([masLiviano], nombreCon(nombre, masLiviano.type), { type: masLiviano.type });
}
