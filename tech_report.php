<?php
require_once 'config.php';
require_login();

$me = user();
$userId = (int)$me['id'];
$isTech = is_field_staff();

$selectedEventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$successMsg = '';
$errorMsg = '';

// Handle Technical Report Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tech_report') {
    verify_csrf();
    $eventId = (int)$_POST['event_id'];
    
    // Find assignment for this technician & event
    $qAssign = db()->prepare('SELECT * FROM event_assignments WHERE event_id = ?' . ($isTech ? ' AND technician_id = ?' : '') . ' ORDER BY id ASC LIMIT 1');
    $qAssign->execute($isTech ? [$eventId, $userId] : [$eventId]);
    $assignment = $qAssign->fetch();

    if ($assignment) {
        $notes = trim($_POST['completion_notes'] ?? '');
        $problems = trim($_POST['problems'] ?? '');
        $actionsTaken = trim($_POST['actions_taken'] ?? '');
        $perf = trim($_POST['performance'] ?? 'Excellent');
        $downtime = max(0, (int)($_POST['downtime_minutes'] ?? 0));
        $actualEquip = trim($_POST['actual_equipment'] ?? '');
        $netPerf = trim($_POST['internet_performance'] ?? '');

        // Update event_assignments table
        $up = db()->prepare('UPDATE event_assignments SET completion_notes = ?, problems = ?, actions_taken = ? WHERE id = ?');
        $up->execute([$notes, $problems, $actionsTaken, $assignment['id']]);

        // Save detailed record in event_reports
        try {
            $rep = db()->prepare('INSERT INTO event_reports (assignment_id, performance, downtime_minutes, recommendations) 
                                 VALUES (?, ?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE performance = VALUES(performance), downtime_minutes = VALUES(downtime_minutes), recommendations = VALUES(recommendations)');
            $rep->execute([$assignment['id'], $perf, $downtime, $actionsTaken]);
        } catch (PDOException $e) {}

        log_activity("Submitted technical completion report for event #$eventId", $eventId);
        $successMsg = 'Technical completion report saved successfully!';
        $selectedEventId = $eventId;
    } else {
        $errorMsg = 'Assignment record not found for this event.';
    }
}

// Fetch events available for report submission
$sqlEvents = "SELECT DISTINCT e.id, e.title, e.start_at, e.status, e.location, o.name office_name
              FROM event_assignments a
              JOIN events e ON e.id = a.event_id
              JOIN offices o ON o.id = e.office_id
              WHERE " . ($isTech ? "a.technician_id = $userId" : "1=1") . "
              ORDER BY e.start_at DESC";

$reportableEvents = db()->query($sqlEvents)->fetchAll();

// If event selected, fetch its current report data
$currentAssignment = null;
if ($selectedEventId > 0) {
    $qCur = db()->prepare('SELECT a.*, e.title event_title, e.location, e.start_at, e.end_at, o.name office_name 
                           FROM event_assignments a 
                           JOIN events e ON e.id = a.event_id 
                           JOIN offices o ON o.id = e.office_id 
                           WHERE a.event_id = ?' . ($isTech ? ' AND a.technician_id = ?' : '') . ' ORDER BY a.id ASC LIMIT 1');
    $qCur->execute($isTech ? [$selectedEventId, $userId] : [$selectedEventId]);
    $currentAssignment = $qCur->fetch();
}

$title = 'Technical Report Desk';
require 'partials/header.php';
?>

<style>
.report-event-menu {
    position: absolute;
    z-index: 1080;
    top: calc(100% + .25rem);
    left: 0;
    right: 0;
    overflow: hidden;
    border: 1px solid #d7e0e3;
    border-radius: .65rem;
    background: #fff;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .18);
}
.report-event-options { max-height: 290px; overflow-y: auto; padding: .35rem; }
.report-event-option { white-space: normal; border-radius: .45rem; }
.report-event-option:hover, .report-event-option:focus { background: #eef7f5; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-journal-text text-success me-2"></i>Technical Completion Report Portal</h3>
        <p class="text-secondary small mb-0">Record network telemetry, performance metrics, downtime logs, and post-setup findings.</p>
    </div>
    <a href="my_tasks.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> My Tasks</a>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm py-2 px-3 small mb-4">
        <i class="bi bi-check-circle-fill me-1"></i> <?= h($successMsg) ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger py-2 px-3 small mb-4"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= h($errorMsg) ?></div>
<?php endif; ?>

<div class="row g-4 justify-content-center">
    <!-- Centered Form Card -->
    <div class="col-12 col-lg-10 col-xl-9">
        <div class="card stat-card border-0 p-4 bg-white shadow-sm">
            <h5 class="fw-bold text-dark mb-3">
                <i class="bi bi-file-earmark-ruled text-primary me-2"></i>Field Completion &amp; Telemetry Report
            </h5>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="save_tech_report">

                <!-- Event Select -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="reportEventSearch">Target Support Event *</label>
                    <div class="report-event-picker position-relative" id="reportEventPicker">
                    <select class="visually-hidden" name="event_id" id="reportEventId" tabindex="-1" aria-hidden="true">
                        <option value="">-- Choose Assigned Support Event --</option>
                        <?php foreach ($reportableEvents as $re): ?>
                            <?php $eventSearchText = '#' . $re['id'] . ' ' . $re['title'] . ' ' . $re['office_name'] . ' ' . $re['location'] . ' ' . $re['status'] . ' ' . date('M j Y', strtotime($re['start_at'])); ?>
                            <option value="<?= (int)$re['id'] ?>"
                                    data-title="<?= h($re['title']) ?>"
                                    data-office="<?= h($re['office_name']) ?>"
                                    data-location="<?= h($re['location']) ?>"
                                    data-date="<?= h(date('M j, Y', strtotime($re['start_at']))) ?>"
                                    data-search="<?= h(strtolower($eventSearchText)) ?>"
                                    <?= $selectedEventId === (int)$re['id'] ? 'selected' : '' ?>>
                                #<?= $re['id'] ?> — <?= h($re['title']) ?> (<?= h($re['office_name']) ?> | <?= date('M j, Y', strtotime($re['start_at'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                        <button type="button" class="form-select report-event-trigger d-flex align-items-center justify-content-between text-start" id="reportEventSearch" aria-haspopup="listbox" aria-expanded="false" aria-controls="reportEventOptions">
                            <span class="report-event-selected text-truncate">-- Search assigned support events --</span>
                        </button>
                        <div class="report-event-menu" id="reportEventMenu" hidden>
                            <div class="p-2 border-bottom bg-light">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
                                    <input type="search" class="form-control" id="reportEventFilter" placeholder="Search event, barangay, office or date..." autocomplete="off" aria-label="Search assigned support events">
                                    <button type="button" class="btn btn-outline-secondary" id="clearReportEventFilter" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <div class="report-event-options" id="reportEventOptions" role="listbox"></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Internet Performance Metric</label>
                        <input class="form-control" name="internet_performance" placeholder="e.g. 120Mbps DL / 25Mbps UL, 15ms ping">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Actual Equipment Deployed / Used</label>
                        <input class="form-control" name="actual_equipment" placeholder="e.g. 1x Starlink Dish, 2x Gigabit Access Points">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Downtime Experienced (Minutes)</label>
                        <input class="form-control" type="number" min="0" name="downtime_minutes" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Overall Connection Quality</label>
                        <select class="form-select" name="performance">
                            <option value="Excellent">Excellent (Zero drops / stable)</option>
                            <option value="Good">Good (Minor speed fluctuations)</option>
                            <option value="Fair">Fair (Intermittent packet loss)</option>
                            <option value="Poor">Poor (High latency / power outage)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Problems Encountered (if any)</label>
                        <textarea class="form-control" name="problems" rows="3" placeholder="e.g. Tree obstruction, power surge, port failure..."><?= h($currentAssignment['problems'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Actions Taken &amp; Corrective Steps</label>
                        <textarea class="form-control" name="actions_taken" rows="3" placeholder="e.g. Relocated antenna mast, enabled failover LTE..."><?= h($currentAssignment['actions_taken'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Final Completion Notes &amp; Recommendations</label>
                        <textarea class="form-control" name="completion_notes" rows="3" placeholder="General handover notes and recommendations for upcoming events..."><?= h($currentAssignment['completion_notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top d-flex gap-2">
                    <button class="btn btn-success px-4 fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i> Save &amp; Submit Technical Report
                    </button>
                    <a href="my_tasks.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Info, centered below the report form -->
    <aside class="col-12 col-lg-10 col-xl-9">
        <div class="card stat-card border-0 p-4 bg-white shadow-sm mb-4">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle text-primary me-2"></i> Report Guidelines</h5>
            <ul class="small text-secondary ps-3 mb-0 d-flex flex-column gap-2">
                <li>Submit your report as soon as technical support or teardown is finished.</li>
                <li>Include telemetry numbers (speed, ping, packet loss) in the metrics field.</li>
                <li>Downtime in minutes helps generate accurate SLA reports for department heads.</li>
                <li>All reports are archived in the system audit log.</li>
            </ul>
        </div>
    </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const picker = document.getElementById('reportEventPicker');
    if (!picker) return;

    const select = document.getElementById('reportEventId');
    const trigger = document.getElementById('reportEventSearch');
    const selectedLabel = picker.querySelector('.report-event-selected');
    const menu = document.getElementById('reportEventMenu');
    const filter = document.getElementById('reportEventFilter');
    const clear = document.getElementById('clearReportEventFilter');
    const options = document.getElementById('reportEventOptions');

    const eventOptions = Array.from(select.options).filter(option => option.value);

    function renderOptions(query) {
        const term = (query || '').trim().toLowerCase();
        options.replaceChildren();
        const matches = eventOptions.filter(option => (option.dataset.search || option.textContent).toLowerCase().includes(term));

        if (!matches.length) {
            const empty = document.createElement('div');
            empty.className = 'p-3 small text-center text-muted';
            empty.textContent = 'No matching assigned events found.';
            options.appendChild(empty);
            return;
        }

        matches.forEach(option => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'report-event-option btn w-100 text-start px-3 py-2 mb-1';
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', option.value === select.value ? 'true' : 'false');

            const title = document.createElement('span');
            title.className = 'd-block fw-semibold small text-dark';
            title.textContent = '#' + option.value + ' — ' + (option.dataset.title || option.textContent.trim());
            const details = document.createElement('span');
            details.className = 'd-block small text-muted text-truncate';
            details.textContent = [option.dataset.office, option.dataset.date, option.dataset.location].filter(Boolean).join(' · ');
            button.append(title, details);

            button.addEventListener('click', function () {
                select.value = option.value;
                selectedLabel.textContent = '#' + option.value + ' — ' + (option.dataset.title || option.textContent.trim()) + ' (' + option.dataset.date + ')';
                closeMenu();
                window.location.href = 'tech_report.php?id=' + encodeURIComponent(option.value);
            });
            options.appendChild(button);
        });
    }

    function openMenu() {
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        filter.value = '';
        renderOptions('');
        filter.focus();
    }

    function closeMenu() {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }

    const current = eventOptions.find(option => option.value === select.value);
    if (current) {
        selectedLabel.textContent = '#' + current.value + ' — ' + (current.dataset.title || current.textContent.trim()) + ' (' + current.dataset.date + ')';
    }

    trigger.addEventListener('click', function () {
        if (menu.hidden) openMenu();
        else closeMenu();
    });
    trigger.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openMenu();
        }
    });
    filter.addEventListener('input', function () { renderOptions(filter.value); });
    filter.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
            trigger.focus();
        } else if (event.key === 'Enter') {
            const first = options.querySelector('button.report-event-option');
            if (first) {
                event.preventDefault();
                first.click();
            }
        }
    });
    clear.addEventListener('click', function () {
        filter.value = '';
        renderOptions('');
        filter.focus();
    });
    document.addEventListener('click', function (event) {
        if (!picker.contains(event.target)) closeMenu();
    });
});
</script>

<?php require 'partials/footer.php'; ?>
