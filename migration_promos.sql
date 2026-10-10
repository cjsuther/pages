-- ---------------------------------------------------------------------------
-- Promos: tipos de entrada por los que entra más de una persona (2x1).
--
-- Cuántas personas entraban con cada una del tipo, copiado al comprar como el
-- nombre y el precio: si después la promo cambia, la compra sigue diciendo lo
-- que se pagó. 1 es una entrada común, y es lo que eran todas hasta acá.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_order_items
    ADD COLUMN personas INT NOT NULL DEFAULT 1 AFTER precio_unitario;
