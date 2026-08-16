import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import EventCollaborators from '../../src/components/EventCollaborators';

const colaborador = (overrides = {}) => ({
  page_id: 7,
  page_slug: 'otra-pagina',
  page_title: 'Otra Página',
  page_image: null,
  ...overrides,
});

describe('EventCollaborators', () => {
  it('no renderiza nada para un evento propio sin colaboradores', () => {
    const { container } = render(<EventCollaborators event={{}} currentPageId={5} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('no renderiza nada si el único colaborador es la propia página', () => {
    const { container } = render(
      <EventCollaborators
        event={{ collaborators: [colaborador({ page_id: 5 })] }}
        currentPageId={5}
      />
    );

    expect(container).toBeEmptyDOMElement();
  });

  it('excluye a la página actual de la lista de colaboradores', () => {
    render(
      <EventCollaborators
        event={{
          collaborators: [
            colaborador({ page_id: 5, page_title: 'Esta Página' }),
            colaborador({ page_id: 7, page_title: 'Otra Página' }),
          ],
        }}
        currentPageId={5}
      />
    );

    expect(screen.queryByText('Esta Página')).not.toBeInTheDocument();
    expect(screen.getByText('Otra Página')).toBeInTheDocument();
  });

  it('compara los ids sin exigir el mismo tipo', () => {
    // La API devuelve los ids como string y el componente los recibe como número.
    const { container } = render(
      <EventCollaborators
        event={{ collaborators: [colaborador({ page_id: '5' })] }}
        currentPageId={5}
      />
    );

    expect(container).toBeEmptyDOMElement();
  });

  it('muestra el organizador de un evento colaborado', () => {
    render(
      <EventCollaborators
        event={{
          is_collaborated: true,
          source_page_slug: 'organizadora',
          source_page_title: 'Página Organizadora',
        }}
        currentPageId={5}
      />
    );

    expect(screen.getByText('Organiza:')).toBeInTheDocument();

    const enlace = screen.getByRole('link', { name: /Página Organizadora/ });
    expect(enlace).toHaveAttribute('href', '/organizadora');
    expect(enlace).toHaveAttribute('target', '_blank');
    expect(enlace).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('no muestra el bloque de organizador si falta el slug', () => {
    const { container } = render(
      <EventCollaborators
        event={{ is_collaborated: true, source_page_title: 'Sin slug' }}
        currentPageId={5}
      />
    );

    expect(screen.queryByText('Organiza:')).not.toBeInTheDocument();
    expect(container).not.toBeEmptyDOMElement();
  });

  it('muestra la imagen del organizador si la hay', () => {
    render(
      <EventCollaborators
        event={{
          is_collaborated: true,
          source_page_slug: 'organizadora',
          source_page_title: 'Organizadora',
          source_page_image: 'https://img/o.png',
        }}
        currentPageId={5}
      />
    );

    expect(screen.getByAltText('Organizadora')).toHaveAttribute('src', 'https://img/o.png');
  });

  it('lista varios colaboradores', () => {
    render(
      <EventCollaborators
        event={{
          collaborators: [
            colaborador({ page_id: 7, page_title: 'Uno', page_slug: 'uno' }),
            colaborador({ page_id: 8, page_title: 'Dos', page_slug: 'dos' }),
          ],
        }}
        currentPageId={5}
      />
    );

    expect(screen.getByText('Colabora:')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Uno' })).toHaveAttribute('href', '/uno');
    expect(screen.getByRole('link', { name: 'Dos' })).toHaveAttribute('href', '/dos');
  });

  it('muestra organizador y colaboradores a la vez', () => {
    render(
      <EventCollaborators
        event={{
          is_collaborated: true,
          source_page_slug: 'organizadora',
          source_page_title: 'Organizadora',
          collaborators: [colaborador({ page_id: 7, page_title: 'Colaboradora' })],
        }}
        currentPageId={5}
      />
    );

    expect(screen.getByText('Organiza:')).toBeInTheDocument();
    expect(screen.getByText('Colabora:')).toBeInTheDocument();
  });

  it('aplica el color recibido a los enlaces', () => {
    render(
      <EventCollaborators
        event={{ collaborators: [colaborador()] }}
        currentPageId={5}
        color="#ff0000"
      />
    );

    expect(screen.getByRole('link', { name: 'Otra Página' })).toHaveStyle({ color: '#ff0000' });
  });

  it('el click en un colaborador no propaga al contenedor', () => {
    // El componente se usa dentro de tarjetas clicables: sin stopPropagation,
    // tocar el colaborador abriría también el detalle del evento.
    const onContenedorClick = vi.fn();

    render(
      <div onClick={onContenedorClick}>
        <EventCollaborators event={{ collaborators: [colaborador()] }} currentPageId={5} />
      </div>
    );

    fireEvent.click(screen.getByRole('link', { name: 'Otra Página' }));

    expect(onContenedorClick).not.toHaveBeenCalled();
  });

  it('el click en el organizador tampoco propaga', () => {
    const onContenedorClick = vi.fn();

    render(
      <div onClick={onContenedorClick}>
        <EventCollaborators
          event={{
            is_collaborated: true,
            source_page_slug: 'organizadora',
            source_page_title: 'Organizadora',
          }}
          currentPageId={5}
        />
      </div>
    );

    fireEvent.click(screen.getByRole('link', { name: 'Organizadora' }));

    expect(onContenedorClick).not.toHaveBeenCalled();
  });
});
