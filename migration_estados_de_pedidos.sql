-- ---------------------------------------------------------------------------
-- Estados de los pedidos de productos físicos (#44).
--
-- Ingresado → Con fecha de entrega → Enviado → Entregado, y Cancelado desde
-- cualquiera salvo Entregado. Es independiente del estado del pago
-- (product_orders.estado): sólo un pedido pagado, o gratis confirmado, entra
-- en esta gestión. NULL en los digitales y en los físicos sin pagar.
--
--   - entrega_fecha y entrega_franja: lo que eligió quien vende en Con fecha
--     de entrega. La franja es opcional (manana, tarde o noche).
--   - envio_empresa y envio_seguimiento: los datos opcionales de Enviado.
--   - cancelado_motivo: obligatorio al cancelar.
--
-- product_order_historial: cada cambio, con quién lo hizo (NULL cuando lo
-- hizo el sistema, al acreditar el pago) y los datos de ese paso en JSON.
-- Sin ON DELETE, como las órdenes: no se borra nunca.
--
-- Al final, los físicos que ya estaban pagados pasan a Ingresado, con su
-- primera línea en el historial.
--
-- Se puede correr más de una vez: columnas con IF NOT EXISTS, la tabla se
-- crea sólo si no existe y el relleno sólo toca lo que falta.
-- ---------------------------------------------------------------------------

-- 1. El estado y sus datos en la orden -------------------------------------------
ALTER TABLE product_orders
    ADD COLUMN IF NOT EXISTS estado_pedido ENUM('ingresado', 'con_fecha', 'enviado', 'entregado', 'cancelado')
        NULL DEFAULT NULL AFTER sin_stock,
    ADD COLUMN IF NOT EXISTS entrega_fecha DATE NULL DEFAULT NULL AFTER estado_pedido,
    ADD COLUMN IF NOT EXISTS entrega_franja VARCHAR(20) NULL DEFAULT NULL AFTER entrega_fecha,
    ADD COLUMN IF NOT EXISTS envio_empresa VARCHAR(100) NULL DEFAULT NULL AFTER entrega_franja,
    ADD COLUMN IF NOT EXISTS envio_seguimiento VARCHAR(255) NULL DEFAULT NULL AFTER envio_empresa,
    ADD COLUMN IF NOT EXISTS cancelado_motivo VARCHAR(255) NULL DEFAULT NULL AFTER envio_seguimiento;

-- 2. El historial -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_order_historial (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NOT NULL,
    estado      ENUM('ingresado', 'con_fecha', 'enviado', 'entregado', 'cancelado') NOT NULL,
    -- {"fecha": "2026-11-03", "franja": "tarde"}, {"empresa": ..., "seguimiento": ...}, {"motivo": ...}
    datos       JSON NULL DEFAULT NULL,
    -- Quien lo cambió. NULL: el sistema, al acreditar el pago.
    user_id     INT NULL DEFAULT NULL,
    creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_historial_orden (order_id, id),
    CONSTRAINT fk_historial_orden FOREIGN KEY (order_id) REFERENCES product_orders(id),
    CONSTRAINT fk_historial_usuario FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Los físicos ya pagados quedan Ingresados -------------------------------------
UPDATE product_orders o
    INNER JOIN digital_products dp ON dp.id = o.product_id
SET o.estado_pedido = 'ingresado'
WHERE dp.tipo = 'fisico' AND o.estado = 'pagada' AND o.estado_pedido IS NULL;

INSERT INTO product_order_historial (order_id, estado, user_id, creado_en)
SELECT o.id, 'ingresado', NULL, COALESCE(o.pagada_en, o.creado_en)
FROM product_orders o
WHERE o.estado_pedido = 'ingresado'
  AND NOT EXISTS (SELECT 1 FROM product_order_historial h WHERE h.order_id = o.id);
