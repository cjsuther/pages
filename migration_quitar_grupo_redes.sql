-- ---------------------------------------------------------------------------
-- Se quita el tipo de grupo "redes".
--
-- Las redes sociales de una página se cargan en su propia sección, que es
-- donde tiene sentido: son de la página, no de un bloque de contenido. El tipo
-- de grupo hacía lo mismo por duplicado y con peor resultado —una lista de
-- links con logos— así que se saca.
--
-- Los grupos que ya existen no se borran: pasan a "links", que es exactamente
-- como los venían dibujando las plantillas. Ninguna página cambia de aspecto.
-- ---------------------------------------------------------------------------

-- Primero los datos; el ENUM no se puede achicar con filas que lo usen.
UPDATE link_groups SET type = 'links' WHERE type = 'redes';

ALTER TABLE link_groups
    MODIFY COLUMN type ENUM('links', 'galeria', 'eventos') NOT NULL DEFAULT 'links';
