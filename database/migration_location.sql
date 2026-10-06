-- ---------------------------------------------------------------------------
-- Location fix migration
--
-- Run once against an existing `network_support` database. Safe to re-run:
-- the ALTERs are guarded by the IF NOT EXISTS behaviour of MySQL 8.0.29+;
-- on older 8.0 builds, drop the `IF NOT EXISTS` and run each ALTER once.
--
-- 1. Adds latitude/longitude so a pin dragged on the request form is actually
--    stored. Before this, the draggable marker discarded its position on save.
-- 2. Repairs event rows that reference barangays which do not exist in
--    Bayawan City.
-- ---------------------------------------------------------------------------

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS latitude  DECIMAL(9,6) NULL AFTER location,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(9,6) NULL AFTER latitude;

CREATE INDEX IF NOT EXISTS idx_events_latlng ON events (latitude, longitude);

-- --- Name corrections -------------------------------------------------------
-- "Banago" is not a Bayawan barangay; the intended name is Banaybanay.
UPDATE events
   SET location = REPLACE(location, 'Banago', 'Banaybanay')
 WHERE location LIKE '%Banago%';

-- "San Miniato" is not a Bayawan barangay; the intended name is San Miguel.
UPDATE events
   SET location = REPLACE(location, 'San Miniato', 'San Miguel')
 WHERE location LIKE '%San Miniato%';

-- "Nabalbagan" belongs to Valencia, not Bayawan. There is no defensible
-- target barangay, so these rows are left untouched on purpose: the map
-- flags them as unresolved so staff can correct them by hand rather than
-- having the system guess a location for a field crew.
SELECT id, title, location
  FROM events
 WHERE location LIKE '%Nabalbagan%';

-- --- Backfill coordinates from the corrected barangay names -----------------
-- Only fills rows that have no pin yet; a manually dragged pin always wins.
UPDATE events SET latitude = 9.532400, longitude = 122.893800 WHERE latitude IS NULL AND location LIKE '%Ali-is%';
UPDATE events SET latitude = 9.553800, longitude = 122.822900 WHERE latitude IS NULL AND location LIKE '%Banaybanay%';
UPDATE events SET latitude = 9.370800, longitude = 122.796600 WHERE latitude IS NULL AND location LIKE '%Banga,%';
UPDATE events SET latitude = 9.361500, longitude = 122.802200 WHERE latitude IS NULL AND location LIKE '%Boyco%';
UPDATE events SET latitude = 9.630400, longitude = 122.834400 WHERE latitude IS NULL AND location LIKE '%Bugay%';
UPDATE events SET latitude = 9.435500, longitude = 122.933500 WHERE latitude IS NULL AND location LIKE '%Cansumalig%';
UPDATE events SET latitude = 9.575700, longitude = 122.881400 WHERE latitude IS NULL AND location LIKE '%Dawis%';
UPDATE events SET latitude = 9.576900, longitude = 122.777300 WHERE latitude IS NULL AND location LIKE '%Kalamtukan%';
UPDATE events SET latitude = 9.505300, longitude = 122.810300 WHERE latitude IS NULL AND location LIKE '%Kalumboyan%';
UPDATE events SET latitude = 9.370800, longitude = 122.763800 WHERE latitude IS NULL AND location LIKE '%Malabugas%';
UPDATE events SET latitude = 9.677200, longitude = 122.798900 WHERE latitude IS NULL AND location LIKE '%Mandu-ao%';
UPDATE events SET latitude = 9.398000, longitude = 122.866700 WHERE latitude IS NULL AND location LIKE '%Maninihon%';
UPDATE events SET latitude = 9.426100, longitude = 122.748400 WHERE latitude IS NULL AND location LIKE '%Minaba%';
UPDATE events SET latitude = 9.432000, longitude = 122.827400 WHERE latitude IS NULL AND location LIKE '%Nangka%';
UPDATE events SET latitude = 9.496800, longitude = 122.891400 WHERE latitude IS NULL AND location LIKE '%Narra%';
UPDATE events SET latitude = 9.381000, longitude = 122.733900 WHERE latitude IS NULL AND location LIKE '%Pagatban%';
UPDATE events SET latitude = 9.425400, longitude = 122.907700 WHERE latitude IS NULL AND location LIKE '%San Isidro%';
UPDATE events SET latitude = 9.660900, longitude = 122.830600 WHERE latitude IS NULL AND location LIKE '%San Jose%';
UPDATE events SET latitude = 9.407800, longitude = 122.734600 WHERE latitude IS NULL AND location LIKE '%San Miguel%';
UPDATE events SET latitude = 9.401800, longitude = 122.769600 WHERE latitude IS NULL AND location LIKE '%San Roque%';
UPDATE events SET latitude = 9.364600, longitude = 122.801400 WHERE latitude IS NULL AND location LIKE '%Suba%';
UPDATE events SET latitude = 9.519400, longitude = 122.845200 WHERE latitude IS NULL AND location LIKE '%Tabuan%';
UPDATE events SET latitude = 9.499300, longitude = 122.738800 WHERE latitude IS NULL AND location LIKE '%Tayawan%';
UPDATE events SET latitude = 9.361900, longitude = 122.806500 WHERE latitude IS NULL AND location LIKE '%Tinago%';
UPDATE events SET latitude = 9.368900, longitude = 122.804000 WHERE latitude IS NULL AND location LIKE '%Ubos%';
UPDATE events SET latitude = 9.356700, longitude = 122.827500 WHERE latitude IS NULL AND location LIKE '%Villareal%';
UPDATE events SET latitude = 9.610100, longitude = 122.777500 WHERE latitude IS NULL AND location LIKE '%Villasol%';
-- Poblacion last: several venue strings mention it alongside another barangay,
-- so more specific matches above get first claim on the row.
UPDATE events SET latitude = 9.365400, longitude = 122.805100 WHERE latitude IS NULL AND location LIKE '%Poblacion%';

-- Rows still without coordinates need a human to set the barangay.
SELECT id, title, location AS unresolved_location
  FROM events
 WHERE latitude IS NULL;
