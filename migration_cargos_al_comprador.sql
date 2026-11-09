-- ---------------------------------------------------------------------------
-- Comisiones a cargo de quien compra.
--
-- Con la función encendida (CARGOS_AL_COMPRADOR en config.php y la llave de
-- cada evento), al precio de la entrada se le suman la comisión de Mercado
-- Pago y la de Rezonar, y el organizador recibe el precio que cargó. La cuenta
-- está en api/lib/CargosAlComprador.php.
--
-- Idempotente: se puede correr más de una vez. No cambia ningún precio: todo
-- arranca en 0.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. La llave de cada evento. 0 = las comisiones las absorbe el organizador,
--    como hasta ahora. Así encender la función global no le cambia el precio a
--    lo que ya está a la venta.
-- ---------------------------------------------------------------------------
ALTER TABLE event_ticketing
    ADD COLUMN IF NOT EXISTS cargos_al_comprador TINYINT(1) NOT NULL DEFAULT 0 AFTER max_por_compra;

-- ---------------------------------------------------------------------------
-- 2. Lo que se le sumó a cada compra, congelado como el resto del precio.
--
--    `total` sigue siendo lo que se cobró por Mercado Pago (y lo que se
--    compara con el pago al acreditar): ahora incluye estos dos montos. Lo que
--    valían las entradas es total - cargo_mercadopago - cargo_plataforma.
--    Las compras anteriores quedan en 0: su total era el precio.
--
--    cargo_plataforma es lo mismo que se manda como marketplace_fee y se
--    guarda en `comision`; va aparte porque `comision` también existe cuando
--    la paga el organizador, y esto dice quién la pagó.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS cargo_mercadopago DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total;

ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS cargo_plataforma DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cargo_mercadopago;
