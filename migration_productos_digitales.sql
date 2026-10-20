-- ---------------------------------------------------------------------------
-- Productos digitales (#17): un PDF, plantillas, un pack de fotos, un audio.
--
-- Un grupo nuevo, "productos", donde cada producto es una fila de links —el
-- título en text, la descripción y la imagen como siempre— y lo que es sólo
-- del producto vive en digital_products: el precio y el archivo.
--
-- El archivo no está en la base: está en api/uploads/privado/productos/, con
-- un nombre aleatorio. Acá queda la ruta relativa a uploads/privado/, el
-- nombre original —con el que se descarga— y lo que se detectó al subirlo.
--
-- Se puede correr más de una vez: el MODIFY deja el ENUM igual y la tabla se
-- crea sólo si no existe.
-- ---------------------------------------------------------------------------

ALTER TABLE link_groups
    MODIFY COLUMN type ENUM('links', 'galeria', 'eventos', 'productos') NOT NULL DEFAULT 'links';

CREATE TABLE IF NOT EXISTS digital_products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    link_id         INT NOT NULL,

    -- En pesos, como las entradas. 0 es gratis.
    precio          DECIMAL(12,2) NOT NULL DEFAULT 0,

    -- Vacíos hasta que se sube el archivo: el producto se crea primero.
    archivo_ruta    VARCHAR(255) NULL DEFAULT NULL,
    archivo_nombre  VARCHAR(255) NULL DEFAULT NULL,
    archivo_bytes   BIGINT UNSIGNED NULL DEFAULT NULL,
    -- El MIME que detectó el servidor. Es un dato: nunca decide cómo se sirve.
    archivo_tipo    VARCHAR(255) NULL DEFAULT NULL,

    creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_producto_link (link_id),
    CONSTRAINT fk_producto_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
