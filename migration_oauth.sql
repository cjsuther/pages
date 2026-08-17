-- ---------------------------------------------------------------------------
-- Autorización OAuth 2.1 para el server MCP.
--
-- Con clave de API alcanza para uno mismo, pero no para que se conecte
-- cualquier dueño de páginas: pedirle a cada persona que copie una credencial
-- en un archivo de configuración es pedirle demasiado, y una credencial que
-- viaja copiada es una credencial que se filtra.
--
-- Acá el cliente se registra solo, la persona autoriza desde el navegador con
-- la sesión que ya tiene, y nadie copia nada.
-- ---------------------------------------------------------------------------

-- 1. Los programas que piden acceso.
--
-- Se registran solos (RFC 7591): un cliente MCP nuevo no puede depender de que
-- alguien lo dé de alta a mano de este lado.
CREATE TABLE IF NOT EXISTS oauth_clients (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      CHAR(32) NOT NULL,

    -- Nulo para clientes públicos, que es lo normal en MCP: una aplicación de
    -- escritorio no puede guardar un secreto que su usuario no pueda leer.
    -- Por eso PKCE es obligatorio y no opcional.
    secreto_hash   CHAR(64) NULL DEFAULT NULL,

    nombre         VARCHAR(120) NOT NULL,
    redirect_uris  TEXT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Los códigos de autorización.
--
-- Viven poco y se usan una sola vez. Se guarda el hash y no el código: si
-- alguien lee la base no puede canjear nada.
CREATE TABLE IF NOT EXISTS oauth_codes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code_hash      CHAR(64) NOT NULL,
    client_id      CHAR(32) NOT NULL,
    user_id        INT NOT NULL,
    redirect_uri   VARCHAR(500) NOT NULL,

    -- PKCE: el cliente prueba al canjear que es el mismo que pidió el código.
    code_challenge VARCHAR(128) NOT NULL,

    -- A qué recurso se pidió el acceso (RFC 8707). Un token emitido para el
    -- server MCP de Rezonar no puede servir en otro lado.
    resource       VARCHAR(255) NULL DEFAULT NULL,

    expira_en      TIMESTAMP NOT NULL,
    usado_en       TIMESTAMP NULL DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_code (code_hash),
    KEY idx_limpieza (expira_en),
    CONSTRAINT fk_code_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Los tokens.
--
-- El de acceso dura poco; el de refresco lo renueva sin volver a molestar a la
-- persona. Los dos se guardan hasheados, por el mismo motivo que los códigos.
CREATE TABLE IF NOT EXISTS oauth_tokens (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    token_hash     CHAR(64) NOT NULL,
    refresh_hash   CHAR(64) NULL DEFAULT NULL,
    client_id      CHAR(32) NOT NULL,
    user_id        INT NOT NULL,
    resource       VARCHAR(255) NULL DEFAULT NULL,

    expira_en      TIMESTAMP NOT NULL,

    -- Se revoca y no se borra: si alguien desconecta una aplicación, conviene
    -- que quede el registro de que estuvo conectada y hasta cuándo.
    revocado_en    TIMESTAMP NULL DEFAULT NULL,
    ultimo_uso_en  TIMESTAMP NULL DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_token (token_hash),
    UNIQUE KEY uniq_refresh (refresh_hash),
    KEY idx_usuario (user_id, revocado_en),
    CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
