import React from 'react';
import { Play, Instagram } from 'lucide-react';
import { analizarEmbed, portadaDe } from '../utils/embeds';

/**
 * Un item de galería, que puede ser una imagen, un video de YouTube o un
 * contenido de Instagram.
 *
 * La grilla muestra siempre una imagen quieta, nunca un iframe: una galería de
 * doce videos serían doce reproductores cargando a la vez. El embed recién
 * aparece cuando alguien abre el item.
 *
 * Las cuatro plantillas maquetan la grilla distinto, así que el tamaño y la
 * forma llegan por className y acá sólo se decide qué se dibuja.
 */

/** Alto del encabezado del embed de Instagram (avatar y nombre de usuario). */
const CHROME_ARRIBA = 54;
/** Alto de la barra de acciones y el pie con el enlace a Instagram. */
const CHROME_ABAJO = 96;

/** Miniatura para la grilla: imagen, portada del video o marcador del servicio. */
export function MiniaturaGaleria({ link, className = '', style }) {
  const embed = analizarEmbed(link?.embed_url);
  const portada = portadaDe(link);

  if (!embed) {
    // Sin imagen no se dibuja nada: la plantilla condensada ya contaba con eso
    // para los items que son sólo texto.
    if (!link?.image_url) return null;
    return <img src={link.image_url} alt={link.text} className={className} style={style} />;
  }

  const Marca = embed.tipo === 'youtube' ? Play : Instagram;

  return (
    <div className={`relative ${className}`} style={style}>
      {portada ? (
        <img src={portada} alt={link.text} className="w-full h-full object-cover" />
      ) : (
        // Instagram no publica miniaturas sin API: si el usuario no subió una
        // portada, el item se anuncia por lo que es en vez de quedar en blanco.
        <div className="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-800 to-black">
          <Instagram className="w-10 h-10 text-white opacity-80" />
        </div>
      )}
      <span
        className="absolute inset-0 flex items-center justify-center"
        aria-hidden="true"
      >
        <span className="flex items-center justify-center w-12 h-12 rounded-full bg-black bg-opacity-60">
          <Marca className="w-6 h-6 text-white" fill={embed.tipo === 'youtube' ? 'currentColor' : 'none'} />
        </span>
      </span>
      <span className="sr-only">
        {embed.tipo === 'youtube' ? 'Video de YouTube' : 'Contenido de Instagram'}
      </span>
    </div>
  );
}

/**
 * El item abierto: acá sí va el embed.
 *
 * De Instagram se muestra sólo la foto, el carrusel o el video. El embed trae
 * además el encabezado con el usuario y una barra de acciones; se recortan
 * corriendo el iframe dentro de una ventana más chica. Son medidas del maquetado
 * de Instagram, así que si algún día lo cambian esto se ajusta acá.
 */
export function VisorGaleria({ link, className = 'w-full max-h-[80vh] object-contain' }) {
  const embed = analizarEmbed(link?.embed_url);

  if (!embed) {
    return <img src={link.image_url} alt={link.text} className={className} />;
  }

  if (embed.tipo === 'youtube') {
    return (
      <div className={`relative ${className}`} style={{ aspectRatio: '16 / 9' }}>
        <iframe
          src={`${embed.urlEmbed}&autoplay=1`}
          title={link.text || 'Video de YouTube'}
          className="absolute inset-0 w-full h-full"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
          allowFullScreen
        />
      </div>
    );
  }

  return (
    <div
      className={`relative mx-auto overflow-hidden bg-white ${className}`}
      style={{ maxWidth: 540, aspectRatio: '1 / 1' }}
    >
      <iframe
        src={embed.urlEmbed}
        title={link.text || 'Contenido de Instagram'}
        scrolling="no"
        className="absolute left-0 w-full border-0"
        style={{ top: -CHROME_ARRIBA, height: `calc(100% + ${CHROME_ARRIBA + CHROME_ABAJO}px)` }}
        allowFullScreen
      />
    </div>
  );
}
