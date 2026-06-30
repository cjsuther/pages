-- Administración multi-usuario de páginas
-- Un dueño invita a otro usuario (por email); este acepta para administrar la página.
-- Los admins aceptados pueden editar contenido y ajustes (no borrar la página ni gestionar admins).

CREATE TABLE IF NOT EXISTS page_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
    invited_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_page_admin (page_id, user_id),
    INDEX idx_user_admins (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permitir notificaciones sin evento asociado (invitaciones de administración)
ALTER TABLE notifications MODIFY COLUMN link_id INT NULL;
