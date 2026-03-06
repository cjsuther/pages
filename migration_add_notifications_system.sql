/*
  # Notifications and Page Following System

  1. New Tables
    - `page_followers`
      - `id` (int, primary key, auto increment)
      - `user_id` (int, foreign key to users)
      - `page_id` (int, foreign key to pages)
      - `notify_all_events` (boolean) - If true, notify all events; if false, only nearby
      - `max_distance_km` (decimal) - Maximum distance in km for nearby events (default 50km)
      - `created_at` (timestamp)
      - Unique constraint on (user_id, page_id)

    - `notifications`
      - `id` (int, primary key, auto increment)
      - `user_id` (int, foreign key to users)
      - `page_id` (int, foreign key to pages)
      - `link_id` (int, foreign key to links - the event)
      - `title` (varchar 255) - Notification title
      - `message` (text) - Notification message
      - `is_read` (boolean) - Whether notification has been read
      - `created_at` (timestamp)
      - Index on (user_id, is_read, created_at)

    - `push_subscriptions`
      - `id` (int, primary key, auto increment)
      - `user_id` (int, foreign key to users)
      - `endpoint` (text) - Push notification endpoint URL
      - `p256dh_key` (text) - P256DH public key
      - `auth_key` (text) - Authentication secret
      - `created_at` (timestamp)
      - Unique constraint on endpoint

  2. Changes to Existing Tables
    - `users` table modifications:
      - `location_latitude` (decimal) - User's primary location latitude
      - `location_longitude` (decimal) - User's primary location longitude
      - `location_name` (varchar 255) - Human-readable location name
      - `last_location_update` (timestamp) - When location was last updated

  3. Security
    - All tables use foreign key constraints for data integrity
    - Indexes added for performance on frequently queried columns

  4. Important Notes
    - Users can follow multiple pages with different notification preferences per page
    - Notifications are created by a daily cron job that checks new events
    - Push subscriptions allow browser push notifications
    - Distance calculations use Haversine formula for accuracy
*/

-- Add location fields to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS location_latitude DECIMAL(10, 8) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS location_longitude DECIMAL(11, 8) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS location_name VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_location_update TIMESTAMP NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_user_location ON users(location_latitude, location_longitude);

-- Create page_followers table
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

-- Create notifications table
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

-- Create push_subscriptions table
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh_key TEXT NOT NULL,
  auth_key TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_subscriptions (user_id),
  UNIQUE KEY unique_endpoint (endpoint(500))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
