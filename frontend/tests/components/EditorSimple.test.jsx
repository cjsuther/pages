import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import EditorSimple from '../../src/components/EditorSimple';

/**
 * El editor es chico a propósito: lo que importa es que arranque con el
 * texto guardado, que avise cada cambio y que cada botón aplique su formato.
 * jsdom no implementa execCommand, así que se espía.
 */
describe('EditorSimple', () => {
  beforeEach(() => {
    document.execCommand = vi.fn(() => true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('arranca con el texto guardado', () => {
    render(<EditorSimple id="e" valor="<p>Hola <strong>che</strong></p>" alCambiar={() => {}} />);

    expect(screen.getByRole('textbox').innerHTML).toBe('<p>Hola <strong>che</strong></p>');
  });

  it('avisa cada cambio con el HTML', () => {
    const alCambiar = vi.fn();
    render(<EditorSimple id="e" alCambiar={alCambiar} />);
    const area = screen.getByRole('textbox');

    area.innerHTML = '<p>Nuevo</p>';
    fireEvent.input(area);

    expect(alCambiar).toHaveBeenCalledWith('<p>Nuevo</p>');
  });

  it.each([
    ['Negrita', 'bold'],
    ['Cursiva', 'italic'],
    ['Lista', 'insertUnorderedList'],
    ['Lista numerada', 'insertOrderedList'],
    ['Sacar formato', 'removeFormat'],
  ])('%s aplica %s', (boton, comando) => {
    render(<EditorSimple id="e" alCambiar={() => {}} />);

    fireEvent.mouseDown(screen.getByRole('button', { name: boton }));

    expect(document.execCommand).toHaveBeenCalledWith(comando, false, null);
  });

  it('el link pide la dirección', () => {
    vi.spyOn(window, 'prompt').mockReturnValue(' instagram.com/carcajada ');
    render(<EditorSimple id="e" alCambiar={() => {}} />);

    fireEvent.mouseDown(screen.getByRole('button', { name: 'Link' }));

    expect(document.execCommand).toHaveBeenCalledWith('createLink', false, 'instagram.com/carcajada');
  });

  it('si se cancela el link no hace nada', () => {
    vi.spyOn(window, 'prompt').mockReturnValue(null);
    render(<EditorSimple id="e" alCambiar={() => {}} />);

    fireEvent.mouseDown(screen.getByRole('button', { name: 'Link' }));

    expect(document.execCommand).not.toHaveBeenCalled();
  });
});
