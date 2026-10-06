<?php
require_once 'config.php';
require_once __DIR__ . '/partials/barangays.php';
require_once __DIR__ . '/sms.php';
allow(['Super Administrator', 'Administrator']);

// The checkbox on this form just records whether HR has already issued the
// Travel Order for the request — this app doesn't generate the paper
// document itself. Toggling it on/off keeps events.travel_order_status in
// sync without touching anything an admin may have already set via the
// dedicated Travel Order screen.
function sync_travel_order_flag(int $eventId, bool $provided, ?string $existingNo, ?int $existingCreatedBy, ?string $existingCreatedAt): void {
    if ($provided) {
        $toNo = $existingNo ?: ('TO-' . date('Y') . '-' . str_pad((string)$eventId, 4, '0', STR_PAD_LEFT));
        $createdBy = $existingCreatedBy ?: user()['id'];
        $createdAt = $existingCreatedAt ?: date('Y-m-d H:i:s');
        db()->prepare('UPDATE events SET travel_order_status = "Completed", travel_order_no = ?, travel_order_created_by = ?, travel_order_created_at = ?, travel_order_completed_at = NOW() WHERE id = ?')
            ->execute([$toNo, $createdBy, $createdAt, $eventId]);
        log_activity("Marked Travel Order as provided by HR ($toNo)", $eventId);
    } else {
        db()->prepare('UPDATE events SET travel_order_status = "Not Created", travel_order_no = NULL, travel_order_created_by = NULL, travel_order_created_at = NULL, travel_order_completed_at = NULL WHERE id = ?')
            ->execute([$eventId]);
        log_activity('Cleared Travel Order flag', $eventId);
    }
}

$eventId = (int)($_GET['id'] ?? 0);
$eventData = null;

if ($eventId > 0) {
    $q = db()->prepare('SELECT * FROM events WHERE id = ?');
    $q->execute([$eventId]);
    $eventData = $q->fetch();
}

$isEdit = $eventData !== false && $eventData !== null;

$activeUsers = db()->query("SELECT u.id, u.full_name, r.name role_name, COALESCE(ta.availability, 'Available') availability
                            FROM users u
                            JOIN roles r ON r.id = u.role_id
                            LEFT JOIN technician_availability ta ON ta.technician_id = u.id
                            WHERE u.status = 'Active'
                            ORDER BY u.full_name")->fetchAll();
$activeUsersById = [];
foreach ($activeUsers as $staffMember) {
    $activeUsersById[(int)$staffMember['id']] = $staffMember;
}
$formError = '';
$selectedTeamLeadId = 0;
$selectedCrewIds = [];
$overrideTeamConflicts = false;

// Handle Location Parsing for Edit Mode
$brgyVal = 'Brgy. Poblacion, Bayawan City';
$venueVal = '';
$pinLat = null;
$pinLng = null;

if ($isEdit && !empty($eventData['location'])) {
    [$brgyPart, $venuePart] = bayawan_split_location($eventData['location']);
    if ($venuePart !== '') {
        $brgyVal  = $brgyPart;
        $venueVal = $venuePart;
    } else {
        // No separator stored: treat the whole string as the venue and try to
        // recover a barangay from it rather than assuming Poblacion.
        $venueVal = $eventData['location'];
        $guess    = bayawan_resolve($eventData['location']);
        $brgyVal  = $guess['matched'] ? bayawan_label($guess['name']) : 'Brgy. Poblacion, Bayawan City';
    }
    if (isset($eventData['latitude'], $eventData['longitude'])
        && $eventData['latitude'] !== null && $eventData['longitude'] !== null) {
        $pinLat = (float)$eventData['latitude'];
        $pinLng = (float)$eventData['longitude'];
    }
}

// Handle POST BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $titleIn = trim($_POST['title'] ?? '');
    if ($titleIn === '') {
        exit('Event title is required.');
    }

    $travelOrderProvided = isset($_POST['travel_order_provided']);
    
    $brgy = trim($_POST['barangay'] ?? '');
    $venueDetail = trim($_POST['venue_detail'] ?? $_POST['location'] ?? '');
    $locationCombined = ($brgy && $brgy !== 'Main Office / Non-Barangay') ? ($brgy . ' — ' . $venueDetail) : $venueDetail;
    $editId = (int)($_POST['event_id'] ?? 0);

    if ($editId === 0) {
        $selectedTeamLeadId = max(0, (int)($_POST['team_lead_id'] ?? 0));
        $rawCrewIds = $_POST['crew_ids'] ?? [];
        if (!is_array($rawCrewIds)) $rawCrewIds = [];
        $selectedCrewIds = array_values(array_unique(array_filter(array_map('intval', $rawCrewIds), static fn($id) => $id > 0)));
        $overrideTeamConflicts = isset($_POST['override_team_conflicts']);

        if ($selectedTeamLeadId > 0 && !isset($activeUsersById[$selectedTeamLeadId])) {
            $formError = 'The selected team lead is not an active user account.';
        }
        foreach ($selectedCrewIds as $crewId) {
            if (!isset($activeUsersById[$crewId])) {
                $formError = 'One or more selected crew members are not active user accounts.';
                break;
            }
        }
        if ($formError === '' && $selectedTeamLeadId > 0 && !in_array($selectedTeamLeadId, $selectedCrewIds, true)) {
            $selectedCrewIds[] = $selectedTeamLeadId;
        }

        $teamWarnings = [];
        if ($formError === '' && $selectedCrewIds) {
            $conflictCheck = db()->prepare('SELECT COUNT(*) FROM event_assignments a
                                            JOIN events e ON e.id = a.event_id
                                            WHERE a.technician_id = ?
                                              AND e.start_at < ? AND e.end_at > ?
                                              AND a.status NOT IN ("Completed", "Cancelled")');
            foreach ($selectedCrewIds as $crewId) {
                $staffMember = $activeUsersById[$crewId];
                if ($staffMember['availability'] === 'On Leave') {
                    $formError = 'Crew member ' . $staffMember['full_name'] . ' is marked On Leave and cannot be assigned.';
                    break;
                }
                if (in_array($staffMember['availability'], ['Busy', 'Unavailable'], true)) {
                    $teamWarnings[] = $staffMember['full_name'] . ' is marked ' . $staffMember['availability'];
                }
                $conflictCheck->execute([$crewId, $_POST['end_at'] ?? '', $_POST['start_at'] ?? '']);
                if ((int)$conflictCheck->fetchColumn() > 0) {
                    $teamWarnings[] = $staffMember['full_name'] . ' has an overlapping assignment';
                }
            }
            if ($formError === '' && $teamWarnings && !$overrideTeamConflicts) {
                $formError = 'Please review these crew warnings and confirm the authorized override: ' . implode('; ', $teamWarnings) . '.';
            }
        }
    }

    // The pin is draggable, so store where it actually ended up. Fall back to
    // the barangay centroid when the pin was never moved, and reject anything
    // outside the Bayawan bounding box so a stray drag can't send a crew to
    // another province.
    $lat = isset($_POST['pin_lat']) && $_POST['pin_lat'] !== '' ? (float)$_POST['pin_lat'] : null;
    $lng = isset($_POST['pin_lng']) && $_POST['pin_lng'] !== '' ? (float)$_POST['pin_lng'] : null;

    $inBayawan = $lat !== null && $lng !== null
        && $lat >= 9.28 && $lat <= 9.76
        && $lng >= 122.64 && $lng <= 123.02;

    if (!$inBayawan) {
        $geo = bayawan_resolve($brgy);
        $lat = $geo['matched'] ? $geo['lat'] : null;
        $lng = $geo['matched'] ? $geo['lng'] : null;
    }

    if ($formError === '' && $editId > 0) {
        $q = db()->prepare('UPDATE events 
                            SET title = ?, description = ?, office_id = ?, category = ?, location = ?, latitude = ?, longitude = ?, start_at = ?, end_at = ?, priority = ?, participants = ?, bandwidth_mbps = ?, primary_connection = ?, backup_connection = ?, notes = ? 
                            WHERE id = ?');
        $q->execute([
            $titleIn,
            trim($_POST['description'] ?? ''),
            $_POST['office_id'],
            $_POST['category'],
            $locationCombined,
            $lat,
            $lng,
            $_POST['start_at'],
            $_POST['end_at'],
            $_POST['priority'],
            (int)($_POST['participants'] ?? 0),
            (int)($_POST['bandwidth_mbps'] ?? 0),
            trim($_POST['primary_connection'] ?? ''),
            trim($_POST['backup_connection'] ?? ''),
            trim($_POST['notes'] ?? ''),
            $editId
        ]);
        log_activity("Updated event #$editId", $editId);

        $wasProvided = in_array($eventData['travel_order_status'], ['Ongoing', 'Completed'], true);
        if ($travelOrderProvided !== $wasProvided) {
            sync_travel_order_flag(
                $editId,
                $travelOrderProvided,
                $eventData['travel_order_no'],
                $eventData['travel_order_created_by'] !== null ? (int)$eventData['travel_order_created_by'] : null,
                $eventData['travel_order_created_at']
            );
        }

        header('Location: event.php?id=' . $editId);
        exit;
    } elseif ($formError === '') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $initialStatus = $selectedCrewIds ? 'Assigned' : 'New';
            // Use one database-generated timestamp for the event and its
            // initial timeline record so every new event has a live entry.
            $q = $pdo->prepare('INSERT INTO events(title, description, office_id, category, location, latitude, longitude, start_at, end_at, priority, participants, bandwidth_mbps, primary_connection, backup_connection, notes, created_by, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $q->execute([
                $titleIn,
                trim($_POST['description'] ?? ''),
                $_POST['office_id'],
                $_POST['category'],
                $locationCombined,
                $lat,
                $lng,
                $_POST['start_at'],
                $_POST['end_at'],
                $_POST['priority'],
                (int)($_POST['participants'] ?? 0),
                (int)($_POST['bandwidth_mbps'] ?? 0),
                trim($_POST['primary_connection'] ?? ''),
                trim($_POST['backup_connection'] ?? ''),
                trim($_POST['notes'] ?? ''),
                user()['id'],
                $initialStatus
            ]);

            $id = (int)$pdo->lastInsertId();
            log_activity('Created event', $id);

            if ($selectedCrewIds) {
                $insertAssignment = $pdo->prepare('INSERT INTO event_assignments(event_id, technician_id, is_team_lead, assigned_by, status)
                                                   VALUES (?, ?, ?, ?, "New Assignment")');
                $insertNotification = $pdo->prepare('INSERT INTO notifications(user_id, event_id, message)
                                                     VALUES (?, ?, ?)');
                foreach ($selectedCrewIds as $crewId) {
                    $insertAssignment->execute([$id, $crewId, $crewId === $selectedTeamLeadId ? 1 : 0, user()['id']]);
                    $insertNotification->execute([$crewId, $id, 'You have been assigned to support a new network event.']);
                }
                log_activity('Assigned technician(s)', $id);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        foreach ($selectedCrewIds as $crewId) {
            try {
                sms_assignment_reminder($crewId, $id);
            } catch (Throwable $smsError) {
                error_log('Could not send an assignment SMS reminder; event creation was saved.');
            }
        }

        if ($travelOrderProvided) {
            sync_travel_order_flag($id, true, null, null, null);
        }

        header('Location: event.php?id=' . $id);
        exit;
    }
}

// Load supporting data for the form
$offices    = db()->query('SELECT * FROM offices ORDER BY name')->fetchAll();
$categories = db()->query('SELECT name FROM event_categories ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);

// If the event being edited holds a category that has since been renamed or
// deleted from Administration → Categories, keep it selectable so saving the
// form doesn't silently rewrite the event's category out from under someone.
// It's tagged so whoever is editing knows to switch it.
$categoryIsOrphan = false;
if ($isEdit && !empty($eventData['category']) && !in_array($eventData['category'], $categories, true)) {
    $categoryIsOrphan = true;
    $categories[] = $eventData['category'];
}

// Selectable locations come from partials/barangays.php — the single source
// of truth. Do not re-declare coordinates here.
$bayawanBarangayGPS = [];
foreach (bayawan_locations() as $bName => $geo) {
    $bayawanBarangayGPS[bayawan_label($bName)] = ['lat' => $geo['lat'], 'lng' => $geo['lng'], 'zone' => $geo['zone']];
}

$selectedTeamLeadLabel = '';
if ($selectedTeamLeadId > 0 && isset($activeUsersById[$selectedTeamLeadId])) {
    $lead = $activeUsersById[$selectedTeamLeadId];
    $selectedTeamLeadLabel = $lead['full_name'] . ' — ' . $lead['role_name'];
}

$title = $isEdit ? 'Edit Event Request #' . $eventData['id'] : 'New Support Request';
require 'partials/header.php';
?>

<!-- Leaflet CSS & JS for Form Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1"><?= $isEdit ? 'Edit Support Event #' . $eventData['id'] : 'Create Support Request' ?></h3>
        <p class="text-secondary small mb-0"><?= $isEdit ? 'Update event parameters, venue location, or connectivity requirements.' : 'Fill out event requirements to schedule network and technical support.' ?></p>
    </div>
    <a href="<?= $isEdit ? 'event.php?id=' . $eventData['id'] : 'events.php' ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Cancel & Return
    </a>
</div>

<?php if ($formError !== ''): ?>
    <div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= h($formError) ?></div>
<?php endif; ?>

<form method="post" class="card stat-card p-4 border-0 mb-4">
    <input type="hidden" name="csrf" value="<?= csrf() ?>">
    <input type="hidden" name="event_id" value="<?= $isEdit ? $eventData['id'] : 0 ?>">
    
    <!-- Section 1: Event Info -->
    <h6 class="fw-bold text-primary small mb-3">
        <i class="bi bi-info-circle me-1"></i> General Event Information
    </h6>
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <label class="form-label small fw-semibold">Event Title *</label>
            <input class="form-control" name="title" required value="<?= $isEdit ? h($eventData['title']) : h($_POST['title'] ?? '') ?>" placeholder="e.g. PhilHealth Go-to-Brgy Registration Support">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Requesting Office *</label>
            <select class="form-select" name="office_id" required>
                <?php foreach ($offices as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= ($isEdit && (int)$eventData['office_id'] === (int)$o['id']) || (!$isEdit && (int)($_POST['office_id'] ?? 0) === (int)$o['id']) ? 'selected' : '' ?>><?= h($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Category</label>
            <select class="form-select" name="category">
                <?php foreach ($categories as $v): ?>
                    <option value="<?= h($v) ?>" <?= ($isEdit && $eventData['category'] === $v) || (!$isEdit && ($_POST['category'] ?? '') === $v) ? 'selected' : '' ?>>
                        <?= h($v) ?><?= $categoryIsOrphan && $eventData['category'] === $v ? ' (no longer in the list — pick another)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isEdit && empty($categories)): ?>
                <p class="small text-muted mt-1 mb-0">No categories set up yet — an administrator can add some under Administration → Categories.</p>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Priority Level</label>
            <select class="form-select" name="priority">
                <?php foreach (['Normal', 'Low', 'High', 'Urgent'] as $p): ?>
                    <option <?= ($isEdit && $eventData['priority'] === $p) || (!$isEdit && ($_POST['priority'] ?? 'Normal') === $p) ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Expected Attendees</label>
            <input class="form-control" type="number" min="0" name="participants" value="<?= $isEdit ? (int)$eventData['participants'] : h($_POST['participants'] ?? 50) ?>">
        </div>
    </div>

    <!-- Section 2: Date & Location Picker -->
    <h6 class="fw-bold text-primary small mb-3 border-top pt-3">
        <i class="bi bi-geo-alt me-1"></i> Schedule & Location
    </h6>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label small fw-semibold">Start Time *</label>
            <input class="form-control" type="datetime-local" name="start_at" required value="<?= $isEdit ? date('Y-m-d\TH:i', strtotime($eventData['start_at'])) : h($_POST['start_at'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-semibold">End Time *</label>
            <input class="form-control" type="datetime-local" name="end_at" required value="<?= $isEdit ? date('Y-m-d\TH:i', strtotime($eventData['end_at'])) : h($_POST['end_at'] ?? '') ?>">
        </div>

        <!-- Barangay Selection Trigger Box -->
        <div class="col-md-5">
            <label class="form-label small fw-semibold">Target Barangay (Bayawan City) *</label>
            <div class="input-group">
                <input type="hidden" name="pin_lat" id="pinLat" value="<?= isset($_POST['pin_lat']) ? h((string)$_POST['pin_lat']) : ($pinLat !== null ? h((string)$pinLat) : '') ?>">
                <input type="hidden" name="pin_lng" id="pinLng" value="<?= isset($_POST['pin_lng']) ? h((string)$_POST['pin_lng']) : ($pinLng !== null ? h((string)$pinLng) : '') ?>">
                <input class="form-control bg-light fw-bold text-primary" id="selectedBrgyInput" name="barangay" required value="<?= h($_POST['barangay'] ?? $brgyVal) ?>" readonly>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#brgyPickerModal">
                    <i class="bi bi-geo-alt-fill me-1"></i> Choose
                </button>
            </div>
        </div>

        <div class="col-md-7">
            <label class="form-label small fw-semibold">Specific Venue / Building Detail *</label>
            <input class="form-control" name="venue_detail" required value="<?= h($_POST['venue_detail'] ?? $venueVal) ?>" placeholder="e.g. Barangay Hall / Covered Court / PhilHealth Outreach Site">
        </div>
    </div>

    <!-- Integrated Interactive GIS Location Map Pin -->
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label small fw-semibold mb-0"><i class="bi bi-pin-map-fill text-danger me-1"></i> Integrated Barangay Location Map Pin</label>
            <span class="small text-muted" id="mapPinCoordsLabel">Coordinates: Bayawan City Center</span>
        </div>
        <div id="formMap" style="height: 230px; width: 100%; border-radius: var(--radius-md); border: 1px solid #e2e8f0;"></div>
    </div>

    <!-- Section 3: Connectivity Specs -->
    <h6 class="fw-bold text-primary small mb-3 border-top pt-3">
        <i class="bi bi-wifi me-1"></i> Network Requirements
    </h6>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Bandwidth Requirement (Mbps)</label>
            <input class="form-control" type="number" min="0" name="bandwidth_mbps" value="<?= $isEdit ? (int)$eventData['bandwidth_mbps'] : h($_POST['bandwidth_mbps'] ?? 100) ?>" placeholder="100">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Primary Connection Type</label>
            <input class="form-control" name="primary_connection" value="<?= $isEdit ? h($eventData['primary_connection']) : h($_POST['primary_connection'] ?? '') ?>" placeholder="Starlink Satellite / 5G Mobile Router / Fiber">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-semibold">Backup Connection Type</label>
            <input class="form-control" name="backup_connection" value="<?= $isEdit ? h($eventData['backup_connection']) : h($_POST['backup_connection'] ?? '') ?>" placeholder="5G Cellular Router / Backup ISP">
        </div>
    </div>

    <!-- Section 4: Details & Notes -->
    <h6 class="fw-bold text-primary small mb-3 border-top pt-3">
        <i class="bi bi-file-text me-1"></i> Detailed Description
    </h6>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <label class="form-label small fw-semibold">Scope & Description</label>
            <textarea class="form-control" name="description" rows="3" placeholder="Describe network configuration requirements, video conferencing setup, etc..."><?= $isEdit ? h($eventData['description']) : h($_POST['description'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
            <label class="form-label small fw-semibold">Special Instructions / Technical Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="Access codes, specialized router configurations, extra cables needed..."><?= $isEdit ? h($eventData['notes']) : h($_POST['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <?php if (!$isEdit): ?>
    <!-- Section 5: Optional crew assignment -->
    <h6 class="fw-bold text-primary small mb-3 border-top pt-3">
        <i class="bi bi-people me-1"></i> Assign Team (Optional)
    </h6>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label small fw-semibold" for="teamLeadSelect">Team Lead</label>
            <input type="hidden" id="teamLeadSelect" name="team_lead_id" value="<?= $selectedTeamLeadId ?>">
            <input class="form-control" type="search" id="teamLeadSearch" value="<?= h($selectedTeamLeadLabel) ?>" placeholder="Search team lead by name or role" aria-label="Search team lead" autocomplete="off">
            <div class="list-group mt-1 shadow-sm" id="teamLeadResults" role="listbox" style="max-height: 220px; overflow-y: auto; display: none;">
                <button class="list-group-item list-group-item-action text-start" type="button" data-lead-id="0" data-lead-label="">No team lead selected</button>
                <?php foreach ($activeUsers as $staffMember): ?>
                    <button class="list-group-item list-group-item-action text-start d-flex justify-content-between align-items-center" type="button" data-lead-id="<?= (int)$staffMember['id'] ?>" data-lead-label="<?= h($staffMember['full_name'] . ' — ' . $staffMember['role_name']) ?>" data-search="<?= h(strtolower($staffMember['full_name'] . ' ' . $staffMember['role_name'])) ?>" <?= $staffMember['availability'] === 'On Leave' ? 'disabled' : '' ?>>
                        <span><?= h($staffMember['full_name']) ?><small class="d-block text-muted"><?= h($staffMember['role_name']) ?></small></span>
                        <small class="text-muted"><?= h($staffMember['availability']) ?></small>
                    </button>
                <?php endforeach; ?>
                <div class="list-group-item small text-muted" id="teamLeadNoResults" hidden>No matching user found.</div>
            </div>
            <div class="form-text">Searches all active user accounts, including Admin and Super Admin. Choose crew members individually; the selected lead is added to the assignment automatically and can accept for the crew.</div>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-semibold" for="crewSearch">Crew Members</label>
            <input class="form-control mb-2" type="search" id="crewSearch" placeholder="Search any active user by name or role">
            <div class="border rounded-3 p-2" id="crewChoices" style="max-height: 220px; overflow-y: auto;">
                <?php foreach ($activeUsers as $staffMember): $staffId = (int)$staffMember['id']; ?>
                    <label class="d-flex align-items-center gap-2 p-2 border-bottom crew-choice" data-staff-name="<?= h(strtolower($staffMember['full_name'] . ' ' . $staffMember['role_name'])) ?>">
                        <input class="form-check-input mt-0" type="checkbox" name="crew_ids[]" value="<?= $staffId ?>" <?= in_array($staffId, $selectedCrewIds, true) ? 'checked' : '' ?> <?= $staffMember['availability'] === 'On Leave' ? 'disabled' : '' ?>>
                        <span class="flex-grow-1"><strong><?= h($staffMember['full_name']) ?></strong><small class="d-block text-muted"><?= h($staffMember['role_name']) ?> · <?= h($staffMember['availability']) ?></small></span>
                        <?php if (in_array($staffMember['availability'], ['Busy', 'Unavailable'], true)): ?><span class="badge text-bg-warning">Check schedule</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$activeUsers): ?><p class="text-muted small mb-0 p-2">No active user accounts are available.</p><?php endif; ?>
            </div>
        </div>
        <div class="col-12">
            <div class="form-check p-3 bg-warning-subtle rounded-3 border">
                <input class="form-check-input" type="checkbox" name="override_team_conflicts" id="overrideTeamConflicts" <?= $overrideTeamConflicts ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="overrideTeamConflicts">Confirm assignment if selected staff are busy or have an overlapping event</label>
                <div class="form-text">On-leave staff cannot be assigned. Conflict warnings are checked when you submit.</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section 6: Travel Order -->
    <h6 class="fw-bold text-primary small mb-3 border-top pt-3">
        <i class="bi bi-signpost-split me-1"></i> Travel Order
    </h6>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="form-check p-3 bg-light rounded-3 border">
                <input class="form-check-input" type="checkbox" name="travel_order_provided" id="travelOrderProvided"
                       <?= ($isEdit && in_array($eventData['travel_order_status'], ['Ongoing', 'Completed'], true)) || (!$isEdit && isset($_POST['travel_order_provided'])) ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold text-dark" for="travelOrderProvided">
                    Travel Order provided / created by HR
                </label>
                <p class="small text-muted mb-0 mt-1">Check this if HR has issued the Travel Order authorizing travel for this request. When checked, the event will display <strong>Travel Order Created</strong>.</p>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 pt-2 border-top">
        <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Save Changes' : 'Submit Support Request' ?></button>
        <a class="btn btn-light border px-4" href="<?= $isEdit ? 'event.php?id=' . $eventData['id'] : 'events.php' ?>">Cancel</a>
    </div>
</form>

<!-- CENTERED MODAL POPUP FOR BARANGAY SELECTION -->
<div class="modal fade" id="brgyPickerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-lg);">
            <div class="modal-header border-bottom py-3 bg-primary-subtle text-primary">
                <h5 class="modal-title fw-bold fs-6 mb-0">
                    <i class="bi bi-geo-alt-fill me-2"></i> Select Barangay in Bayawan City
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Realtime Search Input inside Modal -->
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-primary"></i></span>
                        <input type="text" id="modalBrgySearch" class="form-control border-start-0 fs-6" placeholder="Type to search Barangay name (e.g. Poblacion, San Jose, Villasol)...">
                    </div>
                </div>

                <!-- Grid of 28 Bayawan City Barangays -->
                <div class="row g-2" id="brgyGridContainer" style="max-height: 380px; overflow-y: auto;">
                    <?php foreach ($bayawanBarangayGPS as $bName => $coords): ?>
                        <div class="col-md-4 col-6 brgy-item-col" data-name="<?= strtolower($bName) ?>">
                            <button type="button" class="btn btn-light border w-100 text-start p-3 hover-primary rounded-3 brgy-select-btn"
                                    data-brgy="<?= h($bName) ?>"
                                    data-lat="<?= $coords['lat'] ?>"
                                    data-lng="<?= $coords['lng'] ?>">
                                <div class="fw-bold small text-dark mb-1"><i class="bi bi-pin-map text-danger me-1"></i> <?= h(explode(',', $bName)[0]) ?></div>
                                <div class="small text-muted font-monospace" style="font-size: 0.72rem;">Bayawan City</div>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-footer border-top bg-light py-2">
                <span class="small text-secondary me-auto">28 Barangays of Bayawan City available</span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- LEAFLET MAP & MODAL JS INTEGRATION -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var gpsData = <?= json_encode($bayawanBarangayGPS) ?>;
    var initialBrgy = <?= json_encode($_POST['barangay'] ?? $brgyVal) ?>;

    var crewSearch = document.getElementById('crewSearch');
    if (crewSearch) {
        crewSearch.addEventListener('input', function() {
            var term = this.value.toLowerCase().trim();
            document.querySelectorAll('.crew-choice').forEach(function(choice) {
                var matches = choice.getAttribute('data-staff-name').includes(term);
                choice.style.setProperty('display', matches ? 'flex' : 'none', 'important');
            });
        });
    }

    var teamLeadSelect = document.getElementById('teamLeadSelect');
    var teamLeadSearch = document.getElementById('teamLeadSearch');
    var teamLeadResults = document.getElementById('teamLeadResults');
    var teamLeadOptions = teamLeadResults ? teamLeadResults.querySelectorAll('[data-lead-id]') : [];
    function filterTeamLeads() {
        if (!teamLeadSearch || !teamLeadSelect || !teamLeadResults) return;
        var term = teamLeadSearch.value.toLowerCase().trim();
        var variants = [];
        for (var i = 0; i < term.length - 1; i++) {
            var chars = term.split('');
            var temp = chars[i];
            chars[i] = chars[i + 1];
            chars[i + 1] = temp;
            variants.push(chars.join(''));
        }
        var visibleMatches = 0;
        teamLeadOptions.forEach(function(option) {
            if (option.getAttribute('data-lead-id') === '0') {
                option.style.setProperty('display', term === '' ? 'block' : 'none', 'important');
                return;
            }
            var searchText = option.getAttribute('data-search') || '';
            var matches = term === '' || searchText.includes(term) || variants.some(function(variant) { return searchText.includes(variant); });
            option.style.setProperty('display', matches ? 'flex' : 'none', 'important');
            if (matches && !option.disabled) visibleMatches++;
        });
        var noResults = document.getElementById('teamLeadNoResults');
        if (noResults) noResults.style.setProperty('display', term === '' || visibleMatches > 0 ? 'none' : 'block', 'important');
        teamLeadResults.style.display = 'block';
    }
    if (teamLeadSearch && teamLeadSelect && teamLeadResults) {
        teamLeadSearch.addEventListener('focus', filterTeamLeads);
        teamLeadSearch.addEventListener('input', function() {
            teamLeadSelect.value = '0';
            filterTeamLeads();
        });
        teamLeadOptions.forEach(function(option) {
            option.addEventListener('click', function() {
                teamLeadSelect.value = this.getAttribute('data-lead-id');
                teamLeadSearch.value = this.getAttribute('data-lead-label') || '';
                teamLeadResults.style.display = 'none';
            });
        });
        document.addEventListener('click', function(event) {
            if (!teamLeadResults.contains(event.target) && event.target !== teamLeadSearch) {
                teamLeadResults.style.display = 'none';
            }
        });
    }
    var latField = document.getElementById('pinLat');
    var lngField = document.getElementById('pinLng');

    // A saved pin wins over the barangay centroid: someone put it there on purpose.
    var defaultLat = <?= isset($_POST['pin_lat']) && $_POST['pin_lat'] !== '' ? json_encode((float)$_POST['pin_lat']) : ($pinLat !== null ? json_encode($pinLat) : 'null') ?>;
    var defaultLng = <?= isset($_POST['pin_lng']) && $_POST['pin_lng'] !== '' ? json_encode((float)$_POST['pin_lng']) : ($pinLng !== null ? json_encode($pinLng) : 'null') ?>;

    if (defaultLat === null || defaultLng === null) {
        if (gpsData[initialBrgy]) {
            defaultLat = gpsData[initialBrgy].lat;
            defaultLng = gpsData[initialBrgy].lng;
        } else {
            defaultLat = 9.3654;
            defaultLng = 122.8051;
        }
    }

    // 1. Initialize Interactive Form Map
    var formMap = L.map('formMap').setView([defaultLat, defaultLng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(formMap);

    var pinMarker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(formMap);
    
    function storeCoords(lat, lng) {
        if (latField) latField.value = lat.toFixed(6);
        if (lngField) lngField.value = lng.toFixed(6);
    }

    function updateMarker(lat, lng, name) {
        pinMarker.setLatLng([lat, lng]);
        formMap.setView([lat, lng], 14);
        pinMarker.bindPopup("<b>" + name + "</b><br>Drag the pin to the exact venue").openPopup();
        document.getElementById('mapPinCoordsLabel').innerText = lat.toFixed(4) + ", " + lng.toFixed(4);
        storeCoords(lat, lng);
    }

    updateMarker(defaultLat, defaultLng, initialBrgy);

    // 2. Real-time Barangay Search in Modal
    var searchInput = document.getElementById('modalBrgySearch');
    searchInput.addEventListener('input', function() {
        var term = this.value.toLowerCase().trim();
        var cols = document.querySelectorAll('.brgy-item-col');
        cols.forEach(function(col) {
            var name = col.getAttribute('data-name');
            if (name.includes(term)) {
                col.style.display = 'block';
            } else {
                col.style.display = 'none';
            }
        });
    });

    // 3. Handle Barangay Selection from Modal Buttons
    var selectBtns = document.querySelectorAll('.brgy-select-btn');

    function highlightActiveBtn(name) {
        selectBtns.forEach(function(b) {
            b.classList.toggle('brgy-active', b.getAttribute('data-brgy') === name);
        });
    }

    // Highlight on page load
    highlightActiveBtn(initialBrgy);

    selectBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var selectedName = this.getAttribute('data-brgy');
            var lat = parseFloat(this.getAttribute('data-lat'));
            var lng = parseFloat(this.getAttribute('data-lng'));

            // Update Form Input
            document.getElementById('selectedBrgyInput').value = selectedName;

            // Highlight active button
            highlightActiveBtn(selectedName);

            // Update Map Pin
            updateMarker(lat, lng, selectedName);

            // Close Modal
            var modalEl = document.getElementById('brgyPickerModal');
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) {
                modal.hide();
            }
        });
    });

    // Reset search filter when modal reopens
    document.getElementById('brgyPickerModal').addEventListener('show.bs.modal', function() {
        var searchInput = document.getElementById('modalBrgySearch');
        searchInput.value = '';
        document.querySelectorAll('.brgy-item-col').forEach(function(col) {
            col.style.display = 'block';
        });
        // Scroll active item into view
        var active = document.querySelector('.brgy-select-btn.brgy-active');
        if (active) active.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });

    // Handle marker drag end
    pinMarker.on('dragend', function(e) {
        var coord = e.target.getLatLng();
        document.getElementById('mapPinCoordsLabel').innerText = coord.lat.toFixed(4) + ", " + coord.lng.toFixed(4) + " (moved)";
        storeCoords(coord.lat, coord.lng);
    });

    // Tapping the map also sets the pin — easier than dragging on a phone.
    formMap.on('click', function (e) {
        pinMarker.setLatLng(e.latlng);
        document.getElementById('mapPinCoordsLabel').innerText = e.latlng.lat.toFixed(4) + ", " + e.latlng.lng.toFixed(4) + " (moved)";
        storeCoords(e.latlng.lat, e.latlng.lng);
    });

    // Leaflet measures the container on init; inside a form that is still
    // laying out, that can come back as zero and leave grey tiles.
    setTimeout(function () { formMap.invalidateSize(); }, 200);
});
</script>

<?php require 'partials/footer.php'; ?>
