-- ---------------------------------------------------------------------------
-- Pixel de Meta por página.
--
-- La medición de Rezonar es una sola para toda la plataforma y sirve para
-- mostrarle a cada dueño cómo le va. El pixel es otra cosa: es la medición
-- propia de quien tiene la página, en su cuenta de Meta, para poder anunciar
-- sus shows y saber qué avisos venden entradas.
--
-- Vacío (NULL) significa "esta página no mide con Meta" y no se carga nada.
-- ---------------------------------------------------------------------------

ALTER TABLE pages
    ADD COLUMN meta_pixel_id VARCHAR(20) NULL DEFAULT NULL AFTER dominio;
