-- Agregar columna name a la tabla users
ALTER TABLE users ADD COLUMN name VARCHAR(255) NULL AFTER email;
