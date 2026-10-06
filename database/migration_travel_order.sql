-- ---------------------------------------------------------------------------
-- Travel Order migration
--
-- Run once against an existing `network_support` database.
--
-- Every support event now requires a Travel Order authorizing the assigned
-- technician(s) to travel to the venue. Rather than a new table, the events
-- table tracks the Travel Order lifecycle directly (Not Created -> Ongoing
-- -> Completed) so admins can see, right from the event, whether a Travel
-- Order has already been generated or is still in progress before creating
-- another one. The printable document itself is generated on the fly from
-- the event + its assigned technicians (see travel_order.php).
-- ---------------------------------------------------------------------------

ALTER TABLE events
    ADD COLUMN travel_order_status ENUM('Not Created','Ongoing','Completed') NOT NULL DEFAULT 'Not Created' AFTER status,
    ADD COLUMN travel_order_no VARCHAR(40) NULL AFTER travel_order_status,
    ADD COLUMN travel_order_created_by INT UNSIGNED NULL AFTER travel_order_no,
    ADD COLUMN travel_order_created_at DATETIME NULL AFTER travel_order_created_by,
    ADD COLUMN travel_order_completed_at DATETIME NULL AFTER travel_order_created_at,
    ADD INDEX travel_order_status (travel_order_status),
    ADD CONSTRAINT events_travel_order_created_by_fk FOREIGN KEY (travel_order_created_by) REFERENCES users(id);

-- Existing events all start out with no Travel Order on file, which is
-- already the column default, so no backfill UPDATE is required.
