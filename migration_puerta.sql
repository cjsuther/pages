-- ---------------------------------------------------------------------------
-- Control de ingreso en la puerta.
--
-- Quien organiza genera un link por evento y se lo da a quien está en la
-- puerta, que no necesita cuenta en la plataforma: la clave del link es la
-- credencial. Con él se escanean los QR de las entradas o se busca a la gente
-- en la lista, y se marca que entró.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. El link de puerta de cada evento. Uno por evento: generar otro invalida
--    el anterior, que es la forma de sacarle el acceso a alguien.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_door_access (
    link_id       INT NOT NULL PRIMARY KEY,

    -- Para encontrar el evento a partir de la clave: quien lea la base no
    -- puede usar el hash para entrar.
    clave_hash    CHAR(64) NOT NULL,

    -- La misma clave, cifrada, para que quien organiza pueda volver a copiar
    -- el link sin tener que generar otro (y dejar afuera a quien ya lo tenía).
    clave_cifrada TEXT NOT NULL,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_clave (clave_hash),
    CONSTRAINT fk_puerta_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Cuántas personas de cada compra ya entraron.
--
-- Es un número y no un sí/no: una compra de cuatro entradas puede llegar en
-- dos tandas, y la segunda tiene que poder pasar.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_orders
    ADD COLUMN ingresadas INT NOT NULL DEFAULT 0 AFTER cantidad;

ALTER TABLE ticket_orders
    ADD COLUMN ingreso_en TIMESTAMP NULL DEFAULT NULL AFTER ingresadas;
