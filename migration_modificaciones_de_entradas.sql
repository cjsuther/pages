-- ---------------------------------------------------------------------------
-- Modificación de compras de entradas.
--
-- Quien organiza puede cambiar una compra ya pagada (o una reserva gratuita):
-- subir o bajar la cantidad, cambiar cuántas de cada tipo o cambiar los
-- lugares. No se cobra ni se devuelve nada: el total y la comisión quedan
-- como se pagaron. El mismo código QR sigue valiendo.
--
-- Idempotente: se puede correr más de una vez.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. Cuándo se modificó por última vez, para marcarla en el panel de ventas
--    sin tener que leer el historial de cada compra.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_orders
    ADD COLUMN IF NOT EXISTS modificada_en TIMESTAMP NULL DEFAULT NULL AFTER cancelada_en;

-- ---------------------------------------------------------------------------
-- 2. El historial: qué tenía la compra antes y después de cada cambio, quién
--    lo hizo y si le llegó el mail a quien compró. Es lo que permite explicar
--    después por qué una compra de 2 entradas pagadas tiene 4.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_order_modificaciones (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL,

    -- Quien hizo el cambio. Si se borra la cuenta, el cambio queda igual.
    user_id         INT NULL DEFAULT NULL,

    -- JSON con cantidad, lugares y renglones por tipo.
    antes           TEXT NOT NULL,
    despues         TEXT NOT NULL,

    mail_enviado_en TIMESTAMP NULL DEFAULT NULL,
    mail_error      VARCHAR(255) NULL DEFAULT NULL,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_modificacion_orden (order_id),

    CONSTRAINT fk_modificacion_orden FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_modificacion_usuario FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
