-- ---------------------------------------------------------------------------
-- Claves de API.
--
-- Sirven para que un programa —hoy el server MCP— actúe en nombre de una
-- persona sin usar su contraseña ni un token de sesión. El token de sesión
-- vence a las 24 horas: pegarlo en la configuración de un cliente significaría
-- volver a pegarlo todos los días.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS api_keys (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,

    -- Para que la persona sepa cuál es cuál cuando tenga varias.
    nombre        VARCHAR(80) NOT NULL,

    -- Sólo el hash. Si alguien se lleva la base, se lleva hashes y no claves:
    -- el mismo motivo por el que no se guardan contraseñas en claro.
    hash          CHAR(64) NOT NULL,

    -- Los primeros caracteres, que sí se guardan en claro, para poder mostrar
    -- "rzn_3f9a…" en el listado y que se reconozca cuál se está revocando.
    prefijo       VARCHAR(16) NOT NULL,

    -- Para poder darse cuenta de que una clave quedó sin uso y darla de baja.
    ultimo_uso_en TIMESTAMP NULL DEFAULT NULL,

    -- Se revoca, no se borra: si una clave se filtró, conviene que quede el
    -- registro de que existió y hasta cuándo anduvo.
    revocada_en   TIMESTAMP NULL DEFAULT NULL,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_hash (hash),
    KEY idx_usuario (user_id, revocada_en),
    CONSTRAINT fk_clave_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
