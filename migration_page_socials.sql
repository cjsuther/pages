-- Redes sociales de una página.
--
-- Hasta ahora las redes se cargaban como un grupo de links de tipo "redes",
-- que precargaba las seis con URLs de ejemplo: aparecían todas aunque el
-- usuario no completara ninguna. Pasan a ser una sección propia donde sólo
-- se guarda lo que el usuario efectivamente carga.

CREATE TABLE IF NOT EXISTS page_socials (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    page_id    INT NOT NULL,

    -- Clave del catálogo: instagram, tiktok, youtube, whatsapp, web...
    red        VARCHAR(30) NOT NULL,
    url        VARCHAR(500) NOT NULL,
    position   INT NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Una sola cuenta por red y por página: dos Instagram en la misma página
    -- no tienen sentido y romperían el orden de los iconos.
    UNIQUE KEY uniq_pagina_red (page_id, red),
    KEY idx_pagina (page_id, position),
    CONSTRAINT fk_social_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
