-- ---------------------------------------------------------------------------
-- Caché de geocodificación.
--
-- Los eventos importados traen la dirección en texto pero no coordenadas, y
-- Rezonar las necesita para el mapa. Geocodificar es una llamada a un servicio
-- ajeno con límite de un pedido por segundo, así que se guarda el resultado:
-- la misma sala aparece en decenas de shows y se resuelve una sola vez.
--
-- Los fallos también se cachean, con su fecha, para no reintentar en cada
-- corrida una dirección que el servicio no sabe resolver.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS geocode_cache (
    id          INT AUTO_INCREMENT PRIMARY KEY,

    -- Hash de la dirección normalizada: el texto puede pasar los 191 bytes
    -- que admite un índice único en utf8mb4.
    huella      CHAR(64) NOT NULL,
    direccion   VARCHAR(500) NOT NULL,

    latitud     DECIMAL(10,8) NULL DEFAULT NULL,
    longitud    DECIMAL(11,8) NULL DEFAULT NULL,

    -- Qué servicio la resolvió, para poder rehacer las de uno solo si hiciera falta.
    proveedor   VARCHAR(30) NOT NULL DEFAULT 'nominatim',

    intentos    INT NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_huella (huella)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
