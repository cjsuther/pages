-- ---------------------------------------------------------------------------
-- Cuánta comisión de plataforma cobró Mercado Pago, según Mercado Pago.
--
-- Ya guardábamos `comision`: lo que la plataforma PIDE que se le descuente, y
-- que se manda como marketplace_fee al crear la preferencia. Eso es una
-- intención, no un hecho. Si la cuenta no está conectada por OAuth desde
-- nuestra aplicación, Mercado Pago ignora el pedido sin devolver ningún error
-- y la venta se cobra igual: nadie se entera de que la comisión no entró.
--
-- Mercado Pago devuelve las comisiones desglosadas por tipo en fee_details, y
-- una de esas líneas es application_fee: la nuestra. La estábamos sumando con
-- las demás en mp_comisiones y perdiendo justo el dato que permite comparar lo
-- pedido contra lo cobrado.
--
-- Con esta columna, `comision` y `mp_comision_cobrada` puestas una al lado de
-- la otra responden solas la pregunta.
-- ---------------------------------------------------------------------------

-- NULL significa "no lo sabemos" —una venta anterior a esta columna, o un pago
-- del que Mercado Pago no devolvió el desglose—. Un 0 significa que Mercado
-- Pago no cobró comisión de plataforma, que es una respuesta distinta.
ALTER TABLE ticket_orders
    ADD COLUMN mp_comision_cobrada DECIMAL(10,2) NULL DEFAULT NULL AFTER mp_comisiones;
