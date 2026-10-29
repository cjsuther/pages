-- ---------------------------------------------------------------------------
-- Productos físicos (#42): una remera, un disco, un libro.
--
-- Un producto del grupo "productos" pasa a ser digital (como hasta ahora, con
-- su archivo) o físico (sin archivo, con envío, características y stock). El
-- tipo se elige al crearlo y no cambia: las órdenes de cada uno son distintas.
-- La tabla sigue llamándose digital_products; renombrarla rompe demasiado.
--
-- Características: de 0 a 3 por producto (Talle, Color...), cada una con sus
-- opciones (S, M, L...). Una opción que ya está en un pedido no se borra: se
-- desactiva (activa = 0) y deja de ofrecerse. Lo mismo una característica
-- que tiene alguna opción así.
--
-- Stock: sin características, digital_products.stock; NULL es sin límite.
-- Con características, una fila de product_variants por combinación que tenga
-- stock. La clave es la lista de ids de opciones, ordenados de menor a mayor y
-- separados por guiones ("12-31"). Una combinación sin fila no tiene límite.
--
-- product_order_options dice qué opciones eligió cada pedido. La llena la
-- compra de físicos (#43); acá se crea para que la base no deje borrar una
-- opción usada (su clave foránea no tiene ON DELETE).
--
-- Se puede correr más de una vez: columnas con IF NOT EXISTS y tablas que se
-- crean sólo si no existen.
-- ---------------------------------------------------------------------------

-- 1. El tipo y lo que es sólo de los físicos ------------------------------------
ALTER TABLE digital_products
    ADD COLUMN IF NOT EXISTS tipo ENUM('digital', 'fisico') NOT NULL DEFAULT 'digital' AFTER page_id,
    -- En pesos. 0 es "envío incluido".
    ADD COLUMN IF NOT EXISTS costo_envio DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER precio,
    ADD COLUMN IF NOT EXISTS zona_envio VARCHAR(255) NULL DEFAULT NULL AFTER costo_envio,
    -- El stock de un físico sin características. NULL es sin límite.
    ADD COLUMN IF NOT EXISTS stock INT NULL DEFAULT NULL AFTER zona_envio;

-- 2. Características y opciones ---------------------------------------------------
CREATE TABLE IF NOT EXISTS product_attributes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    nombre      VARCHAR(50) NOT NULL,
    posicion    INT NOT NULL DEFAULT 0,
    activa      TINYINT(1) NOT NULL DEFAULT 1,

    KEY idx_atributo_producto (product_id, posicion),
    CONSTRAINT fk_atributo_producto FOREIGN KEY (product_id) REFERENCES digital_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_attribute_options (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    attribute_id  INT NOT NULL,
    nombre        VARCHAR(50) NOT NULL,
    posicion      INT NOT NULL DEFAULT 0,
    activa        TINYINT(1) NOT NULL DEFAULT 1,

    KEY idx_opcion_atributo (attribute_id, posicion),
    CONSTRAINT fk_opcion_atributo FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Stock por combinación --------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_variants (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    -- Ids de opciones, de menor a mayor, separados por guiones.
    clave       VARCHAR(191) NOT NULL,
    -- NULL es sin límite; 0 es agotado.
    stock       INT NULL DEFAULT NULL,

    UNIQUE KEY uniq_variante_producto_clave (product_id, clave),
    CONSTRAINT fk_variante_producto FOREIGN KEY (product_id) REFERENCES digital_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Las opciones de cada pedido (las llena #43) ---------------------------------
CREATE TABLE IF NOT EXISTS product_order_options (
    order_id   INT NOT NULL,
    option_id  INT NOT NULL,

    PRIMARY KEY (order_id, option_id),
    KEY idx_pedido_opcion (option_id),
    CONSTRAINT fk_pedido_opcion_orden FOREIGN KEY (order_id) REFERENCES product_orders(id),
    -- Sin ON DELETE: la base no deja borrar una opción que está en un pedido.
    CONSTRAINT fk_pedido_opcion_opcion FOREIGN KEY (option_id) REFERENCES product_attribute_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
