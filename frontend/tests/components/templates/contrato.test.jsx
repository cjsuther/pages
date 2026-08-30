import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { screen, within, fireEvent } from '@testing-library/react';
import MinimalTemplate from '../../../src/components/templates/MinimalTemplate';
import ModernTemplate from '../../../src/components/templates/ModernTemplate';
import CardsTemplate from '../../../src/components/templates/CardsTemplate';
import CondensedTemplate from '../../../src/components/templates/CondensedTemplate';
import { renderConProviders } from '../../helpers/render';
import { ANCHO_COLUMNA } from '../../../src/utils/plantillas';

/**
 * Las cuatro plantillas son intercambiables: reciben la misma página y deben
 * cumplir el mismo contrato. Lo que cambia es la estética, no los datos que
 * muestran, así que estas comprobaciones corren contra todas.
 */
const PLANTILLAS = [
  ['MinimalTemplate', MinimalTemplate],
  ['ModernTemplate', ModernTemplate],
  ['CardsTemplate', CardsTemplate],
  ['CondensedTemplate', CondensedTemplate],
];

const pagina = (overrides = {}) => ({
  id: 5,
  title: 'Mi Página',
  description: 'Una descripción',
  url_slug: 'mi-pagina',
  profile_image: null,
  background_image: null,
  background_color: '#ffffff',
  text_color: '#000000',
  primary_color: '#3b82f6',
  follower_count: 7,
  groups: [],
  ...overrides,
});

const grupoDeLinks = (links) => ({
  id: 10,
  title: 'Mis Links',
  type: 'links',
  links,
});

const link = (overrides = {}) => ({
  id: 100,
  url: 'https://ejemplo.com',
  text: 'Un link',
  description: null,
  image_url: null,
  ...overrides,
});

/** Busca un enlace por su destino, sin depender de cómo se maquete el texto. */
const porHref = (href) =>
  screen.getAllByRole('link').find((a) => a.getAttribute('href') === href);

const evento = (overrides = {}) => ({
  id: 200,
  text: 'Mi Evento',
  url: 'https://entradas.com',
  description: 'Descripción del evento',
  image_url: null,
  event_date: '2026-12-01',
  event_time: '20:00:00',
  event_address: 'Av. Corrientes 1234',
  event_maps_url: 'https://maps.google.com/x',
  collaborators: [],
  ...overrides,
});

describe.each(PLANTILLAS)('%s', (nombre, Plantilla) => {
  beforeEach(() => {
    window.gtag = vi.fn();
  });

  // Las páginas se ven siempre en formato mobile, también en una pantalla
  // grande: por el teléfono entra casi todo el mundo, y así una página no se
  // ve de dos maneras distintas.
  describe('formato mobile', () => {
    it('la columna no se ensancha en una pantalla grande', () => {
      const { container } = renderConProviders(<Plantilla page={pagina()} />);

      expect(container.querySelector(`[class*="${ANCHO_COLUMNA}"]`)).not.toBeNull();
    });

    // Los prefijos md: de Tailwind miran la ventana, no el contenedor: dentro
    // de una columna angosta seguirían partiendo el contenido en dos.
    it('no parte el contenido en columnas según el ancho de la ventana', () => {
      const { container } = renderConProviders(
        <Plantilla
          page={pagina({
            groups: [
              grupoDeLinks([link(), link({ id: 101 })]),
              { id: 30, title: 'Fotos', type: 'galeria', links: [{ id: 3, text: 'Foto', image_url: 'https://img/1.jpg' }] },
            ],
          })}
        />
      );

      const conMd = [...container.querySelectorAll('[class*="md:grid-cols"], [class*="md:col-span"]')];
      expect(conMd.map((n) => n.className)).toEqual([]);
    });
  });

  describe('cabecera', () => {
    it('muestra el título de la página', () => {
      renderConProviders(<Plantilla page={pagina()} />);

      expect(screen.getByText('Mi Página')).toBeInTheDocument();
    });

    it('muestra la descripción', () => {
      renderConProviders(<Plantilla page={pagina()} />);

      expect(screen.getByText('Una descripción')).toBeInTheDocument();
    });

    it('funciona sin descripción', () => {
      expect(() =>
        renderConProviders(<Plantilla page={pagina({ description: null })} />)
      ).not.toThrow();
    });

    it('muestra la imagen de perfil si la hay', () => {
      renderConProviders(
        <Plantilla page={pagina({ profile_image: 'https://img/perfil.png' })} />
      );

      const imagenes = screen.getAllByRole('img');
      expect(imagenes.some((i) => i.getAttribute('src') === 'https://img/perfil.png')).toBe(true);
    });

    it('muestra el contador de seguidores', () => {
      renderConProviders(<Plantilla page={pagina({ follower_count: 7 })} />);

      expect(screen.getByText(/7 seguidores/)).toBeInTheDocument();
    });

    it('muestra cero seguidores si no vienen', () => {
      renderConProviders(<Plantilla page={pagina({ follower_count: undefined })} />);

      expect(screen.getByText(/0 seguidores/)).toBeInTheDocument();
    });
  });

  describe('sin contenido', () => {
    it('no rompe con groups vacío', () => {
      expect(() => renderConProviders(<Plantilla page={pagina({ groups: [] })} />)).not.toThrow();
    });

    it('no rompe si groups viene sin definir', () => {
      expect(() => renderConProviders(<Plantilla page={pagina({ groups: undefined })} />)).not.toThrow();
    });

    it('no rompe con un grupo sin links', () => {
      expect(() =>
        renderConProviders(
          <Plantilla page={pagina({ groups: [{ id: 1, title: 'Vacío', type: 'links' }] })} />
        )
      ).not.toThrow();
    });
  });

  describe('grupos de links', () => {
    it('muestra el título del grupo', () => {
      renderConProviders(
        <Plantilla page={pagina({ groups: [grupoDeLinks([link()])] })} />
      );

      expect(screen.getByText('Mis Links')).toBeInTheDocument();
    });

    it('muestra cada link con su texto y su URL', () => {
      renderConProviders(
        <Plantilla
          page={pagina({
            groups: [grupoDeLinks([
              link({ id: 1, text: 'Instagram', url: 'https://instagram.com/yo' }),
              link({ id: 2, text: 'Spotify', url: 'https://spotify.com/yo' }),
            ])],
          })}
        />
      );

      // Cada plantilla maqueta el texto distinto (con flechas, iconos o spans
      // anidados), así que se busca por destino y se comprueba el contenido.
      expect(porHref('https://instagram.com/yo')).toHaveTextContent('Instagram');
      expect(porHref('https://spotify.com/yo')).toHaveTextContent('Spotify');
    });

    it('los links salen en una pestaña nueva y sin filtrar el referrer', () => {
      renderConProviders(
        <Plantilla page={pagina({ groups: [grupoDeLinks([link({ url: 'https://instagram.com/yo' })])] })} />
      );

      const enlace = porHref('https://instagram.com/yo');
      expect(enlace).toHaveAttribute('target', '_blank');
      expect(enlace).toHaveAttribute('rel', expect.stringContaining('noopener'));
    });
  });

  describe('grupos de eventos', () => {
    const conEventos = (links, colaborados = []) =>
      pagina({
        groups: [{ id: 20, title: 'Agenda', type: 'eventos', links, collaborated_events: colaborados }],
      });

    it('muestra el nombre del evento', () => {
      renderConProviders(<Plantilla page={conEventos([evento()])} />);

      expect(screen.getByText('Mi Evento')).toBeInTheDocument();
    });

    it('muestra varios eventos', () => {
      renderConProviders(
        <Plantilla
          page={conEventos([
            evento({ id: 1, text: 'Primero', event_date: '2026-01-01' }),
            evento({ id: 2, text: 'Segundo', event_date: '2026-02-01' }),
          ])}
        />
      );

      expect(screen.getByText('Primero')).toBeInTheDocument();
      expect(screen.getByText('Segundo')).toBeInTheDocument();
    });

    it('incluye los eventos colaborados junto a los propios', () => {
      renderConProviders(
        <Plantilla
          page={conEventos(
            [evento({ id: 1, text: 'Propio' })],
            [evento({ id: 2, text: 'Colaborado', is_collaborated: true, source_page_slug: 'otra', source_page_title: 'Otra' })]
          )}
        />
      );

      expect(screen.getByText('Propio')).toBeInTheDocument();
      expect(screen.getByText('Colaborado')).toBeInTheDocument();
    });

    it('no rompe si no hay eventos colaborados', () => {
      expect(() =>
        renderConProviders(
          <Plantilla page={pagina({ groups: [{ id: 20, title: 'Agenda', type: 'eventos', links: [evento()] }] })} />
        )
      ).not.toThrow();
    });

    it('ofrece el enlace directo al evento', () => {
      renderConProviders(<Plantilla page={conEventos([evento({ id: 200 })])} />);

      const directos = screen.getAllByRole('link').filter(
        (a) => a.getAttribute('href') === '/evento/200'
      );
      expect(directos.length).toBeGreaterThan(0);
    });
  });

  describe('grupos de galería', () => {
    const conGaleria = (links) =>
      pagina({ groups: [{ id: 30, title: 'Fotos', type: 'galeria', links }] });

    it('muestra las imágenes', () => {
      renderConProviders(
        <Plantilla
          page={conGaleria([
            { id: 1, text: 'Foto uno', image_url: 'https://img/1.jpg' },
            { id: 2, text: 'Foto dos', image_url: 'https://img/2.jpg' },
          ])}
        />
      );

      expect(screen.getByAltText('Foto uno')).toHaveAttribute('src', 'https://img/1.jpg');
      expect(screen.getByAltText('Foto dos')).toHaveAttribute('src', 'https://img/2.jpg');
    });

    it('no rompe con una galería vacía', () => {
      expect(() => renderConProviders(<Plantilla page={conGaleria([])} />)).not.toThrow();
    });

    // Una galería también puede tener videos de YouTube y contenido de
    // Instagram. En la grilla van como imagen quieta: doce reproductores
    // cargando a la vez harían inusable la página.
    describe('videos y contenido de Instagram', () => {
      const VIDEO = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
      const POST = 'https://www.instagram.com/p/CxAbC123_-x/';

      it('un video usa su propia miniatura sin que haya que subirla', () => {
        renderConProviders(
          <Plantilla page={conGaleria([{ id: 1, text: 'Mi video', embed_url: VIDEO }])} />
        );

        expect(screen.getByAltText('Mi video'))
          .toHaveAttribute('src', 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
      });

      it('la portada que subió el usuario le gana a la del servicio', () => {
        renderConProviders(
          <Plantilla
            page={conGaleria([
              { id: 1, text: 'Mi video', embed_url: VIDEO, image_url: 'https://img/propia.jpg' },
            ])}
          />
        );

        expect(screen.getByAltText('Mi video')).toHaveAttribute('src', 'https://img/propia.jpg');
      });

      it('la grilla no carga ningún reproductor', () => {
        const { container } = renderConProviders(
          <Plantilla page={conGaleria([{ id: 1, text: 'Mi video', embed_url: VIDEO }])} />
        );

        expect(container.querySelector('iframe')).toBeNull();
      });

      it('al abrir el video se reproduce ahí mismo', () => {
        const { container } = renderConProviders(
          <Plantilla page={conGaleria([{ id: 1, text: 'Mi video', embed_url: VIDEO }])} />
        );

        fireEvent.click(screen.getByAltText('Mi video'));

        const marco = container.querySelector('iframe');
        expect(marco).toHaveAttribute('src', expect.stringContaining('youtube-nocookie.com/embed/dQw4w9WgXcQ'));
      });

      it('al abrir un contenido de Instagram se ve el post', () => {
        const { container } = renderConProviders(
          <Plantilla
            page={conGaleria([
              { id: 1, text: 'Mi post', embed_url: POST, image_url: 'https://img/portada.jpg' },
            ])}
          />
        );

        fireEvent.click(screen.getByAltText('Mi post'));

        expect(container.querySelector('iframe'))
          .toHaveAttribute('src', 'https://www.instagram.com/p/CxAbC123_-x/embed');
      });

      // Instagram no publica miniaturas sin API. En vez de dejar un recuadro
      // con el logo, que no dice nada de lo que hay adentro, lo dibuja el
      // propio Instagram.
      it('un contenido de Instagram sin portada se muestra en la grilla', () => {
        const { container } = renderConProviders(
          <Plantilla page={conGaleria([{ id: 1, text: 'Mi post', embed_url: POST }])} />
        );

        expect(container.querySelector('iframe'))
          .toHaveAttribute('src', 'https://www.instagram.com/p/CxAbC123_-x/embed');
      });

      // Doce celdas son doce iframes: sin esto, una galería larga cargaría
      // todos de golpe al abrir la página.
      it('el contenido de la grilla se carga recién cuando se va a ver', () => {
        const { container } = renderConProviders(
          <Plantilla page={conGaleria([{ id: 1, text: 'Mi post', embed_url: POST }])} />
        );

        expect(container.querySelector('iframe')).toHaveAttribute('loading', 'lazy');
      });

      // El iframe se come los clicks: si no se los dejara pasar, el item sería
      // el único de la galería que no se puede abrir.
      it('un contenido de Instagram sin portada igual se puede abrir', () => {
        const { container } = renderConProviders(
          <Plantilla page={conGaleria([{ id: 1, text: 'Mi post', embed_url: POST }])} />
        );

        expect(container.querySelector('iframe').style.pointerEvents).toBe('none');

        fireEvent.click(screen.getByText('Contenido de Instagram'));

        // Abierto, el mismo contenido pero ya sin recortar por la celda.
        expect(container.querySelectorAll('iframe').length).toBe(2);
      });

      it('con portada la grilla sigue sin cargar nada de Instagram', () => {
        const { container } = renderConProviders(
          <Plantilla
            page={conGaleria([
              { id: 1, text: 'Mi post', embed_url: POST, image_url: 'https://img/portada.jpg' },
            ])}
          />
        );

        expect(container.querySelector('iframe')).toBeNull();
      });
    });
  });

  describe('precio de referencia', () => {
    const conPrecio = (precio, entradas = null) =>
      pagina({
        groups: [{
          id: 20, title: 'Agenda', type: 'eventos', collaborated_events: [],
          links: [evento({ precio_desde: precio, entradas })],
        }],
      });

    const abrir = () => fireEvent.click(screen.getByText('Mi Evento'));

    /** El cero es la afirmación de que el evento es gratis, no falta de dato. */
    it('un evento en cero se anuncia como gratis', () => {
      renderConProviders(<Plantilla page={conPrecio(0)} />);
      abrir();

      expect(screen.getByText('Gratis')).toBeInTheDocument();
    });

    it('con precio dice desde cuánto sale', () => {
      renderConProviders(<Plantilla page={conPrecio(25000)} />);
      abrir();

      expect(screen.getByText(/Desde.*25\.000/)).toBeInTheDocument();
    });

    /** Inventar "Gratis" cuando no se sabe el precio sería mentir. */
    it('sin dato no anuncia nada', () => {
      renderConProviders(<Plantilla page={conPrecio(null)} />);
      abrir();

      expect(screen.queryByText('Gratis')).not.toBeInTheDocument();
      expect(screen.queryByText(/Desde/)).not.toBeInTheDocument();
    });

    /** Dos precios que podrían no coincidir confunden más de lo que ayudan. */
    it('no se muestra si el evento vende entradas por acá', () => {
      const conVenta = {
        activo: true, es_gratis: false, precio: 1500, moneda: 'ARS',
        disponibles: 50, max_por_compra: 6, agotado: false,
      };

      renderConProviders(<Plantilla page={conPrecio(25000, conVenta)} />);
      abrir();

      expect(screen.queryByText(/Desde.*25\.000/)).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ })).toBeInTheDocument();
    });
  });

  describe('venta de entradas', () => {
    const conVenta = {
      activo: true, es_gratis: false, precio: 1500, moneda: 'ARS',
      disponibles: 50, max_por_compra: 6, agotado: false,
    };

    /** Página con un solo evento que además tiene un link cargado a mano. */
    const conEntradas = (entradas) =>
      pagina({
        groups: [{
          id: 20,
          title: 'Agenda',
          type: 'eventos',
          links: [evento({
            url: 'https://entradas-externas.test',
            url_text: 'Comprar afuera',
            entradas,
          })],
          collaborated_events: [],
        }],
      });

    /** Cada plantilla maqueta la tarjeta distinto, pero todas abren al click. */
    const abrirElEvento = () => fireEvent.click(screen.getByText('Mi Evento'));

    it('un evento sin venta muestra el link cargado a mano', () => {
      renderConProviders(<Plantilla page={conEntradas(null)} />);
      abrirElEvento();

      expect(porHref('https://entradas-externas.test')).toBeTruthy();
    });

    it('con venta activa aparece el botón de compra', () => {
      renderConProviders(<Plantilla page={conEntradas(conVenta)} />);
      abrirElEvento();

      expect(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ })).toBeInTheDocument();
    });

    /**
     * Es lo pedido explícitamente: la venta interna reemplaza al link, no
     * convive con él. Dos botones compitiendo confunden a quien va a comprar.
     */
    it('el botón de compra reemplaza al link, no se suma', () => {
      renderConProviders(<Plantilla page={conEntradas(conVenta)} />);
      abrirElEvento();

      expect(porHref('https://entradas-externas.test')).toBeUndefined();
      expect(screen.queryByText('Comprar afuera')).not.toBeInTheDocument();
    });

    it('una reserva sin costo invita a reservar', () => {
      renderConProviders(<Plantilla page={conEntradas({ ...conVenta, es_gratis: true, precio: 0 })} />);
      abrirElEvento();

      expect(screen.getByRole('button', { name: /RESERVAR LUGAR/ })).toBeInTheDocument();
    });

    it('agotado no deja comprar y tampoco devuelve el link', () => {
      renderConProviders(<Plantilla page={conEntradas({ ...conVenta, agotado: true, disponibles: 0 })} />);
      abrirElEvento();

      expect(screen.getByText('AGOTADO')).toBeInTheDocument();
      expect(porHref('https://entradas-externas.test')).toBeUndefined();
    });

    it('la venta desactivada devuelve el link', () => {
      renderConProviders(<Plantilla page={conEntradas({ ...conVenta, activo: false })} />);
      abrirElEvento();

      expect(porHref('https://entradas-externas.test')).toBeTruthy();
    });

    it('se abre el formulario de compra desde el detalle', () => {
      renderConProviders(<Plantilla page={conEntradas(conVenta)} />);
      abrirElEvento();

      fireEvent.click(screen.getByRole('button', { name: /COMPRAR ENTRADAS/ }));

      expect(screen.getByLabelText('NOMBRE Y APELLIDO')).toBeInTheDocument();
    });
  });

  describe('redes sociales', () => {
    it('no ocupa espacio si la página no cargó ninguna', () => {
      renderConProviders(<Plantilla page={pagina({ socials: [] })} />);

      expect(screen.queryByRole('link', { name: 'Instagram' })).not.toBeInTheDocument();
    });

    it('no rompe si la página ni siquiera trae el campo', () => {
      expect(() => renderConProviders(<Plantilla page={pagina()} />)).not.toThrow();
    });

    it('muestra un icono por cada red cargada', () => {
      renderConProviders(
        <Plantilla
          page={pagina({
            socials: [
              { red: 'instagram', url: 'https://instagram.com/yo' },
              { red: 'youtube', url: 'https://youtube.com/@yo' },
            ],
          })}
        />
      );

      expect(screen.getByRole('link', { name: 'Instagram' })).toHaveAttribute(
        'href',
        'https://instagram.com/yo'
      );
      expect(screen.getByRole('link', { name: 'YouTube' })).toBeInTheDocument();
    });

    it('sólo muestra las cargadas, no todo el catálogo', () => {
      renderConProviders(
        <Plantilla page={pagina({ socials: [{ red: 'instagram', url: 'https://instagram.com/yo' }] })} />
      );

      expect(screen.getByRole('link', { name: 'Instagram' })).toBeInTheDocument();
      expect(screen.queryByRole('link', { name: 'TikTok' })).not.toBeInTheDocument();
      expect(screen.queryByRole('link', { name: 'Facebook' })).not.toBeInTheDocument();
    });

    it('los iconos van arriba, antes del contenido', () => {
      renderConProviders(
        <Plantilla
          page={pagina({
            socials: [{ red: 'instagram', url: 'https://instagram.com/yo' }],
            groups: [grupoDeLinks([link({ url: 'https://un-link.test' })])],
          })}
        />
      );

      const iconoRed = screen.getByRole('link', { name: 'Instagram' });
      // Por destino, porque cada plantilla maqueta el texto del link distinto.
      const contenido = porHref('https://un-link.test');

      // compareDocumentPosition: 4 = el segundo va después del primero.
      expect(iconoRed.compareDocumentPosition(contenido) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
  });

  describe('personalización', () => {
    it('aplica los colores de la página', () => {
      const { container } = renderConProviders(
        <Plantilla page={pagina({ background_color: '#112233', text_color: '#ffeedd' })} />
      );

      const raiz = container.firstChild;
      expect(raiz.getAttribute('style')).toContain('rgb(17, 34, 51)');
    });

    it('aplica la imagen de fondo si la hay', () => {
      const { container } = renderConProviders(
        <Plantilla page={pagina({ background_image: 'https://img/fondo.jpg' })} />
      );

      expect(container.firstChild.getAttribute('style')).toContain('https://img/fondo.jpg');
    });

    it('funciona sin colores definidos', () => {
      expect(() =>
        renderConProviders(
          <Plantilla page={pagina({ background_color: null, text_color: null, primary_color: null })} />
        )
      ).not.toThrow();
    });

    /**
     * Una tarjeta pintada de blanco no puede heredar el color de texto de la
     * página: quien elige un fondo oscuro elige texto claro, y ahí adentro
     * queda blanco sobre blanco. La superficie que se pinta fija su color.
     *
     * Tailwind no corre en los tests, así que el fondo se reconoce por la
     * clase. Las traslúcidas (bg-opacity) quedan afuera porque dejan ver el
     * fondo de la página y el texto heredado se lee bien.
     */
    it('las superficies blancas no heredan el color de texto de la página', () => {
      const { container } = renderConProviders(
        <Plantilla
          page={pagina({
            background_color: '#030c1c',
            text_color: '#f7f7f7',
            socials: [{ red: 'instagram', url: 'https://instagram.com/yo' }],
            groups: [
              grupoDeLinks([link({ id: 1, text: 'Instagram', url: 'https://instagram.com/yo' })]),
              { id: 20, title: 'Agenda', type: 'eventos', links: [evento()], collaborated_events: [] },
              { id: 30, title: 'Fotos', type: 'galeria', links: [{ id: 3, text: 'Foto', image_url: 'https://img/1.jpg' }] },
            ],
          })}
        />
      );

      // El detalle del evento es una tarjeta blanca en las cuatro plantillas,
      // así que abrirlo garantiza que la comprobación no quede vacía.
      fireEvent.click(screen.getByText('Mi Evento'));

      const blancas = [...container.querySelectorAll('[class*="bg-white"]')].filter((el) => {
        const clases = el.getAttribute('class') || '';
        return /(^|\s)bg-white(\s|$)/.test(clases) && !clases.includes('bg-opacity');
      });

      expect(blancas.length).toBeGreaterThan(0);

      blancas.forEach((el) => {
        const clases = el.getAttribute('class') || '';
        const propio =
          /(^|;)\s*color:/.test(el.getAttribute('style') || '') ||
          /(^|\s)text-(black|gray-[6-9]00|slate-[6-9]00)/.test(clases);

        expect(propio, `superficie blanca sin color propio: ${clases}`).toBe(true);
      });
    });
  });

  describe('el detalle del evento', () => {
    const conEvento = () =>
      pagina({
        background_color: '#000000',
        text_color: '#ffffff',
        primary_color: '#3b82f6',
        groups: [{ id: 20, title: 'Agenda', type: 'eventos', links: [evento()], collaborated_events: [] }],
      });

    const abrir = () => fireEvent.click(screen.getByText('Mi Evento'));

    /**
     * Estaba fijo en blanco con texto negro. Sobre una página oscura era un
     * recuadro ajeno, y el botón de compra —que se pinta con un color de la
     * paleta— podía quedar del mismo color que ese blanco y desaparecer.
     */
    it('sale de la paleta de la página y no de un blanco fijo', () => {
      const { container } = renderConProviders(<Plantilla page={conEvento()} />);
      abrir();

      // El modal es la capa fija que se superpone; adentro, el primer
      // elemento con estilo propio es el panel.
      const panel = container.querySelector('.fixed [style]');

      expect(panel.style.backgroundColor).not.toBe('rgb(255, 255, 255)');
      expect(panel.style.color).toBe('rgb(255, 255, 255)');
    });

    /**
     * El botón se pinta con el color de acento, no con el del texto: cuando
     * una plantilla le pasaba el color del texto, en una página de tipografía
     * blanca el botón quedaba blanco sobre blanco.
     */
    it('el botón de compra usa el color de acento', () => {
      const conVenta = {
        activo: true, es_gratis: false, precio: 1500, moneda: 'ARS',
        disponibles: 50, max_por_compra: 6, agotado: false,
      };

      renderConProviders(
        <Plantilla
          page={pagina({
            background_color: '#000000',
            text_color: '#ffffff',
            primary_color: '#3b82f6',
            groups: [{
              id: 20, title: 'Agenda', type: 'eventos', collaborated_events: [],
              links: [evento({ entradas: conVenta })],
            }],
          })}
        />
      );
      abrir();

      const boton = screen.getByRole('button', { name: /COMPRAR ENTRADAS/ });

      expect(boton.style.backgroundColor).toBe('rgb(59, 130, 246)');
      expect(boton.style.color).not.toBe(boton.style.backgroundColor);
    });
  });

  describe('pie', () => {
    it('lleva la marca Rezonar al inicio', () => {
      renderConProviders(<Plantilla page={pagina()} />);

      const alInicio = screen.getAllByRole('link').filter((a) => a.getAttribute('href') === '/');
      expect(alInicio.length).toBeGreaterThan(0);
    });
  });
});
