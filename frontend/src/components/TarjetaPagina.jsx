import React from 'react';
import { ExternalLink } from 'lucide-react';
import FollowButton from './FollowButton';
import FollowersPopup from './FollowersPopup';
import { Avatar } from './ui';

/**
 * Una página de artista en un listado, con el botón de seguir a la vista.
 *
 * El botón va en la tarjeta y no adentro de la página: seguir tiene que
 * poder hacerse desde donde se descubre, sin un paso intermedio.
 */
function TarjetaPagina({ pagina, mostrarSeguidores = true }) {
  const slug = pagina.url_slug || pagina.slug;

  return (
    // min-w-0: sin esto la tarjeta no puede achicarse por debajo del ancho de
    // su contenido —un título o un slug largo, que en una celda de grilla se
    // miden sin cortar— y estira la página entera a lo ancho en el teléfono.
    <div className="flex flex-col min-w-0 bg-white border border-borde rounded-2xl p-5 transition-colors hover:border-borde-fuerte">
      <a
        href={`/${slug}`}
        target="_blank"
        rel="noopener noreferrer"
        className="group flex items-start gap-4 mb-4"
      >
        <Avatar src={pagina.profile_image} nombre={pagina.title} tamano="lg" />

        <div className="min-w-0 flex-1">
          <h3 className="text-lg font-bold text-tinta leading-tight group-hover:text-verde-oscuro transition-colors flex items-center gap-1.5 min-w-0">
            <span className="truncate">{pagina.title}</span>
            <ExternalLink className="w-3.5 h-3.5 flex-shrink-0 opacity-0 group-hover:opacity-60 transition-opacity" />
          </h3>
          <p className="text-sm text-tinta-suave truncate">rezon.ar/{slug}</p>
          {pagina.description && (
            <p className="text-sm text-tinta-media mt-1.5 line-clamp-2">{pagina.description}</p>
          )}
        </div>
      </a>

      <div className="mt-auto flex items-center justify-between gap-3 pt-4 border-t border-borde">
        {mostrarSeguidores ? (
          <FollowersPopup
            pageId={pagina.id}
            followerCount={pagina.follower_count || 0}
            className="text-tinta-suave text-sm"
          />
        ) : <span />}
        <FollowButton pageId={pagina.id} />
      </div>
    </div>
  );
}

export default TarjetaPagina;
