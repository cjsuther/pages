/*
  # Remove Push Notifications System

  1. Changes
    - Drop push_subscriptions table (no longer needed)

  2. Notes
    - In-app notifications remain intact
    - Only browser push notification infrastructure is removed
*/

DROP TABLE IF EXISTS push_subscriptions;
