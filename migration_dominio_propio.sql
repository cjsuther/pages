-- ---------------------------------------------------------------------------
-- Dominio propio de una página.
--
-- Con esto, maxipeque.com muestra la página de Rezonar que tenga ese dominio
-- asignado, y sus rutas internas —/evento/123, /entrada/ABC— siguen andando
-- porque es la misma aplicación en el mismo origen.
--
-- Lo que la aplicación NO puede hacer sola: el alta del dominio en hPanel y su
-- certificado. Eso es manual, una vez por dominio. Acá sólo se guarda a qué
-- página corresponde.
--
-- Se guarda normalizado —sin protocolo, sin www y en minúsculas— porque es lo
-- que se compara contra el Host de cada visita. El índice único evita que dos
-- páginas se peleen el mismo dominio: sin él, cuál gana dependería del orden
-- de la consulta.
-- ---------------------------------------------------------------------------

ALTER TABLE pages
    ADD COLUMN dominio VARCHAR(255) NULL DEFAULT NULL AFTER url_slug;

ALTER TABLE pages
    ADD UNIQUE KEY idx_dominio (dominio);
