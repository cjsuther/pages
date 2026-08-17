-- ---------------------------------------------------------------------------
-- Precio de referencia de un evento.
--
-- Es informativo y distinto del precio de la venta interna: sirve para los
-- eventos que se cobran en otro lado —o que importa el cron desde la cartelera
-- del lugar— donde lo único que se sabe es "desde cuánto".
--
-- NULL  = no se sabe, no se muestra nada
-- 0.00  = el evento es gratis, y se dice así
-- > 0   = "desde $X"
-- ---------------------------------------------------------------------------

ALTER TABLE links
    ADD COLUMN precio_desde DECIMAL(10,2) NULL DEFAULT NULL AFTER event_maps_url;
