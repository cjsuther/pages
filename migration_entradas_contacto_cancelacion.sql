-- ---------------------------------------------------------------------------
-- Contacto del organizador y cancelación de compras.
-- ---------------------------------------------------------------------------

-- Mail al que le llegan las respuestas de quienes compraron.
--
-- Va en la página y no en la configuración de cobro: una página puede tomar
-- reservas gratuitas sin haber conectado nunca Mercado Pago, y aun así
-- necesita que le puedan escribir. Vacío significa "no publicar contacto".
ALTER TABLE pages
    ADD COLUMN email_contacto VARCHAR(255) NULL DEFAULT NULL AFTER description;

-- Cuándo se canceló la compra.
--
-- El estado 'cancelada' ya existía en el ENUM, pero nada lo escribía. La marca
-- de tiempo es lo que después permite explicar una diferencia entre lo
-- vendido y lo disponible: sin ella, una orden cancelada no dice cuándo dejó
-- de ocupar lugar.
ALTER TABLE ticket_orders
    ADD COLUMN cancelada_en TIMESTAMP NULL DEFAULT NULL AFTER pagada_en;
