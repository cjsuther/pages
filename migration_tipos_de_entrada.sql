-- ---------------------------------------------------------------------------
-- Tipos de entrada: varios precios con nombre en un mismo evento.
--
-- "General", "Jubilados", "Anticipada", "VIP": cada tipo tiene nombre, precio
-- y, si hace falta, un cupo propio además de la capacidad del evento. En un
-- evento con plano el tipo lo pone la zona —cada fila o mesa dice de qué tipo
-- son sus lugares—, así que el comprador no lo elige: elige dónde sentarse.
-- ---------------------------------------------------------------------------

-- Los tipos, como JSON junto al plano. NULL = un solo precio, como hasta acá.
--
-- Van en JSON y no en una tabla porque el plano los nombra: una fila nueva
-- puede apuntar a un tipo nuevo antes de que nada de eso esté guardado, y con
-- ids que pone la base habría que guardar en dos pasos.
ALTER TABLE event_ticketing
    ADD COLUMN tipos TEXT NULL DEFAULT NULL AFTER max_por_compra;

-- ---------------------------------------------------------------------------
-- Qué tipos se llevó cada compra.
--
-- Nombre y precio se copian al comprar: si después el tipo cambia de precio o
-- se renombra, la compra sigue diciendo lo que se pagó. El id del tipo es lo
-- que cuenta para su cupo.
--
-- Sólo las compras de eventos con tipos tienen renglones acá; las de un solo
-- precio se leen de ticket_orders como siempre.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_order_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL,
    tipo            VARCHAR(20) NOT NULL,
    nombre          VARCHAR(60) NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    cantidad        INT NOT NULL,

    UNIQUE KEY uniq_orden_tipo (order_id, tipo),

    CONSTRAINT fk_item_orden FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
