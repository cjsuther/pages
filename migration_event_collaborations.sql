-- Migration: Event Collaborations Feature
-- Run this after the base schema and existing migrations

CREATE TABLE event_collaborations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,
    collaborator_page_id INT NOT NULL,
    requester_page_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    collaborator_group_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE,
    FOREIGN KEY (collaborator_page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (requester_page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (collaborator_group_id) REFERENCES link_groups(id) ON DELETE SET NULL,
    UNIQUE KEY unique_collab (link_id, collaborator_page_id)
);

ALTER TABLE notifications
    ADD COLUMN type VARCHAR(50) NOT NULL DEFAULT 'event' AFTER message,
    ADD COLUMN collaboration_id INT NULL AFTER type,
    ADD CONSTRAINT fk_notif_collab FOREIGN KEY (collaboration_id) REFERENCES event_collaborations(id) ON DELETE SET NULL;
