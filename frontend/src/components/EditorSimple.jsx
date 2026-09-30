import React, { useEffect, useRef } from 'react';
import { Bold, Italic, List, ListOrdered, Link2, Eraser } from 'lucide-react';

/**
 * Un editor de texto con formato básico: negrita, cursiva, listas y links.
 *
 * Es deliberadamente chico. No hace falta más para describir un show, y cada
 * botón de más es una forma más de que el texto se vea raro en el celular de
 * alguien. Lo que produce se vuelve a limpiar en el servidor (HtmlSimple):
 * acá no se confía en que el HTML llegue sano.
 *
 * El contenido se carga una sola vez al montar. Reescribirlo en cada tecla
 * movería el cursor al principio.
 */
const BOTONES = [
  { comando: 'bold', icono: Bold, etiqueta: 'Negrita' },
  { comando: 'italic', icono: Italic, etiqueta: 'Cursiva' },
  { comando: 'insertUnorderedList', icono: List, etiqueta: 'Lista' },
  { comando: 'insertOrderedList', icono: ListOrdered, etiqueta: 'Lista numerada' },
];

function EditorSimple({ id, valor = '', alCambiar, placeholder = '', etiquetadoPor }) {
  const area = useRef(null);

  useEffect(() => {
    if (area.current) area.current.innerHTML = valor || '';
    // Sólo al montar: ver arriba.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const avisar = () => {
    if (area.current) alCambiar(area.current.innerHTML);
  };

  const aplicar = (comando, argumento = null) => {
    area.current.focus();
    document.execCommand(comando, false, argumento);
    avisar();
  };

  const link = () => {
    const direccion = window.prompt('Dirección del link (por ejemplo instagram.com/carcajada)');
    if (direccion && direccion.trim()) aplicar('createLink', direccion.trim());
  };

  // mousedown y no click: con click el editor pierde la selección antes de
  // que el botón la use, y la negrita no se aplica a nada.
  const boton = (etiqueta, Icono, alTocar) => (
    <button
      key={etiqueta}
      type="button"
      title={etiqueta}
      aria-label={etiqueta}
      onMouseDown={(e) => { e.preventDefault(); alTocar(); }}
      className="p-2 rounded-lg text-tinta-media hover:text-tinta hover:bg-papel-hueso"
    >
      <Icono className="w-4 h-4" />
    </button>
  );

  return (
    <div className="rounded-xl border border-borde-fuerte bg-white focus-within:border-verde-oscuro">
      <div className="flex flex-wrap gap-0.5 border-b border-borde px-1.5 py-1" role="toolbar" aria-label="Formato">
        {BOTONES.map(({ comando, icono, etiqueta }) => boton(etiqueta, icono, () => aplicar(comando)))}
        {boton('Link', Link2, link)}
        {boton('Sacar formato', Eraser, () => aplicar('removeFormat'))}
      </div>

      <div
        id={id}
        ref={area}
        role="textbox"
        aria-multiline="true"
        aria-labelledby={etiquetadoPor}
        contentEditable
        suppressContentEditableWarning
        onInput={avisar}
        onBlur={avisar}
        data-placeholder={placeholder}
        className="editor-simple min-h-[8rem] px-4 py-3 text-tinta leading-relaxed focus:outline-none"
      />
    </div>
  );
}

export default EditorSimple;
