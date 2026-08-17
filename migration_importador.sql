-- ---------------------------------------------------------------------------
-- Importación diaria de carteleras.
--
-- Cada fuente es un lugar o una búsqueda en una ticketera. El cron las recorre
-- una vez por día, crea la página si no existe y sincroniza sus eventos.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS import_sources (
    id           INT AUTO_INCREMENT PRIMARY KEY,

    -- Dueño de las páginas que se creen. Las importadas son de una cuenta
    -- concreta, no de la plataforma.
    user_id      INT NOT NULL,

    -- Página destino. Se completa en la primera corrida, cuando se crea.
    page_id      INT NULL DEFAULT NULL,

    -- Qué clase de fuente es, para poder mostrarlas agrupadas.
    tipo         ENUM('lugar', 'ticketera') NOT NULL DEFAULT 'lugar',

    -- Nombre del adaptador que sabe leerla: eventbrite, niceto, colon...
    adaptador    VARCHAR(50) NOT NULL,

    -- Cómo se llama la página que se va a crear.
    nombre       VARCHAR(150) NOT NULL,
    slug         VARCHAR(100) NOT NULL,

    -- Configuración propia del adaptador, en JSON: la URL a leer, el término
    -- de búsqueda, el límite de páginas.
    parametros   TEXT NULL DEFAULT NULL,

    activo       TINYINT(1) NOT NULL DEFAULT 1,

    -- Para saber si el cron la está levantando y cómo le fue, sin tener que
    -- leer logs.
    ultima_corrida    TIMESTAMP NULL DEFAULT NULL,
    ultimo_resultado  VARCHAR(255) NULL DEFAULT NULL,
    eventos_importados INT NOT NULL DEFAULT 0,

    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_slug (slug),
    KEY idx_activas (activo, ultima_corrida),
    CONSTRAINT fk_fuente_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- De dónde vino cada evento, y qué le tocó el usuario a mano.
--
-- origen + origen_id es lo que hace que reimportar actualice en lugar de
-- duplicar. campos_editados es lo que evita que el cron pise el trabajo
-- manual: guarda los nombres de los campos que el dueño cambió, y esos quedan
-- congelados en las corridas siguientes.
-- ---------------------------------------------------------------------------

ALTER TABLE links
    ADD COLUMN origen VARCHAR(50) NULL DEFAULT NULL AFTER precio_desde;

ALTER TABLE links
    ADD COLUMN origen_id VARCHAR(100) NULL DEFAULT NULL AFTER origen;

ALTER TABLE links
    ADD COLUMN campos_editados TEXT NULL DEFAULT NULL AFTER origen_id;

-- El mismo evento de la misma fuente no puede entrar dos veces en un grupo.
ALTER TABLE links
    ADD UNIQUE KEY uniq_origen (group_id, origen, origen_id);

-- Marca las páginas creadas por el importador, para distinguirlas de las que
-- creó una persona.
ALTER TABLE pages
    ADD COLUMN origen VARCHAR(50) NULL DEFAULT NULL;
