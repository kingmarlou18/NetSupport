<?php
require_once 'config.php';
$title = 'Dashboard';
require 'partials/header.php';

$role = user()['role'];
$isFieldStaff = is_field_staff();
$userId = (int)user()['id'];

// Date Range Filter
$dateFilter = $_GET['range'] ?? 'all';
$customStart = $_GET['start_date'] ?? '';
$customEnd = $_GET['end_date'] ?? '';

$dateWhere = '';
$dateArgs = [];

if ($dateFilter === 'today') {
    $dateWhere = ' AND DATE(e.start_at) = CURDATE()';
} elseif ($dateFilter === 'yesterday') {
    $dateWhere = ' AND DATE(e.start_at) = SUBDATE(CURDATE(), 1)';
} elseif ($dateFilter === 'this_week') {
    $dateWhere = ' AND YEARWEEK(e.start_at, 1) = YEARWEEK(CURDATE(), 1)';
} elseif ($dateFilter === 'this_month') {
    $dateWhere = ' AND MONTH(e.start_at) = MONTH(CURDATE()) AND YEAR(e.start_at) = YEAR(CURDATE())';
} elseif ($dateFilter === 'this_year') {
    $dateWhere = ' AND YEAR(e.start_at) = YEAR(CURDATE())';
} elseif ($dateFilter === 'custom' && $customStart && $customEnd) {
    $dateWhere = ' AND DATE(e.start_at) BETWEEN ? AND ?';
    $dateArgs[] = $customStart;
    $dateArgs[] = $customEnd;
}

// Build Event Query
$sql = $isFieldStaff
    ? 'SELECT e.*, o.name AS office_name FROM events e JOIN offices o ON o.id = e.office_id JOIN event_assignments a ON a.event_id = e.id WHERE a.technician_id = ' . $userId . $dateWhere
    : 'SELECT e.*, o.name AS office_name FROM events e JOIN offices o ON o.id = e.office_id WHERE 1=1' . $dateWhere;

$q = db()->prepare($sql . ' ORDER BY e.start_at ASC');
$q->execute($dateArgs);
$events = $q->fetchAll();

// Calculate counts by Status
$statusCounts = [];
$statuses = ['New', 'Pending', 'Assigned', 'In Progress', 'Completed', 'Cancelled', 'Rescheduled'];
foreach ($statuses as $s) {
    $statusCounts[$s] = count(array_filter($events, fn($e) => $e['status'] === $s));
}

$totalEvents = count($events);
$completedEvents = $statusCounts['Completed'];
$overallCompletionRate = $totalEvents ? round(($completedEvents / $totalEvents) * 100) : 0;

// User Statistics (Super Admin)
$userStats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'super_admins' => 0, 'admins' => 0, 'technicians' => 0];
if ($role === 'Super Administrator') {
    $userStats['total'] = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $userStats['active'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='Active'")->fetchColumn();
    $userStats['inactive'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='Inactive'")->fetchColumn();
    $userStats['super_admins'] = (int)db()->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='Super Administrator'")->fetchColumn();
    $userStats['admins'] = (int)db()->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='Administrator'")->fetchColumn();
    $userStats['technicians'] = (int)db()->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name IN ('Network Technician', 'CCS Staff')")->fetchColumn();
}

// Technician Duty Status & Workload Summary
$techPerformance = db()->query("SELECT u.id, u.full_name, r.name AS role_name,
                    COALESCE(t.availability, 'Available') AS availability,
                    COUNT(a.id) total_assigned,
                    SUM(CASE WHEN a.status = 'New Assignment' THEN 1 ELSE 0 END) count_new,
                    SUM(CASE WHEN a.status = 'In Progress' THEN 1 ELSE 0 END) count_progress,
                    SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) count_completed
                    FROM users u 
                    JOIN roles r ON r.id = u.role_id 
                    LEFT JOIN technician_availability t ON t.technician_id = u.id 
                    LEFT JOIN event_assignments a ON a.technician_id = u.id 
                    WHERE r.name IN ('Network Technician', 'CCS Staff', 'Administrator', 'Super Administrator') 
                      AND u.status = 'Active' 
                    GROUP BY u.id 
                    ORDER BY 
                      CASE 
                        WHEN r.name = 'Super Administrator' THEN 1 
                        WHEN r.name = 'Administrator' THEN 2 
                        ELSE 3 
                      END ASC, 
                      u.full_name ASC")->fetchAll();

$dutyCounts = ['Available' => 0, 'Busy' => 0, 'On Leave' => 0, 'Unavailable' => 0];
foreach ($techPerformance as $tp) {
    $st = $tp['availability'] ?? 'Available';
    if (isset($dutyCounts[$st])) {
        $dutyCounts[$st]++;
    } else {
        $dutyCounts[$st] = 1;
    }
}

// Equipment Usage Summary
$equipmentUsage = [];
if (!$isFieldStaff) {
    $equipmentUsage = db()->query("SELECT eq.name, eq.category, eq.quantity_available, eq.quantity_assigned 
                                    FROM equipment eq ORDER BY eq.quantity_assigned DESC LIMIT 6")->fetchAll();
}

// Counts by Priority
$priorityCounts = ['Urgent' => 0, 'High' => 0, 'Normal' => 0, 'Low' => 0];
foreach ($events as $e) {
    $p = $e['priority'] ?? 'Normal';
    if (isset($priorityCounts[$p])) $priorityCounts[$p]++;
}

$todayEventsCount = count(array_filter($events, fn($e) => strtotime($e['start_at']) >= strtotime('today') && strtotime($e['start_at']) < strtotime('tomorrow')));
$urgentEventsCount = count(array_filter($events, fn($e) => in_array($e['priority'], ['High', 'Urgent']) && $e['status'] !== 'Completed'));
?>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Header & Date Quick Filter -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Good day, <?= h(explode(' ', user()['full_name'])[0]) ?> 👋</h3>
        <p class="text-secondary small mb-0">Role: <span class="badge bg-primary-subtle text-primary fw-bold"><?= h($role) ?></span> · Network Operations Overview.</p>
    </div>
    
    <!-- Quick Date Filter Toolbar -->
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center m-0">
            <select class="form-select form-select-sm bg-white" name="range" onchange="this.form.submit()">
                <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="yesterday" <?= $dateFilter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                <option value="this_week" <?= $dateFilter === 'this_week' ? 'selected' : '' ?>>This Week</option>
                <option value="this_month" <?= $dateFilter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="this_year" <?= $dateFilter === 'this_year' ? 'selected' : '' ?>>This Year</option>
            </select>
        </form>

        <?php if (!$isFieldStaff): ?>
            <a class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1 shadow-sm" href="event_form.php">
                <i class="bi bi-plus-lg"></i> New Request
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Prominent Status Cards (New, Pending, Completed Highlighted) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg">
        <a class="card stat-card p-3 text-decoration-none text-dark h-100 border-start border-4 border-primary" href="events.php?status=New">
            <span class="stat-label text-primary">New Requests</span>
            <div class="stat-value text-primary"><?= $statusCounts['New'] ?></div>
        </a>
    </div>

    <div class="col-6 col-md-4 col-lg">
        <a class="card stat-card p-3 text-decoration-none text-dark h-100 border-start border-4 border-warning" href="events.php?status=Pending">
            <span class="stat-label text-warning">Pending Review</span>
            <div class="stat-value text-warning"><?= $statusCounts['Pending'] ?></div>
        </a>
    </div>

    <div class="col-6 col-md-4 col-lg">
        <a class="card stat-card p-3 text-decoration-none text-dark h-100 border-start border-4 border-info" href="events.php?status=Assigned">
            <span class="stat-label text-info">Assigned</span>
            <div class="stat-value"><?= $statusCounts['Assigned'] ?></div>
        </a>
    </div>

    <div class="col-6 col-md-4 col-lg">
        <a class="card stat-card p-3 text-decoration-none text-dark h-100 border-start border-4 border-primary" href="events.php?status=In Progress">
            <span class="stat-label text-dark">In Progress</span>
            <div class="stat-value"><?= $statusCounts['In Progress'] ?></div>
        </a>
    </div>

    <div class="col-6 col-md-4 col-lg">
        <a class="card stat-card p-3 text-decoration-none text-dark h-100 border-start border-4 border-success" href="events.php?status=Completed">
            <span class="stat-label text-success">Completed</span>
            <div class="stat-value text-success"><?= $statusCounts['Completed'] ?></div>
        </a>
    </div>
</div>

<!-- Super Admin Additional System Analytics Banner -->
<?php if ($role === 'Super Administrator'): ?>
<div class="card stat-card p-4 border-0 mb-4 bg-primary-subtle border-start border-4 border-primary">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-primary mb-0"><i class="bi bi-shield-lock me-2"></i> System-Wide User & Performance Metrics</h5>
        <span class="badge bg-primary text-white">Super Admin Scope</span>
    </div>

    <div class="row g-3">
        <div class="col-6 col-md-2">
            <div class="small text-secondary">Total Users</div>
            <div class="fw-bold fs-4 text-dark"><?= $userStats['total'] ?></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="small text-secondary">Active Users</div>
            <div class="fw-bold fs-4 text-success"><?= $userStats['active'] ?></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="small text-secondary">Super Admins</div>
            <div class="fw-bold fs-4 text-primary"><?= $userStats['super_admins'] ?></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="small text-secondary">Administrators</div>
            <div class="fw-bold fs-4 text-info"><?= $userStats['admins'] ?></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="small text-secondary">Field Staff</div>
            <div class="fw-bold fs-4 text-dark"><?= $userStats['technicians'] ?></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="small text-secondary">Completion Rate</div>
            <div class="fw-bold fs-4 text-success"><?= $overallCompletionRate ?>%</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Analytics Graphs Section -->
<?php if (!$isFieldStaff): ?>
<div class="row g-4 mb-4">
    <!-- Chart 1: Status Distribution -->
    <div class="col-lg-8">
        <div class="card stat-card border-0 h-100 p-4">
            <h5 class="fw-bold text-dark mb-1">Support Operations Status Analytics</h5>
            <p class="text-secondary small mb-3">Lifecycle distribution of support events</p>
            <div style="position: relative; min-height: 250px; height: 250px;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Technician Duty Status & Workload -->
    <div class="col-lg-4">
        <?php if (!empty($techPerformance)): ?>
            <div class="card stat-card border-0 p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-person-badge text-primary me-2"></i>Technician Duty Status
                    </h5>
                    <span class="badge bg-light text-secondary border"><?= count($techPerformance) ?> Staff</span>
                </div>
                
                <!-- Quick Status Counters -->
                <div class="d-flex flex-wrap gap-1 mb-2 pt-1">
                    <span class="badge badge-soft-success small px-2 py-1"><i class="bi bi-check-circle me-1"></i><?= $dutyCounts['Available'] ?> Available</span>
                    <span class="badge badge-soft-warning small px-2 py-1"><i class="bi bi-clock me-1"></i><?= $dutyCounts['Busy'] ?> Busy</span>
                    <span class="badge badge-soft-danger small px-2 py-1"><i class="bi bi-slash-circle me-1"></i><?= $dutyCounts['On Leave'] ?> On Leave</span>
                    <?php if (!empty($dutyCounts['Unavailable'])): ?>
                        <span class="badge badge-soft-secondary small px-2 py-1"><?= $dutyCounts['Unavailable'] ?> Unavailable</span>
                    <?php endif; ?>
                </div>

                <!-- Live Quick Filter for Personnel -->
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="dashboardTechSearch" class="form-control bg-light border-start-0" placeholder="Filter technician by name or status..." oninput="filterDashboardTechs(this.value)">
                </div>

                <div class="table-responsive flex-grow-1" style="max-height: 200px; overflow-y: auto;">
                    <table class="table table-sm align-middle small mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th>Personnel</th>
                                <th>Duty Status</th>
                                <th class="text-center">Assigned</th>
                                <th class="text-center">Done</th>
                                <th class="text-end">Rate</th>
                            </tr>
                        </thead>
                        <tbody id="dashTechTableBody">
                            <?php foreach ($techPerformance as $tp): ?>
                                <?php 
                                $rate = $tp['total_assigned'] ? round(($tp['count_completed'] / $tp['total_assigned']) * 100) : 0;
                                $avail = $tp['availability'];
                                $badgeClass = 'secondary';
                                if ($avail === 'Available') $badgeClass = 'success';
                                elseif ($avail === 'Busy') $badgeClass = 'warning';
                                elseif ($avail === 'On Leave') $badgeClass = 'danger';

                                $isSuperAdmin = ($tp['role_name'] === 'Super Administrator');
                                $isAdmin = ($tp['role_name'] === 'Administrator');
                                $avatarBg = ($isSuperAdmin || $isAdmin) ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-secondary-subtle text-secondary';
                                ?>
                                <tr class="dash-tech-row" data-search="<?= strtolower(h($tp['full_name'] . ' ' . $tp['role_name'] . ' ' . $avail)) ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="<?= $avatarBg ?> rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width: 26px; height: 26px; font-size: 0.72rem;">
                                                <?= strtoupper(substr($tp['full_name'], 0, 1)) ?>
                                            </div>
                                            <div class="text-truncate" style="max-width: 125px;">
                                                <div class="fw-semibold text-dark text-truncate d-flex align-items-center gap-1">
                                                    <span class="text-truncate"><?= h($tp['full_name']) ?></span>
                                                    <?php if ($isSuperAdmin): ?>
                                                        <span class="badge bg-dark-subtle text-dark border" style="font-size: 0.6rem;">Super Admin</span>
                                                    <?php elseif ($isAdmin): ?>
                                                        <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.6rem;">Admin</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.68rem;"><?= h($tp['role_name']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-soft-<?= $badgeClass ?> px-2 py-1">
                                            ● <?= h($avail) ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?= (int)$tp['total_assigned'] ?></td>
                                    <td class="text-center text-success fw-bold"><?= (int)$tp['count_completed'] ?></td>
                                    <td class="text-end fw-bold"><?= $rate ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Main Events Table & Side Workload Panels -->
<div class="row g-4">
    <!-- Upcoming Events List -->
    <section class="col-lg-8">
        <div class="card stat-card border-0">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark">Support Events List</h5>
                <a href="events.php" class="btn btn-sm btn-light text-primary fw-semibold">View All Directory &rarr;</a>
            </div>
            <div class="app-table-container">
                <div class="table-responsive">
                    <table class="table app-table align-middle">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Schedule</th>
                                <th>Office</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($events, 0, 8) as $e): ?>
                                <tr class="priority-<?= h($e['priority']) ?>">
                                    <td>
                                        <a href="event.php?id=<?= $e['id'] ?>" class="fw-bold text-dark text-decoration-none hover-primary">
                                            <?= h($e['title']) ?>
                                        </a>
                                        <div class="small text-muted d-flex align-items-center gap-1 mt-1">
                                            <i class="bi bi-geo-alt"></i> <?= h($e['location']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?= date('M j, Y', strtotime($e['start_at'])) ?></div>
                                        <div class="small text-muted"><?= date('g:i A', strtotime($e['start_at'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= h($e['office_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-soft-<?= status_class($e['status']) ?>">
                                            ● <?= h($e['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (empty($events)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        No support events found for this filter range.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- Sidebar Workload & Operational Highlights -->
    <aside class="col-lg-4">
        <!-- Priority Ratio Donut Chart -->
        <div class="card stat-card border-0 p-4 mb-4">
            <h5 class="fw-bold text-dark mb-1">Priority Ratio</h5>
            <p class="text-secondary small mb-3">Event urgency breakdown</p>
            <div style="position: relative; min-height: 230px; height: 230px;" class="d-flex align-items-center justify-content-center">
                <canvas id="priorityChart"></canvas>
            </div>
        </div>

        <!-- Operational Alerts Card -->
        <div class="card stat-card border-0 mb-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3 text-dark">Operational Snapshot</h5>
                
                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3">
                    <div class="fs-3 text-primary"><i class="bi bi-calendar2-event"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= $todayEventsCount ?></div>
                        <div class="small text-secondary">Events Scheduled Today</div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3">
                    <div class="fs-3 text-warning"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= $statusCounts['Pending'] ?></div>
                        <div class="small text-secondary">Requests Awaiting Review</div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3 p-3 bg-success-subtle text-success rounded-3 mb-3">
                    <div class="fs-3"><i class="bi bi-geo-alt-fill"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= count(array_filter($events, fn($e) => str_contains(strtolower($e['category']), 'brgy') || str_contains(strtolower($e['category']), 'outreach'))) ?></div>
                        <div class="small">Barangay & Mobile Outreach Sites</div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3 p-3 bg-danger-subtle text-danger rounded-3">
                    <div class="fs-3"><i class="bi bi-exclamation-triangle"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= $urgentEventsCount ?></div>
                        <div class="small">High / Urgent Priority Items</div>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</div>

<!-- Chart.js Initialization -->
<?php if (!$isFieldStaff): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctxStatus = document.getElementById('statusChart').getContext('2d');
    new Chart(ctxStatus, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($statusCounts)) ?>,
            datasets: [{
                label: 'Events',
                data: <?= json_encode(array_values($statusCounts)) ?>,
                backgroundColor: ['#2563eb', '#f59e0b', '#06b6d4', '#0e7490', '#10b981', '#ef4444', '#64748b'],
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    const ctxPriority = document.getElementById('priorityChart').getContext('2d');
    new Chart(ctxPriority, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($priorityCounts)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($priorityCounts)) ?>,
                backgroundColor: ['#ef4444', '#f59e0b', '#2563eb', '#64748b']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            cutout: '68%'
        }
    });
});
</script>
<?php endif; ?>

<script>
function filterDashboardTechs(query) {
    const q = (query || '').trim().toLowerCase();
    const rows = document.querySelectorAll('.dash-tech-row');
    rows.forEach(r => {
        const str = (r.dataset.search || '').toLowerCase();
        r.classList.toggle('d-none', !(!q || str.includes(q)));
    });
}
</script>

<?php require 'partials/footer.php'; ?>
