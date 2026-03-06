/*
  Remove Event Address Link Field Migration

  This migration removes the event_address_link field from the links table as it's no longer needed.
  Event addresses will be validated using Google Maps API directly in the frontend.

  1. Changes
     - Drop event_address_link column from links table
     - This field was used to store Google Maps links but is redundant

  2. Important Notes
     - Ensure you have backed up any important address links before running
     - The event_address field will still be available for storing addresses
*/

ALTER TABLE links
DROP COLUMN IF EXISTS event_address_link;
