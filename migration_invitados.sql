-- ---------------------------------------------------------------------------
-- Invitados sin costo en cualquier evento con entradas.
--
-- Un invitado es una orden más de ticket_orders: mismo código, mismo QR,
-- misma puerta y el mismo "no se puede usar dos veces". Nace 'pagada', con
-- total 0, sin Mercado Pago, sin comisión y sin cargos. Lo que la distingue
-- de una venta es el origen: con eso las ventas, los reportes y el Excel la
-- muestran aparte y no la suman a lo vendido ni a lo recaudado.
--
-- 1. ticket_orders.origen: 'venta' para todo lo que ya existe (el valor por
--    defecto rellena las filas viejas) e 'invitacion' para los invitados.
-- 2. ticket_orders.invitado_por: quién lo agregó. Sin clave foránea a
--    propósito: si la cuenta se borra el invitado sigue valiendo, y el dato
--    es sólo para saber quién fue.
-- 3. event_ticketing.max_invitados: tope opcional de lugares de invitados
--    por evento. NULL = sin tope (igual descuentan de la capacidad).
--
-- Idempotente: se puede correr más de una vez.
-- ---------------------------------------------------------------------------

ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS origen ENUM('venta', 'invitacion') NOT NULL DEFAULT 'venta' AFTER record_id;

ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS invitado_por INT NULL DEFAULT NULL AFTER origen;

ALTER TABLE ticket_orders
    ADD KEY IF NOT EXISTS idx_evento_origen (link_id, origen);

ALTER TABLE event_ticketing
    ADD COLUMN IF NOT EXISTS max_invitados INT NULL DEFAULT NULL AFTER max_por_compra;
