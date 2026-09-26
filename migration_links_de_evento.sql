-- ---------------------------------------------------------------------------
-- Links con clave de un evento.
--
-- Ya existía uno: el de la puerta. Ahora se suma el de la venta, para
-- compartirle a alguien sin cuenta cómo viene vendiéndose un show. Son la
-- misma idea —una clave que da acceso a una pantalla de un evento— así que
-- viven en una tabla sola, con el tipo como columna. Dos tablas gemelas
-- habrían duplicado también las decisiones: cómo se guarda la clave, cómo se
-- revoca, qué pasa si se borra el evento.
--
-- La clave nunca se guarda en claro: se busca por su hash, y se conserva
-- cifrada aparte para que quien organiza pueda volver a copiar el link sin
-- tener que generar otro y dejar afuera a quien ya lo tenía.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS event_access_links (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    link_id       INT NOT NULL,

    -- puerta: marca quién entró. ventas: mira cómo viene la venta.
    tipo          ENUM('puerta', 'ventas') NOT NULL,

    clave_hash    CHAR(64) NOT NULL,
    clave_cifrada TEXT NOT NULL,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_clave (clave_hash),
    -- Uno por evento y por tipo: generar otro reemplaza al anterior, que es
    -- la forma de sacarle el acceso a alguien.
    UNIQUE KEY uniq_evento_tipo (link_id, tipo),

    CONSTRAINT fk_acceso_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los links de puerta que ya se habían entregado siguen funcionando: se copian
-- tal cual, con su clave y su fecha.
INSERT IGNORE INTO event_access_links (link_id, tipo, clave_hash, clave_cifrada, created_at)
    SELECT link_id, 'puerta', clave_hash, clave_cifrada, created_at
    FROM event_door_access;

-- event_door_access queda como estaba a propósito: entre esta migración y el
-- deploy del código nuevo hay unos minutos en los que el código viejo la sigue
-- leyendo. Una vez verificado, se borra a mano:
--
--     DROP TABLE event_door_access;
