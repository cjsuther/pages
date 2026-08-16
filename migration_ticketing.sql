-- ---------------------------------------------------------------------------
-- Venta de entradas para eventos, cobrando con Mercado Pago.
--
-- Tres piezas: las credenciales de cobro de cada página, la configuración de
-- venta de cada evento, y las órdenes de compra.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. Credenciales de cobro, una por página.
--
-- El access token es un secreto de otra persona: se guarda cifrado, nunca en
-- claro, y no vuelve al frontend. Para que el dueño reconozca cuál cargó se
-- guardan aparte los últimos cuatro caracteres.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS page_payment_settings (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    page_id              INT NOT NULL,

    access_token_cifrado TEXT NOT NULL,
    token_ultimos4       CHAR(4) NOT NULL,
    public_key           VARCHAR(255) NOT NULL,

    -- Los tokens de prueba de MP empiezan con TEST-. Cobrar de verdad con uno
    -- de prueba (o al revés) es un error caro, así que se muestra siempre.
    modo                 ENUM('prueba', 'produccion') NOT NULL DEFAULT 'prueba',

    -- Última vez que la credencial respondió bien contra la API de MP.
    verificado_en        TIMESTAMP NULL DEFAULT NULL,

    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_pagina (page_id),
    CONSTRAINT fk_cobro_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Configuración de venta de cada evento.
--
-- precio = 0 significa reserva sin cobro: se confirma en el momento y no pasa
-- por Mercado Pago.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_ticketing (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    link_id        INT NOT NULL,

    activo         TINYINT(1) NOT NULL DEFAULT 1,
    capacidad      INT NOT NULL,
    precio         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    moneda         CHAR(3) NOT NULL DEFAULT 'ARS',

    -- Tope por compra, para que uno solo no se lleve todo el cupo.
    max_por_compra INT NOT NULL DEFAULT 10,

    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_evento (link_id),
    CONSTRAINT fk_entradas_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Órdenes de compra.
--
-- Una orden nace 'reservada' y toma cupo desde ese momento, para que dos
-- compras simultáneas no puedan pasarse de la capacidad. Si no se paga antes
-- de reserva_vence_en, el cupo se libera solo: no hace falta ningún proceso
-- que las limpie, la consulta de disponibilidad ya ignora las vencidas.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_orders (
    id                INT AUTO_INCREMENT PRIMARY KEY,

    -- Identificador público de la orden: va en la URL a la que vuelve el
    -- comprador. Aleatorio, para que nadie vea órdenes ajenas probando ids.
    codigo            CHAR(12) NOT NULL,

    link_id           INT NOT NULL,

    nombre            VARCHAR(150) NOT NULL,
    email             VARCHAR(255) NOT NULL,
    telefono          VARCHAR(50)  NOT NULL,
    cantidad          INT NOT NULL,

    -- Copiado de event_ticketing al comprar: si el dueño cambia el precio
    -- después, lo cobrado no puede cambiar retroactivamente.
    precio_unitario   DECIMAL(10,2) NOT NULL,
    total             DECIMAL(10,2) NOT NULL,
    moneda            CHAR(3) NOT NULL DEFAULT 'ARS',

    estado            ENUM('reservada', 'pagada', 'vencida', 'cancelada', 'rechazada')
                      NOT NULL DEFAULT 'reservada',

    reserva_vence_en  DATETIME NULL DEFAULT NULL,

    mp_preference_id  VARCHAR(100) NULL DEFAULT NULL,
    mp_payment_id     VARCHAR(100) NULL DEFAULT NULL,

    pagada_en         TIMESTAMP NULL DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_codigo (codigo),

    -- Mercado Pago reintenta el aviso de pago varias veces y no garantiza
    -- mandarlo una sola vez. El índice único es lo que hace que reprocesar el
    -- mismo pago no acredite dos veces.
    UNIQUE KEY uniq_pago_mp (mp_payment_id),

    KEY idx_evento_estado (link_id, estado),
    KEY idx_vencimiento (estado, reserva_vence_en),

    CONSTRAINT fk_orden_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
