/*
  OAuth and Events Migration

  This migration adds OAuth support and events functionality to the personal pages application.

  1. OAuth Support
     - Adds oauth_provider column (google, apple, or NULL for email/password)
     - Adds oauth_id column to store provider's unique user ID
     - Adds name column for user's display name
     - Adds avatar_url column for user's profile picture
     - Makes password optional (NULL for OAuth users)

  2. Events Table
     - Creates events table for public calendar functionality
     - Links events to user pages
     - Supports title, description, date, time, and location
     - Includes public/private visibility toggle

  3. Security
     - All tables have proper indexes for performance
     - Foreign key constraints maintain referential integrity
     - OAuth users are distinguished from email/password users
*/

-- Add OAuth support to users table
ALTER TABLE users
ADD COLUMN oauth_provider VARCHAR(20) DEFAULT NULL COMMENT 'OAuth provider: google, apple, or NULL for email/password',
ADD COLUMN oauth_id VARCHAR(255) DEFAULT NULL COMMENT 'Unique ID from OAuth provider',
ADD COLUMN name VARCHAR(255) DEFAULT NULL COMMENT 'User display name from OAuth',
ADD COLUMN avatar_url TEXT DEFAULT NULL COMMENT 'Profile picture URL from OAuth';

-- Make password optional for OAuth users
ALTER TABLE users
MODIFY COLUMN password VARCHAR(255) DEFAULT NULL COMMENT 'Password hash (NULL for OAuth users)';

-- Add indexes for OAuth lookups
CREATE INDEX idx_oauth_provider_id ON users(oauth_provider, oauth_id);

-- Create events table for public calendar
CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  event_date DATE NOT NULL,
  event_time TIME,
  location VARCHAR(255),
  is_public BOOLEAN DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
  INDEX idx_page_events (page_id),
  INDEX idx_public_events (is_public, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
