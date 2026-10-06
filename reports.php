<?php
require_once 'config.php';
allow(['Super Administrator', 'Administrator']);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=technician_assignment_report_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Assignment ID', 'Event Title', 'Requesting Office', 'Category', 'Priority', 'Assigned Technician', 'Assigned By', 'Event Date', 'Event Status', 'Assignment Status', 'Completed At', 'Completion Notes']);

    $where = ["u.status = 'Active'"];
    $args = [];

    if (!empty($_GET['technician_id'])) {
        $where[] = 'a.technician_id = ?';
        $args[] = (int)$_GET['technician_id'];
    }

    if (!empty($_GET['office_id'])) {
        $where[] = 'e.office_id = ?';
        $args[] = (int)$_GET['office_id'];
    }

    if (!empty($_GET['event_status'])) {
        $where[] = 'e.status = ?';
        $args[] = $_GET['event_status'];
    }

    if (!empty($_GET['assignment_status'])) {
        $where[] = 'a.status = ?';
        $args[] = $_GET['assignment_status'];
    }

    $sql = 'SELECT a.id, e.title, o.name office_name, e.category, e.priority, e.start_at, u.full_name technician, ab.full_name assigned_by_name, e.status event_status, a.status assignment_status, a.completed_at, a.completion_notes 
            FROM event_assignments a 
            JOIN events e ON e.id = a.event_id 
            JOIN offices o ON o.id = e.office_id 
            JOIN users u ON u.id = a.technician_id 
            JOIN users ab ON ab.id = a.assigned_by '
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . 
            ' ORDER BY e.start_at DESC';

    $q = db()->prepare($sql);
    $q->execute($args);
    $rows = $q->fetchAll();

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['id'],
            $r['title'],
            $r['office_name'],
            $r['category'],
            $r['priority'],
            $r['technician'],
            $r['assigned_by_name'],
            $r['start_at'],
            $r['event_status'],
            $r['assignment_status'],
            $r['completed_at'] ?? 'N/A',
            $r['completion_notes'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

$title = 'Reports & Analytics';
require 'partials/header.php';

// Prepare Filter Arguments
$where = ["u.status = 'Active'"];
$args = [];

if (!empty($_GET['technician_id'])) {
    $where[] = 'a.technician_id = ?';
    $args[] = (int)$_GET['technician_id'];
}

if (!empty($_GET['office_id'])) {
    $where[] = 'e.office_id = ?';
    $args[] = (int)$_GET['office_id'];
}

if (!empty($_GET['event_status'])) {
    $where[] = 'e.status = ?';
    $args[] = $_GET['event_status'];
}

if (!empty($_GET['assignment_status'])) {
    $where[] = 'a.status = ?';
    $args[] = $_GET['assignment_status'];
}

$sql = 'SELECT a.id, e.title, o.name office_name, e.category, e.priority, e.start_at, u.full_name technician, ab.full_name assigned_by_name, e.status event_status, a.status assignment_status, a.completed_at, a.completion_notes 
        FROM event_assignments a 
        JOIN events e ON e.id = a.event_id 
        JOIN offices o ON o.id = e.office_id 
        JOIN users u ON u.id = a.technician_id 
        JOIN users ab ON ab.id = a.assigned_by '
        . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . 
        ' ORDER BY e.start_at DESC';

$q = db()->prepare($sql);
$q->execute($args);
$rows = $q->fetchAll();

// Statistics Summary
$total = count($rows);
$newAssign = count(array_filter($rows, fn($r) => $r['assignment_status'] === 'New Assignment'));
$accepted = count(array_filter($rows, fn($r) => $r['assignment_status'] === 'Accepted'));
$inProgress = count(array_filter($rows, fn($r) => $r['assignment_status'] === 'In Progress'));
$done = count(array_filter($rows, fn($r) => $r['assignment_status'] === 'Completed'));
$rate = $total ? round(($done / $total) * 100) : 0;

$technicians = db()->query("SELECT u.id, u.full_name, r.name role_name
                            FROM users u
                            JOIN roles r ON r.id = u.role_id
                            WHERE u.status = 'Active'
                            ORDER BY u.full_name")->fetchAll();
$selectedTechnicianId = (int)($_GET['technician_id'] ?? 0);
$selectedTechnicianLabel = 'All Active Users';
foreach ($technicians as $technician) {
    if ((int)$technician['id'] === $selectedTechnicianId) {
        $selectedTechnicianLabel = $technician['full_name'];
        break;
    }
}
$offices = db()->query("SELECT * FROM offices ORDER BY name")->fetchAll();
?>

<style>
@media print {
    @page { size: A4 landscape; margin: 10mm; }
    html, body { width: 100% !important; background: #fff !important; font-size: 9pt !important; }
    .app-navbar, .app-sidebar, .mobile-dock, .sidebar-scrim,
    .report-actions, .report-filters { display: none !important; }
    .app-wrapper { display: block !important; min-height: 0 !important; }
    .app-content { display: block !important; width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
    .report-heading { margin-bottom: 8pt !important; }
    .report-heading h3 { font-size: 18pt !important; }
    .report-metrics { display: grid !important; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8pt !important; margin: 0 0 10pt !important; }
    .report-metrics > [class*="col-"] { width: auto !important; max-width: none !important; flex: none !important; padding: 0 !important; }
    .report-metrics .card, .report-table-card { box-shadow: none !important; border: 1px solid #d5ddda !important; }
    .report-table-card { width: 100% !important; break-inside: auto; }
    .app-table-container, .table-responsive { width: 100% !important; overflow: visible !important; }
    .report-table { width: 100% !important; min-width: 0 !important; table-layout: fixed !important; font-size: 8pt !important; }
    .report-table th, .report-table td { white-space: normal !important; overflow-wrap: anywhere !important; word-break: normal !important; padding: 4pt !important; }
    .report-table thead { display: table-header-group; }
    .report-table tr { break-inside: avoid; }
    .report-table .badge { border: 1px solid #aaa !important; color: #222 !important; background: #f3f5f4 !important; }
}
</style>

<div class="report-heading d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Technician Assignment & Performance Reports</h3>
        <p class="text-secondary small mb-0">Assignment tracking for all active user accounts, including Super Administrator, with office requests and completion analytics.</p>
    </div>
    <div class="report-actions d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1">
            <i class="bi bi-printer"></i> Print Report
        </button>
        <a href="reports.php?export=csv<?= http_build_query($_GET) ? '&' . http_build_query($_GET) : '' ?>" class="btn btn-primary d-inline-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-download"></i> Export CSV
        </a>
    </div>
</div>

<!-- Report Filters Card -->
<div class="report-filters card stat-card p-3 mb-4 border-0">
    <form class="row g-2 align-items-center" method="get">
        <div class="col-6 col-md-3">
            <input type="hidden" name="technician_id" id="technicianFilterId" value="<?= $selectedTechnicianId > 0 ? $selectedTechnicianId : '' ?>">
            <div class="dropdown w-100">
                <button class="form-select bg-light text-start d-flex justify-content-between align-items-center" type="button" id="technicianFilterButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <span><?= h($selectedTechnicianLabel) ?></span>
                </button>
                <div class="dropdown-menu w-100 p-0" aria-labelledby="technicianFilterButton" style="max-height: 280px; overflow-y: auto; overscroll-behavior: contain;">
                    <button class="dropdown-item py-2 technician-filter-option" type="button" data-user-id="">All Active Users</button>
                    <?php foreach ($technicians as $t): ?>
                        <button class="dropdown-item py-2 technician-filter-option" type="button" data-user-id="<?= (int)$t['id'] ?>">
                            <?= h($t['full_name']) ?><small class="d-block text-muted"><?= h($t['role_name']) ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select bg-light" name="office_id">
                <option value="">All Offices</option>
                <?php foreach ($offices as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= ($_GET['office_id'] ?? '') == $o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select bg-light" name="event_status">
                <option value="">Event Status</option>
                <?php foreach (['New', 'Pending', 'Assigned', 'In Progress', 'Completed', 'Cancelled', 'Rescheduled'] as $s): ?>
                    <option <?= ($_GET['event_status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select bg-light" name="assignment_status">
                <option value="">Task Status</option>
                <?php foreach (['New Assignment', 'Accepted', 'In Progress', 'Completed'] as $as): ?>
                    <option <?= ($_GET['assignment_status'] ?? '') === $as ? 'selected' : '' ?>><?= $as ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-secondary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <a href="reports.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<!-- Metrics Cards -->
<div class="report-metrics row g-3 mb-4">
    <div class="col-md-2 col-6">
        <div class="card stat-card p-3 border-0">
            <span class="stat-label">Total Assignments</span>
            <div class="stat-value text-primary"><?= $total ?></div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card stat-card p-3 border-0">
            <span class="stat-label">New Task</span>
            <div class="stat-value text-info"><?= $newAssign ?></div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card stat-card p-3 border-0">
            <span class="stat-label">In Progress</span>
            <div class="stat-value text-warning"><?= $inProgress ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 border-0">
            <span class="stat-label">Completed</span>
            <div class="stat-value text-success"><?= $done ?></div>
        </div>
    </div>
    <div class="col-md-3 col-12">
        <div class="card stat-card p-3 border-0">
            <span class="stat-label">Completion Rate</span>
            <div class="stat-value text-dark"><?= $rate ?>%</div>
        </div>
    </div>
</div>

<!-- Assignment-Based Table -->
<div class="report-table-card card stat-card border-0">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="report-table table app-table align-middle">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>Event Title</th>
                        <th>Office</th>
                        <th>Technician</th>
                        <th>Assigned By</th>
                        <th>Event Date</th>
                        <th>Event Status</th>
                        <th>Assignment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-bold text-muted">#<?= $r['id'] ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= h($r['title']) ?></div>
                                <div class="small text-muted"><?= h($r['category']) ?> · Priority: <?= h($r['priority']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($r['office_name']) ?></span>
                            </td>
                            <td>
                                <span class="fw-semibold text-dark"><i class="bi bi-person me-1"></i> <?= h($r['technician']) ?></span>
                            </td>
                            <td class="small text-secondary"><?= h($r['assigned_by_name']) ?></td>
                            <td>
                                <div class="small fw-semibold"><?= date('M j, Y', strtotime($r['start_at'])) ?></div>
                            </td>
                            <td>
                                <span class="badge badge-soft-<?= status_class($r['event_status']) ?>">
                                    <?= h($r['event_status']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-soft-<?= status_class($r['assignment_status']) ?>">
                                    ● <?= h($r['assignment_status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                No technician assignment logs found matching your filter criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var technicianId = document.getElementById('technicianFilterId');
    var technicianButton = document.getElementById('technicianFilterButton');
    var technicianOptions = document.querySelectorAll('.technician-filter-option');
    if (!technicianId || !technicianButton) return;

    technicianOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            technicianId.value = this.getAttribute('data-user-id') || '';
            technicianButton.querySelector('span').textContent = this.childNodes[0].textContent.trim();
        });
    });
});
</script>

<?php require 'partials/footer.php'; ?>
