<?php
require_once 'config.php';
$title = 'Events Directory';

// Handle Delete Event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_event') {
    verify_csrf();
    allow(['Super Administrator', 'Administrator']);
    $delId = (int)$_POST['id'];
    if ($delId > 0) {
        db()->prepare('DELETE FROM events WHERE id = ?')->execute([$delId]);
        log_activity("Deleted support event #$delId");
        header('Location: events.php');
        exit;
    }
}

// Process redirects before the shared header sends HTML to the browser.
require 'partials/header.php';

$where = [];
$args = [];

if (is_field_staff()) {
    $where[] = 'a.technician_id = ?';
    $args[] = user()['id'];
}

if (!empty($_GET['status'])) {
    $where[] = 'e.status = ?';
    $args[] = $_GET['status'];
}

if (!empty($_GET['priority'])) {
    $where[] = 'e.priority = ?';
    $args[] = $_GET['priority'];
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $where[] = '(e.title LIKE ? OR e.location LIKE ? OR e.category LIKE ? OR o.name LIKE ? OR tech.full_name LIKE ? OR creator.full_name LIKE ? OR e.travel_order_no LIKE ? OR e.description LIKE ?)';
    $searchTerm = '%' . $search . '%';
    for ($i = 0; $i < 8; $i++) {
        $args[] = $searchTerm;
    }
}

$sql = 'SELECT e.*, o.name office_name, creator.full_name creator_name,
               COUNT(DISTINCT a.id) technician_count,
               GROUP_CONCAT(DISTINCT tech.full_name SEPARATOR ", ") assigned_tech_names
        FROM events e 
        JOIN offices o ON o.id = e.office_id 
        LEFT JOIN users creator ON creator.id = e.created_by
        LEFT JOIN event_assignments a ON a.event_id = e.id 
        LEFT JOIN users tech ON tech.id = a.technician_id ' 
        . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . 
        ' GROUP BY e.id ORDER BY e.start_at DESC';

$q = db()->prepare($sql);
$q->execute($args);
$events = $q->fetchAll();
$userRole = user()['role'];
$isFieldStaff = is_field_staff();
?>

<!-- Title & Action Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Support Events Directory</h3>
        <p class="text-secondary small mb-0">Browse, filter, edit, and manage all scheduled network support requests.</p>
    </div>
    <?php if (!$isFieldStaff): ?>
        <a href="event_form.php" class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-plus-lg"></i>
            <span>Create Event Request</span>
        </a>
    <?php endif; ?>
</div>

<!-- Search & Filter Controls -->
<div class="card stat-card p-3 mb-4 border-0">
    <form class="row g-2 align-items-center" method="get" id="filterForm">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="eventSearchInput" class="form-control bg-light border-start-0" name="q" value="<?= h($search) ?>" placeholder="Search by title, venue, office, or personnel/technician name..." oninput="filterEventsTable()" autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" onclick="clearEventSearch()" title="Clear search"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select bg-light" name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach (['New', 'Pending', 'Assigned', 'In Progress', 'Completed', 'Cancelled', 'Rescheduled'] as $s): ?>
                    <option <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select bg-light" name="priority" onchange="this.form.submit()">
                <option value="">All Priorities</option>
                <?php foreach (['Low', 'Normal', 'High', 'Urgent'] as $p): ?>
                    <option <?= ($_GET['priority'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-secondary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <?php if ($search || !empty($_GET['status']) || !empty($_GET['priority'])): ?>
                <a href="events.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Events Table -->
<div class="card stat-card border-0">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="table app-table align-middle" id="eventsTable">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>Event Details</th>
                        <th>Office</th>
                        <th>Schedule</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th class="text-center">Assigned Personnel</th>
                        <?php if (!$isFieldStaff): ?>
                            <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $e): ?>
                        <tr class="event-row priority-<?= h($e['priority']) ?>" data-search="<?= strtolower(h($e['id'] . ' ' . $e['title'] . ' ' . $e['location'] . ' ' . $e['office_name'] . ' ' . $e['category'] . ' ' . ($e['creator_name'] ?? '') . ' ' . ($e['assigned_tech_names'] ?? '') . ' ' . $e['priority'] . ' ' . $e['status'] . ' ' . ($e['travel_order_no'] ?? ''))) ?>">
                            <td class="fw-bold text-muted">#<?= $e['id'] ?></td>
                            <td>
                                <a href="event.php?id=<?= $e['id'] ?>" class="fw-bold text-dark text-decoration-none hover-primary">
                                    <?= h($e['title']) ?>
                                </a>
                                <div class="small text-muted d-flex align-items-center gap-2 mt-1">
                                    <span class="badge bg-light text-secondary border"><?= h($e['category']) ?></span>
                                    <span><i class="bi bi-geo-alt text-danger"></i> <?= h($e['location']) ?></span>
                                    <?php if (!empty($e['travel_order_status']) && in_array($e['travel_order_status'], ['Completed', 'Ongoing'], true)): ?>
                                        <span class="badge badge-soft-success"><i class="bi bi-file-earmark-check me-1"></i> Travel Order Created</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="fw-semibold text-dark"><?= h($e['office_name']) ?></span>
                                <?php if (!empty($e['creator_name'])): ?>
                                    <div class="text-muted" style="font-size: 0.72rem;">Req by: <?= h($e['creator_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?= date('M j, Y', strtotime($e['start_at'])) ?></div>
                                <div class="small text-muted"><?= date('g:i A', strtotime($e['start_at'])) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-soft-<?= status_class($e['priority']) ?>">
                                    <?= h($e['priority']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-soft-<?= status_class($e['status']) ?>">
                                    ● <?= h($e['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if (!empty($e['assigned_tech_names'])): ?>
                                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                                        <?php foreach (explode(', ', $e['assigned_tech_names']) as $tName): ?>
                                            <span class="badge bg-light text-primary border rounded-pill px-2 py-1 fw-semibold small" title="Assigned Personnel">
                                                <i class="bi bi-person-fill me-1"></i><?= h($tName) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1 small">
                                        <i class="bi bi-person-x me-1"></i>0 Assigned
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if (!$isFieldStaff): ?>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <!-- View Details Button -->
                                        <a href="event.php?id=<?= $e['id'] ?>" class="btn btn-outline-secondary" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <!-- Edit Event Button -->
                                        <a href="event_form.php?id=<?= $e['id'] ?>" class="btn btn-outline-secondary" title="Edit Event">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>

                                        <!-- Delete Event Button -->
                                        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteEventModal<?= $e['id'] ?>" title="Delete Event">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <!-- Delete Event Confirmation Modal -->
                        <?php if (!$isFieldStaff): ?>
                        <div class="modal fade" id="deleteEventModal<?= $e['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                <div class="modal-content border-0 shadow-lg text-start">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="id" value="<?= $e['id'] ?>">

                                        <div class="modal-header border-bottom bg-danger-subtle text-danger">
                                            <h5 class="modal-title fw-bold fs-6"><i class="bi bi-exclamation-triangle me-1"></i> Delete Support Event</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body py-3">
                                            <p class="small text-secondary mb-0">Are you sure you want to delete event <strong><?= h($e['title']) ?></strong> (<code>#<?= $e['id'] ?></code>)?</p>
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

                    <?php endforeach; ?>

                    <!-- Dynamic No Match Row -->
                    <tr id="noEventsMatchRow" style="display: none;">
                        <td colspan="<?= !$isFieldStaff ? '9' : '8' ?>" class="text-center py-5 text-muted">
                            <i class="bi bi-search fs-1 d-block text-secondary mb-2"></i>
                            No support events found matching your search.
                        </td>
                    </tr>

                    <?php if (empty($events)): ?>
                        <tr>
                            <td colspan="<?= !$isFieldStaff ? '9' : '8' ?>" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x fs-1 d-block text-secondary mb-2"></i>
                                No support events found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Live real-time search across all event fields including technician and creator names
function filterEventsTable() {
    const input = document.getElementById('eventSearchInput');
    const q = (input ? input.value : '').trim().toLowerCase();
    const rows = document.querySelectorAll('#eventsTable tbody .event-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const searchData = (row.dataset.search || '').toLowerCase();
        const matches = !q || searchData.includes(q);
        row.classList.toggle('d-none', !matches);
        if (matches) visibleCount++;
    });

    const noMatchRow = document.getElementById('noEventsMatchRow');
    if (noMatchRow) {
        noMatchRow.classList.toggle('d-none', !(visibleCount === 0 && rows.length > 0));
    }
}

function clearEventSearch() {
    const input = document.getElementById('eventSearchInput');
    if (input) {
        input.value = '';
        filterEventsTable();
        input.focus();
    }
}
</script>

<?php require 'partials/footer.php'; ?>
