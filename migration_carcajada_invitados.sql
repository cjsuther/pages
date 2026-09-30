-- ---------------------------------------------------------------------------
-- Carcajada: comediantes sin cuenta y descripción del show.
--
-- 1. Quien produce puede sumar a alguien que no tiene cuenta en Rezonar,
--    cargando sólo nombre, foto e Instagram. Ese comediante no tiene usuario:
--    user_id pasa a aceptar NULL. La clave única sigue valiendo para los que
--    sí tienen cuenta (MariaDB no compara NULLs entre sí).
-- 2. Cada show lleva una descripción pública, en HTML limpio (lo arma un
--    editor simple y lo filtra HtmlSimple antes de guardarlo). Las notas
--    siguen siendo internas.
-- 3. En el alta, cada comediante cuenta con quién estudió, en qué año egresó
--    y de qué se trata su material. Es para quien produce, al armar fechas:
--    no se muestra en la página pública.
-- ---------------------------------------------------------------------------

ALTER TABLE carcajada_comediantes MODIFY user_id INT NULL DEFAULT NULL;

ALTER TABLE carcajada_comediantes
    ADD COLUMN estudio_con VARCHAR(120) NULL DEFAULT NULL AFTER instagram,
    ADD COLUMN egreso_anio SMALLINT NULL DEFAULT NULL AFTER estudio_con,
    ADD COLUMN material TEXT NULL DEFAULT NULL AFTER egreso_anio;

ALTER TABLE carcajada_shows
    ADD COLUMN descripcion MEDIUMTEXT NULL DEFAULT NULL AFTER lugar;
