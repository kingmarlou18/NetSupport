<?php
require_once 'config.php';
require_login();

$me = user();
$userId = (int)$me['id'];
$isTech = is_field_staff();
$viewingOwnTasks = $isTech || (($_GET['view'] ?? '') === 'mine');
$taskStatusOptions = [
    'all' => 'All',
    'new' => 'New Assignment',
    'accepted' => 'Accepted',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
$taskStatusFilter = (string)($_GET['status'] ?? $_POST['task_filter'] ?? 'all');
if (!array_key_exists($taskStatusFilter, $taskStatusOptions)) {
    $taskStatusFilter = 'all';
}
$returnParams = [];
if ($viewingOwnTasks && !$isTech) $returnParams['view'] = 'mine';
if ($taskStatusFilter !== 'all') $returnParams['status'] = $taskStatusFilter;
$taskReturnUrl = 'my_tasks.php' . ($returnParams ? '?' . http_build_query($returnParams) : '');

// Handle Quick Status Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action = $_POST['action'];

    if ($action === 'quick_status' && isset($_POST['accept_crew'])) {
        $assignId = (int)($_POST['assignment_id'] ?? 0);
        $leadCheck = db()->prepare("SELECT a.event_id, e.title FROM event_assignments a JOIN events e ON e.id = a.event_id WHERE a.id = ? AND a.technician_id = ? AND a.is_team_lead = 1 AND a.status = 'New Assignment'");
        $leadCheck->execute([$assignId, $userId]);
        $leadRow = $leadCheck->fetch();

        if ($leadRow) {
            $crewQuery = db()->prepare("SELECT technician_id FROM event_assignments WHERE event_id = ? AND status = 'New Assignment'");
            $crewQuery->execute([$leadRow['event_id']]);
            $crewIds = $crewQuery->fetchAll(PDO::FETCH_COLUMN);

            $accept = db()->prepare("UPDATE event_assignments SET status = 'Accepted', accepted_at = COALESCE(accepted_at, NOW()) WHERE event_id = ? AND status = 'New Assignment'");
            $accept->execute([$leadRow['event_id']]);

            if ($accept->rowCount() > 0) {
                log_activity('Team lead accepted the assignment for the crew', (int)$leadRow['event_id']);
                foreach ($crewIds as $crewId) {
                    if ((int)$crewId !== $userId) {
                        notify((int)$crewId, user()['full_name'] . ' accepted the assignment for the crew: ' . $leadRow['title'], (int)$leadRow['event_id']);
                    }
                }
                $_SESSION['flash_msg'] = 'Assignment accepted for the whole crew.';
            } else {
                $_SESSION['flash_msg'] = 'The crew assignment was already accepted.';
            }
        } else {
            $_SESSION['flash_msg'] = 'Only the assigned team leader can accept this assignment for the crew.';
        }

        header('Location: ' . $taskReturnUrl);
        exit;
    }

    if ($action === 'quick_status') {
        $assignId = (int)$_POST['assignment_id'];
        $newStatus = $_POST['status'];

        // Verify assignment belongs to this technician (or user is admin)
        $qCheck = db()->prepare('SELECT a.*, e.id event_id FROM event_assignments a JOIN events e ON e.id = a.event_id WHERE a.id = ? AND a.technician_id = ?');
        $qCheck->execute([$assignId, $userId]);
        $assignRow = $qCheck->fetch();

        if ($assignRow) {
            $acceptedAt = $assignRow['accepted_at'];
            $startedAt = $assignRow['started_at'];
            $completedAt = $assignRow['completed_at'];

            if ($newStatus === 'Accepted' && !$acceptedAt) $acceptedAt = date('Y-m-d H:i:s');
            if ($newStatus === 'In Progress' && !$startedAt) $startedAt = date('Y-m-d H:i:s');
            if ($newStatus === 'Completed' && !$completedAt) $completedAt = date('Y-m-d H:i:s');

            $up = db()->prepare('UPDATE event_assignments SET status = ?, accepted_at = ?, started_at = ?, completed_at = ? WHERE id = ?');
            $up->execute([$newStatus, $acceptedAt, $startedAt, $completedAt, $assignId]);

            // Auto sync event status
            if ($newStatus === 'In Progress') {
                db()->prepare('UPDATE events SET status = "In Progress" WHERE id = ?')->execute([$assignRow['event_id']]);
            } elseif ($newStatus === 'Completed') {
                db()->prepare('UPDATE events SET status = "Completed" WHERE id = ?')->execute([$assignRow['event_id']]);
            }

            log_activity("Updated task status to '$newStatus' for event #{$assignRow['event_id']}", $assignRow['event_id']);
            $_SESSION['flash_msg'] = "Task status updated to $newStatus successfully!";
        }
        header('Location: ' . $taskReturnUrl);
        exit;
    }

    // Toggle checklist
    if ($action === 'toggle_checklist') {
        $itemId = (int)$_POST['checklist_id'];
        $val = isset($_POST['completed']) ? 1 : 0;
        db()->prepare('UPDATE event_checklists SET completed = ? WHERE id = ?')->execute([$val, $itemId]);
        header('Location: ' . $taskReturnUrl);
        exit;
    }
}

// Fetch active assignments for this technician (or all if admin viewing)
$sql = "SELECT a.*, e.title event_title, e.start_at, e.end_at, e.location, e.priority, e.category, e.primary_connection, e.backup_connection, o.name office_name, u.full_name technician_name
        FROM event_assignments a
        JOIN events e ON e.id = a.event_id
        JOIN offices o ON o.id = e.office_id
        JOIN users u ON u.id = a.technician_id
        WHERE " . ($viewingOwnTasks ? "a.technician_id = $userId" : "1=1") . "
        ORDER BY CASE WHEN a.status IN ('New Assignment', 'Accepted', 'In Progress') THEN 0 ELSE 1 END, e.start_at ASC";

$myAssignments = db()->query($sql)->fetchAll();

$taskStatusCounts = array_fill_keys(array_keys($taskStatusOptions), 0);
$taskStatusCounts['all'] = count($myAssignments);
foreach ($myAssignments as $task) {
    $countKey = array_search($task['status'], $taskStatusOptions, true);
    if ($countKey !== false && $countKey !== 'all') {
        $taskStatusCounts[$countKey]++;
    }
}
if ($taskStatusFilter !== 'all') {
    $filteredStatus = $taskStatusOptions[$taskStatusFilter];
    $myAssignments = array_values(array_filter($myAssignments, static fn($task) => $task['status'] === $filteredStatus));
}

// Group into Active vs Completed
$activeTasks = [];
$completedTasks = [];

foreach ($myAssignments as $task) {
    // Fetch equipment for this event
    $qEq = db()->prepare('SELECT ee.quantity, eq.name, eq.category FROM event_equipment ee JOIN equipment eq ON eq.id = ee.equipment_id WHERE ee.event_id = ?');
    $qEq->execute([$task['event_id']]);
    $task['equipment'] = $qEq->fetchAll();

    // Fetch checklists
    $qChk = db()->prepare('SELECT * FROM event_checklists WHERE assignment_id = ? ORDER BY id ASC');
    $qChk->execute([$task['id']]);
    $task['checklists'] = $qChk->fetchAll();

    if (in_array($task['status'], ['Completed', 'Cancelled'])) {
        $completedTasks[] = $task;
    } else {
        $activeTasks[] = $task;
    }
}

$title = 'My Tasks & Dispatch';
require 'partials/header.php';

$showActiveSection = in_array($taskStatusFilter, ['all', 'new', 'accepted', 'in_progress'], true);
$showHistorySection = in_array($taskStatusFilter, ['all', 'completed', 'cancelled'], true);

$flash = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">
            <i class="bi bi-person-workspace text-primary me-2"></i><?= $viewingOwnTasks ? 'My Task Assignments &amp; Dispatch' : 'Technician Task Roster' ?>
        </h3>
        <p class="text-secondary small mb-0"><?= $viewingOwnTasks ? 'Manage your assigned support work and duty progress.' : 'Review technician assignments and their current status.' ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="tech_report.php" class="btn btn-success btn-sm fw-semibold">
            <i class="bi bi-journal-check me-1"></i> Submit Tech Report &rarr;
        </a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm py-2 px-3 small mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i> <?= h($flash) ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card stat-card border-0 p-3 mb-4 bg-white shadow-sm">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
        <div class="small fw-semibold text-secondary"><i class="bi bi-funnel me-1"></i> Filter tasks by status</div>
        <div class="d-flex flex-wrap gap-2" role="group" aria-label="Filter technician tasks by status">
            <?php foreach ($taskStatusOptions as $filterKey => $filterLabel): ?>
                <?php
                $filterParams = [];
                if ($viewingOwnTasks && !$isTech) $filterParams['view'] = 'mine';
                if ($filterKey !== 'all') $filterParams['status'] = $filterKey;
                $filterUrl = 'my_tasks.php' . ($filterParams ? '?' . http_build_query($filterParams) : '');
                ?>
                <a href="<?= h($filterUrl) ?>" class="btn btn-sm <?= $taskStatusFilter === $filterKey ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= h($filterLabel) ?>
                    <span class="badge <?= $taskStatusFilter === $filterKey ? 'bg-white text-primary' : 'bg-light text-secondary border' ?> ms-1"><?= (int)$taskStatusCounts[$filterKey] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="input-group mt-3 pt-3 border-top" role="search">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted" aria-hidden="true"></i></span>
        <input type="search" id="taskRosterSearch" class="form-control bg-light border-start-0" placeholder="Search event, office, location, technician, date or status..." autocomplete="off" aria-label="Search technician tasks">
        <button type="button" class="btn btn-outline-secondary" id="clearTaskRosterSearch" aria-label="Clear task search"><i class="bi bi-x-lg"></i></button>
        <span class="input-group-text small text-muted" id="taskSearchSummary" aria-live="polite"></span>
    </div>
</div>

<!-- ═══════ SECTION 1: Active Duty Tasks ═══════ -->
<?php if ($showActiveSection): ?>
<div class="mb-5" id="activeTaskSection">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-lightning-charge-fill text-warning me-2"></i>Active Assignments (<span id="activeTaskCount"><?= count($activeTasks) ?></span>)
        </h5>
    </div>

    <?php if (empty($activeTasks)): ?>
        <div class="card stat-card border-0 p-5 text-center bg-white shadow-sm task-empty-state">
            <div class="fs-1 text-muted mb-2"><i class="bi bi-check2-all text-success"></i></div>
            <h6 class="fw-bold text-dark">No matching assignments</h6>
            <p class="text-muted small mb-0">There are no tasks under this status for the selected roster.</p>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($activeTasks as $t): ?>
            <?php $taskSearchText = implode(' ', [$t['event_title'], $t['office_name'], $t['location'], $t['technician_name'], $t['category'], $t['status'], $t['primary_connection'] ?? '', date('M j Y', strtotime($t['start_at']))]); ?>
            <div class="col-lg-6 task-search-item" data-task-section="active" data-task-search="<?= h(strtolower($taskSearchText)) ?>">
                <div class="card stat-card border-0 h-100 shadow-sm border-start border-4 border-<?= status_class($t['status']) ?> p-4 bg-white">
                    
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-light text-secondary border mb-1"><?= h($t['office_name']) ?></span><?php if (!$viewingOwnTasks): ?> <span class="badge bg-info-subtle text-info border border-info-subtle mb-1"><?= h($t['technician_name']) ?></span><?php endif; ?><?php if ($viewingOwnTasks && !empty($t['is_team_lead'])): ?> <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1">Team Leader</span><?php endif; ?>
                            <h5 class="fw-bold text-dark mb-1">
                                <a href="event.php?id=<?= $t['event_id'] ?>" class="text-decoration-none text-dark hover-primary">
                                    <?= h($t['event_title']) ?>
                                </a>
                            </h5>
                        </div>
                        <span class="badge badge-soft-<?= status_class($t['status']) ?> fs-6 px-3 py-1">
                            ● <?= h($t['status']) ?>
                        </span>
                    </div>

                    <!-- Details -->
                    <div class="small text-secondary mb-3">
                        <div class="mb-1"><i class="bi bi-geo-alt text-danger me-1"></i> <strong>Location:</strong> <?= h($t['location']) ?></div>
                        <div class="mb-1"><i class="bi bi-clock text-primary me-1"></i> <strong>Schedule:</strong> <?= date('M j, Y g:i A', strtotime($t['start_at'])) ?> &mdash; <?= date('g:i A', strtotime($t['end_at'])) ?></div>
                        <div><i class="bi bi-wifi text-success me-1"></i> <strong>Connection:</strong> <?= h($t['primary_connection'] ?: 'Standard Network') ?></div>
                    </div>

                    <!-- Allocated Equipment Pills -->
                    <div class="mb-3 p-2 bg-light rounded border">
                        <div class="small fw-semibold text-dark mb-1"><i class="bi bi-hdd-network text-primary me-1"></i> Allocated Equipment:</div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if (!empty($t['equipment'])): ?>
                                <?php foreach ($t['equipment'] as $eq): ?>
                                    <span class="badge bg-white text-dark border">
                                        <?= h($eq['name']) ?> <strong class="text-primary">x<?= (int)$eq['quantity'] ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="small text-muted">No specific equipment allocated.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Checklist items (if any) -->
                    <?php if ($viewingOwnTasks && !empty($t['checklists'])): ?>
                        <div class="mb-3">
                            <div class="small fw-semibold text-dark mb-1"><i class="bi bi-card-checklist text-primary me-1"></i> Field Readiness Checklist:</div>
                            <div class="d-flex flex-column gap-1">
                                <?php foreach ($t['checklists'] as $chk): ?>
                                    <form method="post" class="d-flex justify-content-between align-items-center p-1 px-2 rounded bg-light border m-0">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="task_filter" value="<?= h($taskStatusFilter) ?>">
                                        <input type="hidden" name="action" value="toggle_checklist">
                                        <input type="hidden" name="checklist_id" value="<?= $chk['id'] ?>">
                                        <span class="small <?= $chk['completed'] ? 'text-decoration-line-through text-muted' : 'fw-semibold text-dark' ?>">
                                            <?= h($chk['item_name']) ?>
                                        </span>
                                        <button type="submit" name="completed" value="<?= $chk['completed'] ? '0' : '1' ?>" class="btn btn-sm py-0 px-2 <?= $chk['completed'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                            <i class="bi <?= $chk['completed'] ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons for Status -->
                    <div class="mt-auto pt-3 border-top">
                        <?php if ($viewingOwnTasks): ?>
                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <!-- Quick Status Buttons -->
                            <form method="post" class="d-flex flex-wrap gap-1 m-0">
                                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                <input type="hidden" name="task_filter" value="<?= h($taskStatusFilter) ?>">
                                <input type="hidden" name="action" value="quick_status">
                                <input type="hidden" name="assignment_id" value="<?= $t['id'] ?>">

                                <?php if ($t['status'] === 'New Assignment'): ?>
                                    <?php if ($viewingOwnTasks && !empty($t['is_team_lead'])): ?>
                                        <button type="submit" name="accept_crew" value="1" class="btn btn-sm btn-primary fw-semibold">
                                            <i class="bi bi-people-fill me-1"></i> Accept for Crew
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="status" value="Accepted" class="btn btn-sm btn-primary fw-semibold">
                                            <i class="bi bi-check-lg me-1"></i> Accept Assignment
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if (in_array($t['status'], ['New Assignment', 'Accepted'])): ?>
                                    <button type="submit" name="status" value="In Progress" class="btn btn-sm btn-warning text-dark fw-semibold">
                                        <i class="bi bi-play-circle me-1"></i> Start / In Progress
                                    </button>
                                <?php endif; ?>

                                <?php if ($t['status'] === 'In Progress'): ?>
                                    <button type="submit" name="status" value="Completed" class="btn btn-sm btn-success fw-semibold">
                                        <i class="bi bi-check2-all me-1"></i> Mark Completed
                                    </button>
                                <?php endif; ?>
                            </form>

                            <!-- Link to Submit Report -->
                            <a href="tech_report.php?id=<?= $t['event_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-journal-text me-1"></i> Report
                            </a>
                        </div>
                        <?php else: ?>
                            <div class="small text-muted">Read-only roster view</div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════ SECTION 2: Completed Assignment History ═══════ -->
<?php if ($showHistorySection): ?>
<?php if (!empty($completedTasks)): ?>
<div class="card stat-card border-0 p-4 bg-white shadow-sm mb-4" id="historyTaskSection">
    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-archive text-secondary me-2"></i> <?= $taskStatusFilter === 'cancelled' ? 'Cancelled Task History' : 'Completed Task History' ?></h5>
    
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light small">
                <tr>
                    <th>Event</th>
                    <th>Office</th>
                    <th>Location</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody class="small">
                <?php foreach ($completedTasks as $ct): ?>
                    <?php $historySearchText = implode(' ', [$ct['event_title'], $ct['office_name'], $ct['location'], $ct['technician_name'], $ct['category'], $ct['status'], date('M j Y', strtotime($ct['start_at']))]); ?>
                    <tr class="task-search-item" data-task-section="history" data-task-search="<?= h(strtolower($historySearchText)) ?>">
                        <td class="fw-bold text-dark">
                            <a href="event.php?id=<?= $ct['event_id'] ?>" class="text-decoration-none text-dark">
                                <?= h($ct['event_title']) ?>
                            </a>
                        </td>
                        <td><?= h($ct['office_name']) ?></td>
                        <td><?= h($ct['location']) ?></td>
                        <td><?= date('M j, Y', strtotime($ct['start_at'])) ?></td>
                        <td><span class="badge badge-soft-<?= status_class($ct['status']) ?>"><?= h($ct['status']) ?></span></td>
                        <td class="text-end">
                            <a href="tech_report.php?id=<?= $ct['event_id'] ?>" class="btn btn-sm btn-outline-secondary py-0">View Report</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($taskStatusFilter !== 'all'): ?>
<div class="card stat-card border-0 p-4 text-center bg-white shadow-sm mb-4 task-empty-state" id="historyTaskSection">
    <h6 class="fw-bold text-dark mb-1">No <?= h($taskStatusOptions[$taskStatusFilter]) ?> tasks found</h6>
    <p class="text-muted small mb-0">Choose another status filter to view more assignments.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card stat-card border-0 p-4 text-center bg-white shadow-sm mb-4 d-none" id="taskSearchEmpty">
    <i class="bi bi-search fs-3 text-muted mb-2" aria-hidden="true"></i>
    <h6 class="fw-bold text-dark mb-1">No matching tasks</h6>
    <p class="text-muted small mb-0">Try another event, office, location, technician, date, or status.</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('taskRosterSearch');
    const clear = document.getElementById('clearTaskRosterSearch');
    const summary = document.getElementById('taskSearchSummary');
    const noResults = document.getElementById('taskSearchEmpty');
    const items = Array.from(document.querySelectorAll('.task-search-item'));
    if (!input) return;

    function filterTasks() {
        const term = input.value.trim().toLowerCase();
        const counts = { active: 0, history: 0 };

        items.forEach(function (item) {
            const matches = !term || (item.dataset.taskSearch || '').includes(term);
            item.classList.toggle('d-none', !matches);
            if (matches) counts[item.dataset.taskSection]++;
        });

        const total = counts.active + counts.history;
        const activeSection = document.getElementById('activeTaskSection');
        const historySection = document.getElementById('historyTaskSection');
        if (activeSection) activeSection.classList.toggle('d-none', Boolean(term) && counts.active === 0);
        if (historySection) historySection.classList.toggle('d-none', Boolean(term) && counts.history === 0);
        document.querySelectorAll('.task-empty-state').forEach(function (emptyState) {
            emptyState.classList.toggle('d-none', Boolean(term));
        });

        const activeCount = document.getElementById('activeTaskCount');
        if (activeCount) activeCount.textContent = String(counts.active);
        summary.textContent = total + (total === 1 ? ' task' : ' tasks');
        noResults.classList.toggle('d-none', !(term && total === 0));
    }

    input.addEventListener('input', filterTasks);
    clear.addEventListener('click', function () {
        input.value = '';
        filterTasks();
        input.focus();
    });
    filterTasks();
});
</script>

<?php require 'partials/footer.php'; ?>
