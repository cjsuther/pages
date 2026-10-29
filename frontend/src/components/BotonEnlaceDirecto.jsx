import React from 'react';
import { ExternalLink } from 'lucide-react';
import { textoSobre } from '../utils/colores';

/**
 * El botón redondo de una tarjeta de evento o de producto que lleva a su
 * página propia (#51). La posición la pone cada plantilla con className.
 *
 * Va adentro de una tarjeta que se abre al tocarla: ni el clic ni las teclas
 * tienen que llegarle, porque abrirían el detalle en lugar de seguir el link.
 */
export default function BotonEnlaceDirecto({ href, colores, className = '' }) {
  return (
    <a
      href={href}
      onClick={(e) => e.stopPropagation()}
      onKeyDown={(e) => e.stopPropagation()}
      className={`p-2 rounded-full shadow-md transition hover:scale-110 ${className}`}
      style={{ backgroundColor: colores.acento, color: textoSobre(colores.acento, colores.texto) }}
      title="Ver enlace directo"
    >
      <ExternalLink className="w-4 h-4" />
    </a>
  );
}
