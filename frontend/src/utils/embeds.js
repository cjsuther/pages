/**
 * Videos de YouTube y contenido de Instagram en los grupos de galería.
 *
 * De un item de galería sólo se guarda la URL que pegó el usuario (embed_url):
 * de qué servicio es y cómo se muestra se deduce acá. Guardar además el tipo
 * daría dos campos que pueden contradecirse.
 */

/** Un id de YouTube son siempre 11 caracteres de este alfabeto. */
const ID_YOUTUBE = '([A-Za-z0-9_-]{11})';

// watch?v=, youtu.be/, /embed/, /shorts/ y /live/: las cinco formas en que
// YouTube reparte el mismo video.
const YOUTUBE = [
  new RegExp(`youtube\\.com/watch\\?(?:.*&)?v=${ID_YOUTUBE}`, 'i'),
  new RegExp(`youtu\\.be/${ID_YOUTUBE}`, 'i'),
  new RegExp(`youtube\\.com/(?:embed|shorts|live|v)/${ID_YOUTUBE}`, 'i'),
];

// Instagram usa el mismo código para posts, reels y videos; lo que cambia es
// el segmento. Se conserva el original porque el embed de un reel vive en
// /reel/ y redirigir cuesta una carga de más.
const INSTAGRAM = /instagram\.com\/(p|reel|reels|tv)\/([A-Za-z0-9_-]+)/i;

/**
 * Interpreta la URL de un contenido embebible.
 *
 * @returns null si no se reconoce, o { tipo, id, urlEmbed, miniatura }.
 *          `miniatura` es null cuando el servicio no publica una: Instagram
 *          no da miniaturas sin API, así que ahí la portada la sube el usuario.
 */
export function analizarEmbed(url) {
  if (!url || typeof url !== 'string') return null;

  const texto = url.trim();
  if (!texto) return null;

  for (const patron of YOUTUBE) {
    const encontrado = texto.match(patron);
    if (encontrado) {
      const id = encontrado[1];
      return {
        tipo: 'youtube',
        id,
        // nocookie: no deja rastro en quien mira la página si no le da play.
        // rel=0 limita los videos sugeridos del final al mismo canal.
        urlEmbed: `https://www.youtube-nocookie.com/embed/${id}?rel=0`,
        miniatura: `https://img.youtube.com/vi/${id}/hqdefault.jpg`,
      };
    }
  }

  const instagram = texto.match(INSTAGRAM);
  if (instagram) {
    const seccion = instagram[1].toLowerCase() === 'reels' ? 'reel' : instagram[1].toLowerCase();
    const id = instagram[2];
    return {
      tipo: 'instagram',
      id,
      urlEmbed: `https://www.instagram.com/${seccion}/${id}/embed`,
      miniatura: null,
    };
  }

  return null;
}

/** ¿Este item de galería es un embed, o una imagen como toda la vida? */
export const esEmbed = (link) => analizarEmbed(link?.embed_url) !== null;

/**
 * Qué imagen mostrar en la grilla.
 *
 * La portada que subió el usuario manda siempre: si eligió una, es la que
 * quiere ver. Detrás va la miniatura del servicio.
 */
export function portadaDe(link) {
  if (link?.image_url) return link.image_url;
  return analizarEmbed(link?.embed_url)?.miniatura || null;
}

/** Texto de ayuda del editor, para no repetir los ejemplos en dos formularios. */
export const EJEMPLOS_EMBED =
  'Pegá el link del video de YouTube o del post, reel o carrusel de Instagram';
