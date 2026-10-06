<?php
require_once 'config.php';
allow(['Super Administrator', 'Administrator']);
$title = 'Equipment Inventory';

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Add new equipment
    if ($action === 'add_equipment') {
        $name     = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'Network');
        $serial   = trim($_POST['serial_number'] ?? '');
        $qty      = max(0, (int)($_POST['quantity_available'] ?? 1));
        $cond     = trim($_POST['condition_status'] ?? 'Good');
        $storage  = trim($_POST['storage_location'] ?? 'ICT Store');

        if ($name !== '') {
            $q = db()->prepare('INSERT INTO equipment (name, category, serial_number, quantity_available, condition_status, storage_location) VALUES (?, ?, ?, ?, ?, ?)');
            $q->execute([$name, $category, $serial, $qty, $cond, $storage]);
            log_activity("Added equipment item: $name");
            header('Location: equipment.php');
            exit;
        }
    }

    // Edit existing equipment
    if ($action === 'edit_equipment') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'Network');
        $serial  = trim($_POST['serial_number'] ?? '');
        $qty     = max(0, (int)($_POST['quantity_available'] ?? 0));
        $cond    = trim($_POST['condition_status'] ?? 'Good');
        $storage = trim($_POST['storage_location'] ?? 'ICT Store');

        if ($id > 0 && $name !== '') {
            $q = db()->prepare('UPDATE equipment SET name=?, category=?, serial_number=?, quantity_available=?, condition_status=?, storage_location=? WHERE id=?');
            $q->execute([$name, $category, $serial, $qty, $cond, $storage, $id]);
            log_activity("Updated equipment item #$id: $name");
            header('Location: equipment.php');
            exit;
        }
    }

    // Delete equipment
    if ($action === 'delete_equipment') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $q = db()->prepare('SELECT name FROM equipment WHERE id = ?');
            $q->execute([$id]);
            $row = $q->fetch();
            db()->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
            log_activity('Deleted equipment item: ' . ($row['name'] ?? "#$id"));
            header('Location: equipment.php');
            exit;
        }
    }
}

$items = db()->query('SELECT * FROM equipment ORDER BY category, name')->fetchAll();

require 'partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Equipment Inventory</h3>
        <p class="text-secondary small mb-0">Track available internet kits, routers, access points, and power backups.</p>
    </div>
    <button class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm"
            data-bs-toggle="modal" data-bs-target="#addEquipmentModal">
        <i class="bi bi-plus-lg"></i>
        <span>Add Equipment</span>
    </button>
</div>

<!-- Search Bar -->
<div class="card stat-card border-0 p-3 mb-4">
    <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="equipSearchInput" class="form-control bg-light border-start-0" placeholder="Search equipment name, category, serial, location..." oninput="filterEquipTable()">
        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('equipSearchInput').value='';filterEquipTable();" title="Clear"><i class="bi bi-x-lg"></i></button>
    </div>
</div>
<!-- Equipment Table -->
<div class="card stat-card border-0">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="table app-table align-middle" id="equipTable">
                <thead>
                    <tr>
                        <th>Equipment Name</th>
                        <th>Category</th>
                        <th>Serial Number</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Assigned</th>
                        <th>Condition</th>
                        <th>Storage Location</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= h($i['name']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($i['category']) ?></span>
                            </td>
                            <td class="font-monospace small text-muted">
                                <?= h($i['serial_number'] ?: 'N/A') ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success fw-bold fs-6 px-3">
                                    <?= (int)$i['quantity_available'] ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border px-3">
                                    <?= (int)$i['quantity_assigned'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-soft-<?= $i['condition_status'] === 'Good' ? 'success' : 'warning' ?>">
                                    ● <?= h($i['condition_status']) ?>
                                </span>
                            </td>
                            <td class="small text-secondary">
                                <i class="bi bi-geo me-1"></i> <?= h($i['storage_location']) ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <!-- Edit button -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Edit"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($i), ENT_QUOTES) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <!-- Delete button -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Delete"
                                            onclick="openDeleteModal(<?= (int)$i['id'] ?>, <?= htmlspecialchars(json_encode($i['name']), ENT_QUOTES) ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                No equipment items in inventory yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Modal: Add New Equipment ──────────────────────────────────────────────── -->
<div class="modal fade" id="addEquipmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="add_equipment">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold text-dark">
                        <i class="bi bi-box-seam me-2 text-primary"></i> Add Equipment Item
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Equipment Name *</label>
                        <input class="form-control" name="name" required placeholder="e.g. Starlink Mini Kit V2">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Category</label>
                            <select class="form-select" name="category">
                                <option>Internet</option>
                                <option>Network</option>
                                <option>Cable</option>
                                <option>Power</option>
                                <option>Hardware</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Serial Number</label>
                            <input class="form-control" name="serial_number" placeholder="SN-8849201">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Quantity Available</label>
                            <input class="form-control" type="number" min="0" name="quantity_available" value="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Condition Status</label>
                            <select class="form-select" name="condition_status">
                                <option>Good</option>
                                <option>Fair</option>
                                <option>Maintenance Required</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Storage Location</label>
                        <input class="form-control" name="storage_location" value="ICT Store" placeholder="ICT Store Room 3">
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-check-lg me-1"></i> Save Equipment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Edit Equipment ─────────────────────────────────────────────────── -->
<div class="modal fade" id="editEquipmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post" id="editEquipmentForm">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="edit_equipment">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold text-dark">
                        <i class="bi bi-pencil-square me-2 text-primary"></i> Edit Equipment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Equipment Name *</label>
                        <input class="form-control" name="name" id="edit_name" required placeholder="e.g. Starlink Mini Kit V2">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Category</label>
                            <select class="form-select" name="category" id="edit_category">
                                <option>Internet</option>
                                <option>Network</option>
                                <option>Cable</option>
                                <option>Power</option>
                                <option>Hardware</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Serial Number</label>
                            <input class="form-control" name="serial_number" id="edit_serial" placeholder="SN-8849201">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Quantity Available</label>
                            <input class="form-control" type="number" min="0" name="quantity_available" id="edit_qty">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Condition Status</label>
                            <select class="form-select" name="condition_status" id="edit_condition">
                                <option>Good</option>
                                <option>Fair</option>
                                <option>Maintenance Required</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Storage Location</label>
                        <input class="form-control" name="storage_location" id="edit_storage" placeholder="ICT Store Room 3">
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-check-lg me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Confirm Delete ─────────────────────────────────────────────────── -->
<div class="modal fade" id="deleteEquipmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <form method="post" id="deleteEquipmentForm">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete_equipment">
                <input type="hidden" name="id" id="delete_id">

                <div class="modal-header border-bottom border-danger-subtle bg-danger-subtle">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="bi bi-trash me-2"></i> Delete Equipment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <p class="mb-1">Are you sure you want to delete</p>
                    <p class="fw-bold text-dark" id="delete_name_label"></p>
                    <p class="small text-muted mb-0">This action cannot be undone.</p>
                </div>
                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border w-50" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-semibold w-50">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(item) {
    document.getElementById('edit_id').value       = item.id;
    document.getElementById('edit_name').value     = item.name;
    document.getElementById('edit_serial').value   = item.serial_number || '';
    document.getElementById('edit_qty').value      = item.quantity_available;
    document.getElementById('edit_storage').value  = item.storage_location || '';

    // Set select values
    ['edit_category', 'edit_condition'].forEach(id => {
        const map = { edit_category: 'category', edit_condition: 'condition_status' };
        const sel = document.getElementById(id);
        const val = item[map[id]];
        for (let opt of sel.options) {
            opt.selected = opt.value === val || opt.text === val;
        }
    });

    new bootstrap.Modal(document.getElementById('editEquipmentModal')).show();
}

function openDeleteModal(id, name) {
    document.getElementById('delete_id').value      = id;
    document.getElementById('delete_name_label').textContent = '"' + name + '"';
    new bootstrap.Modal(document.getElementById('deleteEquipmentModal')).show();
}
</script>


<script>
(function() {
    function filterEquipTable() {
        var q = document.getElementById('equipSearchInput').value.toLowerCase().trim();
        var rows = document.querySelectorAll('#equipTable tbody tr');
        rows.forEach(function(row) {
            // Only filter data rows (not modal rows hiding inside tbody if any)
            var text = row.textContent.toLowerCase();
            row.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
    }
    window.filterEquipTable = filterEquipTable;
    // Also wire up the clear button dynamically
    document.addEventListener('DOMContentLoaded', function() {
        var inp = document.getElementById('equipSearchInput');
        if (inp) {
            inp.addEventListener('input', filterEquipTable);
        }
    });
})();
</script>
<?php require 'partials/footer.php'; ?>
