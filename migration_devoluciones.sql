-- ---------------------------------------------------------------------------
-- Devoluciones de dinero a quienes compraron entradas.
--
-- Quien organiza le devuelve a quien compró SÓLO lo que le llegó a su cuenta
-- de Mercado Pago por esa compra (net_received_amount, menos lo ya devuelto).
-- Lo que se quedaron Mercado Pago y Rezonar no se devuelve. La devolución es
-- un reembolso de Mercado Pago (POST /v1/payments/{id}/refunds, parcial por
-- ese monto) hecho con el token OAuth del vendedor, así que se ve como
-- devolución en su cuenta. La lógica está en api/lib/Devoluciones.php.
--
-- Todo detrás de DEVOLUCIONES_HABILITADAS en config.php (apagado si no está).
--
-- Aditiva e idempotente: se puede correr más de una vez y no cambia nada de
-- lo que ya existe.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. Cada intento de devolución, salga bien o mal. Nunca se borra ni se pisa
--    el monto: es la traza de la plata que salió.
--
--    estado:
--      pendiente  se anotó y se está llamando a Mercado Pago
--      aprobada   Mercado Pago la hizo
--      rechazada  no se hizo: la rechazó Mercado Pago o la frenamos nosotros
--                 (origen_rechazo dice cuál de los dos)
--      incierta   no sabemos si Mercado Pago la hizo (se cortó la conexión,
--                 respondió 5xx). El cron de conciliar.php le pregunta y la
--                 resuelve. Nunca se reintenta sola.
--
--    reparto: lo que Mercado Pago informa del pago DESPUÉS de devolver
--    (fee_details, neto, lo devuelto). Es lo que permite comprobar si el
--    reembolso le sacó una parte a la comisión de la plataforma.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_order_devoluciones (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    order_id         INT NOT NULL,

    -- Quien la pidió. Si se borra la cuenta, la devolución queda igual.
    user_id          INT NULL DEFAULT NULL,

    -- completa: todo lo que queda por devolver, y se anula la compra.
    -- entradas: la parte proporcional de algunas entradas, que se sacan.
    modo             ENUM('completa', 'entradas') NOT NULL,

    -- Lo que se le pidió a Mercado Pago que devuelva (0 si se frenó antes).
    monto            DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    -- Foto de la cuenta en el momento de pedirla.
    neto_al_pedir    DECIMAL(10,2) NULL DEFAULT NULL,
    devuelto_antes   DECIMAL(10,2) NULL DEFAULT NULL,

    -- JSON: qué entradas se sacan (cantidad, tipos, lugares) y cómo queda.
    pedido           TEXT NULL,

    estado           ENUM('pendiente', 'aprobada', 'rechazada', 'incierta') NOT NULL DEFAULT 'pendiente',
    origen_rechazo   ENUM('validacion', 'mercadopago') NULL DEFAULT NULL,

    mp_payment_id    VARCHAR(100) NULL DEFAULT NULL,
    -- La que viaja en X-Idempotency-Key. Una por intento: el mismo intento
    -- nunca puede convertirse en dos reembolsos.
    idempotency_key  VARCHAR(64) NULL DEFAULT NULL,
    mp_refund_id     VARCHAR(100) NULL DEFAULT NULL,
    mp_status_http   INT NULL DEFAULT NULL,

    -- Lo que dijo Mercado Pago tal cual, y lo mismo en castellano.
    mp_error_codigo  VARCHAR(50) NULL DEFAULT NULL,
    mp_error_mensaje VARCHAR(255) NULL DEFAULT NULL,
    motivo_rechazo   VARCHAR(255) NULL DEFAULT NULL,

    reparto          TEXT NULL,

    -- Si la plata salió pero no se pudieron sacar las entradas (la compra
    -- cambió mientras tanto): queda anotado para arreglarlo a mano.
    entradas_error   VARCHAR(255) NULL DEFAULT NULL,

    mail_enviado_en  TIMESTAMP NULL DEFAULT NULL,
    mail_error       VARCHAR(255) NULL DEFAULT NULL,

    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resuelta_en      TIMESTAMP NULL DEFAULT NULL,

    UNIQUE KEY uniq_devolucion_refund (mp_refund_id),
    UNIQUE KEY uniq_devolucion_idempotencia (idempotency_key),
    KEY idx_devolucion_orden (order_id, estado),
    KEY idx_devolucion_estado (estado, created_at),

    -- RESTRICT: la traza de plata no se borra con la compra. Las compras no se
    -- borran nunca (si se borra el evento, link_id queda en NULL).
    CONSTRAINT fk_devolucion_orden FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_devolucion_usuario FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. En la compra, lo que hace falta para el panel sin leer el historial:
--    cuánto se devolvió, cuándo, y el último intento que no se pudo hacer.
--    devolucion_error se limpia cuando una devolución sale bien.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS devuelto DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER mp_comision_cobrada;

ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS devuelta_en TIMESTAMP NULL DEFAULT NULL AFTER modificada_en;

ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS devolucion_error VARCHAR(255) NULL DEFAULT NULL AFTER devuelta_en;

ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS devolucion_error_en TIMESTAMP NULL DEFAULT NULL AFTER devolucion_error;
