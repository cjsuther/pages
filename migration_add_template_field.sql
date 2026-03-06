/*
  # Add Template Selection to Pages

  1. New Columns
     - `template` (varchar) - Template style for public page display
       Options: 'minimal', 'cards', 'modern'
       Default: 'minimal'

  2. Changes
     - Adds template field to pages table
     - Default template is 'minimal' for backward compatibility
     - Existing pages will use the minimal template

  3. Important Notes
     - Template determines the visual layout of the public page
     - Users can switch between templates in the page editor
     - Each template has a unique design approach
*/

ALTER TABLE pages
ADD COLUMN IF NOT EXISTS template VARCHAR(50) DEFAULT 'minimal';

UPDATE pages SET template = 'minimal' WHERE template IS NULL;
