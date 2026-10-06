<?php
require_once 'config.php';
allow(['Super Administrator']);

$error = '';

// Handle POST BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_office') {
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        if ($name !== '') {
            $q = db()->prepare('INSERT INTO offices (name, department) VALUES (?, ?)');
            $q->execute([$name, $dept]);
            log_activity("Created office: $name");
            header('Location: offices.php');
            exit;
        }
    }

    if ($action === 'edit_office') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        if ($id && $name !== '') {
            $q = db()->prepare('UPDATE offices SET name = ?, department = ? WHERE id = ?');
            $q->execute([$name, $dept, $id]);
            log_activity("Updated office: $name");
            header('Location: offices.php');
            exit;
        }
    }

    if ($action === 'delete_office') {
        $id = (int)$_POST['id'];
        try {
            $q = db()->prepare('DELETE FROM offices WHERE id = ?');
            $q->execute([$id]);
            log_activity("Deleted office ID #$id");
            header('Location: offices.php');
            exit;
        } catch (PDOException $e) {
            $error = "Cannot delete office: Users or events are associated with this office.";
        }
    }
}

$title = 'Offices & Departments';
require 'partials/header.php';

$offices = db()->query('SELECT o.*, COUNT(u.id) user_count 
                        FROM offices o 
                        LEFT JOIN users u ON u.office_id = o.id 
                        GROUP BY o.id 
                        ORDER BY o.name')->fetchAll();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Offices & Departments</h3>
        <p class="text-secondary small mb-0">Manage national, regional, and branch offices requesting network support.</p>
    </div>
    <button class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addOfficeModal">
        <i class="bi bi-building-add"></i>
        <span>Add Office</span>
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4 shadow-sm">
        <i class="bi bi-exclamation-octagon me-1"></i> <?= h($error) ?>
    </div>
<?php endif; ?>

<!-- Search Bar -->
<div class="card stat-card border-0 p-3 mb-4">
    <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="officeSearchInput" class="form-control bg-light border-start-0" placeholder="Search office name or department..." oninput="filterOfficeTable()">
        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('officeSearchInput').value='';filterOfficeTable();" title="Clear"><i class="bi bi-x-lg"></i></button>
    </div>
</div>
<div class="card stat-card border-0">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="table app-table align-middle" id="officeTable">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>Office / Regional Name</th>
                        <th>Department</th>
                        <th class="text-center">Assigned Users</th>
                        <th>Created Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($offices as $o): ?>
                        <tr>
                            <td class="fw-bold text-muted">#<?= $o['id'] ?></td>
                            <td>
                                <div class="fw-bold text-dark"><i class="bi bi-building me-1 text-primary"></i> <?= h($o['name']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($o['department'] ?: 'General') ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary-subtle text-primary fw-semibold px-3"><?= (int)$o['user_count'] ?> users</span>
                            </td>
                            <td class="small text-muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editOfficeModal<?= $o['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteOfficeModal<?= $o['id'] ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Office Modal -->
                        <div class="modal fade" id="editOfficeModal<?= $o['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border-0 shadow-lg text-start">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="edit_office">
                                        <input type="hidden" name="id" value="<?= $o['id'] ?>">

                                        <div class="modal-header border-bottom">
                                            <h5 class="modal-title fw-bold text-dark">Edit Office</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label small fw-semibold">Office Name *</label>
                                                <input class="form-control" name="name" required value="<?= h($o['name']) ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label small fw-semibold">Department</label>
                                                <input class="form-control" name="department" value="<?= h($o['department']) ?>">
                                            </div>
                                        </div>

                                        <div class="modal-footer border-top bg-light">
                                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary fw-semibold">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete Office Modal -->
                        <div class="modal fade" id="deleteOfficeModal<?= $o['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                <div class="modal-content border-0 shadow-lg text-start">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="delete_office">
                                        <input type="hidden" name="id" value="<?= $o['id'] ?>">

                                        <div class="modal-header border-bottom bg-danger-subtle text-danger">
                                            <h5 class="modal-title fw-bold fs-6">Delete Office</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body py-3">
                                            <p class="small text-secondary mb-0">Delete office <strong><?= h($o['name']) ?></strong>?</p>
                                        </div>

                                        <div class="modal-footer border-top bg-light">
                                            <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger btn-sm fw-semibold">Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Office Modal -->
<div class="modal fade" id="addOfficeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="add_office">

                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-building-add me-2 text-primary"></i> Add Office</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Office Name *</label>
                        <input class="form-control" name="name" required placeholder="e.g. Regional Office East">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Department</label>
                        <input class="form-control" name="department" placeholder="e.g. ICT / Operations">
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">Create Office</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
(function() {
    function filterOfficeTable() {
        var q = document.getElementById('officeSearchInput').value.toLowerCase().trim();
        var rows = document.querySelectorAll('#officeTable tbody tr');
        rows.forEach(function(row) {
            // Only filter data rows (not modal rows hiding inside tbody if any)
            var text = row.textContent.toLowerCase();
            row.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
    }
    window.filterOfficeTable = filterOfficeTable;
    // Also wire up the clear button dynamically
    document.addEventListener('DOMContentLoaded', function() {
        var inp = document.getElementById('officeSearchInput');
        if (inp) {
            inp.addEventListener('input', filterOfficeTable);
        }
    });
})();
</script>
<?php require 'partials/footer.php'; ?>
