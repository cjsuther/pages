-- ---------------------------------------------------------------------------
-- Cuenta SMTP propia de cada página (#36).
--
-- Cada página puede cargar su casilla para escribirles a sus clientes desde
-- ahí y no desde la de Rezonar. Las entradas y los productos siguen saliendo
-- por la cuenta de la plataforma: esto no los toca.
--
-- La contraseña nunca se guarda en claro: va cifrada con libsodium y la clave
-- (CLAVE_CIFRADO) vive en config.php, fuera de la base y del repositorio.
--
-- remitente_nombre y responder_a vacíos quieren decir "lo de la página": el
-- título y el email_contacto del momento en que se manda, no los de cuando se
-- guardó.
--
-- page_smtp_pruebas registra cada mail de prueba para limitarlos por hora: el
-- botón conecta a un servidor que elige quien usa la página.
--
-- Se puede correr más de una vez: las dos tablas se crean sólo si no existen.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS page_smtp (
    page_id             INT NOT NULL PRIMARY KEY,
    host                VARCHAR(255) NOT NULL,
    port                SMALLINT UNSIGNED NOT NULL,
    seguridad           ENUM('ssl', 'tls', 'ninguna') NOT NULL DEFAULT 'tls',
    usuario             VARCHAR(255) NOT NULL,
    contrasena_cifrada  TEXT NOT NULL,
    remitente_email     VARCHAR(255) NOT NULL,
    remitente_nombre    VARCHAR(255) NULL DEFAULT NULL,
    responder_a         VARCHAR(255) NULL DEFAULT NULL,

    -- Cuándo salió bien la última prueba. Se vacía al cambiar la cuenta.
    verificado_en       DATETIME NULL DEFAULT NULL,
    actualizado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_page_smtp_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_smtp_pruebas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    page_id     INT NOT NULL,
    user_id     INT NULL DEFAULT NULL,
    ok          TINYINT(1) NOT NULL DEFAULT 0,
    creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_pruebas_pagina_fecha (page_id, creado_en),
    CONSTRAINT fk_smtp_pruebas_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
