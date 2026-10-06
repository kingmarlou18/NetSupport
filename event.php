<?php
require_once 'config.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$q = db()->prepare('SELECT e.*, o.name office_name, u.full_name creator 
                    FROM events e 
                    JOIN offices o ON o.id = e.office_id 
                    JOIN users u ON u.id = e.created_by 
                    WHERE e.id = ?');
$q->execute([$id]);
$e = $q->fetch();

if (!$e) {
    http_response_code(404);
    exit('Event not found.');
}

$userRole = user()['role'];
$isFieldStaff = is_field_staff();
$userId = user()['id'];

// Check technician access
$myAssignment = null;
if ($isFieldStaff) {
    $q = db()->prepare('SELECT * FROM event_assignments WHERE event_id = ? AND technician_id = ?');
    $q->execute([$id, $userId]);
    $myAssignment = $q->fetch();
    if (!$myAssignment && $isFieldStaff) {
        exit('Access denied: You are not assigned to this event.');
    }
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Handle Quick Technician Status Update
    if ($action === 'update_task_status' && $myAssignment) {
        $newStatus = $_POST['assignment_status'] ?? $myAssignment['status'];
        $quickNote = trim($_POST['quick_note'] ?? '');

        $acceptedAt = $myAssignment['accepted_at'];
        $startedAt = $myAssignment['started_at'];
        $completedAt = $myAssignment['completed_at'];

        if ($newStatus === 'Accepted' && !$acceptedAt) $acceptedAt = date('Y-m-d H:i:s');
        if ($newStatus === 'In Progress' && !$startedAt) $startedAt = date('Y-m-d H:i:s');
        if ($newStatus === 'Completed' && !$completedAt) $completedAt = date('Y-m-d H:i:s');

        $up = db()->prepare('UPDATE event_assignments 
                             SET status = ?, accepted_at = ?, started_at = ?, completed_at = ? 
                             WHERE id = ?');
        $up->execute([$newStatus, $acceptedAt, $startedAt, $completedAt, $myAssignment['id']]);

        // Auto-update event status
        if ($newStatus === 'In Progress') {
            db()->prepare('UPDATE events SET status = "In Progress" WHERE id = ?')->execute([$id]);
        } elseif ($newStatus === 'Completed') {
            db()->prepare('UPDATE events SET status = "Completed" WHERE id = ?')->execute([$id]);
        }

        $logMsg = "Updated task status to $newStatus" . ($quickNote ? " — Note: $quickNote" : "");
        log_activity($logMsg, $id);
        header('Location: event.php?id=' . $id);
        exit;
    }

    // Handle Post-Event Technical Completion Report
    if ($action === 'submit_tech_report' && $myAssignment) {
        $notes = trim($_POST['completion_notes'] ?? '');
        $problems = trim($_POST['problems'] ?? '');
        $actionsTaken = trim($_POST['actions_taken'] ?? '');
        $perf = trim($_POST['performance'] ?? 'Excellent');
        $downtime = max(0, (int)($_POST['downtime_minutes'] ?? 0));
        $actualEquip = trim($_POST['actual_equipment'] ?? '');
        $netPerf = trim($_POST['internet_performance'] ?? '');

        // Update event_assignments notes
        $up = db()->prepare('UPDATE event_assignments 
                             SET completion_notes = ?, problems = ?, actions_taken = ? 
                             WHERE id = ?');
        $up->execute([$notes, $problems, $actionsTaken, $myAssignment['id']]);

        // Save detailed report into event_reports table
        try {
            $rep = db()->prepare('INSERT INTO event_reports (assignment_id, performance, downtime_minutes, recommendations) 
                                 VALUES (?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE performance = VALUES(performance), downtime_minutes = VALUES(downtime_minutes), recommendations = VALUES(recommendations)');
            $rep->execute([$myAssignment['id'], $perf, $downtime, $actionsTaken]);
        } catch (PDOException $e) {
            // Ignore if report table schema differs
        }

        log_activity("Submitted technical completion report", $id);
        header('Location: event.php?id=' . $id);
        exit;
    }

    // Handle Pre-Event Checklist Item Toggle
    if ($action === 'toggle_checklist' && $myAssignment) {
        $itemId = (int)$_POST['checklist_id'];
        $val = isset($_POST['completed']) ? 1 : 0;
        db()->prepare('UPDATE event_checklists SET completed = ? WHERE id = ? AND assignment_id = ?')->execute([$val, $itemId, $myAssignment['id']]);
        header('Location: event.php?id=' . $id);
        exit;
    }

    // Handle Assigning Equipment to Event (Admins)
    if ($action === 'assign_equipment' && !$isFieldStaff) {
        $equipId = (int)$_POST['equipment_id'];
        $qty = max(1, (int)$_POST['quantity']);
        if ($equipId) {
            $stmt = db()->prepare('INSERT INTO event_equipment (event_id, equipment_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)');
            $stmt->execute([$id, $equipId, $qty]);
            db()->prepare('UPDATE equipment SET quantity_assigned = quantity_assigned + ? WHERE id = ?')->execute([$qty, $equipId]);
            log_activity("Assigned equipment to event #$id", $id);
            header('Location: event.php?id=' . $id);
            exit;
        }
    }

    // Handle Admin Event Status Change
    if ($action === 'update_event_status' && !$isFieldStaff) {
        $newEventStatus = $_POST['event_status'] ?? $e['status'];
        db()->prepare('UPDATE events SET status = ? WHERE id = ?')->execute([$newEventStatus, $id]);
        log_activity("Changed event status to $newEventStatus", $id);
        header('Location: event.php?id=' . $id);
        exit;
    }

    if ($action === 'delete_event' && !$isFieldStaff) {
        db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        log_activity("Deleted support event #$id");
        header('Location: events.php');
        exit;
    }
}

// Pre-populate default checklist for assignment if empty
if ($myAssignment) {
    $cCheck = db()->prepare('SELECT COUNT(*) FROM event_checklists WHERE assignment_id = ?');
    $cCheck->execute([$myAssignment['id']]);
    if ((int)$cCheck->fetchColumn() === 0) {
        $defaults = ['Site Inspected', 'Equipment Prepared', 'Starlink Tested', 'Router Configured', 'Internet Connection Tested', 'Backup Connection Prepared', 'Power Source Checked', 'Cables Prepared'];
        $stmtIns = db()->prepare('INSERT INTO event_checklists (assignment_id, item_name, completed) VALUES (?, ?, 0)');
        foreach ($defaults as $item) {
            $stmtIns->execute([$myAssignment['id'], $item]);
        }
    }
}

$title = $e['title'];
require 'partials/header.php';

// Fetch assignments
$q = db()->prepare('SELECT a.*, u.full_name technician, u.email 
                    FROM event_assignments a 
                    JOIN users u ON u.id = a.technician_id 
                    WHERE a.event_id = ?');
$q->execute([$id]);
$assignments = $q->fetchAll();

// Fetch checklist items for current technician
$checklists = [];
if ($myAssignment) {
    $qC = db()->prepare('SELECT * FROM event_checklists WHERE assignment_id = ? ORDER BY id ASC');
    $qC->execute([$myAssignment['id']]);
    $checklists = $qC->fetchAll();
}

// Fetch assigned equipment for event
$qEq = db()->prepare('SELECT ee.quantity, eq.name, eq.category, eq.serial_number 
                      FROM event_equipment ee 
                      JOIN equipment eq ON eq.id = ee.equipment_id 
                      WHERE ee.event_id = ?');
$qEq->execute([$id]);
$assignedEquipment = $qEq->fetchAll();

// Fetch overall equipment list for dropdown
$allEquipment = db()->query('SELECT * FROM equipment ORDER BY name')->fetchAll();

// Fetch Event Timeline Logs
$qTimeline = db()->prepare('SELECT l.*, u.full_name FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE l.event_id = ? ORDER BY l.id ASC');
$qTimeline->execute([$id]);
$timelineLogs = $qTimeline->fetchAll();
?>

<!-- Title Banner -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <a href="events.php" class="text-decoration-none small text-secondary fw-semibold mb-1 d-inline-block">
            <i class="bi bi-arrow-left"></i> Back to Events Directory
        </a>
        <h3 class="fw-bold text-dark mb-1">
            <?= h($e['title']) ?> 
            <span class="fs-6 text-muted">#<?= $e['id'] ?></span>
        </h3>
        <div class="d-flex align-items-center gap-2">
            <span class="badge badge-soft-<?= status_class($e['status']) ?>">● Event <?= h($e['status']) ?></span>
            <span class="badge badge-soft-<?= status_class($e['priority']) ?>">Priority: <?= h($e['priority']) ?></span>
            <span class="badge bg-light text-dark border"><?= h($e['category']) ?></span>
            <?php if (!empty($e['travel_order_status']) && in_array($e['travel_order_status'], ['Completed', 'Ongoing'], true)): ?>
                <span class="badge badge-soft-success">
                    <i class="bi bi-file-earmark-check me-1"></i> Travel Order Created
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex gap-2">
        <?php if (!$isFieldStaff): ?>
            <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 shadow-sm" href="event_form.php?id=<?= $id ?>">
                <i class="bi bi-pencil"></i> Edit Event
            </a>

            <button class="btn btn-outline-danger d-inline-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#deleteDetailEventModal">
                <i class="bi bi-trash"></i> Delete
            </button>

            <a class="btn btn-primary d-inline-flex align-items-center gap-1 shadow-sm" href="assignments.php?event_id=<?= $id ?>">
                <i class="bi bi-person-plus"></i> Assign Technicians
            </a>

            <!-- Event Status Dropdown -->
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                Change Status
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <?php foreach (['New', 'Pending', 'Assigned', 'In Progress', 'Completed', 'Cancelled', 'Rescheduled'] as $st): ?>
                    <li>
                        <form method="post" class="m-0">
                            <input type="hidden" name="csrf" value="<?= csrf() ?>">
                            <input type="hidden" name="action" value="update_event_status">
                            <input type="hidden" name="event_status" value="<?= $st ?>">
                            <button class="dropdown-item small <?= $e['status'] === $st ? 'fw-bold active' : '' ?>">
                                Mark as <?= $st ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Main Event Content -->
    <section class="col-lg-8">
        <!-- Event Overview Card -->
        <div class="card stat-card p-4 border-0 mb-4">
            <h5 class="fw-bold text-dark mb-3">Event Overview</h5>
            <p class="text-secondary"><?= nl2br(h($e['description'] ?: 'No detailed description provided.')) ?></p>
            
            <div class="row g-3 bg-light p-3 rounded-3 mt-2">
                <div class="col-md-6">
                    <div class="small text-muted">Requesting Office:</div>
                    <div class="fw-semibold text-dark"><i class="bi bi-building me-1 text-primary"></i> <?= h($e['office_name']) ?></div>
                    
                    <div class="small text-muted mt-2">Venue / Location:</div>
                    <div class="fw-semibold text-dark"><i class="bi bi-geo-alt me-1 text-danger"></i> <?= h($e['location']) ?></div>
                    
                    <div class="small text-muted mt-2">Expected Attendees:</div>
                    <div class="fw-semibold text-dark"><i class="bi bi-people me-1 text-info"></i> <?= (int)$e['participants'] ?> participants</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Schedule Start:</div>
                    <div class="fw-semibold text-dark"><i class="bi bi-clock me-1 text-success"></i> <?= date('M j, Y — g:i A', strtotime($e['start_at'])) ?></div>
                    
                    <div class="small text-muted mt-2">Schedule End:</div>
                    <div class="fw-semibold text-dark"><i class="bi bi-clock-history me-1 text-warning"></i> <?= date('M j, Y — g:i A', strtotime($e['end_at'])) ?></div>

                    <div class="small text-muted mt-2">Bandwidth Allocated:</div>
                    <div class="fw-semibold text-dark"><i class="bi bi-speedometer2 me-1 text-primary"></i> <?= (int)$e['bandwidth_mbps'] ?> Mbps</div>

                    <div class="small text-muted mt-2">Travel Order:</div>
                    <?php if (!empty($e['travel_order_status']) && in_array($e['travel_order_status'], ['Completed', 'Ongoing'], true)): ?>
                        <div class="fw-semibold text-success"><i class="bi bi-file-earmark-check me-1"></i> Travel Order Created</div>
                    <?php else: ?>
                        <div class="fw-semibold text-muted"><i class="bi bi-dash-circle me-1"></i> Not Created</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pre-Event Checklist (For Assigned Technician) -->
        <?php if ($myAssignment && $checklists): ?>
            <div class="card stat-card border-0 p-4 mb-4">
                <h5 class="fw-bold text-dark mb-3"><i class="bi bi-check2-square text-success me-2"></i> Pre-Event Checklist</h5>
                <div class="row g-2">
                    <?php foreach ($checklists as $chk): ?>
                        <div class="col-md-6">
                            <form method="post" class="p-2 border rounded bg-light d-flex align-items-center justify-content-between m-0">
                                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                <input type="hidden" name="action" value="toggle_checklist">
                                <input type="hidden" name="checklist_id" value="<?= $chk['id'] ?>">
                                
                                <span class="small <?= $chk['completed'] ? 'text-decoration-line-through text-muted fw-semibold' : 'fw-semibold text-dark' ?>">
                                    <?= h($chk['item_name']) ?>
                                </span>
                                
                                <button type="submit" name="completed" value="<?= $chk['completed'] ? '0' : '1' ?>" class="btn btn-sm <?= $chk['completed'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                    <i class="bi <?= $chk['completed'] ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Technician Quick Action Hub Banner -->
                <?php if ($isFieldStaff && $myAssignment): ?>
            <div class="card stat-card border-0 border-start border-4 border-primary p-4 mb-4 bg-white shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-tools text-primary me-1"></i> Your Duty Status:</h5>
                            <span class="badge badge-soft-<?= status_class($myAssignment['status']) ?> fs-6">● <?= h($myAssignment['status']) ?></span>
                        </div>
                        <p class="small text-secondary mb-0">Manage all your active dispatches, checklists, and post-event findings from the dedicated <strong>Technician Desk</strong> in the sidebar.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="my_tasks.php" class="btn btn-outline-primary btn-sm fw-semibold">
                            <i class="bi bi-list-task me-1"></i> My Tasks &amp; Dispatch
                        </a>
                        <a href="tech_report.php?id=<?= $id ?>" class="btn btn-success btn-sm fw-semibold">
                            <i class="bi bi-journal-check me-1"></i> Submit Tech Report &rarr;
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Assigned Technicians List -->
        <div class="card stat-card border-0 p-4 mb-4">
            <h5 class="fw-bold text-dark mb-3">Assigned Support Personnel</h5>
            
            <?php foreach ($assignments as $a): ?>
                <div class="p-3 bg-light rounded-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                <?= strtoupper(substr($a['technician'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-dark mb-0"><?= h($a['technician']) ?></div>
                                <div class="small text-muted"><?= h($a['email']) ?></div><?php if (!empty($a['is_team_lead'])): ?><span class="badge bg-primary-subtle text-primary border border-primary-subtle mt-1">Team Leader</span><?php endif; ?>
                            </div>
                        </div>
                        <span class="badge badge-soft-<?= status_class($a['status']) ?>">
                            ● <?= h($a['status']) ?>
                        </span>
                    </div>

                    <?php if ($a['completion_notes']): ?>
                        <div class="small border-top pt-2 mt-2">
                            <strong class="text-secondary">Completion Notes:</strong> <?= nl2br(h($a['completion_notes'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($a['problems']): ?>
                        <div class="small text-danger mt-1">
                            <strong>Problems logged:</strong> <?= h($a['problems']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($assignments)): ?>
                <p class="text-muted small mb-0 py-2">
                    <i class="bi bi-info-circle me-1"></i> No technicians assigned to this event yet.
                </p>
            <?php endif; ?>
        </div>

        <!-- Event Timeline -->
        <div class="card stat-card border-0 p-4">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history text-primary me-2"></i> Event Timeline</h5>
            <div class="small text-secondary bg-light border rounded-3 px-3 py-2 mb-3">
                <i class="bi bi-calendar-event text-primary me-1"></i>
                Scheduled for <strong><?= date('M j, Y g:i A', strtotime($e['start_at'])) ?></strong>.
                Timeline entries below show when each action was recorded.
            </div>
            <div class="activity-timeline" id="eventTimeline" data-event-id="<?= $id ?>" data-last-id="<?= (int)($timelineLogs ? end($timelineLogs)['id'] : 0) ?>">
                <?php foreach ($timelineLogs as $tl): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="fw-bold small text-dark"><?= h($tl['action']) ?></div>
                        <div class="small text-muted">By <?= h($tl['full_name']) ?> on <?= date('M j, Y g:i A', strtotime($tl['created_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Sidebar Info & Equipment Assignment -->
    <aside class="col-lg-4">
        <!-- Field Location Mini Map Card -->
        <div class="card stat-card border-0 p-4 mb-4">
            <h5 class="fw-bold text-dark mb-2"><i class="bi bi-geo-alt text-danger me-2"></i> Field Location Map</h5>
            <p class="small text-muted mb-2">📍 <?= h($e['location']) ?></p>
            <a href="map.php" class="btn btn-sm btn-outline-primary w-100 fw-semibold">
                <i class="bi bi-map me-1"></i> Open Interactive Barangay Map &rarr;
            </a>
        </div>

        <!-- Assigned Equipment Card -->
        <div class="card stat-card border-0 p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-router text-primary me-2"></i> Assigned Equipment</h5>
                <?php if (!$isFieldStaff): ?>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignEquipModal">+</button>
                <?php endif; ?>
            </div>

            <?php foreach ($assignedEquipment as $eq): ?>
                <div class="p-2 border rounded bg-light mb-2 d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold small text-dark"><?= h($eq['name']) ?></div>
                        <div class="small text-muted"><?= h($eq['category']) ?> <?= $eq['serial_number'] ? '· ' . h($eq['serial_number']) : '' ?></div>
                    </div>
                    <span class="badge bg-primary rounded-pill px-2">x<?= (int)$eq['quantity'] ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (empty($assignedEquipment)): ?>
                <p class="small text-muted mb-0">No equipment items assigned yet.</p>
            <?php endif; ?>
        </div>

        <!-- Connectivity Specs -->
        <div class="card stat-card border-0 p-4 mb-4">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-wifi text-primary me-2"></i> Connectivity Specs</h5>
            
            <div class="mb-3">
                <div class="small text-muted">Primary Connection:</div>
                <div class="fw-semibold text-dark"><?= h($e['primary_connection'] ?: 'Not specified') ?></div>
            </div>

            <div class="mb-3">
                <div class="small text-muted">Backup Connection:</div>
                <div class="fw-semibold text-dark"><?= h($e['backup_connection'] ?: 'None configured') ?></div>
            </div>

            <div class="mb-0">
                <div class="small text-muted">Technical Notes:</div>
                <div class="small text-secondary bg-light p-2 rounded-2 mt-1">
                    <?= nl2br(h($e['notes'] ?: 'No special notes.')) ?>
                </div>
            </div>
        </div>
    </aside>
</div>

<!-- Modal: Assign Equipment to Event -->
<?php if (!$isFieldStaff): ?>
<div class="modal fade" id="assignEquipModal" tabindex="-1" aria-labelledby="assignEquipModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="assign_equipment">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold text-dark fs-6" id="assignEquipModalLabel"><i class="bi bi-box-seam me-2 text-primary"></i>Assign Equipment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold">Equipment Item</label>
                        <select class="form-select form-select-sm" name="equipment_id" required>
                            <?php foreach ($allEquipment as $eq): ?>
                                <option value="<?= $eq['id'] ?>"><?= h($eq['name']) ?> (Avail: <?= $eq['quantity_available'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold">Quantity</label>
                        <input class="form-control form-control-sm" type="number" min="1" name="quantity" value="1" required>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm fw-semibold">Assign Item</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Delete Event Confirmation -->
<?php if (!$isFieldStaff): ?>
<div class="modal fade" id="deleteDetailEventModal" tabindex="-1" aria-labelledby="deleteDetailEventModalLabel" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete_event">

                <div class="modal-header border-bottom bg-danger-subtle text-danger">
                    <h5 class="modal-title fw-bold fs-6 text-danger" id="deleteDetailEventModalLabel"><i class="bi bi-exclamation-triangle-fill me-1"></i> Delete Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-start py-3">
                    <p class="small text-secondary mb-0">Are you sure you want to delete event <strong><?= h($e['title']) ?></strong> (<code>#<?= $id ?></code>)? This action cannot be undone.</p>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm fw-semibold">Delete Event</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const timeline = document.getElementById('eventTimeline');
    if (!timeline) return;

    let lastId = Number(timeline.dataset.lastId || 0);
    let loading = false;
    const feedUrl = 'event_timeline_feed.php?event_id=' + encodeURIComponent(timeline.dataset.eventId);

    async function refreshTimeline() {
        if (loading || document.hidden) return;
        loading = true;
        try {
            const response = await fetch(feedUrl + '&after_id=' + lastId, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            if (!response.ok) return;
            const items = await response.json();
            items.forEach(function (item) {
                const row = document.createElement('div');
                row.className = 'timeline-item';
                row.dataset.logId = item.id;
                const marker = document.createElement('div');
                marker.className = 'timeline-marker';
                const action = document.createElement('div');
                action.className = 'fw-bold small text-dark';
                action.textContent = item.action;
                const meta = document.createElement('div');
                meta.className = 'small text-muted';
                const timestamp = new Date(item.created_at);
                meta.textContent = 'By ' + item.full_name + ' on ' + timestamp.toLocaleString([], {
                    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
                });
                row.append(marker, action, meta);
                timeline.appendChild(row);
                lastId = Math.max(lastId, Number(item.id));
                timeline.dataset.lastId = String(lastId);
            });
        } catch (error) {
            // Retry on the next poll if the connection is temporarily unavailable.
        } finally {
            loading = false;
        }
    }

    window.setInterval(refreshTimeline, 3000);
});
</script>
<?php require 'partials/footer.php'; ?>
