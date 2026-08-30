-- ---------------------------------------------------------------------------
-- Videos de YouTube y contenido de Instagram dentro de un grupo de galería.
--
-- Un item de galería sigue siendo una imagen: embed_url vacío se comporta
-- exactamente como antes. Cuando tiene una URL de YouTube o de Instagram, la
-- galería muestra el video o el post en su lugar.
--
-- No hace falta guardar de qué servicio es: se deduce de la URL (ver
-- frontend/src/utils/embeds.js), y así no hay dos campos que puedan
-- contradecirse.
--
-- image_url sigue siendo la portada: YouTube la trae sola, Instagram no
-- publica miniaturas sin API, así que ahí la sube el usuario.
-- ---------------------------------------------------------------------------

ALTER TABLE links
    ADD COLUMN embed_url VARCHAR(500) NULL DEFAULT NULL AFTER url_text;
