-- ---------------------------------------------------------------------------
-- Envío de la entrada por mail.
--
-- Se anota cuándo salió y cuántas veces se intentó. Sin esto no habría forma
-- de distinguir "no se le mandó todavía" de "se le mandó y no llegó", ni de
-- reintentar sólo lo que falta sin mandarle dos veces la misma entrada a
-- alguien que ya la tiene.
-- ---------------------------------------------------------------------------

ALTER TABLE ticket_orders
    ADD COLUMN mail_enviado_en TIMESTAMP NULL DEFAULT NULL AFTER pagada_en;

ALTER TABLE ticket_orders
    ADD COLUMN mail_intentos INT NOT NULL DEFAULT 0 AFTER mail_enviado_en;

-- Último motivo por el que falló, para poder diagnosticar sin adivinar.
ALTER TABLE ticket_orders
    ADD COLUMN mail_error VARCHAR(255) NULL DEFAULT NULL AFTER mail_intentos;

-- El cron busca las pagadas que todavía no salieron: conviene que no recorra
-- la tabla entera cada vez.
ALTER TABLE ticket_orders
    ADD KEY idx_mail_pendiente (estado, mail_enviado_en, mail_intentos);
