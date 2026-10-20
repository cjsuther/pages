-- ---------------------------------------------------------------------------
-- Órdenes de productos digitales (#18).
--
-- Aparte de ticket_orders a propósito: una entrada tiene cupo, lugares, tipos
-- y puerta; un producto no tiene nada de eso, y mezclarlos obligaría a cada
-- consulta de entradas a filtrar lo que no es suyo. Una orden es una unidad.
--
-- El link de descarga es la credencial. Se guarda sólo su hash —se busca por
-- token_hash, que es único—, y además una copia cifrada con Cripto para poder
-- reenviar el mail sin generar otro link. El código de la orden NO es una
-- credencial: son 12 hex, se ve en la URL de vuelta de Mercado Pago.
--
-- Las ventas sobreviven al producto, como las entradas al evento
-- (migration_clientes.sql):
--   - La orden cuelga de digital_products, no del link, y sin cascada: una
--     orden no se borra nunca porque se borre otra cosa.
--   - digital_products sobrevive al borrado del link cuando tiene órdenes: el
--     link_id pasa a null y queda una foto mínima para reconocerlo (titulo,
--     page_id, link_original, borrado_en). El archivo se conserva mientras
--     haya compras, para que quien pagó pueda seguir descargando. De eso se
--     ocupa la API antes de borrar el link, el grupo o la página.
--   - oculto: el producto no se muestra ni acepta pedidos nuevos, pero lo ya
--     vendido se sigue acreditando y descargando.
--
-- Se puede correr más de una vez: columnas con IF NOT EXISTS, claves foráneas
-- que se sueltan (IF EXISTS) y se vuelven a crear, y la tabla de órdenes se
-- crea sólo si no existe.
-- ---------------------------------------------------------------------------

-- 1. digital_products sobrevive a su link ---------------------------------------
ALTER TABLE digital_products
    ADD COLUMN IF NOT EXISTS titulo VARCHAR(255) NOT NULL DEFAULT '' AFTER link_id,
    ADD COLUMN IF NOT EXISTS page_id INT NULL DEFAULT NULL AFTER titulo,
    ADD COLUMN IF NOT EXISTS oculto TINYINT(1) NOT NULL DEFAULT 0 AFTER precio,
    -- El link que tenía al borrarse: link_id ya es null, y con esto un pedido
    -- que llega de una página vieja se contesta "ya no está disponible".
    ADD COLUMN IF NOT EXISTS link_original INT NULL DEFAULT NULL AFTER archivo_tipo,
    ADD COLUMN IF NOT EXISTS borrado_en TIMESTAMP NULL DEFAULT NULL AFTER link_original;

ALTER TABLE digital_products DROP FOREIGN KEY IF EXISTS fk_producto_link;
ALTER TABLE digital_products MODIFY COLUMN link_id INT NULL DEFAULT NULL;
ALTER TABLE digital_products
    ADD CONSTRAINT fk_producto_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL;

ALTER TABLE digital_products DROP FOREIGN KEY IF EXISTS fk_producto_pagina;
ALTER TABLE digital_products
    ADD CONSTRAINT fk_producto_pagina FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL;

ALTER TABLE digital_products ADD KEY IF NOT EXISTS idx_producto_link_original (link_original);

-- La foto de los productos que ya existen.
UPDATE digital_products dp
    INNER JOIN links l ON l.id = dp.link_id
    INNER JOIN link_groups lg ON lg.id = l.group_id
SET dp.titulo = l.text, dp.page_id = lg.page_id;

-- 2. Órdenes -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_orders (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    codigo               CHAR(12) NOT NULL,
    product_id           INT NOT NULL,

    nombre               VARCHAR(150) NOT NULL,
    email                VARCHAR(255) NOT NULL,
    telefono             VARCHAR(50)  NULL DEFAULT NULL,
    -- Desde dónde se pidió. Sólo para limitar los pedidos gratis.
    ip                   VARCHAR(45)  NULL DEFAULT NULL,

    -- En pesos, como el precio del producto. 0 es un producto gratis.
    total                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- La comisión que se pidió al crear el cobro, congelada: si mañana cambia
    -- el porcentaje, esta venta sigue mostrando lo que se descontó.
    comision             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    comision_porcentaje  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    -- Lo que Mercado Pago dice que cobró como comisión de plataforma.
    mp_comision_cobrada  DECIMAL(12,2) NULL DEFAULT NULL,

    estado               ENUM('pendiente', 'pagada', 'rechazada', 'cancelada', 'vencida') NOT NULL DEFAULT 'pendiente',
    mp_preference_id     VARCHAR(100) NULL DEFAULT NULL,
    mp_payment_id        VARCHAR(100) NULL DEFAULT NULL,
    pagada_en            TIMESTAMP NULL DEFAULT NULL,

    token_hash           CHAR(64) NOT NULL,
    token_cifrado        VARCHAR(255) NOT NULL,

    descargas            INT NOT NULL DEFAULT 0,
    primera_descarga_en  TIMESTAMP NULL DEFAULT NULL,
    ultima_descarga_en   TIMESTAMP NULL DEFAULT NULL,

    mail_enviado_en      TIMESTAMP NULL DEFAULT NULL,
    mail_intentos        INT NOT NULL DEFAULT 0,
    mail_error           VARCHAR(255) NULL DEFAULT NULL,

    creado_en            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_producto_orden_codigo (codigo),
    -- Idempotencia del aviso: el mismo pago no puede acreditar dos órdenes.
    UNIQUE KEY uniq_producto_orden_pago (mp_payment_id),
    UNIQUE KEY uniq_producto_orden_token (token_hash),
    -- El pedido repetido de un producto gratis se busca por producto y email.
    KEY idx_producto_orden_email (product_id, email),
    -- El límite de pedidos gratis cuenta por email y por IP en la última hora.
    KEY idx_producto_orden_email_fecha (email, creado_en),
    KEY idx_producto_orden_ip_fecha (ip, creado_en),
    -- La conciliación y los reintentos de mail recorren por estado.
    KEY idx_producto_orden_estado (estado, creado_en),
    -- Sin ON DELETE: la base no deja borrar un producto que tiene órdenes.
    CONSTRAINT fk_producto_orden_producto FOREIGN KEY (product_id) REFERENCES digital_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
