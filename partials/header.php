<?php
require_once __DIR__ . '/../config.php';
require_login();
$me   = user();
$page = basename($_SERVER['PHP_SELF']);
$isTech = is_field_staff();
$canHaveDutyStatus = $isTech || in_array($me['role'], ['Administrator', 'Super Administrator'], true);

// Handle availability status update for technicians and admins
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_availability') {
    verify_csrf();
    $newAvail = $_POST['availability'] ?? 'Available';
    $stmt = db()->prepare('INSERT INTO technician_availability (technician_id, availability) VALUES (?, ?) ON DUPLICATE KEY UPDATE availability = VALUES(availability)');
    $stmt->execute([$me['id'], $newAvail]);
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$myAvailability = 'Available';
if ($canHaveDutyStatus) {
    $q = db()->prepare('SELECT availability FROM technician_availability WHERE technician_id = ?');
    $q->execute([$me['id']]);
    $myAvailability = $q->fetchColumn() ?: 'Available';
}

$q = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$q->execute([$me['id']]);
$unreadNotifications = (int)$q->fetchColumn();

/**
 * Bottom dock for phones: the handful of destinations someone actually needs
 * while standing in a barangay hall. Everything else lives in the drawer.
 */
$dock = $isTech
    ? [
        ['index.php',         'bi-speedometer2',   'Home',   ['index.php']],
        ['my_tasks.php',      'bi-tools',          'Tasks',  ['my_tasks.php']],
        ['map.php',           'bi-geo-alt-fill',   'Map',    ['map.php']],
        ['tech_report.php',   'bi-journal-text',   'Report', ['tech_report.php']],
        ['notifications.php', 'bi-bell-fill',      'Alerts', ['notifications.php']],
    ]
    : [
        ['index.php',         'bi-speedometer2',     'Home',    ['index.php']],
        ['events.php',        'bi-calendar-event',   'Events',  ['events.php', 'event.php']],
        ['map.php',           'bi-geo-alt-fill',     'Map',     ['map.php']],
        ['assignments.php',   'bi-person-workspace', 'Crews',   ['assignments.php']],
        ['notifications.php', 'bi-bell-fill',        'Alerts',  ['notifications.php']],
    ];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0C1B1F">
    <title><?= h($title ?? 'Network support') ?> · NetSupport</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/svg+xml" href="assets/netsupport-mark.svg?v=<?= (int) filemtime(__DIR__ . '/../assets/netsupport-mark.svg') ?>">
    <link href="assets/app.css?v=<?= (int) filemtime(__DIR__ . '/../assets/app.css') ?>" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark app-navbar sticky-top">
    <div class="container-fluid p-0 d-flex align-items-center gap-2">
        <button class="icon-btn" id="sidebarToggle" type="button"
                aria-label="Open navigation" aria-controls="appSidebar" aria-expanded="false">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <a class="brand-title" href="index.php">
            <span class="wordmark"><img class="brand-mark" src="assets/netsupport-mark.svg" alt=""> NetSupport</span>
            <span class="city">Bayawan City ICT</span>
        </a>

        <div class="d-flex align-items-center gap-2 gap-md-3 ms-auto">
            <?php if ($canHaveDutyStatus): ?>
                <div class="dropdown">
                    <button class="duty-btn <?= $myAvailability === 'Available' ? '' : 'is-off' ?>" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="dot" aria-hidden="true"></span>
                        <span><?= h($myAvailability) ?></span>
                        <i class="bi bi-chevron-down" style="font-size:.6875rem" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">Set your duty status</li>
                        <?php foreach (['Available', 'Busy', 'On Leave', 'Unavailable'] as $av): ?>
                            <li>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                    <input type="hidden" name="action" value="toggle_availability">
                                    <input type="hidden" name="availability" value="<?= h($av) ?>">
                                    <button type="submit" class="dropdown-item <?= $myAvailability === $av ? 'active' : '' ?>"><?= h($av) ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="who">
                <span class="avatar" aria-hidden="true"><?= strtoupper(substr($me['full_name'], 0, 1)) ?></span>
                <span class="d-none d-md-block">
                    <span class="name d-block"><?= h($me['full_name']) ?></span>
                    <span class="role d-block"><?= h($me['role']) ?></span>
                </span>
            </div>

            <a href="logout.php" class="signout">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                <span>Sign out</span>
            </a>
        </div>
    </div>
</nav>

<div class="sidebar-scrim" id="sidebarScrim" hidden></div>

<div class="app-wrapper">
    <aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
        <div class="sidebar-heading">Operations</div>

        <a href="index.php" class="sidebar-nav-link <?= $page === 'index.php' ? 'active' : '' ?>" data-label="Dashboard">
            <i class="bi bi-speedometer2" aria-hidden="true"></i>
            <span class="nav-label">Dashboard</span>
        </a>

        <a href="events.php" class="sidebar-nav-link <?= in_array($page, ['events.php', 'event.php']) ? 'active' : '' ?>" data-label="Support requests">
            <i class="bi bi-calendar-event" aria-hidden="true"></i>
            <span class="nav-label">Support requests</span>
        </a>

        <a href="map.php" class="sidebar-nav-link <?= $page === 'map.php' ? 'active' : '' ?>" data-label="Barangay map">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <span class="nav-label">Barangay map</span>
        </a>

        <a href="calendar.php" class="sidebar-nav-link <?= $page === 'calendar.php' ? 'active' : '' ?>" data-label="Calendar">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <span class="nav-label">Calendar</span>
        </a>

        <?php if ($isTech): ?>
            <div class="sidebar-heading">Your work</div>

            <a href="my_tasks.php" class="sidebar-nav-link <?= $page === 'my_tasks.php' ? 'active' : '' ?>" data-label="My tasks">
                <i class="bi bi-tools" aria-hidden="true"></i>
                <span class="nav-label">My tasks</span>
            </a>

            <a href="tech_report.php" class="sidebar-nav-link <?= $page === 'tech_report.php' ? 'active' : '' ?>" data-label="File a report">
                <i class="bi bi-journal-text" aria-hidden="true"></i>
                <span class="nav-label">File a report</span>
            </a>
        <?php else: ?>
            <div class="sidebar-heading">Dispatch</div>

            <a href="event_form.php" class="sidebar-nav-link <?= $page === 'event_form.php' ? 'active' : '' ?>" data-label="New request">
                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                <span class="nav-label">New request</span>
            </a>

            <a href="assignments.php" class="sidebar-nav-link <?= $page === 'assignments.php' ? 'active' : '' ?>" data-label="Assignments">
                <i class="bi bi-person-workspace" aria-hidden="true"></i>
                <span class="nav-label">Assignments</span>
            </a>

            <a href="my_tasks.php?view=mine" class="sidebar-nav-link <?= $page === 'my_tasks.php' && (($_GET['view'] ?? '') === 'mine') ? 'active' : '' ?>" data-label="My assigned tasks">
                <i class="bi bi-person-check" aria-hidden="true"></i>
                <span class="nav-label">My assigned tasks</span>
            </a>

            <a href="my_tasks.php" class="sidebar-nav-link <?= $page === 'my_tasks.php' && (($_GET['view'] ?? '') !== 'mine') ? 'active' : '' ?>" data-label="Technician roster">
                <i class="bi bi-people-fill" aria-hidden="true"></i>
                <span class="nav-label">Technician roster</span>
            </a>

            <a href="equipment.php" class="sidebar-nav-link <?= $page === 'equipment.php' ? 'active' : '' ?>" data-label="Equipment">
                <i class="bi bi-router" aria-hidden="true"></i>
                <span class="nav-label">Equipment</span>
            </a>

            <a href="reports.php" class="sidebar-nav-link <?= $page === 'reports.php' ? 'active' : '' ?>" data-label="Reports">
                <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
                <span class="nav-label">Reports</span>
            </a>
        <?php endif; ?>

        <?php if ($me['role'] === 'Super Administrator'): ?>
            <div class="sidebar-heading">Administration</div>

            <a href="offices.php" class="sidebar-nav-link <?= $page === 'offices.php' ? 'active' : '' ?>" data-label="Offices">
                <i class="bi bi-building" aria-hidden="true"></i>
                <span class="nav-label">Offices</span>
            </a>

            <a href="categories.php" class="sidebar-nav-link <?= $page === 'categories.php' ? 'active' : '' ?>" data-label="Categories">
                <i class="bi bi-tags" aria-hidden="true"></i>
                <span class="nav-label">Categories</span>
            </a>

            <a href="users.php" class="sidebar-nav-link <?= $page === 'users.php' ? 'active' : '' ?>" data-label="People">
                <i class="bi bi-people" aria-hidden="true"></i>
                <span class="nav-label">Users</span>
            </a>

            <a href="activity.php" class="sidebar-nav-link <?= $page === 'activity.php' ? 'active' : '' ?>" data-label="Audit log">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <span class="nav-label">Audit log</span>
            </a>
        <?php endif; ?>

        <div class="sidebar-heading">You</div>

        <a href="notifications.php" class="sidebar-nav-link <?= $page === 'notifications.php' ? 'active' : '' ?>" data-label="Notifications">
            <i class="bi bi-bell" aria-hidden="true"></i>
            <span class="nav-label">Notifications</span>
            <?php if ($unreadNotifications > 0): ?>
                <span class="nav-count"><?= $unreadNotifications ?><span class="visually-hidden"> unread</span></span>
            <?php endif; ?>
        </a>
    </aside>

    <main class="app-content" id="mainContent">
