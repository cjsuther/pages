-- ---------------------------------------------------------------------------
-- Clientes de una página, que sobreviven al evento.
--
-- Hasta acá las compras colgaban del evento con ON DELETE CASCADE: borrar un
-- evento se llevaba a quienes compraron, a cuáles vinieron y hasta los lugares
-- del plano. Esos datos son de la página, no del evento, y son lo que una
-- productora más cuida.
--
-- Tres cambios:
--   1. Un registro por evento con entradas, que no se borra con el evento.
--      Guarda lo mínimo para reconocerlo después: título, fecha y lugar.
--   2. Qué páginas ven los clientes de cada evento: la organizadora y las que
--      colaboran. Se mantiene al día al aceptar o deshacer una colaboración, y
--      queda como estaba cuando el evento se borra.
--   3. Las compras y sus lugares dejan de borrarse con el evento: el link pasa
--      a null y la compra queda atada al registro.
--
-- Aplicar una sola vez, en orden, y después migration_clientes_relleno.sql,
-- que ata a su registro las compras que ya existen.
-- ---------------------------------------------------------------------------

-- 1. Registro de eventos --------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_records (
    id           INT AUTO_INCREMENT PRIMARY KEY,

    -- null cuando el evento se borró. Es el único rastro del borrado: el resto
    -- de la fila queda como estaba.
    link_id      INT NULL,

    -- La página que organiza. null sólo si la página entera se borró.
    page_id      INT NULL,

    titulo       VARCHAR(255) NOT NULL DEFAULT '',
    event_date   DATE NULL DEFAULT NULL,
    event_time   TIME NULL DEFAULT NULL,
    event_address VARCHAR(500) NULL DEFAULT NULL,

    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_link (link_id),
    KEY idx_pagina (page_id),

    CONSTRAINT fk_registro_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL,
    CONSTRAINT fk_registro_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Páginas que ven los clientes de cada evento --------------------------------
CREATE TABLE IF NOT EXISTS event_record_pages (
    record_id  INT NOT NULL,
    page_id    INT NOT NULL,
    rol        ENUM('organizador', 'colaborador') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (record_id, page_id),
    KEY idx_pagina (page_id),

    CONSTRAINT fk_registro_pagina_registro FOREIGN KEY (record_id) REFERENCES event_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_registro_pagina_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Las compras dejan de borrarse con el evento -------------------------------
ALTER TABLE ticket_orders DROP FOREIGN KEY fk_orden_link;
ALTER TABLE ticket_orders MODIFY link_id INT NULL;
ALTER TABLE ticket_orders
    ADD CONSTRAINT fk_orden_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL;

ALTER TABLE ticket_orders ADD COLUMN record_id INT NULL DEFAULT NULL AFTER link_id;
ALTER TABLE ticket_orders ADD KEY idx_registro (record_id);
ALTER TABLE ticket_orders
    ADD CONSTRAINT fk_orden_registro FOREIGN KEY (record_id) REFERENCES event_records(id);

-- El email se busca normalizado para agrupar a la misma persona.
ALTER TABLE ticket_orders ADD KEY idx_email (email);

-- 4. Y los lugares, tampoco -----------------------------------------------------
ALTER TABLE ticket_order_lugares DROP FOREIGN KEY fk_lugar_link;
ALTER TABLE ticket_order_lugares MODIFY link_id INT NULL;
ALTER TABLE ticket_order_lugares
    ADD CONSTRAINT fk_lugar_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL;
