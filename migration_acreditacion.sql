-- ---------------------------------------------------------------------------
-- Cuándo y cuánto se acredita cada venta.
--
-- Mercado Pago ya lo dice al consultar el pago, y lo estábamos descartando. Lo
-- que la plataforma calculaba hasta ahora ("te queda") sólo descontaba nuestra
-- comisión; el neto real le descuenta además la de Mercado Pago, que cambia
-- según el plazo de acreditación que cada vendedor tenga configurado en su
-- cuenta. Con estos datos el panel puede mostrar la cifra de verdad en lugar
-- de una estimación.
-- ---------------------------------------------------------------------------

-- Lo que efectivamente entra a la cuenta del vendedor, según Mercado Pago.
ALTER TABLE ticket_orders
    ADD COLUMN mp_neto DECIMAL(10,2) NULL DEFAULT NULL AFTER comision_porcentaje;

-- Todo lo que se descontó del total: la comisión de Mercado Pago más la
-- nuestra. Se guarda junto al neto para que la resta cierre sin depender de
-- cómo estuviera configurada la plataforma el día de la venta.
ALTER TABLE ticket_orders
    ADD COLUMN mp_comisiones DECIMAL(10,2) NULL DEFAULT NULL AFTER mp_neto;

-- Cuándo queda disponible la plata (money_release_date).
--
-- Con tarjeta de crédito puede ser un mes después del pago; con dinero en
-- cuenta, el mismo día. Es dato de Mercado Pago, no nuestro: sólo se muestra.
ALTER TABLE ticket_orders
    ADD COLUMN acreditacion_en TIMESTAMP NULL DEFAULT NULL AFTER pagada_en;
