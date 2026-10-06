<?php
require_once 'config.php';
$title = 'Support Calendar';
require 'partials/header.php';

$where = [];
$args = [];

if (is_field_staff()) {
    $where[] = 'a.technician_id = ?';
    $args[] = user()['id'];
}

if (!empty($_GET['office_id'])) {
    $where[] = 'e.office_id = ?';
    $args[] = (int)$_GET['office_id'];
}

if (!empty($_GET['status'])) {
    $where[] = 'e.status = ?';
    $args[] = $_GET['status'];
}

if (!empty($_GET['priority'])) {
    $where[] = 'e.priority = ?';
    $args[] = $_GET['priority'];
}

$sql = 'SELECT e.*, o.name office_name 
        FROM events e 
        JOIN offices o ON o.id = e.office_id 
        LEFT JOIN event_assignments a ON a.event_id = e.id ' 
        . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . 
        ' GROUP BY e.id ORDER BY e.start_at ASC';

$q = db()->prepare($sql);
$q->execute($args);
$events = $q->fetchAll();

$offices = db()->query('SELECT * FROM offices ORDER BY name')->fetchAll();

// Prepare events JSON for FullCalendar
$calendarEvents = [];
foreach ($events as $ev) {
    $color = match($ev['priority']) {
        'Urgent' => '#ef4444',
        'High' => '#f59e0b',
        'Normal' => '#2563eb',
        default => '#64748b'
    };

    $calendarEvents[] = [
        'id' => $ev['id'],
        'title' => $ev['title'] . ' (' . $ev['office_name'] . ')',
        'start' => date('c', strtotime($ev['start_at'])),
        'end' => date('c', strtotime($ev['end_at'])),
        'url' => 'event.php?id=' . $ev['id'],
        'backgroundColor' => $color,
        'borderColor' => $color,
        'priority' => $ev['priority'],
        'tooltip' => implode("\n", array_filter([
            $ev['title'],
            'Office: ' . $ev['office_name'],
            'Location: ' . ($ev['location'] ?: 'Not specified'),
            'Schedule: ' . date('M j, Y g:i A', strtotime($ev['start_at'])) . ' – ' . date('g:i A', strtotime($ev['end_at'])),
            'Category: ' . ($ev['category'] ?: 'Not specified'),
            'Priority: ' . $ev['priority'],
            'Status: ' . $ev['status'],
            !empty($ev['description']) ? 'Details: ' . substr(trim(strip_tags($ev['description'])), 0, 140) : ''
        ]))
    ];
}
?>

<style>
.calendar-event-tooltip .tooltip-inner {
    max-width: 320px;
    text-align: left;
    white-space: pre-line;
    line-height: 1.45;
    padding: .75rem .85rem;
    color: #f8ffff;
    background: linear-gradient(145deg, #0f766e 0%, #0c1b1f 76%);
    border: 1px solid rgba(255, 255, 255, .18);
    border-left: 4px solid #14b8a6;
    border-radius: .75rem;
    box-shadow: 0 12px 28px rgba(12, 27, 31, .3);
}
#calendar .fc-event {
    cursor: pointer;
    border-radius: .35rem;
    transition: filter .15s ease, box-shadow .15s ease, transform .15s ease;
}
#calendar .fc-event:hover {
    filter: brightness(.94) saturate(1.1);
    box-shadow: 0 3px 9px rgba(12, 27, 31, .2);
    transform: translateY(-1px);
}
.calendar-event-tooltip--urgent .tooltip-inner { border-left-color: #ef4444; }
.calendar-event-tooltip--high .tooltip-inner { border-left-color: #f59e0b; }
.calendar-event-tooltip--normal .tooltip-inner { border-left-color: #3b82f6; }
.calendar-event-tooltip--low .tooltip-inner { border-left-color: #10b981; }
.calendar-event-tooltip.bs-tooltip-top .tooltip-arrow::before { border-top-color: #0f766e; }
.calendar-event-tooltip.bs-tooltip-bottom .tooltip-arrow::before { border-bottom-color: #0c1b1f; }
.calendar-event-tooltip.bs-tooltip-start .tooltip-arrow::before { border-left-color: #0c1b1f; }
.calendar-event-tooltip.bs-tooltip-end .tooltip-arrow::before { border-right-color: #0c1b1f; }
</style>

<!-- FullCalendar Library -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.min.js"></script>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Interactive Support Calendar</h3>
        <p class="text-secondary small mb-0">Visual monthly and weekly schedule of network support operations.</p>
    </div>
</div>

<!-- Filters -->
<div class="card stat-card p-3 mb-4 border-0">
    <form class="row g-2 align-items-center" method="get">
        <div class="col-md-3">
            <select class="form-select bg-light" name="office_id">
                <option value="">All Offices</option>
                <?php foreach ($offices as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= ($_GET['office_id'] ?? '') == $o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select bg-light" name="status">
                <option value="">All Statuses</option>
                <?php foreach (['New', 'Pending', 'Assigned', 'In Progress', 'Completed', 'Cancelled', 'Rescheduled'] as $s): ?>
                    <option <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select bg-light" name="priority">
                <option value="">All Priorities</option>
                <?php foreach (['Low', 'Normal', 'High', 'Urgent'] as $p): ?>
                    <option <?= ($_GET['priority'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-secondary w-100"><i class="bi bi-funnel"></i> Filter</button>
            <a href="calendar.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<!-- Calendar Container -->
<div class="card stat-card p-4 border-0">
    <div id="calendar" style="min-height: 650px;"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listMonth'
        },
        themeSystem: 'standard',
        events: <?= json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        eventDidMount: function(info) {
            var priority = (info.event.extendedProps.priority || 'normal').toLowerCase();
            new bootstrap.Tooltip(info.el, {
                title: info.event.extendedProps.tooltip,
                trigger: 'hover focus',
                placement: 'top',
                container: 'body',
                customClass: 'calendar-event-tooltip calendar-event-tooltip--' + priority
            });
        },
        eventWillUnmount: function(info) {
            var tooltip = bootstrap.Tooltip.getInstance(info.el);
            if (tooltip) tooltip.dispose();
        },
        eventTimeFormat: {
            hour: 'numeric',
            minute: '2-digit',
            meridiem: 'short'
        }
    });
    calendar.render();
});
</script>

<?php require 'partials/footer.php'; ?>
