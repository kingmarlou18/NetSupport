-- Team lead / crew acceptance migration.
-- Apply once to an existing network_support database. Existing assignments keep
-- is_team_lead = 0 until an administrator re-saves the crew with its lead in the
-- primary technician row. New assignments default to non-lead until assignments.php
-- marks the selected primary technician.
ALTER TABLE event_assignments
    ADD COLUMN IF NOT EXISTS is_team_lead TINYINT(1) NOT NULL DEFAULT 0 AFTER technician_id;