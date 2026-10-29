-- ---------------------------------------------------------------------------
-- Pedidos de productos físicos (#43): la compra con dirección y horarios.
--
-- Un pedido de un físico es una orden de product_orders como la de un
-- digital (mismo cobro, misma conciliación), con lo que hace falta para
-- entregarlo. La orden guarda una foto del pedido: si después el producto
-- cambia de nombre, de precio o de opciones, o se borra, el pedido sigue
-- diciendo lo que se compró.
--
--   - producto_titulo, opciones_texto ("Talle M · Color Negro"),
--     precio_unitario, cantidad y costo_envio: la foto. total sigue siendo lo
--     cobrado (precio_unitario × cantidad + costo_envio).
--   - variante_id: la fila de stock de la combinación al crear el pedido. Es
--     informativo y sin clave foránea: el editor reemplaza las filas de
--     product_variants al guardar. El stock se descuenta por la combinación
--     (product_order_options), no por este id.
--   - sin_stock: el pago se acreditó pero ya no quedaba stock (dos compraron
--     la última unidad a la vez). La orden queda pagada; lo resuelve quien
--     vende.
--   - aviso_vendedor_en: cuándo salió el mail de "Pedido nuevo" a quien vende.
--
-- product_order_envios: la dirección y los horarios de entrega, una fila por
-- pedido. Aparte para no llenar product_orders de columnas que un digital no
-- usa nunca. Sin ON DELETE, como las órdenes: no se borran nunca.
--
-- Se puede correr más de una vez: columnas con IF NOT EXISTS y la tabla se
-- crea sólo si no existe.
-- ---------------------------------------------------------------------------

-- 1. La foto del pedido en la orden -----------------------------------------------
ALTER TABLE product_orders
    ADD COLUMN IF NOT EXISTS producto_titulo VARCHAR(255) NULL DEFAULT NULL AFTER product_id,
    ADD COLUMN IF NOT EXISTS variante_id INT NULL DEFAULT NULL AFTER producto_titulo,
    ADD COLUMN IF NOT EXISTS opciones_texto VARCHAR(255) NULL DEFAULT NULL AFTER variante_id,
    -- Un digital es siempre una unidad.
    ADD COLUMN IF NOT EXISTS cantidad INT NOT NULL DEFAULT 1 AFTER opciones_texto,
    -- NULL en los digitales: su precio es el total.
    ADD COLUMN IF NOT EXISTS precio_unitario DECIMAL(12,2) NULL DEFAULT NULL AFTER cantidad,
    ADD COLUMN IF NOT EXISTS costo_envio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER precio_unitario,
    ADD COLUMN IF NOT EXISTS sin_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
    ADD COLUMN IF NOT EXISTS aviso_vendedor_en TIMESTAMP NULL DEFAULT NULL AFTER mail_error;

-- 2. Dirección y horarios de entrega ----------------------------------------------
CREATE TABLE IF NOT EXISTS product_order_envios (
    order_id            INT NOT NULL PRIMARY KEY,

    -- Calle y número.
    calle               VARCHAR(150) NOT NULL,
    piso_depto          VARCHAR(50)  NULL DEFAULT NULL,
    localidad           VARCHAR(100) NOT NULL,
    provincia           VARCHAR(60)  NOT NULL,
    codigo_postal       VARCHAR(10)  NOT NULL,
    -- "Timbre B", "portón verde".
    indicaciones        VARCHAR(255) NULL DEFAULT NULL,

    -- Los días en que puede recibir, separados por comas, de lunes a domingo:
    -- "lunes,miercoles,sabado".
    dias                VARCHAR(80)  NOT NULL,
    -- Las franjas, separadas por comas: "manana,tarde".
    franjas             VARCHAR(30)  NOT NULL,
    -- "Después de las 18".
    comentario_horario  VARCHAR(255) NULL DEFAULT NULL,

    CONSTRAINT fk_envio_orden FOREIGN KEY (order_id) REFERENCES product_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
