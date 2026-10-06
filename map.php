<?php
require_once 'config.php';
require_once __DIR__ . '/partials/barangays.php';

$where = [];
$args  = [];

if (is_field_staff()) {
    $where[] = 'a.technician_id = ?';
    $args[]  = user()['id'];
}
if (!empty($_GET['category'])) {
    $where[] = 'e.category = ?';
    $args[]  = $_GET['category'];
}
if (!empty($_GET['status'])) {
    $where[] = 'e.status = ?';
    $args[]  = $_GET['status'];
}

$sql = 'SELECT e.*, o.name office_name
        FROM events e
        JOIN offices o ON o.id = e.office_id
        LEFT JOIN event_assignments a ON a.event_id = e.id '
        . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
        . ' GROUP BY e.id ORDER BY e.start_at DESC';

$q = db()->prepare($sql);
$q->execute($args);
$events = $q->fetchAll();

$filterCategories = db()->query('SELECT name FROM event_categories ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);

/**
 * Build the pin list for network support visits.
 */
$mapPins    = [];
$unresolved = 0;
$eventsByBarangay = [];

foreach ($events as $ev) {
    $loc = (string)($ev['location'] ?? '');
    [$brgyPart, $venuePart] = bayawan_split_location($loc);

    $hasPin = isset($ev['latitude'], $ev['longitude'])
        && $ev['latitude'] !== null && $ev['longitude'] !== null;

    if ($hasPin) {
        $lat     = (float)$ev['latitude'];
        $lng     = (float)$ev['longitude'];
        $matched = true;
        $brgy    = bayawan_resolve($brgyPart)['name'] ?: $brgyPart;
        $exact   = true;
    } else {
        $geo     = bayawan_resolve($brgyPart !== '' ? $brgyPart : $loc);
        $lat     = $geo['lat'];
        $lng     = $geo['lng'];
        $matched = $geo['matched'];
        $brgy    = $geo['name'];
        $exact   = false;
    }

    if (!$matched) {
        $unresolved++;
    }

    $pinItem = [
        'id'       => (int)$ev['id'],
        'title'    => $ev['title'],
        'brgy'     => $brgy !== '' ? $brgy : 'Location not set',
        'venue'    => $venuePart,
        'office'   => $ev['office_name'],
        'category' => $ev['category'],
        'priority' => $ev['priority'],
        'status'   => $ev['status'],
        'start'    => date('M j, Y g:i A', strtotime($ev['start_at'])),
        'lat'      => $lat,
        'lng'      => $lng,
        'resolved' => $matched,
        'exact'    => $exact,
        'url'      => 'event.php?id=' . (int)$ev['id'],
    ];

    $mapPins[] = $pinItem;

    // Group visits by barangay name (normalized)
    $cleanBrgy = strtolower(trim(str_ireplace(['brgy.', 'barangay', ', bayawan city', 'bayawan city'], '', $brgy)));
    if (!isset($eventsByBarangay[$cleanBrgy])) {
        $eventsByBarangay[$cleanBrgy] = [];
    }
    $eventsByBarangay[$cleanBrgy][] = $pinItem;
}

$title = 'Barangay GIS Map';
require 'partials/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* Immersive GIS Map Interface */
.gis-wrapper {
    position: relative;
    width: 100%;
    height: calc(100vh - 120px);
    min-height: 680px;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.08);
    background: #e2e8f0;
}

#gisMap {
    width: 100%;
    height: 100%;
    z-index: 1;
}

/* Floating Search Bar */
.gis-search-box {
    position: absolute;
    top: 18px;
    left: 20px;
    z-index: 1010;
    width: 320px;
    max-width: calc(100% - 40px);
}

.gis-search-input-wrap {
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
    border: 1.5px solid #e2e8f0;
    padding: 7px 12px;
    display: flex;
    align-items: center;
    transition: all 0.2s ease;
}

.gis-search-input-wrap:focus-within {
    border-color: #ff6115;
    box-shadow: 0 8px 24px rgba(255, 97, 21, 0.18);
}

.gis-search-input-wrap .search-icon {
    color: #ff6115;
    font-size: 16px;
    margin-right: 10px;
}

.gis-search-input {
    border: none;
    outline: none;
    width: 100%;
    min-width: 0;
    flex: 1 1 auto;
    font-size: 13.5px;
    font-weight: 500;
    color: #1e293b;
    background: transparent;
}

.gis-search-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #64748b;
    user-select: none;
    flex: 0 0 auto;
}

.gis-search-results {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.15);
    border: 1px solid #e2e8f0;
    max-height: 250px;
    overflow-y: auto;
    display: none;
    z-index: 1001;
}

.gis-search-item {
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 500;
    color: #334155;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background 0.15s;
}

.gis-search-item:last-child { border-bottom: none; }
.gis-search-item:hover { background: #fff7ed; color: #ea580c; }

/* Floating Map Layers Control */
.gis-layers-card {
    position: absolute;
    top: 76px;
    left: 20px;
    z-index: 1000;
    width: 290px;
    max-width: calc(100% - 40px);
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.14);
    border: 1px solid rgba(0, 0, 0, 0.06);
    overflow: hidden;
    transition: all 0.25s ease;
}

.gis-layers-head {
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    user-select: none;
    border-bottom: 1px solid #f1f5f9;
}

.gis-layers-title {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.8px;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 8px;
}

.gis-layers-title i {
    color: #6366f1;
    font-size: 14px;
}

.gis-layers-body {
    padding: 14px 16px;
    max-height: 380px;
    overflow-y: auto;
}

/* Base Tile Tabs */
.gis-tabs {
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    display: flex;
    gap: 4px;
    margin-bottom: 14px;
}

.gis-tab-btn {
    flex: 1;
    border: none;
    background: transparent;
    padding: 6px 10px;
    font-size: 11.5px;
    font-weight: 600;
    color: #64748b;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.gis-tab-btn.active {
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

/* Layer Group Items */
.gis-layer-group {
    margin-bottom: 12px;
}

.gis-layer-group-title {
    font-size: 12.5px;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.gis-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}

.gis-dot-green  { background: #10b981; }
.gis-dot-orange { background: #ff6115; }
.gis-dot-pink   { background: #ec4899; }
.gis-dot-blue   { background: #0284c7; }

.gis-checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #475569;
    padding: 3px 0 3px 18px;
    cursor: pointer;
    user-select: none;
}

.gis-checkbox-item input[type="checkbox"] {
    accent-color: #0e7c66;
    width: 15px;
    height: 15px;
    cursor: pointer;
}

/* Floating Reset View Button */
.gis-reset-btn {
    position: absolute;
    bottom: 24px;
    right: 24px;
    z-index: 1000;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: #ff6115;
    color: #ffffff;
    font-weight: 700;
    font-size: 13px;
    border-radius: 12px;
    box-shadow: 0 6px 20px rgba(255, 97, 21, 0.4);
    border: 1px solid #ea580c;
    cursor: pointer;
    transition: all 0.2s ease;
}

.gis-reset-btn:hover {
    background: #e04f08;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(255, 97, 21, 0.5);
    color: #ffffff;
}

.gis-reset-btn:hover i {
    transform: rotate(180deg);
}

.gis-reset-btn i {
    transition: transform 0.4s ease;
}

/* Top Right Action Pill - Scheduled Visits Drawer */
.gis-visits-toggle-btn {
    position: absolute;
    top: 18px;
    right: 24px;
    z-index: 1000;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: #ffffff;
    color: #0f172a;
    font-weight: 700;
    font-size: 13px;
    border-radius: 12px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
    border: 1px solid #e2e8f0;
    cursor: pointer;
    transition: all 0.2s;
}

.gis-visits-toggle-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.gis-visits-count-badge {
    background: #0e7c66;
    color: #ffffff;
    border-radius: 999px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 700;
}

/* Floating Barangay Info Card */
.gis-brgy-card {
    position: absolute;
    top: 68px;
    right: 24px;
    z-index: 1000;
    width: 320px;
    max-width: calc(100% - 48px);
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.18);
    border: 1.5px solid #fed7aa;
    overflow: hidden;
    display: none;
    animation: fadeInSlide 0.25s ease-out;
}

@keyframes fadeInSlide {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.gis-brgy-card-head {
    background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
    padding: 14px 16px;
    border-bottom: 1px solid #fed7aa;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.gis-brgy-card-title {
    font-size: 15px;
    font-weight: 800;
    color: #9a3412;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.gis-brgy-card-close {
    border: none;
    background: rgba(255, 255, 255, 0.6);
    color: #9a3412;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.15s;
}

.gis-brgy-card-close:hover {
    background: #ffffff;
}

.gis-brgy-card-body {
    padding: 14px 16px;
    font-size: 12.5px;
}

.gis-brgy-detail-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px dashed #f1f5f9;
}

.gis-brgy-detail-label {
    color: #64748b;
    font-weight: 500;
}

.gis-brgy-detail-value {
    color: #1e293b;
    font-weight: 700;
}

/* Barangay Labels on Polygon Centroids */
.gis-brgy-label {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    font-family: var(--font, sans-serif);
    font-size: 11px;
    font-weight: 700;
    color: #0f172a;
    text-transform: capitalize;
    text-shadow: 
        0 1px 3px rgba(255, 255, 255, 0.95),
        0 -1px 3px rgba(255, 255, 255, 0.95),
        1px 0 3px rgba(255, 255, 255, 0.95),
        -1px 0 3px rgba(255, 255, 255, 0.95);
    white-space: nowrap;
    pointer-events: none;
}

/* Custom Marker Pins */
.pin-wrap { background: transparent; border: none; }
.pin {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--pin, #0e7c66);
    border: 2.5px solid #ffffff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
    display: block;
    cursor: pointer;
    transition: transform 0.2s ease;
}
.pin:hover {
    transform: scale(1.3);
}

/* Offcanvas Scheduled Visits Drawer */
.gis-drawer {
    position: absolute;
    top: 0;
    right: -360px;
    width: 360px;
    height: 100%;
    background: #ffffff;
    box-shadow: -4px 0 25px rgba(0, 0, 0, 0.15);
    z-index: 1050;
    transition: right 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
}

.gis-drawer.open {
    right: 0;
}

.gis-drawer-head {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.gis-drawer-body {
    padding: 14px;
    overflow-y: auto;
    flex: 1;
}

.gis-drawer-visit-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.gis-drawer-visit-item:hover {
    border-color: #0e7c66;
    background: #f0fdf4;
    transform: translateX(-2px);
}
</style>

<div class="mb-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
    <div>
        <h4 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-geo-alt-fill text-success"></i> Barangay GIS Map
        </h4>
        <p class="text-secondary small mb-0">Official boundary polygons &amp; live network support dispatches across Bayawan City.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="events.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="bi bi-list-ul me-1"></i> Support Requests Table
        </a>
        <a href="event_form.php" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-plus-lg me-1"></i> New Request
        </a>
    </div>
</div>

<div class="gis-wrapper">
    <!-- Map Container -->
    <div id="gisMap"></div>

    <!-- Floating Search Bar -->
    <div class="gis-search-box">
        <div class="gis-search-input-wrap">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="gisSearchInput" class="gis-search-input" placeholder="Search barangay..." autocomplete="off">
            <span class="gis-search-badge">Brgy.</span>
        </div>
        <div id="gisSearchResults" class="gis-search-results"></div>
    </div>

    <!-- Floating Map Layers Card -->
    <div class="gis-layers-card" id="gisLayersCard">
        <div class="gis-layers-head" id="gisLayersToggle">
            <div class="gis-layers-title">
                <i class="bi bi-grid-fill"></i> MAP LAYERS
            </div>
            <i class="bi bi-chevron-up text-secondary" id="gisLayersChevron"></i>
        </div>
        <div class="gis-layers-body" id="gisLayersBody">
            <!-- Base Tile Switcher -->
            <div class="gis-tabs">
                <button type="button" class="gis-tab-btn active" id="tabStandard">Standard</button>
                <button type="button" class="gis-tab-btn" id="tabTopo">Topographic</button>
                <button type="button" class="gis-tab-btn" id="tabSatellite">Satellite</button>
            </div>

            <!-- Boundary Group -->
            <div class="gis-layer-group">
                <div class="gis-layer-group-title">
                    <span class="gis-dot gis-dot-green"></span> Boundary
                </div>
                <label class="gis-checkbox-item">
                    <input type="checkbox" id="chkBarangays" checked>
                    <span>Barangay Boundaries</span>
                </label>
                <label class="gis-checkbox-item">
                    <input type="checkbox" id="chkLabels" checked>
                    <span>Barangay Name Labels</span>
                </label>
                <label class="gis-checkbox-item">
                    <input type="checkbox" id="chkVisits" checked>
                    <span>Support Visit Pins</span>
                </label>
            </div>

            <!-- Barangay Explorer Group -->
            <div class="gis-layer-group">
                <div class="gis-layer-group-title">
                    <span class="gis-dot gis-dot-orange"></span> Barangay Explorer
                </div>
                <label class="gis-checkbox-item">
                    <input type="checkbox" id="chkExplorer" checked>
                    <span>Click to Inspect Barangay</span>
                </label>
            </div>

            <!-- Support Status Filter Group -->
            <div class="gis-layer-group mb-0">
                <div class="gis-layer-group-title">
                    <span class="gis-dot gis-dot-pink"></span> Visit Filters
                </div>
                <div class="d-flex flex-wrap gap-1 ps-3 pt-1">
                    <button type="button" class="btn btn-sm btn-light border py-0 px-2 small status-filter-btn active" data-status="all">All</button>
                    <button type="button" class="btn btn-sm btn-light border py-0 px-2 small status-filter-btn" data-status="New">New</button>
                    <button type="button" class="btn btn-sm btn-light border py-0 px-2 small status-filter-btn" data-status="In Progress">Active</button>
                    <button type="button" class="btn btn-sm btn-light border py-0 px-2 small status-filter-btn" data-status="Completed">Done</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Reset View Button -->
    <button type="button" class="gis-reset-btn" id="btnResetView" title="Reset view to full Bayawan City">
        <i class="bi bi-arrow-counterclockwise"></i> Reset View
    </button>

    <!-- Top Right Toggle for Visits Drawer -->
    <button type="button" class="gis-visits-toggle-btn" id="btnToggleVisitsDrawer">
        <i class="bi bi-calendar2-event text-primary"></i>
        <span>Scheduled Visits</span>
        <span class="gis-visits-count-badge"><?= count($mapPins) ?></span>
    </button>

    <!-- Floating Barangay Information Card -->
    <div class="gis-brgy-card" id="brgyInfoCard">
        <div class="gis-brgy-card-head">
            <h6 class="gis-brgy-card-title">
                <i class="bi bi-geo-alt-fill text-warning"></i>
                <span id="cardBrgyName">Barangay Name</span>
            </h6>
            <button type="button" class="gis-brgy-card-close" id="btnCloseBrgyCard">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="gis-brgy-card-body">
            <div class="gis-brgy-detail-row">
                <span class="gis-brgy-detail-label">Barangay Captain:</span>
                <span class="gis-brgy-detail-value" id="cardBrgyCaptain">—</span>
            </div>
            <div class="gis-brgy-detail-row">
                <span class="gis-brgy-detail-label">Contact No.:</span>
                <span class="gis-brgy-detail-value" id="cardBrgyContact">—</span>
            </div>
            <div class="gis-brgy-detail-row">
                <span class="gis-brgy-detail-label">Population:</span>
                <span class="gis-brgy-detail-value" id="cardBrgyPop">—</span>
            </div>
            <div class="gis-brgy-detail-row">
                <span class="gis-brgy-detail-label">Land Area:</span>
                <span class="gis-brgy-detail-value" id="cardBrgyArea">—</span>
            </div>
            <div class="gis-brgy-detail-row border-0 mb-2">
                <span class="gis-brgy-detail-label">Support Visits:</span>
                <span class="gis-brgy-detail-value text-success" id="cardBrgyVisitsCount">0 visits</span>
            </div>
            <div id="cardBrgyVisitsList" class="mb-3"></div>
            <a href="event_form.php" class="btn btn-sm btn-outline-primary w-100 fw-semibold" id="cardBtnNewVisit">
                <i class="bi bi-plus-circle me-1"></i> New Request Here
            </a>
        </div>
    </div>

    <!-- Scheduled Visits Slide-Over Drawer -->
    <div class="gis-drawer" id="gisVisitsDrawer">
        <div class="gis-drawer-head">
            <h6 class="fw-bold text-dark mb-0">
                <i class="bi bi-calendar-check text-primary me-1"></i> Scheduled Visits (<?= count($mapPins) ?>)
            </h6>
            <button type="button" class="btn-close" id="btnCloseDrawer" aria-label="Close"></button>
        </div>
        <div class="gis-drawer-body">
            <?php if (empty($mapPins)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-2"></i>
                    <p class="small mt-2">No visits scheduled matching current filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($mapPins as $i => $pin): ?>
                    <div class="gis-drawer-visit-item" data-pin-index="<?= $i ?>">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <span class="fw-bold text-dark small text-truncate" style="max-width: 210px;"><?= h($pin['title']) ?></span>
                            <span class="badge badge-soft-<?= status_class($pin['status']) ?>"><?= h($pin['status']) ?></span>
                        </div>
                        <div class="small text-muted mb-1">
                            <i class="bi bi-geo-alt text-danger"></i> <?= h($pin['brgy']) ?><?= $pin['venue'] ? ' · ' . h($pin['venue']) : '' ?>
                        </div>
                        <div class="small text-secondary d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-building"></i> <?= h($pin['office']) ?></span>
                            <time class="font-monospace small"><?= date('M j, g:i A', strtotime($pin['start'])) ?></time>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Bayawan City Center & Default Bounds
    var DEFAULT_CENTER = [9.50, 122.83];
    var DEFAULT_ZOOM   = 11;
    var defaultBounds  = null;

    var map = L.map('gisMap', {
        scrollWheelZoom: true,
        zoomControl: false // We will position controls nicely
    }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);

    // Zoom control at bottom left
    L.control.zoom({ position: 'bottomleft' }).addTo(map);

    // Keep floating map controls below the sticky header while the page scrolls.
    var mapWrapper = document.querySelector('.gis-wrapper');
    var mapSearchBox = document.querySelector('.gis-search-box');
    var mapLayersCard = document.getElementById('gisLayersCard');
    var mapVisitsButton = document.getElementById('btnToggleVisitsDrawer');
    var mapBarangayCard = document.getElementById('brgyInfoCard');
    function positionMapOverlays() {
        var wrapperRect = mapWrapper.getBoundingClientRect();
        var navbar = document.querySelector('.app-navbar');
        var navbarBottom = navbar ? navbar.getBoundingClientRect().bottom : 0;
        var top = Math.max(18, navbarBottom - wrapperRect.top + 12);
        var maxTop = mapWrapper.clientHeight - mapSearchBox.offsetHeight - 12;
        top = Math.min(top, maxTop);

        mapSearchBox.style.top = top + 'px';
        mapLayersCard.style.top = (top + mapSearchBox.offsetHeight + 12) + 'px';
        mapVisitsButton.style.top = top + 'px';

        var cardTop = top + mapVisitsButton.offsetHeight + 12;
        var maxCardTop = mapWrapper.clientHeight - mapBarangayCard.offsetHeight - 12;
        mapBarangayCard.style.top = Math.min(cardTop, maxCardTop) + 'px';
    }
    positionMapOverlays();
    window.addEventListener('scroll', positionMapOverlays, { passive: true });
    window.addEventListener('resize', positionMapOverlays);

    // Tile Layers
    var standardLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var topoLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri, HERE, Garmin, FAO, NOAA, USGS, EPA, NPS'
    });

    var satelliteLayer = L.tileLayer('https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics, and the GIS User Community'
    });

    var satelliteLabelsLayer = L.tileLayer('https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Labels &copy; Esri, HERE, Garmin, FAO, NOAA, USGS, EPA, NPS'
    });

    // Base Layer Tab Handlers
    var baseLayerTabs = {
        tabStandard: standardLayer,
        tabTopo: topoLayer,
        tabSatellite: satelliteLayer
    };

    Object.keys(baseLayerTabs).forEach(function (tabId) {
        document.getElementById(tabId).addEventListener('click', function () {
            Object.keys(baseLayerTabs).forEach(function (otherTabId) {
                var otherLayer = baseLayerTabs[otherTabId];
                if (map.hasLayer(otherLayer)) map.removeLayer(otherLayer);
                document.getElementById(otherTabId).classList.toggle('active', otherTabId === tabId);
            });
            if (map.hasLayer(satelliteLabelsLayer)) map.removeLayer(satelliteLabelsLayer);
            map.addLayer(baseLayerTabs[tabId]);
            if (tabId === 'tabSatellite') map.addLayer(satelliteLabelsLayer);
        });
    });

    // Layers & State
    var geojsonLayer    = null;
    var labelMarkers    = [];
    var visitMarkers    = [];
    var selectedPolygon = null;

    var pinsData = <?= json_encode($mapPins, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var eventsByBarangay = <?= json_encode($eventsByBarangay, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var barangayList = [];

    // Fan-out for exact same lat/lng pins to avoid overlapping markers
    var seenCoords = {};
    pinsData.forEach(function (pin) {
        var key = pin.lat.toFixed(5) + ',' + pin.lng.toFixed(5);
        var n = seenCoords[key] || 0;
        seenCoords[key] = n + 1;
        if (n > 0) {
            var angle = (n * 2.39996);
            var r = 0.0012 * Math.sqrt(n);
            pin.lat += r * Math.cos(angle);
            pin.lng += r * Math.sin(angle);
        }
    });

    // Status Colors mapping
    var statusColors = {
        'New':         '#1f6feb',
        'Pending':     '#b45309',
        'Assigned':    '#6d28d9',
        'In Progress': '#0e7c66',
        'Completed':   '#166534',
        'Cancelled':   '#b91c1c'
    };

    // Render Visit Pins Layer
    var pinBounds = [];
    pinsData.forEach(function (pin, idx) {
        var color = statusColors[pin.status] || '#64748b';
        var icon = L.divIcon({
            html: '<span class="pin" style="--pin:' + color + '"></span>',
            iconSize: [16, 16],
            iconAnchor: [8, 8],
            popupAnchor: [0, -10],
            className: 'pin-wrap'
        });

        var marker = L.marker([pin.lat, pin.lng], {
            icon: icon,
            title: pin.title + ' (' + pin.status + ')'
        }).addTo(map);

        var venueStr = pin.venue ? '<div class="small text-muted mb-1"><i class="bi bi-geo-alt"></i> ' + pin.venue + '</div>' : '';
        marker.bindPopup(
            '<div style="min-width: 200px; font-family: sans-serif;">' +
                '<div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<span class="badge" style="background:' + color + '; color:#fff;">' + pin.status + '</span>' +
                    '<span class="badge bg-light text-dark border">' + pin.category + '</span>' +
                '</div>' +
                '<h6 class="fw-bold mb-1" style="font-size:14px;">' + pin.title + '</h6>' +
                venueStr +
                '<div class="small text-secondary mb-2"><i class="bi bi-building"></i> ' + pin.office + '</div>' +
                '<div class="small text-muted mb-2"><i class="bi bi-clock"></i> ' + pin.start + '</div>' +
                '<a href="' + pin.url + '" class="btn btn-sm btn-primary w-100 fw-semibold text-white">Open Request &rarr;</a>' +
            '</div>'
        );

        marker.pinData = pin;
        visitMarkers.push(marker);
        pinBounds.push([pin.lat, pin.lng]);
    });

    // Style for Barangay Polygons (Matching the green outline from the GIS portal)
    function defaultPolygonStyle(feature) {
        return {
            color: '#10b981',        // Vibrant emerald green boundary outline
            weight: 2,
            opacity: 0.95,
            fillColor: '#10b981',
            fillOpacity: 0.07,
            dashArray: null
        };
    }

    function hoverPolygonStyle() {
        return {
            color: '#059669',
            weight: 3,
            fillOpacity: 0.22
        };
    }

    function selectedPolygonStyle() {
        return {
            color: '#0284c7',        // Crisp Blue border like GIS portal screenshot
            weight: 3.5,
            fillColor: '#38bdf8',
            fillOpacity: 0.18
        };
    }

    // Load GeoJSON Boundaries
    fetch('assets/bayawan_barangays.geojson')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            geojsonLayer = L.geoJSON(data, {
                style: defaultPolygonStyle,
                onEachFeature: function (feature, layer) {
                    var p = feature.properties;
                    barangayList.push({
                        name: p.barangay,
                        layer: layer,
                        properties: p
                    });

                    // Add center label for each barangay
                    var center = layer.getBounds().getCenter();
                    var labelMarker = L.marker(center, {
                        icon: L.divIcon({
                            className: 'gis-brgy-label',
                            html: p.barangay,
                            iconSize: [100, 20],
                            iconAnchor: [50, 10]
                        }),
                        interactive: false
                    }).addTo(map);
                    labelMarkers.push(labelMarker);

                    // Polygon Interaction
                    layer.on({
                        mouseover: function (e) {
                            if (layer !== selectedPolygon) {
                                layer.setStyle(hoverPolygonStyle());
                            }
                        },
                        mouseout: function (e) {
                            if (layer !== selectedPolygon) {
                                geojsonLayer.resetStyle(layer);
                            }
                        },
                        click: function (e) {
                            if (document.getElementById('chkExplorer').checked) {
                                selectBarangay(p, layer);
                            }
                        }
                    });
                }
            }).addTo(map);

            defaultBounds = geojsonLayer.getBounds();
            map.fitBounds(defaultBounds, { padding: [30, 30] });

            initSearch();
        })
        .catch(function (err) {
            console.error('Failed to load GeoJSON:', err);
        });

    // Select Barangay & Show Info Card
    function selectBarangay(props, layer) {
        if (selectedPolygon && geojsonLayer) {
            geojsonLayer.resetStyle(selectedPolygon);
        }
        selectedPolygon = layer;
        selectedPolygon.setStyle(selectedPolygonStyle());

        map.flyToBounds(layer.getBounds(), {
            padding: [80, 80],
            maxZoom: 14,
            duration: 1.0
        });

        // Populate Card
        var name = props.barangay;
        document.getElementById('cardBrgyName').textContent = 'Brgy. ' + name;
        document.getElementById('cardBrgyCaptain').textContent = props.captain || 'N/A';
        document.getElementById('cardBrgyContact').textContent = props.contact || 'N/A';
        document.getElementById('cardBrgyPop').textContent = props.population ? Number(props.population).toLocaleString() : 'N/A';
        document.getElementById('cardBrgyArea').textContent = props.area ? Number(props.area).toLocaleString() + ' ha' : 'N/A';

        // Match scheduled events in this barangay
        var cleanKey = name.toLowerCase().trim();
        var evs = eventsByBarangay[cleanKey] || [];
        var countEl = document.getElementById('cardBrgyVisitsCount');
        var listEl  = document.getElementById('cardBrgyVisitsList');

        countEl.textContent = evs.length + (evs.length === 1 ? ' scheduled visit' : ' scheduled visits');
        listEl.innerHTML = '';

        if (evs.length > 0) {
            evs.slice(0, 3).forEach(function (v) {
                var item = document.createElement('a');
                item.href = v.url;
                item.className = 'd-flex justify-content-between align-items-center p-2 mb-1 bg-light rounded text-decoration-none text-dark small';
                item.innerHTML = '<span class="text-truncate fw-semibold" style="max-width:170px;">' + v.title + '</span><span class="badge badge-soft-' + (v.status === 'Completed' ? 'success' : 'primary') + '">' + v.status + '</span>';
                listEl.appendChild(item);
            });
        }

        document.getElementById('cardBtnNewVisit').href = 'event_form.php?location=' + encodeURIComponent('Brgy. ' + name + ', Bayawan City');
        document.getElementById('brgyInfoCard').style.display = 'block';
        requestAnimationFrame(positionMapOverlays);
    }

    // Close Info Card
    document.getElementById('btnCloseBrgyCard').addEventListener('click', function () {
        document.getElementById('brgyInfoCard').style.display = 'none';
        if (selectedPolygon && geojsonLayer) {
            geojsonLayer.resetStyle(selectedPolygon);
            selectedPolygon = null;
        }
    });

    // Reset View
    document.getElementById('btnResetView').addEventListener('click', function () {
        if (selectedPolygon && geojsonLayer) {
            geojsonLayer.resetStyle(selectedPolygon);
            selectedPolygon = null;
        }
        document.getElementById('brgyInfoCard').style.display = 'none';
        if (defaultBounds) {
            map.flyToBounds(defaultBounds, { padding: [30, 30], duration: 1.0 });
        } else {
            map.flyTo(DEFAULT_CENTER, DEFAULT_ZOOM);
        }
    });

    // Toggle MAP LAYERS panel body
    var layersToggle = document.getElementById('gisLayersToggle');
    var layersBody = document.getElementById('gisLayersBody');
    var layersChevron = document.getElementById('gisLayersChevron');

    layersToggle.addEventListener('click', function () {
        if (layersBody.style.display === 'none') {
            layersBody.style.display = 'block';
            layersChevron.className = 'bi bi-chevron-up text-secondary';
        } else {
            layersBody.style.display = 'none';
            layersChevron.className = 'bi bi-chevron-down text-secondary';
        }
    });

    // Layer Checkboxes
    document.getElementById('chkBarangays').addEventListener('change', function (e) {
        if (geojsonLayer) {
            if (e.target.checked) {
                if (!map.hasLayer(geojsonLayer)) map.addLayer(geojsonLayer);
            } else {
                if (map.hasLayer(geojsonLayer)) map.removeLayer(geojsonLayer);
            }
        }
    });

    document.getElementById('chkLabels').addEventListener('change', function (e) {
        labelMarkers.forEach(function (m) {
            if (e.target.checked) {
                if (!map.hasLayer(m)) map.addLayer(m);
            } else {
                if (map.hasLayer(m)) map.removeLayer(m);
            }
        });
    });

    document.getElementById('chkVisits').addEventListener('change', function (e) {
        visitMarkers.forEach(function (m) {
            if (e.target.checked) {
                if (!map.hasLayer(m)) map.addLayer(m);
            } else {
                if (map.hasLayer(m)) map.removeLayer(m);
            }
        });
    });

    // Status Filter Buttons
    document.querySelectorAll('.status-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.status-filter-btn').forEach(function (b) { b.classList.remove('active', 'btn-primary'); b.classList.add('btn-light'); });
            btn.classList.add('active', 'btn-primary');
            btn.classList.remove('btn-light');

            var filter = btn.dataset.status;
            visitMarkers.forEach(function (m) {
                if (filter === 'all' || m.pinData.status === filter) {
                    if (!map.hasLayer(m) && document.getElementById('chkVisits').checked) map.addLayer(m);
                } else {
                    if (map.hasLayer(m)) map.removeLayer(m);
                }
            });
        });
    });

    // Search Box Functionality
    function initSearch() {
        var input = document.getElementById('gisSearchInput');
        var results = document.getElementById('gisSearchResults');

        input.addEventListener('input', function () {
            var val = input.value.toLowerCase().trim();
            results.innerHTML = '';
            if (!val) {
                results.style.display = 'none';
                return;
            }

            var matches = barangayList.filter(function (b) {
                return b.name.toLowerCase().indexOf(val) !== -1;
            });

            if (matches.length === 0) {
                results.innerHTML = '<div class="gis-search-item text-muted">No barangay found</div>';
                results.style.display = 'block';
                return;
            }

            matches.slice(0, 6).forEach(function (b) {
                var item = document.createElement('div');
                item.className = 'gis-search-item';
                item.innerHTML = '<span><i class="bi bi-geo-alt text-danger me-2"></i>' + b.name + '</span><span class="badge bg-light text-muted border">Barangay</span>';
                item.addEventListener('click', function () {
                    input.value = b.name;
                    results.style.display = 'none';
                    selectBarangay(b.properties, b.layer);
                });
                results.appendChild(item);
            });

            results.style.display = 'block';
        });

        document.addEventListener('click', function (e) {
            if (!document.querySelector('.gis-search-box').contains(e.target)) {
                results.style.display = 'none';
            }
        });
    }

    // Scheduled Visits Drawer
    var drawer = document.getElementById('gisVisitsDrawer');
    var btnToggle = document.getElementById('btnToggleVisitsDrawer');
    var btnClose  = document.getElementById('btnCloseDrawer');

    btnToggle.addEventListener('click', function () {
        drawer.classList.add('open');
    });

    btnClose.addEventListener('click', function () {
        drawer.classList.remove('open');
    });

    // Clicking an item in the drawer flies to its marker
    document.querySelectorAll('.gis-drawer-visit-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var idx = parseInt(item.dataset.pinIndex, 10);
            var m = visitMarkers[idx];
            if (m) {
                map.flyTo(m.getLatLng(), 15, { duration: 1.0 });
                m.openPopup();
                drawer.classList.remove('open');
            }
        });
    });
});
</script>

<?php require 'partials/footer.php'; ?>
