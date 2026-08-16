import React from 'react';
import { Twitter, Music2, Linkedin, Send, Mail, Globe } from 'lucide-react';
import { IconoDeMarca } from './IconosRedes';
import { buscarRed } from '../utils/redes';

/**
 * Fila de iconos de redes sociales para la cabecera de las plantillas.
 *
 * Sólo dibuja lo que el usuario cargó: si no completó ninguna, no ocupa
 * espacio ni deja huecos.
 */

/** Redes sin icono de marca propio: se usa el de lucide más cercano. */
const ICONOS_LUCIDE = {
  x: Twitter,
  spotify: Music2,
  linkedin: Linkedin,
  telegram: Send,
  email: Mail,
  web: Globe,
};

function Icono({ red, className }) {
  const DeLucide = ICONOS_LUCIDE[red];

  if (DeLucide) {
    return <DeLucide className={className} />;
  }

  return <IconoDeMarca red={red} className={className} />;
}

function RedesSociales({ socials, color, className = '', tamano = 'w-5 h-5' }) {
  const cargadas = (socials || []).filter((s) => s && s.url);

  if (cargadas.length === 0) {
    return null;
  }

  return (
    <div className={`flex items-center justify-center gap-4 flex-wrap ${className}`}>
      {cargadas.map((social) => {
        const red = buscarRed(social.red);

        return (
          <a
            key={social.red}
            href={social.url}
            target="_blank"
            rel="noopener noreferrer"
            title={red ? red.nombre : social.red}
            aria-label={red ? red.nombre : social.red}
            className="opacity-70 hover:opacity-100 transition-opacity"
            style={color ? { color } : undefined}
            onClick={(e) => e.stopPropagation()}
          >
            <Icono red={social.red} className={tamano} />
          </a>
        );
      })}
    </div>
  );
}

export default RedesSociales;
