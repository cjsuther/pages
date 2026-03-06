-- Agregar campos de imágenes a la tabla pages

ALTER TABLE pages
ADD COLUMN profile_image VARCHAR(500) AFTER text_color,
ADD COLUMN background_image VARCHAR(500) AFTER profile_image;
