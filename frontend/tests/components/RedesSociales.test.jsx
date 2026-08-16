import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import RedesSociales from '../../src/components/RedesSociales';

describe('RedesSociales', () => {
  /** La razón de ser de la sección: sólo se ve lo que el usuario completó. */
  it('no renderiza nada si no hay redes cargadas', () => {
    const { container } = render(<RedesSociales socials={[]} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('no renderiza nada si socials viene sin definir', () => {
    const { container } = render(<RedesSociales />);

    expect(container).toBeEmptyDOMElement();
  });

  it('ignora las entradas sin URL', () => {
    const { container } = render(
      <RedesSociales socials={[{ red: 'instagram', url: '' }, { red: 'tiktok' }]} />
    );

    expect(container).toBeEmptyDOMElement();
  });

  it('dibuja un enlace por red cargada', () => {
    render(
      <RedesSociales
        socials={[
          { red: 'instagram', url: 'https://instagram.com/yo' },
          { red: 'youtube', url: 'https://youtube.com/@yo' },
        ]}
      />
    );

    expect(screen.getAllByRole('link')).toHaveLength(2);
  });

  it('cada enlace apunta a su URL y abre en otra pestaña', () => {
    render(<RedesSociales socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]} />);

    const enlace = screen.getByRole('link');

    expect(enlace).toHaveAttribute('href', 'https://instagram.com/yo');
    expect(enlace).toHaveAttribute('target', '_blank');
    expect(enlace).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('etiqueta cada icono con el nombre de la red', () => {
    render(<RedesSociales socials={[{ red: 'whatsapp', url: 'https://wa.me/549' }]} />);

    expect(screen.getByRole('link', { name: 'WhatsApp' })).toBeInTheDocument();
  });

  it('usa la clave como etiqueta si la red no está en el catálogo', () => {
    render(<RedesSociales socials={[{ red: 'rara', url: 'https://x.com' }]} />);

    expect(screen.getByRole('link', { name: 'rara' })).toBeInTheDocument();
  });

  it('dibuja un svg por icono', () => {
    const { container } = render(
      <RedesSociales socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]} />
    );

    expect(container.querySelector('svg')).toBeInTheDocument();
  });

  /** Los iconos de marca heredan el color del template en vez de imponerlo. */
  it('los iconos propios usan currentColor', () => {
    const { container } = render(
      <RedesSociales socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]} />
    );

    expect(container.querySelector('svg')).toHaveAttribute('fill', 'currentColor');
  });

  /** Cafecito está dibujado con trazo; con fill saldría como una mancha. */
  it('los iconos de trazo se dibujan con stroke y sin relleno', () => {
    const { container } = render(
      <RedesSociales socials={[{ red: 'cafecito', url: 'https://cafecito.app/yo' }]} />
    );

    const svg = container.querySelector('svg');
    expect(svg).toHaveAttribute('stroke', 'currentColor');
    expect(svg).toHaveAttribute('fill', 'none');
  });

  it('las redes sin icono de marca usan el de lucide', () => {
    const { container } = render(
      <RedesSociales socials={[{ red: 'spotify', url: 'https://open.spotify.com/x' }]} />
    );

    expect(container.querySelector('svg')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Spotify' })).toBeInTheDocument();
  });

  it('aplica el color recibido', () => {
    render(
      <RedesSociales socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]} color="#ff0000" />
    );

    expect(screen.getByRole('link')).toHaveStyle({ color: '#ff0000' });
  });

  it('el click no propaga al contenedor', () => {
    // Va dentro de cabeceras que a veces son clicables.
    const onContenedor = vi.fn();

    render(
      <div onClick={onContenedor}>
        <RedesSociales socials={[{ red: 'instagram', url: 'https://instagram.com/yo' }]} />
      </div>
    );

    fireEvent.click(screen.getByRole('link'));

    expect(onContenedor).not.toHaveBeenCalled();
  });

  it('respeta el orden en que llegan', () => {
    render(
      <RedesSociales
        socials={[
          { red: 'youtube', url: 'https://youtube.com/@yo' },
          { red: 'instagram', url: 'https://instagram.com/yo' },
        ]}
      />
    );

    const nombres = screen.getAllByRole('link').map((a) => a.getAttribute('aria-label'));
    expect(nombres).toEqual(['YouTube', 'Instagram']);
  });
});
