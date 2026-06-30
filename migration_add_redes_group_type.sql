-- Agregar el tipo de grupo "redes" (Redes Sociales)
-- Funciona igual que "links" pero se pre-carga con Instagram, TikTok, YouTube,
-- Facebook, WhatsApp y Cafecito (cada uno con su logo en /social/*.svg).

ALTER TABLE link_groups
MODIFY COLUMN type ENUM('links', 'redes', 'galeria', 'eventos') DEFAULT 'links';
