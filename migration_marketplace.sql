-- ---------------------------------------------------------------------------
-- Split de pagos: comisión de la plataforma sobre cada venta.
--
-- Mercado Pago sólo reparte automáticamente si el dueño conectó su cuenta por
-- OAuth desde la aplicación de marketplace. Con un access token pegado a mano
-- la comisión se ignora en silencio, así que hay que guardar aparte cómo se
-- conectó cada página.
-- ---------------------------------------------------------------------------

-- Identificador del vendedor en Mercado Pago, para saber a qué cuenta quedó
-- asociada la página.
ALTER TABLE page_payment_settings
    ADD COLUMN mp_user_id VARCHAR(50) NULL DEFAULT NULL AFTER page_id;

-- El access token de OAuth vence (unos seis meses). El refresh token permite
-- renovarlo sin molestar al dueño; también es un secreto, así que va cifrado.
ALTER TABLE page_payment_settings
    ADD COLUMN refresh_token_cifrado TEXT NULL DEFAULT NULL AFTER access_token_cifrado;

ALTER TABLE page_payment_settings
    ADD COLUMN token_expira_en DATETIME NULL DEFAULT NULL AFTER modo;

-- Sólo las conexiones por OAuth admiten split. Una credencial pegada a mano
-- cobra igual, pero la comisión de la plataforma nunca se descuenta.
ALTER TABLE page_payment_settings
    ADD COLUMN conectado_por ENUM('oauth', 'manual') NOT NULL DEFAULT 'manual' AFTER modo;

-- ---------------------------------------------------------------------------
-- Comisión congelada en cada orden.
--
-- Se guarda el monto y el porcentaje con el que se calculó: si mañana cambia
-- el porcentaje de la plataforma, lo ya vendido tiene que seguir mostrando lo
-- que efectivamente se cobró.
-- ---------------------------------------------------------------------------
ALTER TABLE ticket_orders
    ADD COLUMN comision DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total;

ALTER TABLE ticket_orders
    ADD COLUMN comision_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER comision;
