import React, { useEffect, useRef, useState } from 'react';
import { Play, Instagram } from 'lucide-react';
import { analizarEmbed, portadaDe } from '../utils/embeds';

/**
 * Un item de galería, que puede ser una imagen, un video de YouTube o un
 * contenido de Instagram.
 *
 * En la grilla se muestra una imagen quieta siempre que se pueda: una galería
 * de doce videos serían doce reproductores cargando a la vez. YouTube publica
 * la miniatura de cada video, así que ahí alcanza con eso.
 *
 * Instagram no publica ninguna sin API. Antes esos items quedaban con un
 * recuadro con el logo, que no dice nada de lo que hay adentro; ahora se deja
 * que Instagram dibuje el contenido en la propia celda. Es un iframe, pero
 * diferido: el navegador no lo carga hasta que la celda está por verse.
 *
 * Las cuatro plantillas maquetan la grilla distinto, así que el tamaño y la
 * forma llegan por className y acá sólo se decide qué se dibuja.
 */

/** Alto del encabezado del embed de Instagram (avatar y nombre de usuario). */
const CHROME_ARRIBA = 54;
/** Alto de la barra de acciones y el pie con el enlace a Instagram. */
const CHROME_ABAJO = 96;
/**
 * Ancho al que se pide el embed antes de escalarlo.
 *
 * Instagram no maqueta por debajo de unos 320px: en una celda chica, pedirlo
 * al ancho real dejaría la foto cortada por la izquierda. Se pide grande y se
 * achica con un scale, que además mantiene la proporción en cualquier celda.
 */
const ANCHO_LOGICO = 340;

/**
 * El contenido de Instagram dibujado por Instagram, recortado a la foto.
 *
 * El embed trae encabezado con el usuario y barra de acciones; se recortan
 * corriendo el iframe dentro de una ventana más chica. Son medidas del
 * maquetado de Instagram: si algún día lo cambian, se ajusta en las constantes
 * de arriba.
 */
function MarcoInstagram({ urlEmbed, titulo, escalar = false, diferido = false }) {
  const caja = useRef(null);
  const [encaje, setEncaje] = useState({ escala: 1, corrimientoX: 0 });

  useEffect(() => {
    if (!escalar) return undefined;

    const nodo = caja.current;
    if (!nodo) return undefined;

    const medir = () => {
      const { clientWidth: ancho, clientHeight: alto } = nodo;
      if (!ancho) return;
      // Se agranda hasta tapar la celda entera, como haría un object-cover:
      // más vale recortar los costados que dejar una franja vacía.
      const escala = Math.max(ancho / ANCHO_LOGICO, alto / ANCHO_LOGICO);
      // Y lo que sobra de ancho se reparte a los dos lados, para que la foto
      // quede centrada en vez de pegada a la izquierda.
      const corrimientoX = (ANCHO_LOGICO * escala - ancho) / 2 / escala;
      setEncaje({ escala, corrimientoX });
    };

    medir();

    if (typeof ResizeObserver === 'undefined') return undefined;
    const observador = new ResizeObserver(medir);
    observador.observe(nodo);
    return () => observador.disconnect();
  }, [escalar]);

  const estiloMarco = escalar
    ? {
        width: ANCHO_LOGICO,
        height: ANCHO_LOGICO + CHROME_ARRIBA + CHROME_ABAJO,
        transform: `scale(${encaje.escala}) translate(${-encaje.corrimientoX}px, ${-CHROME_ARRIBA}px)`,
        transformOrigin: 'top left',
        // El click tiene que llegar a la celda, que es la que abre el item.
        pointerEvents: 'none',
      }
    : {
        top: -CHROME_ARRIBA,
        height: `calc(100% + ${CHROME_ARRIBA + CHROME_ABAJO}px)`,
      };

  return (
    <div ref={caja} className="absolute inset-0 overflow-hidden bg-neutral-900">
      <iframe
        src={urlEmbed}
        title={titulo}
        scrolling="no"
        loading={diferido ? 'lazy' : undefined}
        className={`absolute left-0 border-0 ${escalar ? '' : 'w-full'}`}
        style={estiloMarco}
        allowFullScreen
      />
    </div>
  );
}

/** Miniatura para la grilla: imagen, portada del video o el propio contenido. */
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
  const etiqueta = embed.tipo === 'youtube' ? 'Video de YouTube' : 'Contenido de Instagram';

  return (
    <div className={`relative ${className}`} style={style}>
      {portada ? (
        <img src={portada} alt={link.text} className="w-full h-full object-cover" />
      ) : (
        <MarcoInstagram urlEmbed={embed.urlEmbed} titulo={link.text || etiqueta} escalar diferido />
      )}

      {portada ? (
        // Sobre una imagen quieta hace falta decir que hay algo que reproducir.
        <span className="absolute inset-0 flex items-center justify-center" aria-hidden="true">
          <span className="flex items-center justify-center w-12 h-12 rounded-full bg-black bg-opacity-60">
            <Marca
              className="w-6 h-6 text-white"
              fill={embed.tipo === 'youtube' ? 'currentColor' : 'none'}
            />
          </span>
        </span>
      ) : (
        // Sobre el contenido en vivo alcanza con una marca chica: taparlo con
        // un botón grande sería esconder justamente lo que se quiere ver.
        <span className="absolute top-1.5 right-1.5" aria-hidden="true">
          <span className="flex items-center justify-center w-6 h-6 rounded-full bg-black bg-opacity-50">
            <Instagram className="w-3.5 h-3.5 text-white" />
          </span>
        </span>
      )}

      <span className="sr-only">{etiqueta}</span>
    </div>
  );
}

/** El item abierto: acá el contenido se ve entero y se puede reproducir. */
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
      className={`relative mx-auto overflow-hidden ${className}`}
      style={{ maxWidth: 540, aspectRatio: '1 / 1' }}
    >
      <MarcoInstagram urlEmbed={embed.urlEmbed} titulo={link.text || 'Contenido de Instagram'} />
    </div>
  );
}
