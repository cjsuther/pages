/*
  Remove Events Table Migration

  This migration removes the events table as events are now handled through
  the links table with link_groups of type "eventos".

  1. Changes
     - Drop the events table completely
     - Events functionality now uses links with event_date, event_time, event_address fields
     - Events are grouped in link_groups with type = 'eventos'

  2. Important Notes
     - Ensure all events have been migrated to links before running this
     - This operation cannot be undone
     - Backup your database before running this migration
*/

-- Drop the events table
DROP TABLE IF EXISTS events;
