-- Sistema de notificaciones push (Web Push + VAPID).
-- Ver GUIA-PUSH-PWA.md secciones 6 y 7.

-- ---------------------------------------------------------------------------
-- 1. Suscripciones push, una por dispositivo.
-- ---------------------------------------------------------------------------
-- `endpoint` es la clave única: identifica al dispositivo. Si el usuario
-- recarga o se vuelve a suscribir, se reemplaza en lugar de duplicarse
-- (guía §6).
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    endpoint      VARCHAR(500) NOT NULL,
    p256dh_key    VARCHAR(255) NOT NULL,
    auth_key      VARCHAR(255) NOT NULL,

    -- Segmentar métricas por plataforma es lo que evita que un 100% global
    -- esconda un 0% en iOS (guía §7).
    platform      VARCHAR(20)  DEFAULT NULL,   -- iOS | Android | Desktop
    brand         VARCHAR(40)  DEFAULT NULL,   -- Samsung, Xiaomi... para las guías de batería
    standalone    TINYINT(1)   NOT NULL DEFAULT 0,
    user_agent    VARCHAR(500) DEFAULT NULL,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at  TIMESTAMP NULL DEFAULT NULL,

    UNIQUE KEY uniq_endpoint (endpoint(191)),
    KEY idx_user (user_id),
    KEY idx_platform (platform),
    CONSTRAINT fk_push_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Cola de envío y confirmación de entrega.
-- ---------------------------------------------------------------------------
-- Medir envíos no alcanza: hay que medir entregas confirmadas por el service
-- worker (guía §7). Cada fila es un intento de envío a un dispositivo.
CREATE TABLE IF NOT EXISTS push_deliveries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    subscription_id INT NOT NULL,

    -- Identificador que viaja en el payload y vuelve en el ack.
    envio_id        CHAR(10) NOT NULL,

    estado          ENUM('pendiente','enviado','confirmado','fallido','expirado')
                    NOT NULL DEFAULT 'pendiente',
    platform        VARCHAR(20) DEFAULT NULL,

    enviado_en      BIGINT DEFAULT NULL,   -- epoch en milisegundos
    recibido_en     BIGINT DEFAULT NULL,
    latencia_ms     INT    DEFAULT NULL,

    intentos        TINYINT NOT NULL DEFAULT 0,
    error           VARCHAR(255) DEFAULT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_envio (envio_id),
    -- Una notificación se envía una sola vez a cada dispositivo.
    UNIQUE KEY uniq_notificacion_dispositivo (notification_id, subscription_id),
    KEY idx_estado (estado),
    KEY idx_notificacion (notification_id),
    CONSTRAINT fk_delivery_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_sub   FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Notificaciones generadas una sola vez.
-- ---------------------------------------------------------------------------
-- El aviso de "una página que seguís publicó un evento" debe existir una única
-- vez por (usuario, evento), sin importar cuántas veces corra el cron ni
-- cuántas veces se edite el evento.
--
-- No se puede usar UNIQUE(user_id, link_id, type): las notificaciones de
-- colaboración son legítimamente repetibles (invitar, rechazar, volver a
-- invitar) y hoy ya hay 9 casos así en producción. Se usa en cambio una clave
-- de deduplicación que se llena SÓLO en las notificaciones que deben ser
-- únicas; el resto la deja en NULL, y MySQL admite múltiples NULL en un índice
-- único.
ALTER TABLE notifications
    ADD COLUMN dedupe_key VARCHAR(191) DEFAULT NULL AFTER type;

ALTER TABLE notifications
    ADD UNIQUE KEY uniq_dedupe (dedupe_key);
