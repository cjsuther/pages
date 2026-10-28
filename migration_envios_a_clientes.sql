-- ---------------------------------------------------------------------------
-- Envío de emails a los clientes de una página (#37).
--
-- Sale por la cuenta SMTP propia de la página (page_smtp, #36) y por ráfagas:
-- un cron (api/correos/procesar.php) manda unos pocos mails por vez y espera
-- entre una ráfaga y la siguiente, para que la casilla no quede marcada como
-- spam.
--
-- mensajes_guardados: las plantillas de cada página. {nombre} y
--   {primer_nombre} se reemplazan por el del cliente; reemplazo_nombre es lo
--   que va cuando el cliente no tiene nombre (vacío por defecto).
--
-- envios: cada envío congela el mensaje (asunto, cuerpo y reemplazo) en el
--   momento de crearlo: editar la plantilla después no cambia lo que sale.
--   motivo_pausa explica por qué lo pausó el sistema (por ejemplo, la casilla
--   rechazó la contraseña).
--
-- envio_destinatarios: la lista congelada al crear el envío, un email por
--   envío como mucho. intentos se suma justo antes de mandar: un destinatario
--   pendiente con intentos > 0 es uno al que el proceso se cortó mandándole,
--   y no se reintenta para no mandarle dos veces.
--
-- bajas_de_correo: quien pidió no recibir más mails de una página. Vale para
--   todos los envíos futuros de esa página.
--
-- En page_smtp, la configuración de las ráfagas y cuándo empezó la última.
--
-- Se puede correr más de una vez: las tablas y las columnas se crean sólo si
-- no existen.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mensajes_guardados (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    page_id             INT NOT NULL,
    nombre              VARCHAR(120) NOT NULL,
    asunto              VARCHAR(255) NOT NULL,
    cuerpo              TEXT NOT NULL,
    reemplazo_nombre    VARCHAR(100) NOT NULL DEFAULT '',
    creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_mensajes_pagina (page_id),
    CONSTRAINT fk_mensajes_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS envios (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    page_id             INT NOT NULL,
    mensaje_id          INT NULL DEFAULT NULL,
    mensaje_nombre      VARCHAR(120) NULL DEFAULT NULL,
    asunto              VARCHAR(255) NOT NULL,
    cuerpo              TEXT NOT NULL,
    reemplazo_nombre    VARCHAR(100) NOT NULL DEFAULT '',
    creado_por          INT NULL DEFAULT NULL,
    estado              ENUM('en_curso', 'pausado', 'terminado', 'cancelado') NOT NULL DEFAULT 'en_curso',
    motivo_pausa        VARCHAR(500) NULL DEFAULT NULL,
    total               INT UNSIGNED NOT NULL DEFAULT 0,
    creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    terminado_en        DATETIME NULL DEFAULT NULL,

    KEY idx_envios_pagina (page_id, creado_en),
    KEY idx_envios_estado (estado),
    CONSTRAINT fk_envios_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_envios_mensaje FOREIGN KEY (mensaje_id) REFERENCES mensajes_guardados(id) ON DELETE SET NULL,
    CONSTRAINT fk_envios_usuario FOREIGN KEY (creado_por) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS envio_destinatarios (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    envio_id            INT NOT NULL,
    email               VARCHAR(255) NOT NULL,
    nombre              VARCHAR(255) NULL DEFAULT NULL,
    telefono            VARCHAR(50) NULL DEFAULT NULL,
    estado              ENUM('pendiente', 'enviado', 'fallido', 'omitido') NOT NULL DEFAULT 'pendiente',
    error               VARCHAR(500) NULL DEFAULT NULL,
    intentos            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    enviado_en          DATETIME NULL DEFAULT NULL,

    UNIQUE KEY uq_destinatario_envio (envio_id, email),
    KEY idx_destinatarios_estado (envio_id, estado),
    KEY idx_destinatarios_enviado (estado, enviado_en),
    CONSTRAINT fk_destinatarios_envio FOREIGN KEY (envio_id) REFERENCES envios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bajas_de_correo (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    page_id             INT NOT NULL,
    email               VARCHAR(255) NOT NULL,
    creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_baja_pagina_email (page_id, email),
    CONSTRAINT fk_bajas_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE page_smtp
    ADD COLUMN IF NOT EXISTS rafaga_tamano          SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    ADD COLUMN IF NOT EXISTS rafaga_pausa_minutos   SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    ADD COLUMN IF NOT EXISTS tope_diario            INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ultima_rafaga_en       DATETIME NULL DEFAULT NULL;
