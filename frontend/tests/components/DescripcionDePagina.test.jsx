import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import DescripcionDePagina from '../../src/components/DescripcionDePagina';

/**
 * jsdom no maquetea: scrollHeight y clientHeight son siempre 0, así que no hay
 * forma de que un texto "desborde" solo. Se fuerzan las dos medidas para poder
 * probar las dos ramas, que es justo lo que decide si el "ver más" aparece.
 */
function medidas({ contenido, visible }) {
  // jsdom las define en Element, no en HTMLElement, y puede no definirlas: si
  // no había descriptor, restaurar es borrar la propiedad y no redefinirla con
  // undefined, que lanza.
  const donde = Element.prototype;
  const antes = {
    scrollHeight: Object.getOwnPropertyDescriptor(donde, 'scrollHeight'),
    clientHeight: Object.getOwnPropertyDescriptor(donde, 'clientHeight'),
  };

  const fingir = (nombre, alto) => Object.defineProperty(donde, nombre, {
    configurable: true,
    get() { return this.tagName === 'P' ? alto : 0; },
  });

  fingir('scrollHeight', contenido);
  fingir('clientHeight', visible);

  return () => {
    Object.entries(antes).forEach(([nombre, descriptor]) => {
      if (descriptor) {
        Object.defineProperty(donde, nombre, descriptor);
      } else {
        delete donde[nombre];
      }
    });
  };
}

let restaurar = null;

afterEach(() => {
  if (restaurar) restaurar();
  restaurar = null;
});

/** Un texto que entra en dos renglones. */
function corta(props = {}) {
  restaurar = medidas({ contenido: 40, visible: 40 });
  return render(<DescripcionDePagina texto="Una descripción" {...props} />);
}

/** Un texto que no entra: el contenido mide más que lo visible. */
function larga(props = {}) {
  restaurar = medidas({ contenido: 120, visible: 40 });
  return render(<DescripcionDePagina texto="Una descripción muy larga" {...props} />);
}

describe('DescripcionDePagina', () => {
  it('no dibuja nada sin texto', () => {
    const { container } = render(<DescripcionDePagina texto={null} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('muestra la descripción', () => {
    corta();

    expect(screen.getByText('Una descripción')).toBeInTheDocument();
  });

  /** Dos renglones: una descripción larga empujaba los links fuera de pantalla. */
  it('arranca recortada a dos renglones', () => {
    larga();

    expect(screen.getByText('Una descripción muy larga').className).toContain('line-clamp-2');
  });

  /**
   * Ofrecer "ver más" sobre un texto que ya se lee entero es prometer algo que
   * no pasa cuando se toca, y ensucia la mayoría de las páginas, que tienen
   * una sola línea.
   */
  it('no ofrece ver más si el texto entra entero', () => {
    corta();

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('ofrece ver más cuando hay texto escondido', () => {
    larga();

    expect(screen.getByRole('button', { name: 'Ver más' })).toBeInTheDocument();
  });

  it('despliega y vuelve a plegar', () => {
    larga();

    fireEvent.click(screen.getByRole('button', { name: 'Ver más' }));

    const parrafo = screen.getByText('Una descripción muy larga');
    expect(parrafo.className).not.toContain('line-clamp-2');
    expect(screen.getByRole('button', { name: 'Ver menos' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Ver menos' }));

    expect(screen.getByText('Una descripción muy larga').className).toContain('line-clamp-2');
    expect(screen.getByRole('button', { name: 'Ver más' })).toBeInTheDocument();
  });

  /** Para quien navega con lector de pantalla, el botón dice en qué estado está. */
  it('declara si está desplegada', () => {
    larga();

    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'false');

    fireEvent.click(screen.getByRole('button'));

    expect(screen.getByRole('button')).toHaveAttribute('aria-expanded', 'true');
  });

  /** La pantalla es del artista: el botón va con su paleta, no con la de Rezonar. */
  it('usa el color de la página', () => {
    larga({ color: '#ff0000' });

    expect(screen.getByRole('button')).toHaveStyle({ color: '#ff0000' });
  });

  it('conserva las clases de la plantilla', () => {
    larga({ className: 'text-xl opacity-70' });

    const parrafo = screen.getByText('Una descripción muy larga');
    expect(parrafo.className).toContain('text-xl');
    expect(parrafo.className).toContain('opacity-70');
  });

  it('se puede alinear a la izquierda', () => {
    const { container } = larga({ centrado: false });

    expect(container.firstChild.className).not.toContain('text-center');
  });
});
