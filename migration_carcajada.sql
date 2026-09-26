-- ---------------------------------------------------------------------------
-- Carcajada: los comediantes, los shows y la ficha de cada uno.
--
-- Vive en el mismo lugar que Rezonar y usa sus cuentas: para anotarse hay que
-- tener página en rezon.ar, que es lo que después ve el público cuando escanea
-- el QR durante el show.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. Quién produce. Es distinto de administrar la plataforma: producir
--    Carcajada no tiene por qué dar acceso a las páginas de todo el mundo.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carcajada_productores (
    user_id    INT NOT NULL PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_carcajada_productor FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Los comediantes anotados.
--
-- Uno por cuenta de Rezonar: el alta se hace con la sesión, así que nadie
-- puede anotarse dos veces ni anotar la página de otro.
--
-- El nombre, la foto y el Instagram se copian de la página al anotarse, pero
-- quedan acá y no se leen de la página cada vez: el nombre artístico puede no
-- ser el título de la página, y la foto del flyer suele ser otra.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carcajada_comediantes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,

    -- La página que se muestra en el QR. Puede quedar en NULL si la borran:
    -- el comediante sigue anotado y su ficha no se pierde.
    page_id     INT NULL DEFAULT NULL,

    nombre      VARCHAR(80) NOT NULL,
    foto_url    VARCHAR(500) NULL DEFAULT NULL,
    instagram   VARCHAR(60) NULL DEFAULT NULL,

    -- Con cuánta gente se compromete a venir cuando se presenta. Es lo que
    -- después se compara contra lo que trajo de verdad.
    personas_comprometidas INT NOT NULL DEFAULT 0,

    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_usuario (user_id),
    CONSTRAINT fk_comediante_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_comediante_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Los ciclos. Hoy son dos, y son una tabla y no un ENUM porque sumar el
--    tercero no puede ser una migración.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carcajada_ciclos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(80) NOT NULL,
    slug       VARCHAR(80) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO carcajada_ciclos (nombre, slug) VALUES
    ('Corta la Semana', 'corta-la-semana'),
    ('JaJaJaJueves', 'jajajajueves');

-- ---------------------------------------------------------------------------
-- 4. Cada fecha.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carcajada_shows (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ciclo_id   INT NOT NULL,

    fecha      DATE NOT NULL,
    hora       TIME NULL DEFAULT NULL,
    lugar      VARCHAR(255) NULL DEFAULT NULL,
    notas      TEXT NULL DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- El QR del público busca el show del día: conviene que no recorra todo.
    KEY idx_fecha (fecha),
    CONSTRAINT fk_show_ciclo FOREIGN KEY (ciclo_id) REFERENCES carcajada_ciclos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Quién se presenta en cada fecha, y cómo le fue.
--
-- La evaluación vive acá y no en el comediante porque es de esa noche: la
-- ficha de alguien es el conjunto de sus noches, no un número que se pisa.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carcajada_lineup (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    show_id        INT NOT NULL,
    comediante_id  INT NOT NULL,

    -- El orden en que suben. Es el orden del flyer y el de la pantalla del QR.
    orden          INT NOT NULL DEFAULT 0,

    -- Se completan después del show. NULL es "todavía no se evaluó", que no
    -- es lo mismo que un cero.
    puntaje          TINYINT NULL DEFAULT NULL,
    personas_traidas INT NULL DEFAULT NULL,
    comentario       VARCHAR(500) NULL DEFAULT NULL,
    evaluado_en      TIMESTAMP NULL DEFAULT NULL,

    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_show_comediante (show_id, comediante_id),
    KEY idx_comediante (comediante_id),
    CONSTRAINT fk_lineup_show FOREIGN KEY (show_id) REFERENCES carcajada_shows(id) ON DELETE CASCADE,
    CONSTRAINT fk_lineup_comediante FOREIGN KEY (comediante_id) REFERENCES carcajada_comediantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
