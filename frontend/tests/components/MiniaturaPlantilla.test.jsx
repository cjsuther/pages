import React from 'react';
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import MiniaturaPlantilla from '../../src/components/MiniaturaPlantilla';

/**
 * La miniatura no es una captura: se dibuja con los colores de la página. Lo
 * que hay que sostener es que cada plantilla se vea distinta de las otras y
 * que la paleta sea la que el usuario eligió.
 */
const PLANTILLAS = ['minimal', 'cards', 'modern', 'condensed'];

const pagina = (overrides = {}) => ({
  background_color: '#102030',
  text_color: '#f0f0f0',
  primary_color: '#ff8800',
  ...overrides,
});

describe('MiniaturaPlantilla', () => {
  it.each(PLANTILLAS)('dibuja la plantilla %s', (plantilla) => {
    const { container } = render(<MiniaturaPlantilla plantilla={plantilla} page={pagina()} />);

    expect(container.querySelector(`[data-plantilla="${plantilla}"]`)).not.toBeNull();
  });

  it('usa el color de fondo de la página', () => {
    const { container } = render(<MiniaturaPlantilla plantilla="minimal" page={pagina()} />);

    expect(container.querySelector('[data-plantilla] > div')).toHaveStyle({
      backgroundColor: '#102030',
    });
  });

  // Si dos plantillas se dibujaran igual, la vista previa no serviría de nada.
  it('cada plantilla se dibuja distinto de las demás', () => {
    const dibujos = PLANTILLAS.map((plantilla) => {
      const { container } = render(<MiniaturaPlantilla plantilla={plantilla} page={pagina()} />);
      return container.querySelector('[data-plantilla]').innerHTML;
    });

    expect(new Set(dibujos).size).toBe(PLANTILLAS.length);
  });

  it('no rompe con una página sin colores elegidos', () => {
    const { container } = render(<MiniaturaPlantilla plantilla="cards" page={{}} />);

    // Los mismos valores por defecto que usan las plantillas de verdad.
    expect(container.querySelector('[data-plantilla] > div')).toHaveStyle({
      backgroundColor: '#ffffff',
    });
  });

  it('una plantilla desconocida no deja el recuadro vacío', () => {
    const { container } = render(<MiniaturaPlantilla plantilla="inventada" page={pagina()} />);

    expect(container.querySelector('[data-plantilla]').children.length).toBeGreaterThan(0);
  });
});
