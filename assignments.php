<?php
require_once 'config.php';
require_once 'sms.php';
allow(['Super Administrator', 'Administrator']);

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);

$events = db()->query("SELECT e.id, e.title, e.location, e.start_at, e.end_at, e.status, o.name office_name 
                       FROM events e 
                       JOIN offices o ON o.id = e.office_id 
                       WHERE e.status != 'Cancelled' 
                       ORDER BY 
                         CASE WHEN e.status IN ('New','Pending','Assigned','In Progress','Rescheduled') THEN 0 ELSE 1 END ASC,
                         e.start_at DESC")->fetchAll();

// Include Network Technicians and Admin accounts in technicians list and roster
$techs = db()->query("SELECT u.id, u.full_name, r.name AS role_name, COALESCE(t.availability, 'Available') availability 
                      FROM users u 
                      JOIN roles r ON r.id = u.role_id 
                      LEFT JOIN technician_availability t ON t.technician_id = u.id 
                      WHERE r.name IN ('Network Technician', 'CCS Staff', 'Administrator', 'Super Administrator') 
                        AND u.status = 'Active' 
                      ORDER BY 
                        CASE 
                          WHEN r.name = 'Super Administrator' THEN 1 
                          WHEN r.name = 'Administrator' THEN 2 
                          ELSE 3 
                        END ASC, 
                        u.full_name ASC")->fetchAll();

$equipmentList = db()->query("SELECT * FROM equipment ORDER BY name")->fetchAll();

$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $eventId = (int)$_POST['event_id'];

    // The primary technician selected in the first row is the crew lead.
    $leadTechnicianId = (int)($_POST['technician_id'] ?? 0);

    // Collect all selected technicians (primary + additional)
    $technicianIds = [];
    if (!empty($_POST['technician_id'])) {
        $technicianIds[] = (int)$_POST['technician_id'];
    }
    if (!empty($_POST['extra_technician_ids']) && is_array($_POST['extra_technician_ids'])) {
        foreach ($_POST['extra_technician_ids'] as $tid) {
            $tid = (int)$tid;
            if ($tid > 0 && !in_array($tid, $technicianIds)) {
                $technicianIds[] = $tid;
            }
        }
    }

    // Collect multiple equipment items from dynamic rows
    $selectedEquipment = [];
    if (!empty($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])) {
        foreach ($_POST['equipment_ids'] as $idx => $rawId) {
            $eqId = (int)$rawId;
            $qty  = max(1, (int)($_POST['equipment_qtys'][$idx] ?? 1));
            if ($eqId > 0) {
                $selectedEquipment[$eqId] = ($selectedEquipment[$eqId] ?? 0) + $qty;
            }
        }
    }

    // Run checks for all technicians
    $reasons = [];
    $onLeaveError = '';

    foreach ($technicianIds as $technician) {
        // Availability check - On Leave is strictly blocked and cannot be overridden
        $qAv = db()->prepare('SELECT availability FROM technician_availability WHERE technician_id = ?');
        $qAv->execute([$technician]);
        $availStatus = $qAv->fetchColumn() ?: 'Available';

        $name = '';
        foreach ($techs as $t) {
            if ((int)$t['id'] === $technician) {
                $name = $t['full_name'];
                break;
            }
        }

        if ($availStatus === 'On Leave') {
            $onLeaveError = "Technician <strong>" . h($name) . "</strong> is currently <strong>On Leave</strong> and cannot be assigned. Please select an available personnel.";
            break;
        }

        // Schedule conflict
        $q = db()->prepare('SELECT COUNT(*) 
                            FROM event_assignments a 
                            JOIN events e ON e.id = a.event_id 
                            JOIN events target ON target.id = ? 
                            WHERE a.technician_id = ? 
                            AND e.start_at < target.end_at AND e.end_at > target.start_at 
                            AND a.status NOT IN ("Completed", "Cancelled")');
        $q->execute([$eventId, $technician]);
        if ((int)$q->fetchColumn() > 0) {
            $reasons[] = "Schedule conflict: <strong>" . h($name) . "</strong> has an overlapping assignment.";
        }

        if (in_array($availStatus, ['Busy', 'Unavailable'])) {
            $reasons[] = "Availability: <strong>" . h($name) . "</strong> is currently marked as '<em>{$availStatus}</em>'.";
        }
    }

    // Equipment checks
    foreach ($selectedEquipment as $eqId => $reqQty) {
        $qEq = db()->prepare('SELECT name, quantity_available FROM equipment WHERE id = ?');
        $qEq->execute([$eqId]);
        $eqRow = $qEq->fetch();
        if ($eqRow && $reqQty > (int)$eqRow['quantity_available']) {
            $reasons[] = 'Equipment: ' . h($eqRow['name']) . " (Requested: {$reqQty}, Available: {$eqRow['quantity_available']}).";
        }
    }

    if ($onLeaveError) {
        $warning = $onLeaveError;
    } elseif (!empty($reasons) && empty($_POST['override'])) {
        $warning = implode(' ', $reasons) . ' Check the authorization checkbox below to override and continue.';
    } else {
        // Create assignments for each technician
        $newlyAssignedTechnicians = [];
        foreach ($technicianIds as $technician) {
            // Avoid duplicate assignments
            $qCheck = db()->prepare('SELECT COUNT(*) FROM event_assignments WHERE event_id = ? AND technician_id = ?');
            $qCheck->execute([$eventId, $technician]);
            if ((int)$qCheck->fetchColumn() === 0) {
                $q = db()->prepare('INSERT INTO event_assignments(event_id, technician_id, assigned_by, status) VALUES (?, ?, ?, "New Assignment")');
                $q->execute([$eventId, $technician, user()['id']]);
                notify($technician, 'You have been assigned to support a new network event.', $eventId);
                $newlyAssignedTechnicians[] = $technician;
            }
        }

        // Mark the primary technician as lead for this event's assigned crew.
        db()->prepare('UPDATE event_assignments SET is_team_lead = (technician_id = ?) WHERE event_id = ?')->execute([$leadTechnicianId, $eventId]);

        // Send only after every selected technician is stored so each SMS includes the full crew.
        foreach ($newlyAssignedTechnicians as $technician) {
            sms_assignment_reminder((int)$technician, $eventId);
        }

        // Assign Multiple Equipment Items
        foreach ($selectedEquipment as $eqId => $reqQty) {
            $stmtEq = db()->prepare('INSERT INTO event_equipment (event_id, equipment_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)');
            $stmtEq->execute([$eventId, $eqId, $reqQty]);
            db()->prepare('UPDATE equipment SET quantity_assigned = quantity_assigned + ? WHERE id = ?')->execute([$reqQty, $eqId]);
        }

        db()->prepare('UPDATE events SET status = "Assigned" WHERE id = ? AND status = "New"')->execute([$eventId]);
        log_activity('Assigned technician(s)', $eventId);

        header('Location: event.php?id=' . $eventId);
        exit;
    }
}

$title = 'Technician Assignments';
require 'partials/header.php';
?>

<style>
/* Custom Searchable Select Styling */
.custom-search-select {
    position: relative;
    width: 100%;
}
.custom-search-select .search-select-trigger {
    background-color: #fff;
    cursor: pointer;
    min-height: 42px;
    border: 1px solid #cbd5e1;
    border-radius: 0.5rem;
    transition: all .15s ease-in-out;
}
.custom-search-select .search-select-trigger.sm {
    min-height: 36px;
    padding: 0.3rem 0.6rem;
    font-size: 0.875rem;
}
.custom-search-select .search-select-trigger:focus,
.custom-search-select .search-select-trigger:hover {
    border-color: #0d9488;
    box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.15);
}
.custom-search-select .search-select-dropdown {
    box-shadow: 0 12px 28px -4px rgba(15, 23, 42, 0.16), 0 4px 10px -2px rgba(15, 23, 42, 0.08);
    border: 1px solid #e2e8f0;
    border-radius: 0.6rem;
}
.custom-search-select .search-select-opt {
    cursor: pointer;
    border-radius: 0.375rem;
    transition: background 0.12s ease;
    user-select: none;
}
.custom-search-select .search-select-opt:hover:not(.is-disabled) {
    background-color: #f1f5f9;
}
.custom-search-select .search-select-opt.is-selected {
    background-color: #e0f2fe;
}
.custom-search-select .search-select-opt.is-disabled {
    opacity: 0.55;
    background-color: #f8fafc;
    cursor: not-allowed !important;
}
.custom-search-select .search-select-opt.is-disabled:hover {
    background-color: #fee2e2;
}
.toast-notice {
    animation: fadeInSlide 0.25s ease forwards;
}
@keyframes fadeInSlide {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Assign Network Technician</h3>
        <p class="text-secondary small mb-0">Dispatch technical personnel and allocate equipment for upcoming support events.</p>
    </div>
    <a href="events.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Events Directory</a>
</div>

<?php if ($warning): ?>
    <div class="alert alert-warning border-start border-4 border-warning shadow-sm mb-4">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Conflict Warning:</strong> <?= $warning ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" id="assignmentForm" class="card stat-card p-4 border-0">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">

            <!-- Target Event -->
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="targetEventSearch">Target Support Event *</label>
                <div class="custom-search-select position-relative" data-type="event" data-placeholder="-- Search Support Event --">
                    <select class="search-select-native d-none" name="event_id" aria-label="Target Support Event">
                        <option value="">-- Search Support Event --</option>
                    <?php foreach ($events as $e): ?>
                        <?php $eventSearchText = '#' . $e['id'] . ' ' . $e['title'] . ' ' . $e['office_name'] . ' ' . $e['location'] . ' ' . $e['status'] . ' ' . date('M j Y g:i A', strtotime($e['start_at'])); ?>
                        <option value="<?= (int)$e['id'] ?>"
                                <?= $eventId === (int)$e['id'] ? 'selected' : '' ?>
                                data-title="<?= h($e['title']) ?>"
                                data-office="<?= h($e['office_name']) ?>"
                                data-location="<?= h($e['location']) ?>"
                                data-schedule="<?= h(date('M j, Y g:i A', strtotime($e['start_at']))) ?>"
                                data-status="<?= h($e['status']) ?>"
                                data-search="<?= h(strtolower($eventSearchText)) ?>">
                            #<?= (int)$e['id'] ?> — <?= h($e['title']) ?> (<?= h($e['office_name']) ?> | <?= date('M j g:i A', strtotime($e['start_at'])) ?>) [<?= h($e['status']) ?>]
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                        <option disabled>No events available</option>
                    <?php endif; ?>
                    </select>

                    <div class="search-select-trigger form-select d-flex align-items-center justify-content-between px-3" id="targetEventSearch" tabindex="0" role="combobox" aria-label="Search and select target support event" aria-expanded="false" aria-controls="targetEventOptions">
                        <div class="search-select-display d-flex align-items-center gap-2 text-truncate">
                            <span class="text-muted"><i class="bi bi-search me-1"></i> Search Support Event...</span>
                        </div>
                        <i class="bi bi-chevron-down text-muted small ms-2 flex-shrink-0"></i>
                    </div>

                    <div class="search-select-dropdown position-absolute bg-white" style="top: calc(100% + 4px); left: 0; right: 0; z-index: 1060; display: none;">
                        <div class="p-2 border-bottom bg-light rounded-top-3">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" class="form-control border-start-0 search-select-filter" placeholder="Search by event, barangay, office, date or status..." autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary search-select-clear-btn d-none" title="Clear search"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>
                        <div class="search-select-options p-1" id="targetEventOptions" role="listbox" style="max-height: 280px; overflow-y: auto;"></div>
                    </div>
                </div>
            </div>

            <!-- Technician Selection Section -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label small fw-semibold mb-0">
                        <i class="bi bi-people-fill me-1 text-primary"></i> Assign Technician(s) *
                    </label>
                    <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3" id="addTechBtn" onclick="addTechnicianRow()">
                        <i class="bi bi-person-plus me-1"></i> Add Other Technician
                    </button>
                </div>

                <!-- Primary technician row -->
                <div id="technician-container" class="d-flex flex-column gap-2">
                    <div class="tech-row d-flex align-items-center gap-2">
                        <div class="tech-badge d-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold flex-shrink-0"
                             style="width:34px;height:34px;font-size:.85rem;">1</div>
                        
                        <div class="custom-search-select position-relative flex-grow-1" data-type="technician" data-placeholder="-- Search & Select Technician --">
                            <select class="search-select-native d-none" name="technician_id" required>
                                <option value="">-- Search & Select Technician --</option>
                                <?php foreach ($techs as $t): ?>
                                    <?php $isOnLeave = ($t['availability'] === 'On Leave'); ?>
                                    <option value="<?= $t['id'] ?>"
                                            <?= $isOnLeave ? 'disabled' : '' ?>
                                            data-name="<?= h($t['full_name']) ?>"
                                            data-role="<?= h($t['role_name']) ?>"
                                            data-status="<?= h($t['availability']) ?>"
                                            data-initial="<?= strtoupper(substr($t['full_name'], 0, 1)) ?>"
                                            data-search="<?= strtolower(h($t['full_name'] . ' ' . $t['role_name'] . ' ' . $t['availability'])) ?>">
                                        <?= h($t['full_name']) ?> (<?= h($t['role_name']) ?>) — <?= h($t['availability']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="search-select-trigger form-select d-flex align-items-center justify-content-between px-3" tabindex="0">
                                <div class="search-select-display d-flex align-items-center gap-2 text-truncate">
                                    <span class="text-muted"><i class="bi bi-search me-1"></i> Search &amp; Select Technician...</span>
                                </div>
                                <i class="bi bi-chevron-down text-muted small ms-2 flex-shrink-0"></i>
                            </div>

                            <div class="search-select-dropdown position-absolute bg-white" style="top: calc(100% + 4px); left: 0; right: 0; z-index: 1060; display: none;">
                                <div class="p-2 border-bottom bg-light rounded-top-3">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                        <input type="text" class="form-control border-start-0 search-select-filter" placeholder="Search technician by name, role or status..." autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary search-select-clear-btn d-none" title="Clear search"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                                <div class="search-select-options p-1" style="max-height: 230px; overflow-y: auto;">
                                    <!-- Dynamic options -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <small class="text-muted mt-2 d-block">
                    <i class="bi bi-info-circle me-1"></i> The first technician you select is the Team Leader and can accept the assignment for everyone assigned to the event. Personnel with <em>On Leave</em> status cannot be selected.
                </small>
            </div>

            <!-- Allocate Multiple Equipment Items -->
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label small fw-semibold mb-0">
                        <i class="bi bi-hdd-network me-1 text-primary"></i> Allocate Equipment Items (Optional)
                    </label>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="addEquipmentRow()">
                        <i class="bi bi-plus-circle me-1"></i> Add Equipment Item
                    </button>
                </div>

                <div id="equipment-container" class="d-flex flex-column gap-2">
                    <!-- Default Equipment Row -->
                    <div class="equipment-row row g-2 align-items-center bg-light p-2 rounded border">
                        <div class="col-7">
                            <div class="custom-search-select position-relative" data-type="equipment" data-placeholder="-- Search & Select Equipment Item --">
                                <select class="search-select-native d-none" name="equipment_ids[]">
                                    <option value="">-- Search & Select Equipment Item --</option>
                                    <?php foreach ($equipmentList as $eq): ?>
                                        <option value="<?= $eq['id'] ?>"
                                                data-name="<?= h($eq['name']) ?>"
                                                data-category="<?= h($eq['category'] ?? 'Equipment') ?>"
                                                data-available="<?= (int)$eq['quantity_available'] ?>"
                                                data-search="<?= strtolower(h($eq['name'] . ' ' . ($eq['category'] ?? '') . ' ' . ($eq['storage_location'] ?? ''))) ?>">
                                            <?= h($eq['name']) ?> (Avail: <?= $eq['quantity_available'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <div class="search-select-trigger sm form-select form-select-sm d-flex align-items-center justify-content-between" tabindex="0">
                                    <div class="search-select-display d-flex align-items-center gap-2 text-truncate">
                                        <span class="text-muted"><i class="bi bi-search me-1"></i> Search &amp; Select Item...</span>
                                    </div>
                                    <i class="bi bi-chevron-down text-muted small ms-2 flex-shrink-0"></i>
                                </div>

                                <div class="search-select-dropdown position-absolute bg-white" style="top: calc(100% + 4px); left: 0; right: 0; z-index: 1060; display: none;">
                                    <div class="p-2 border-bottom bg-light rounded-top-3">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                            <input type="text" class="form-control border-start-0 search-select-filter" placeholder="Search equipment by name or category..." autocomplete="off">
                                            <button type="button" class="btn btn-outline-secondary search-select-clear-btn d-none" title="Clear search"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>
                                    <div class="search-select-options p-1" style="max-height: 200px; overflow-y: auto;">
                                        <!-- Dynamic options -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">Qty</span>
                                <input class="form-control" type="number" min="1" name="equipment_qtys[]" value="1">
                            </div>
                        </div>
                        <div class="col-2 text-end">
                            <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="removeEquipmentRow(this)" title="Remove item">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <small class="text-muted mt-2 d-block">
                    <i class="bi bi-info-circle me-1"></i> You can search and add multiple equipment items (e.g. Starlink Kit, Gigabit Router, Access Points) for this event.
                </small>
            </div>

            <?php if ($warning): ?>
                <div class="form-check mb-3 p-3 bg-warning-subtle rounded border border-warning">
                    <input class="form-check-input" type="checkbox" name="override" value="1" id="overRequired">
                    <label class="form-check-label fw-bold text-dark small" for="overRequired">
                        I am authorized to override schedule / availability / equipment conflicts.
                    </label>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary btn-lg fs-6 fw-semibold py-2">
                <i class="bi bi-person-check me-1"></i> Confirm Technician Assignment
            </button>
        </form>
    </div>

    <!-- Active Technicians & Admins Roster Sidebar -->
    <aside class="col-lg-5">
        <div class="card stat-card border-0 p-4 sticky-top" style="top: 80px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-people text-primary me-2"></i>Technician Roster &amp; Duty Status
                </h5>
                <span class="badge bg-light text-secondary border"><?= count($techs) ?> Personnel</span>
            </div>

            <!-- Quick search for roster personnel -->
            <div class="input-group input-group-sm mb-3">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="rosterSearchInput" class="form-control bg-light border-start-0" placeholder="Filter roster personnel..." oninput="filterRosterSidebar()">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('rosterSearchInput').value=''; filterRosterSidebar();" title="Clear filter">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="list-group list-group-flush" id="rosterList" style="max-height: 480px; overflow-y: auto;">
                <?php foreach ($techs as $t): ?>
                    <?php
                    $avail = $t['availability'];
                    $badgeClass = 'secondary';
                    if ($avail === 'Available') $badgeClass = 'success';
                    elseif ($avail === 'Busy') $badgeClass = 'warning';
                    elseif ($avail === 'On Leave') $badgeClass = 'danger';
                    ?>
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center roster-item" data-search="<?= strtolower(h($t['full_name'] . ' ' . $t['role_name'] . ' ' . $avail)) ?>">
                        <div class="d-flex align-items-center gap-2">
                            <div class="<?= in_array($t['role_name'], ['Super Administrator', 'Administrator']) ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-secondary-subtle text-secondary' ?> rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                <?= strtoupper(substr($t['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-semibold small text-dark d-flex align-items-center gap-1">
                                    <?= h($t['full_name']) ?>
                                    <?php if ($t['role_name'] === 'Super Administrator'): ?>
                                        <span class="badge bg-dark-subtle text-dark border px-1" style="font-size: 0.65rem;">Super Admin</span>
                                    <?php elseif ($t['role_name'] === 'Administrator'): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-1" style="font-size: 0.65rem;">Admin</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted" style="font-size: 0.72rem;"><?= h($t['role_name']) ?></div>
                            </div>
                        </div>
                        <span class="badge badge-soft-<?= $badgeClass ?>">
                            ● <?= h($avail) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>
</div>

<!-- Template for additional technician row -->
<template id="tech-row-template">
    <div class="tech-row d-flex align-items-center gap-2">
        <div class="tech-badge d-flex align-items-center justify-content-center rounded-circle bg-success text-white fw-bold flex-shrink-0"
             style="width:34px;height:34px;font-size:.85rem;">+</div>
        
        <div class="custom-search-select position-relative flex-grow-1" data-type="technician" data-placeholder="-- Search & Select Additional Technician --">
            <select class="search-select-native d-none" name="extra_technician_ids[]">
                <option value="">-- Search & Select Additional Technician --</option>
                <?php foreach ($techs as $t): ?>
                    <?php $isOnLeave = ($t['availability'] === 'On Leave'); ?>
                    <option value="<?= $t['id'] ?>"
                            <?= $isOnLeave ? 'disabled' : '' ?>
                            data-name="<?= h($t['full_name']) ?>"
                            data-role="<?= h($t['role_name']) ?>"
                            data-status="<?= h($t['availability']) ?>"
                            data-initial="<?= strtoupper(substr($t['full_name'], 0, 1)) ?>"
                            data-search="<?= strtolower(h($t['full_name'] . ' ' . $t['role_name'] . ' ' . $t['availability'])) ?>">
                        <?= h($t['full_name']) ?> (<?= h($t['role_name']) ?>) — <?= h($t['availability']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <div class="search-select-trigger form-select d-flex align-items-center justify-content-between px-3" tabindex="0">
                <div class="search-select-display d-flex align-items-center gap-2 text-truncate">
                    <span class="text-muted"><i class="bi bi-search me-1"></i> Search &amp; Select Technician...</span>
                </div>
                <i class="bi bi-chevron-down text-muted small ms-2 flex-shrink-0"></i>
            </div>

            <div class="search-select-dropdown position-absolute bg-white" style="top: calc(100% + 4px); left: 0; right: 0; z-index: 1060; display: none;">
                <div class="p-2 border-bottom bg-light rounded-top-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 search-select-filter" placeholder="Search technician by name, role or status..." autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary search-select-clear-btn d-none" title="Clear search"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
                <div class="search-select-options p-1" style="max-height: 230px; overflow-y: auto;">
                    <!-- Dynamic options -->
                </div>
            </div>
        </div>

        <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" onclick="removeTechnicianRow(this)" title="Remove" style="height:38px; width:38px; display:flex; align-items:center; justify-content:center;">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</template>

<!-- Template for Dynamic Equipment Row -->
<template id="equipment-row-template">
    <div class="equipment-row row g-2 align-items-center bg-light p-2 rounded border">
        <div class="col-7">
            <div class="custom-search-select position-relative" data-type="equipment" data-placeholder="-- Search & Select Equipment Item --">
                <select class="search-select-native d-none" name="equipment_ids[]">
                    <option value="">-- Search & Select Equipment Item --</option>
                    <?php foreach ($equipmentList as $eq): ?>
                        <option value="<?= $eq['id'] ?>"
                                data-name="<?= h($eq['name']) ?>"
                                data-category="<?= h($eq['category'] ?? 'Equipment') ?>"
                                data-available="<?= (int)$eq['quantity_available'] ?>"
                                data-search="<?= strtolower(h($eq['name'] . ' ' . ($eq['category'] ?? '') . ' ' . ($eq['storage_location'] ?? ''))) ?>">
                            <?= h($eq['name']) ?> (Avail: <?= $eq['quantity_available'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <div class="search-select-trigger sm form-select form-select-sm d-flex align-items-center justify-content-between" tabindex="0">
                    <div class="search-select-display d-flex align-items-center gap-2 text-truncate">
                        <span class="text-muted"><i class="bi bi-search me-1"></i> Search &amp; Select Item...</span>
                    </div>
                    <i class="bi bi-chevron-down text-muted small ms-2 flex-shrink-0"></i>
                </div>

                <div class="search-select-dropdown position-absolute bg-white" style="top: calc(100% + 4px); left: 0; right: 0; z-index: 1060; display: none;">
                    <div class="p-2 border-bottom bg-light rounded-top-3">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control border-start-0 search-select-filter" placeholder="Search equipment by name or category..." autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary search-select-clear-btn d-none" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="search-select-options p-1" style="max-height: 200px; overflow-y: auto;">
                        <!-- Dynamic options -->
                    </div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text">Qty</span>
                <input class="form-control" type="number" min="1" name="equipment_qtys[]" value="1">
            </div>
        </div>
        <div class="col-2 text-end">
            <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="removeEquipmentRow(this)" title="Remove item">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</template>

<script>
// ── Toast Notification ────────────────────────────────────
function showToast(msg, type = 'danger') {
    let container = document.getElementById('searchToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'searchToastContainer';
        container.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;max-width:360px;';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast-notice alert alert-${type} shadow-lg py-2 px-3 small rounded-3 d-flex align-items-center gap-2 mb-2 border border-${type}`;
    toast.innerHTML = `<i class="bi bi-exclamation-octagon-fill fs-6 flex-shrink-0"></i> <div class="fw-semibold">${msg}</div>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 350);
    }, 3200);
}

// ── Searchable Select Engine ──────────────────────────────
function initSearchableSelect(wrap, autoFocus = false) {
    if (!wrap || wrap.dataset.initialized === 'true') return;
    wrap.dataset.initialized = 'true';

    const type = wrap.dataset.type || 'technician';
    const placeholder = wrap.dataset.placeholder || '-- Select --';
    const select = wrap.querySelector('.search-select-native');
    const trigger = wrap.querySelector('.search-select-trigger');
    const display = wrap.querySelector('.search-select-display');
    const dropdown = wrap.querySelector('.search-select-dropdown');
    const searchInput = wrap.querySelector('.search-select-filter');
    const clearBtn = wrap.querySelector('.search-select-clear-btn');
    const optionsContainer = wrap.querySelector('.search-select-options');

    function buildOptions() {
        optionsContainer.innerHTML = '';
        const options = select.querySelectorAll('option');
        
        let hasOptions = false;
        options.forEach(opt => {
            if (!opt.value) return; // skip empty placeholder
            hasOptions = true;

            const val = opt.value;
            const isSelected = (select.value === val);
            const isDisabled = opt.disabled;
            const searchTerms = (opt.dataset.search || opt.textContent).toLowerCase();

            const itemDiv = document.createElement('div');
            itemDiv.className = 'search-select-opt p-2 rounded d-flex align-items-center justify-content-between mb-1' + 
                                (isDisabled ? ' is-disabled' : '') + 
                                (isSelected ? ' is-selected' : '');
            itemDiv.dataset.value = val;
            itemDiv.dataset.search = searchTerms;
            itemDiv.dataset.disabled = isDisabled ? 'true' : 'false';

            if (type === 'technician') {
                const name = opt.dataset.name || opt.textContent;
                const role = opt.dataset.role || '';
                const status = opt.dataset.status || 'Available';
                const initial = opt.dataset.initial || name.charAt(0).toUpperCase();

                const isSuperAdmin = (role === 'Super Administrator');
                const isAdmin = (role === 'Administrator');
                const isStaff = isSuperAdmin || isAdmin;

                let roleBadgeHtml = '';
                if (isSuperAdmin) {
                    roleBadgeHtml = '<span class="badge bg-dark-subtle text-dark border px-1" style="font-size: 0.65rem;">Super Admin</span>';
                } else if (isAdmin) {
                    roleBadgeHtml = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-1" style="font-size: 0.65rem;">Admin</span>';
                }

                let statusBadgeHtml = '';
                if (status === 'Available') {
                    statusBadgeHtml = '<span class="badge badge-soft-success">● Available</span>';
                } else if (status === 'Busy') {
                    statusBadgeHtml = '<span class="badge badge-soft-warning">● Busy</span>';
                } else if (status === 'On Leave') {
                    statusBadgeHtml = '<span class="badge badge-soft-danger"><i class="bi bi-slash-circle me-1"></i>On Leave (Cannot Select)</span>';
                } else {
                    statusBadgeHtml = '<span class="badge badge-soft-secondary">● ' + status + '</span>';
                }

                const avatarBg = isStaff ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-secondary-subtle text-secondary';

                itemDiv.innerHTML = `
                    <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
                        <div class="${avatarBg} rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width:28px;height:28px;font-size:0.75rem;">
                            ${initial}
                        </div>
                        <div class="text-truncate">
                            <div class="fw-semibold small ${isDisabled ? 'text-secondary' : 'text-dark'} d-flex align-items-center gap-1">
                                <span class="text-truncate">${name}</span>
                                ${roleBadgeHtml}
                            </div>
                            <div class="text-muted" style="font-size: 0.7rem;">${role}</div>
                        </div>
                    </div>
                    <div class="flex-shrink-0">
                        ${statusBadgeHtml}
                    </div>
                `;
            } else if (type === 'equipment') {
                // Equipment
                const name = opt.dataset.name || opt.textContent;
                const category = opt.dataset.category || 'Equipment';
                const available = parseInt(opt.dataset.available || '0', 10);

                let availBadge = '';
                if (available > 0) {
                    availBadge = `<span class="badge bg-success-subtle text-success border border-success-subtle">Avail: ${available}</span>`;
                } else {
                    availBadge = `<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Out of stock</span>`;
                }

                itemDiv.innerHTML = `
                    <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
                        <i class="bi bi-hdd-network text-primary flex-shrink-0"></i>
                        <div class="text-truncate">
                            <div class="fw-semibold small text-dark text-truncate">${name}</div>
                            <div class="text-muted" style="font-size: 0.7rem;">${category}</div>
                        </div>
                    </div>
                    <div class="flex-shrink-0">
                        ${availBadge}
                    </div>
                `;
            } else {
                // Support event
                const eventInfo = document.createElement('div');
                eventInfo.className = 'me-2 text-truncate';
                const eventTitle = document.createElement('div');
                eventTitle.className = 'fw-semibold small text-dark text-truncate';
                eventTitle.textContent = '#' + val + ' — ' + (opt.dataset.title || opt.textContent.trim());
                const eventMeta = document.createElement('div');
                eventMeta.className = 'small text-muted text-truncate';
                eventMeta.textContent = [opt.dataset.office, opt.dataset.schedule, opt.dataset.location]
                    .filter(Boolean).join(' · ');
                const eventStatus = document.createElement('span');
                eventStatus.className = 'badge bg-light text-secondary border flex-shrink-0';
                eventStatus.textContent = opt.dataset.status || '';
                eventInfo.append(eventTitle, eventMeta);
                itemDiv.append(eventInfo, eventStatus);
            }

            // Click handling
            itemDiv.addEventListener('click', function(e) {
                e.stopPropagation();
                if (itemDiv.dataset.disabled === 'true') {
                    showToast('Cannot select: This personnel is currently On Leave.', 'danger');
                    return;
                }
                selectOption(val);
                closeDropdown();
            });

            optionsContainer.appendChild(itemDiv);
        });

        if (!hasOptions) {
            optionsContainer.innerHTML = '<div class="p-3 text-center text-muted small">No items available</div>';
        }
    }

    function updateTriggerDisplay() {
        const selectedVal = select.value;
        const selectedOpt = select.querySelector(`option[value="${selectedVal}"]`);

        if (!selectedVal || !selectedOpt) {
            display.innerHTML = `<span class="text-muted"><i class="bi bi-search me-1"></i> ${placeholder}</span>`;
            return;
        }

        if (type === 'technician') {
            const name = selectedOpt.dataset.name || selectedOpt.textContent;
            const role = selectedOpt.dataset.role || '';
            const status = selectedOpt.dataset.status || 'Available';
            const initial = selectedOpt.dataset.initial || name.charAt(0).toUpperCase();
            const isSuperAdmin = (role === 'Super Administrator');
            const isAdmin = (role === 'Administrator');
            const isStaff = isSuperAdmin || isAdmin;

            let roleBadgeHtml = '';
            if (isSuperAdmin) {
                roleBadgeHtml = '<span class="badge bg-dark-subtle text-dark border px-1" style="font-size: 0.65rem;">Super Admin</span>';
            } else if (isAdmin) {
                roleBadgeHtml = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-1" style="font-size: 0.65rem;">Admin</span>';
            }

            let statusBadgeHtml = '';
            if (status === 'Available') {
                statusBadgeHtml = '<span class="badge badge-soft-success">● Available</span>';
            } else if (status === 'Busy') {
                statusBadgeHtml = '<span class="badge badge-soft-warning">● Busy</span>';
            } else if (status === 'On Leave') {
                statusBadgeHtml = '<span class="badge badge-soft-danger">● On Leave</span>';
            } else {
                statusBadgeHtml = '<span class="badge badge-soft-secondary">● ' + status + '</span>';
            }

            const avatarBg = isStaff ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-secondary-subtle text-secondary';

            display.innerHTML = `
                <div class="${avatarBg} rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width:24px;height:24px;font-size:0.75rem;">
                    ${initial}
                </div>
                <span class="fw-semibold text-dark text-truncate">${name}</span>
                ${roleBadgeHtml}
                <span class="ms-auto flex-shrink-0">${statusBadgeHtml}</span>
            `;
        } else if (type === 'equipment') {
            // Equipment
            const name = selectedOpt.dataset.name || selectedOpt.textContent;
            const available = parseInt(selectedOpt.dataset.available || '0', 10);
            display.innerHTML = `
                <i class="bi bi-hdd-network text-primary flex-shrink-0"></i>
                <span class="fw-semibold text-dark text-truncate">${name}</span>
                <span class="badge bg-secondary-subtle text-secondary ms-auto flex-shrink-0">Avail: ${available}</span>
            `;
        } else {
            display.textContent = '';
            const title = document.createElement('span');
            title.className = 'fw-semibold text-dark text-truncate';
            title.textContent = '#' + selectedVal + ' — ' + (selectedOpt.dataset.title || selectedOpt.textContent.trim());
            const meta = document.createElement('span');
            meta.className = 'small text-muted ms-auto flex-shrink-0';
            meta.textContent = selectedOpt.dataset.schedule || '';
            display.append(title, meta);
        }
    }

    function selectOption(val) {
        select.value = val;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        updateTriggerDisplay();

        optionsContainer.querySelectorAll('.search-select-opt').forEach(opt => {
            opt.classList.toggle('is-selected', opt.dataset.value === val);
        });
    }

    function openDropdown() {
        document.querySelectorAll('.search-select-dropdown').forEach(d => {
            if (d !== dropdown) d.style.display = 'none';
        });

        dropdown.style.display = 'block';
        trigger.setAttribute('aria-expanded', 'true');
        searchInput.value = '';
        if (clearBtn) clearBtn.classList.add('d-none');
        filterOptions('');
        setTimeout(() => searchInput.focus(), 60);
    }

    function closeDropdown() {
        dropdown.style.display = 'none';
        trigger.setAttribute('aria-expanded', 'false');
    }

    function filterOptions(query) {
        const q = query.trim().toLowerCase();
        let visibleCount = 0;
        const items = optionsContainer.querySelectorAll('.search-select-opt');
        
        items.forEach(item => {
            const searchStr = (item.dataset.search || '').toLowerCase();
            const matches = !q || searchStr.includes(q);
            item.classList.toggle('d-none', !matches);
            if (matches) visibleCount++;
        });

        // Always scroll back to top of filtered list
        optionsContainer.scrollTop = 0;

        let noMatchDiv = optionsContainer.querySelector('.no-results-msg');
        if (visibleCount === 0) {
            if (!noMatchDiv) {
                noMatchDiv = document.createElement('div');
                noMatchDiv.className = 'no-results-msg p-3 text-center text-muted small';
                noMatchDiv.innerHTML = '<i class="bi bi-search me-1"></i> No matching items found';
                optionsContainer.appendChild(noMatchDiv);
            }
            noMatchDiv.classList.remove('d-none');
        } else if (noMatchDiv) {
            noMatchDiv.classList.add('d-none');
        }
    }

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        if (dropdown.style.display === 'block') {
            closeDropdown();
        } else {
            openDropdown();
        }
    });

    trigger.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
            e.preventDefault();
            openDropdown();
        }
    });

    searchInput.addEventListener('input', function() {
        if (clearBtn) {
            clearBtn.classList.toggle('d-none', !this.value);
        }
        filterOptions(this.value);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDropdown();
            trigger.focus();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const visible = Array.from(optionsContainer.querySelectorAll('.search-select-opt')).filter(el => !el.classList.contains('d-none') && el.dataset.disabled !== 'true');
            if (visible.length === 1) {
                selectOption(visible[0].dataset.value);
                closeDropdown();
                trigger.focus();
            }
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            searchInput.value = '';
            clearBtn.classList.add('d-none');
            filterOptions('');
            searchInput.focus();
        });
    }

    buildOptions();
    updateTriggerDisplay();

    if (autoFocus) {
        openDropdown();
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-search-select')) {
        document.querySelectorAll('.search-select-dropdown').forEach(d => {
            d.style.display = 'none';
        });
    }
});

// ── Technician rows ──────────────────────────────────────
var techCount = 1;

function addTechnicianRow() {
    techCount++;
    const container = document.getElementById('technician-container');
    const template  = document.getElementById('tech-row-template');
    const clone     = template.content.cloneNode(true);
    container.appendChild(clone);
    updateTechBadges();

    const rows = container.querySelectorAll('.tech-row');
    const lastRow = rows[rows.length - 1];
    const newSelectWrap = lastRow.querySelector('.custom-search-select');
    initSearchableSelect(newSelectWrap, true);
}

function removeTechnicianRow(btn) {
    btn.closest('.tech-row').remove();
    techCount = Math.max(1, techCount - 1);
    updateTechBadges();
}

function updateTechBadges() {
    const rows = document.querySelectorAll('#technician-container .tech-row');
    rows.forEach(function(row, i) {
        var badge = row.querySelector('.tech-badge');
        if (badge) badge.textContent = i + 1;
    });
}

// ── Equipment rows ────────────────────────────────────────
function addEquipmentRow() {
    const container = document.getElementById('equipment-container');
    const template  = document.getElementById('equipment-row-template');
    const clone     = template.content.cloneNode(true);
    container.appendChild(clone);

    const rows = container.querySelectorAll('.equipment-row');
    const lastRow = rows[rows.length - 1];
    const newSelectWrap = lastRow.querySelector('.custom-search-select');
    initSearchableSelect(newSelectWrap, true);
}

function removeEquipmentRow(btn) {
    const container = document.getElementById('equipment-container');
    const rows      = container.getElementsByClassName('equipment-row');
    if (rows.length > 1) {
        btn.closest('.equipment-row').remove();
    } else {
        const row = rows[0];
        const select = row.querySelector('.search-select-native');
        if (select) {
            select.value = '';
            select.dispatchEvent(new Event('change'));
        }
        row.querySelector('input[type="number"]').value = '1';
    }
}

// ── Roster Sidebar Filtering ──────────────────────────────
function filterRosterSidebar() {
    const q = (document.getElementById('rosterSearchInput').value || '').trim().toLowerCase();
    const items = document.querySelectorAll('#rosterList .roster-item');
    items.forEach(item => {
        const searchStr = (item.dataset.search || '').toLowerCase();
        const matches = !q || searchStr.includes(q);
        item.classList.toggle('d-none', !matches);
    });
}

// ── Form Validation ───────────────────────────────────────
document.getElementById('assignmentForm').addEventListener('submit', function(e) {
    const eventSelect = document.querySelector('select[name="event_id"]');
    if (!eventSelect || !eventSelect.value) {
        e.preventDefault();
        showToast('Please select a target support event.', 'danger');
        const trigger = document.getElementById('targetEventSearch');
        if (trigger) {
            trigger.scrollIntoView({ behavior: 'smooth', block: 'center' });
            trigger.classList.add('border-danger');
            setTimeout(() => trigger.classList.remove('border-danger'), 2500);
            trigger.click();
        }
        return false;
    }

    const primaryTechSelect = document.querySelector('select[name="technician_id"]');
    if (!primaryTechSelect || !primaryTechSelect.value) {
        e.preventDefault();
        showToast('Please select a primary technician to assign.', 'danger');
        const trigger = document.querySelector('[data-type="technician"] .search-select-trigger');
        if (trigger) {
            trigger.scrollIntoView({ behavior: 'smooth', block: 'center' });
            trigger.classList.add('border-danger');
            setTimeout(() => trigger.classList.remove('border-danger'), 2500);
            trigger.click();
        }
        return false;
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.custom-search-select').forEach(el => initSearchableSelect(el, false));
});
</script>

<?php require 'partials/footer.php'; ?>
