-- ---------------------------------------------------------------------------
-- Subidas de imagen con link de un solo uso.
--
-- Un asistente no puede mandar un archivo: los argumentos de una herramienta
-- son texto que el modelo escribe, y una imagen de verdad no entra ahí. Esto
-- resuelve el caso real —el afiche está en la computadora de la persona—
-- devolviendo una dirección corta donde soltarlo.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS image_uploads (
    id          INT AUTO_INCREMENT PRIMARY KEY,

    -- Sólo el hash: quien lea la base no puede usar el link.
    token_hash  CHAR(64) NOT NULL,

    -- A nombre de quién se sube, para poder revalidar el permiso al soltar el
    -- archivo y no sólo al pedir el link.
    user_id     INT NOT NULL,

    -- El evento que va a quedar con esa imagen.
    link_id     INT NOT NULL,

    -- Vive poco: es para usarlo en el momento, no para guardarlo.
    expira_en   TIMESTAMP NOT NULL,
    usado_en    TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_token (token_hash),
    KEY idx_limpieza (expira_en),
    CONSTRAINT fk_subida_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_subida_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
