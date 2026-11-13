-- ---------------------------------------------------------------------------
-- Colaboraciones que se aceptan automáticamente.
--
-- Al aceptar una invitación a colaborar, la página invitada puede elegir
-- "Aceptar siempre las de <página que invita>". Desde ahí, las invitaciones
-- nuevas de esa página a esta se aceptan solas al crearse, y el evento se
-- publica en el grupo de eventos que se eligió al aceptar.
--
-- La regla es de página a página, como las colaboraciones: no abarca otras
-- páginas de la misma persona, ni de un lado ni del otro. La crea y la borra
-- sólo quien administra la página invitada (dueño o administrador aceptado).
-- Borrarla no toca las colaboraciones ya aceptadas.
--
-- Idempotente: se puede correr más de una vez. No cambia ninguna tabla
-- existente.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS colaboraciones_automaticas (
    id                   INT AUTO_INCREMENT PRIMARY KEY,

    -- La página invitada, dueña de la regla.
    collaborator_page_id INT NOT NULL,
    -- La página que invita.
    requester_page_id    INT NOT NULL,
    -- Dónde se publican los eventos. Si se borra el grupo se borra la regla:
    -- las invitaciones vuelven a quedar pendientes, como siempre.
    group_id             INT NOT NULL,

    -- Quién la creó. Si se borra la cuenta, la regla queda igual.
    created_by           INT NULL DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_colaboracion_automatica (collaborator_page_id, requester_page_id),
    KEY idx_colaboracion_automatica_requester (requester_page_id),

    CONSTRAINT fk_colab_auto_invitada FOREIGN KEY (collaborator_page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_colab_auto_invitadora FOREIGN KEY (requester_page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_colab_auto_grupo FOREIGN KEY (group_id) REFERENCES link_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_colab_auto_usuario FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
