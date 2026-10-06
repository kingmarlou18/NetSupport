-- ---------------------------------------------------------------------------
-- Category management migration
--
-- Run once against an existing `network_support` database. If your MySQL/
-- MariaDB build rejects `IF NOT EXISTS` on CREATE TABLE (unlikely — that
-- clause is old and widely supported), drop it and run once.
--
-- The category list on the request form and the map filter used to be a
-- hardcoded PHP array, duplicated in event_form.php and map.php, and the two
-- copies had already drifted into a different order. This migration moves
-- categories into a table so they can be added, renamed, reordered, and
-- retired from Administration → Categories, with the two pages reading from
-- one place.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS event_categories (
    id         INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name       VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_category_name (name)
);

-- Seed with the categories that were previously hardcoded (event_form.php's
-- order, which put outreach work first — that's most of what this system
-- schedules).
INSERT IGNORE INTO event_categories (name, sort_order) VALUES
    ('Barangay Mobile Outreach (Go-to-Brgy)', 10),
    ('Community Outreach Services',            20),
    ('Official Event',                         30),
    ('Emergency Support',                      40),
    ('Training',                               50),
    ('Seminar',                                60),
    ('Meeting',                                70),
    ('Conference',                             80),
    ('Workshop',                               90),
    ('Other',                                 100);

-- Carry forward any category already in use on an event that isn't in the
-- seed list above (case-insensitive), so a historical or custom value never
-- silently disappears from the filter or the edit form. New rows are given
-- a sort_order past the seeded ones so they land at the end of the list.
INSERT INTO event_categories (name, sort_order)
SELECT DISTINCT e.category, 1000 + (@rownum := @rownum + 1)
FROM events e, (SELECT @rownum := 0) r
WHERE e.category IS NOT NULL AND e.category <> ''
  AND NOT EXISTS (
      SELECT 1 FROM event_categories c WHERE c.name = e.category
  );

-- Rows worth a look: categories now on file that no event currently uses,
-- and (should be none) event categories still missing from the table.
SELECT id, name FROM event_categories ORDER BY sort_order, name;
SELECT DISTINCT e.category
  FROM events e
 WHERE e.category IS NOT NULL AND e.category <> ''
   AND NOT EXISTS (SELECT 1 FROM event_categories c WHERE c.name = e.category);
