-- ---------------------------------------------------------------------------
-- Formas de entrega de los productos físicos (#53): además del envío a
-- domicilio (#43), el retiro en un lugar y coordinar después de la compra.
--
-- En digital_products, las formas que habilita quien vende (una, dos o las
-- tres) y el punto de retiro, uno solo por producto, con la dirección de
-- Google Maps como la ubicación de un evento (event_address, event_latitude,
-- event_longitude y event_maps_url en links) y un texto con los días,
-- horarios e indicaciones. Los físicos que ya existen quedan con sólo el
-- envío: entrega_envio nace en 1 y las otras en 0.
--
-- En product_orders, la forma que eligió quien compró (NULL en los digitales)
-- y la foto del retiro como estaba al comprar: si después el producto cambia
-- de lugar, el pedido sigue diciendo adónde había que ir.
--
-- Al final, los pedidos de físicos que ya existen quedan como envío, que era
-- la única forma.
--
-- Se puede correr más de una vez: columnas con IF NOT EXISTS y el relleno
-- sólo toca lo que falta.
-- ---------------------------------------------------------------------------

-- 1. Las formas y el punto de retiro del producto ------------------------------
ALTER TABLE digital_products
    ADD COLUMN IF NOT EXISTS entrega_envio TINYINT(1) NOT NULL DEFAULT 1 AFTER zona_envio,
    ADD COLUMN IF NOT EXISTS entrega_retiro TINYINT(1) NOT NULL DEFAULT 0 AFTER entrega_envio,
    ADD COLUMN IF NOT EXISTS entrega_coordinar TINYINT(1) NOT NULL DEFAULT 0 AFTER entrega_retiro,
    ADD COLUMN IF NOT EXISTS retiro_direccion VARCHAR(255) NULL DEFAULT NULL AFTER entrega_coordinar,
    ADD COLUMN IF NOT EXISTS retiro_latitud DECIMAL(10,8) NULL DEFAULT NULL AFTER retiro_direccion,
    ADD COLUMN IF NOT EXISTS retiro_longitud DECIMAL(11,8) NULL DEFAULT NULL AFTER retiro_latitud,
    ADD COLUMN IF NOT EXISTS retiro_maps_url TEXT NULL DEFAULT NULL AFTER retiro_longitud,
    -- "Lunes a viernes de 10 a 18, timbre 3".
    ADD COLUMN IF NOT EXISTS retiro_indicaciones VARCHAR(255) NULL DEFAULT NULL AFTER retiro_maps_url;

-- 2. La forma elegida y la foto del retiro en la orden ---------------------------
ALTER TABLE product_orders
    ADD COLUMN IF NOT EXISTS forma_entrega ENUM('envio', 'retiro', 'coordinar') NULL DEFAULT NULL AFTER costo_envio,
    ADD COLUMN IF NOT EXISTS retiro_direccion VARCHAR(255) NULL DEFAULT NULL AFTER forma_entrega,
    ADD COLUMN IF NOT EXISTS retiro_maps_url TEXT NULL DEFAULT NULL AFTER retiro_direccion,
    ADD COLUMN IF NOT EXISTS retiro_indicaciones VARCHAR(255) NULL DEFAULT NULL AFTER retiro_maps_url;

-- 3. Los pedidos de físicos que ya existen fueron todos con envío -----------------
UPDATE product_orders o
    INNER JOIN digital_products dp ON dp.id = o.product_id
SET o.forma_entrega = 'envio'
WHERE dp.tipo = 'fisico' AND o.forma_entrega IS NULL;
