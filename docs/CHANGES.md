# Changes — Travel Orders

## Run this first (existing installs only)

```sql
-- in phpMyAdmin, against the network_support database
SOURCE database/migration_travel_order.sql;
```

Fresh installs don't need the migration; `schema.sql` and
`network_support.sql` already carry the new `events.travel_order_*` columns.

## What's new

Every support event now tracks a Travel Order — the document authorizing
the assigned technician(s) to travel to the venue — right on the event
itself:

- `events.travel_order_status` is `Not Created`, `Ongoing`, or `Completed`.
  No new table: the lifecycle lives on the event row, and the printable
  document is assembled on the fly from the event plus its assigned
  technicians.
- **New page `travel_order.php`.** Pick a support request, see its Travel
  Order status at a glance (so nobody generates a duplicate for a request
  that already has one on file), and — for Admins/Super Admins — generate
  one once technicians are assigned, print it, mark it completed, or reset
  it back to Not Created.
- The Travel Order lists every currently assigned technician with their
  position/designation, the purpose (event title + category), destination
  (venue), inclusive dates, and signature lines for the approving officer
  and the requesting office head. It prints cleanly (nav/buttons hidden via
  `@media print`).
- `event.php` and `events.php` now show a Travel Order status badge that
  links straight into `travel_order.php` for that event.
- Generating a Travel Order requires at least one technician assigned to
  the event first; the button is disabled and a pointer to Assignments is
  shown otherwise.

---

# Changes — location fix, UI rework, mobile

## 1. Run this first

```sql
-- in phpMyAdmin, against the network_support database
SOURCE database/migration_location.sql;
```

It adds `events.latitude` / `events.longitude`, repairs two misspelled
barangays, and backfills coordinates from the corrected names.

At the end it prints two lists. The second one — rows still without
coordinates — needs a person to open each request and pick a barangay.
Expect any `Nabalbagan` rows to appear there (see below).

Fresh installs don't need the migration; `schema.sql` and
`network_support.sql` already carry the new columns.

---

## 2. What was wrong with the location feature

**The barangay list was partly fictional.** Bayawan has 28 barangays. The
app listed three that do not exist there — `Banago`, `Nabalbagan`,
`San Miniato` — and was missing three that do: `Banaybanay`, `San Isidro`,
`San Miguel`.

**Every coordinate was wrong.** The real city runs from Villareal on the
coast (lat 9.3567) up to Mandu-ao in the uplands (9.6772) — about 36 km.
The old table squeezed all 28 points into a box roughly a third that size,
so upland barangays were plotted tens of kilometres from where they are.
Bugay was stored at 9.4520, 122.7120; it is actually at 9.6304, 122.8344,
roughly 20 km away.

**The list existed twice and disagreed with itself.** `map.php` and
`event_form.php` each carried their own copy. Minaba was 9.4200 in one and
9.4600 in the other; Villasol was 9.5080 vs 9.3920. The pin you placed on
the form was not the pin you saw on the map.

**The draggable pin was decorative.** There was no coordinate column, so
dragging the marker updated a label on screen and then threw the position
away on save.

**Unmatched locations were hidden, not reported.** Anything the matcher
missed silently fell back to the city centre, so bad records looked like
valid pins and stacked on top of each other where only the topmost was
clickable.

### What changed

- `partials/barangays.php` — **new, single source of truth.** The correct
  28 barangays with PSA/PhilAtlas centroids, plus a normalizer and
  resolver. Do not re-declare coordinates anywhere else.
- `map.php` — rebuilt on the shared data. A saved pin wins over the
  barangay centroid. Unresolvable rows render as hollow "no location set"
  markers with a banner count, instead of pretending to be real sites.
  Identical coordinates fan out on a small spiral — Poblacion, Suba,
  Tinago, Ubos and Boyco all sit inside one square kilometre and used to
  hide each other. Clicking a row in the side list flies the map to it.
- `event_form.php` — the pin now saves. Added tap-to-place for phones, a
  bounding-box check so a stray drag can't send a crew to another
  province, and an `invalidateSize()` call that fixes the grey-tile
  problem when Leaflet initialises inside a form that is still laying out.
- `database/migration_location.sql` — schema change, name repairs,
  coordinate backfill.

### A deliberate non-fix

`Banago` → `Banaybanay` and `San Miniato` → `San Miguel` are handled as
aliases, since those are plainly typos.

`Nabalbagan` is **not** remapped. It is a barangay of Valencia, not
Bayawan, and there is no defensible target. Those rows are reported by
the migration and flagged on the map so someone can correct them by hand.
Guessing a location for a dispatched crew is worse than admitting the
record is broken.

---

## 3. Mobile

Below 992px the old stylesheet set the sidebar to `width: 100%`. That
turned the rail into a full-width block stacked *above* the content, so
every page opened on a wall of navigation links and the toggle's only
other option was to hide the nav entirely.

Now:

- The rail becomes an off-canvas drawer with a scrim, Escape to close,
  and focus moved into the drawer on open. Drawer state is never
  persisted — nobody wants to land on a page with the menu covering it.
- A bottom dock carries the five destinations someone actually needs
  while standing in a barangay hall. It is role-aware: technicians get
  Home / Tasks / Map / Report / Alerts, coordinators get Home / Events /
  Map / Crews / Alerts.
- The desktop icon-only collapse state no longer leaks into the drawer.
- Wide tables keep horizontal scrolling but now show scroll shadows, so
  it is visible that there is more to the right rather than guessed at.
- Touch targets are 40–46px, safe-area insets are respected, and
  `prefers-reduced-motion` is honoured.

---

## 4. Visual design

`assets/app.css` was rewritten. It carries the redesign across all pages
without touching the individual page files, so the page markup is
unchanged apart from `map.php`, `event_form.php` and `login.php`.

- Palette moved off the stock indigo-SaaS blue to sea-ink chrome with a
  teal signal colour, warm amber for work that is waiting on someone, and
  rust for urgent. Each colour means one thing on the board.
- Archivo replaces Plus Jakarta Sans — it holds up better at small sizes
  on a phone in daylight. Times, counts and coordinates use tabular
  numerals so they align in columns.
- Status and priority read as a left edge rule rather than colour alone.
- All-caps section labels and the gradient wordmark are gone.
- `login.php` opens on a plot of the real barangay coordinates — coast at
  the bottom, uplands at the top — drawn from the same table the dispatch
  map uses.

Old class names (`.stat-card`, `.app-table`, `.badge-soft-*`,
`--radius-md`, etc.) still resolve, so nothing else needs editing.

---

## 5. Before you trust it

There was no PHP runtime available where this was edited, so `php -l`
was never run. Tag balance was checked and the resolver logic was ported
and tested against 19 real and edge-case location strings, but load each
page once locally before deploying.

Worth checking by hand:

- `map.php` with filters applied and with a technician account
- `event_form.php` in both create and edit mode, including dragging the pin,
  saving, and reopening to confirm the pin came back
- the drawer and dock on a real phone, not just a narrow desktop window
