/*
  Add Event Location GPS Coordinates and Maps URL

  This migration adds geographic location fields to the links table for events,
  enabling location-based event searches and Google Maps integration.

  1. New Columns
     - `event_latitude` (decimal) - GPS latitude coordinate
     - `event_longitude` (decimal) - GPS longitude coordinate
     - `event_maps_url` (text) - Google Maps URL for the event location

  2. Changes
     - All new fields are nullable to maintain backward compatibility
     - Coordinates use DECIMAL(10,8) for latitude and DECIMAL(11,8) for longitude
     - These precision levels provide accuracy within ~1 meter

  3. Important Notes
     - Existing event records will have NULL values for these fields
     - The event_address field remains and stores the human-readable address
     - Coordinates enable proximity-based event searches
*/

ALTER TABLE links
ADD COLUMN IF NOT EXISTS event_latitude DECIMAL(10, 8) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS event_longitude DECIMAL(11, 8) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS event_maps_url TEXT DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_event_coordinates ON links(event_latitude, event_longitude);
