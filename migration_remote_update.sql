-- ============================================================
-- Migration: Update remote DB to match local schema
-- Target: u414051709_rezonar @ srv1171.hstgr.io
-- Date: 2026-03-10
-- ============================================================
-- Changes:
--   1. Add location fields to users table
--   2. Create page_followers table
--   3. Create notifications table
-- ============================================================

-- 1. Add location fields to users table
ALTER TABLE users
ADD COLUMN location_latitude DECIMAL(10, 8) DEFAULT NULL,
ADD COLUMN location_longitude DECIMAL(11, 8) DEFAULT NULL,
ADD COLUMN location_name VARCHAR(255) DEFAULT NULL,
ADD COLUMN last_location_update TIMESTAMP NULL DEFAULT NULL;

CREATE INDEX idx_user_location ON users(location_latitude, location_longitude);

-- 2. Create page_followers table
CREATE TABLE IF NOT EXISTS page_followers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page_id INT NOT NULL,
  notify_all_events BOOLEAN DEFAULT true,
  max_distance_km DECIMAL(6, 2) DEFAULT 50.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_page (user_id, page_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
  INDEX idx_user_followers (user_id),
  INDEX idx_page_followers (page_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page_id INT NOT NULL,
  link_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN DEFAULT false,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
  FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE,
  INDEX idx_user_notifications (user_id, is_read, created_at),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
