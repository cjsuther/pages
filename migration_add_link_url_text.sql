-- Texto del botón para la URL de los links/eventos.
-- Si se completa, se usa como etiqueta del botón que abre la URL en los templates
-- (si queda vacío, se usa el texto por defecto "Más información").

ALTER TABLE links ADD COLUMN url_text VARCHAR(255) NULL AFTER url;
