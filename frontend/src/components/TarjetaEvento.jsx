import React from 'react';
import { Link } from 'react-router-dom';
import { MapPin, Clock, Navigation as Brujula } from 'lucide-react';
import { cuandoEs, horaDe, localidadDe } from '../utils/eventos';
import { Avatar } from './ui';

/**
 * Un evento en un listado.
 *
 * La misma tarjeta en la home, en los resultados de búsqueda y en la agenda de
 * páginas seguidas: si el mismo dato se dibuja distinto en cada pantalla, la
 * persona tiene que volver a aprender a leerlo en cada una.
 *
 * El orden de lectura es cuándo → qué → quién → dónde, que es el orden en que
 * se decide si se va o no.
 */
function TarjetaEvento({ evento, distanciaKm = null, mostrarPagina = true }) {
  const cuando = cuandoEs(evento);
  const hora = horaDe(evento);
  const donde = localidadDe(evento.event_address) || evento.event_address;
  const titulo = evento.text || evento.title;

  return (
    <Link
      to={`/evento/${evento.id}`}
      className="group flex flex-col bg-white border border-borde rounded-2xl overflow-hidden transition-colors hover:border-verde focus-visible:border-verde"
    >
      {evento.image_url && (
        <div className="relative aspect-[16/10] overflow-hidden bg-papel-hueso">
          <img
            src={evento.image_url}
            alt=""
            loading="lazy"
            className="w-full h-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
          />
          {cuando && (
            <span className="absolute top-3 left-3 inline-flex items-center gap-1.5 bg-verde text-verde-tinta text-xs font-bold px-3 py-1.5 rounded-full">
              {cuando}
              {hora && <span className="font-semibold opacity-80">· {hora}</span>}
            </span>
          )}
        </div>
      )}

      <div className="p-5 flex flex-col gap-2.5 flex-1">
        {/* Sin imagen la fecha no tiene dónde ir encima, así que abre el texto. */}
        {!evento.image_url && cuando && (
          <span className="inline-flex items-center gap-1.5 self-start bg-verde-claro text-verde-oscuro text-xs font-bold px-3 py-1 rounded-full border border-verde-medio">
            {cuando}
            {hora && <span className="opacity-80">· {hora}</span>}
          </span>
        )}

        <h3 className="text-lg font-bold text-tinta leading-snug text-balance group-hover:text-verde-oscuro transition-colors">
          {titulo}
        </h3>

        {mostrarPagina && evento.page_title && (
          <div className="flex items-center gap-2 text-sm text-tinta-media">
            <Avatar src={evento.page_image} nombre={evento.page_title} tamano="sm" className="!w-5 !h-5 !text-[10px]" />
            <span className="truncate font-medium">{evento.page_title}</span>
          </div>
        )}

        <div className="mt-auto pt-1 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-tinta-suave">
          {donde && (
            <span className="inline-flex items-center gap-1.5 min-w-0">
              <MapPin className="w-3.5 h-3.5 flex-shrink-0" />
              <span className="truncate">{donde}</span>
            </span>
          )}
          {hora && !evento.image_url && (
            <span className="inline-flex items-center gap-1.5">
              <Clock className="w-3.5 h-3.5" />
              {hora}
            </span>
          )}
          {distanciaKm !== null && distanciaKm !== undefined && (
            <span className="inline-flex items-center gap-1.5 text-verde-oscuro font-semibold">
              <Brujula className="w-3.5 h-3.5" />
              a {distanciaKm < 1 ? 'menos de 1' : distanciaKm.toFixed(1)} km
            </span>
          )}
        </div>
      </div>
    </Link>
  );
}

export default TarjetaEvento;
