-- Agregar tipos de grupo y campos de eventos

ALTER TABLE groups
ADD COLUMN type ENUM('links', 'galeria', 'eventos') DEFAULT 'links' AFTER title;

ALTER TABLE links
ADD COLUMN event_date DATE AFTER description,
ADD COLUMN event_time TIME AFTER event_date,
ADD COLUMN event_address VARCHAR(500) AFTER event_time,
ADD COLUMN event_address_link VARCHAR(1000) AFTER event_address;
