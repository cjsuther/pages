-- ---------------------------------------------------------------------------
-- Los colores que se manejan desde el administrador.
--
-- Hasta acá había tres controles —fondo, texto y "elementos"— y una columna,
-- secondary_color, que no leía ninguna plantilla: existía en la base y viajaba
-- en cada respuesta sin pintar nada.
--
-- Ahora cada color tiene un rol:
--
--   background_color  el fondo de la página
--   text_color        la tipografía
--   primary_color     el acento: títulos de grupo y detalles estructurales
--   secondary_color   los botones (entradas, seguir)
--   card_color        la superficie de tarjetas y píldoras
--   title_color       los títulos, si se quieren distintos del texto
--
-- Los tres últimos son opcionales: vacíos se comportan como hasta ahora
-- —botones con el acento, tarjeta calculada a partir del fondo, títulos con el
-- color del texto—, así que ninguna página publicada cambia de aspecto.
--
-- secondary_color se vacía a propósito: tiene valores que nunca se dibujaron,
-- así que nadie los eligió mirando el resultado. Si se dejaran, al empezar a
-- usarse los botones cambiarían de color solos en todas las páginas.
-- ---------------------------------------------------------------------------

ALTER TABLE pages
    MODIFY COLUMN secondary_color VARCHAR(7) NULL DEFAULT NULL;

UPDATE pages SET secondary_color = NULL;

ALTER TABLE pages
    ADD COLUMN card_color VARCHAR(7) NULL DEFAULT NULL AFTER secondary_color;

ALTER TABLE pages
    ADD COLUMN title_color VARCHAR(7) NULL DEFAULT NULL AFTER card_color;
