-- ---------------------------------------------------------------------------
-- Clientes: ata a su registro las compras y colaboraciones que ya existen.
--
-- Va después de migration_clientes.sql. Se puede correr las veces que haga
-- falta: no duplica nada ni pisa lo que ya estaba.
--
-- Hay que correrlo otra vez apenas se despliega el código nuevo. Entre la
-- migración y el deploy el código viejo sigue vendiendo sin completar
-- record_id, y esas compras quedarían sueltas si el evento se borra.
-- ---------------------------------------------------------------------------

-- 1. Registro de los eventos que ya vendieron o tienen venta configurada -------
INSERT INTO event_records (link_id, page_id, titulo, event_date, event_time, event_address)
SELECT l.id, lg.page_id, l.text, l.event_date, l.event_time, l.event_address
FROM links l
INNER JOIN link_groups lg ON lg.id = l.group_id
WHERE l.id IN (SELECT link_id FROM ticket_orders WHERE link_id IS NOT NULL)
   OR l.id IN (SELECT link_id FROM event_ticketing)
ON DUPLICATE KEY UPDATE titulo = VALUES(titulo);

-- 2. Cada compra, a su registro --------------------------------------------------
UPDATE ticket_orders o
INNER JOIN event_records r ON r.link_id = o.link_id
SET o.record_id = r.id
WHERE o.record_id IS NULL;

-- 3. La organizadora de cada evento ----------------------------------------------
INSERT IGNORE INTO event_record_pages (record_id, page_id, rol)
SELECT r.id, r.page_id, 'organizador'
FROM event_records r
WHERE r.page_id IS NOT NULL;

-- 4. Las colaboraciones aceptadas -------------------------------------------------
INSERT IGNORE INTO event_record_pages (record_id, page_id, rol)
SELECT r.id, ec.collaborator_page_id, 'colaborador'
FROM event_collaborations ec
INNER JOIN event_records r ON r.link_id = ec.link_id
WHERE ec.status = 'accepted';
