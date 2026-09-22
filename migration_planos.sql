-- ---------------------------------------------------------------------------
-- Lugares asignados: plano de butacas y mesas para los eventos con entradas.
--
-- El plano es la forma del lugar —filas, mesas, escenario— y se guarda como
-- JSON junto a la configuración de venta. Con plano, la capacidad deja de
-- cargarse a mano: es la cantidad de lugares que tiene.
-- ---------------------------------------------------------------------------

-- NULL = el evento vende sin lugares asignados, como hasta ahora.
ALTER TABLE event_ticketing
    ADD COLUMN plano MEDIUMTEXT NULL DEFAULT NULL AFTER max_por_compra;

-- ---------------------------------------------------------------------------
-- Qué lugares se llevó cada compra.
--
-- El lugar se guarda como texto que se entiende solo ("f:A:7" es fila A,
-- butaca 7; "m:3:2" es mesa 3, lugar 2): si el plano cambia después, la compra
-- sigue diciendo qué lugar era.
--
-- No hay índice único sobre (link_id, lugar): un lugar de una reserva vencida
-- vuelve a estar libre, y eso depende de la hora, no de algo que un índice
-- pueda ver. Lo que impide venderlo dos veces es que las compras de un mismo
-- evento se hacen de a una, con la configuración del evento bloqueada.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_order_lugares (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    order_id  INT NOT NULL,
    link_id   INT NOT NULL,
    lugar     VARCHAR(20) NOT NULL,

    UNIQUE KEY uniq_orden_lugar (order_id, lugar),
    KEY idx_evento_lugar (link_id, lugar),

    CONSTRAINT fk_lugar_orden FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_lugar_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
