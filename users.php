<?php
require_once 'config.php';
allow(['Super Administrator']);

$message = '';
$error = '';
// Handle POST BEFORE any HTML output

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Handle Add User
    if ($action === 'add_user') {
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $pass = $_POST['password'] ?? 'password';
        $roleId = (int)$_POST['role_id'];
        $officeId = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : null;

        if ($name && $username && $email) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $q = db()->prepare('INSERT INTO users (full_name, username, email, phone_number, password_hash, office_id, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, "Active")');
            $q->execute([$name, $username, $email, $phoneNumber ?: null, $hash, $officeId, $roleId]);
            log_activity("Created user account: $username");
            header('Location: users.php');
            exit;
        }
    }

    // Handle Edit User
    if ($action === 'edit_user') {
        $userId = (int)$_POST['id'];
        $name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        $roleId = (int)$_POST['role_id'];
        $officeId = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : null;
        $status = $_POST['status'] ?? 'Active';
        $newPass = trim($_POST['password'] ?? '');

        if ($userId && $name && $username && $email) {
            if ($newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $q = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone_number = ?, password_hash = ?, office_id = ?, role_id = ?, status = ? WHERE id = ?');
                $q->execute([$name, $username, $email, $phoneNumber ?: null, $hash, $officeId, $roleId, $status, $userId]);
            } else {
                $q = db()->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, phone_number = ?, office_id = ?, role_id = ?, status = ? WHERE id = ?');
                $q->execute([$name, $username, $email, $phoneNumber ?: null, $officeId, $roleId, $status, $userId]);
            }
            log_activity("Updated user account: $username", null);
            header('Location: users.php');
            exit;
        }
    }

    // Handle Delete User
    if ($action === 'delete_user') {
        $targetId = (int)$_POST['id'];
        if ($targetId === (int)user()['id']) {
            $error = "You cannot delete your own account while logged in!";
        } else {
            try {
                $q = db()->prepare('DELETE FROM users WHERE id = ?');
                $q->execute([$targetId]);
                log_activity("Deleted user ID #$targetId");
                header('Location: users.php');
                exit;
            } catch (PDOException $e) {
                $error = "Cannot delete user: They may have assigned events or audit logs attached.";
            }
        }
    }
}

$users = db()->query('SELECT u.*, r.name role, o.name office 
                      FROM users u 
                      JOIN roles r ON r.id = u.role_id 
                      LEFT JOIN offices o ON o.id = u.office_id 
                      ORDER BY u.created_at DESC')->fetchAll();

$roles = db()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$offices = db()->query('SELECT * FROM offices')->fetchAll();

$title = 'Users Management';
require 'partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">User Management</h3>
        <p class="text-secondary small mb-0">Manage system users, technician roles, and office access permissions.</p>
    </div>
    <button class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus"></i>
        <span>Add User Account</span>
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4 shadow-sm" role="alert">
        <i class="bi bi-exclamation-octagon me-1"></i> <?= h($error) ?>
    </div>
<?php endif; ?>

<!-- Search Bar -->
<div class="card stat-card border-0 p-3 mb-4">
    <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="userSearchInput" class="form-control bg-light border-start-0" placeholder="Search user name, username, email, role, office..." oninput="filterUserTable()">
        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('userSearchInput').value='';filterUserTable();" title="Clear"><i class="bi bi-x-lg"></i></button>
    </div>
</div>
<!-- Users Table -->
<div class="card stat-card border-0">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="table app-table align-middle" id="userTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Email Address</th>
                        <th>Mobile Number</th>
                        <th>Assigned Office</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div class="fw-bold text-dark"><?= h($u['full_name']) ?></div>
                                </div>
                            </td>
                            <td class="font-monospace small text-dark"><?= h($u['username']) ?></td>
                            <td class="small text-secondary"><?= h($u['email']) ?></td>
                            <td class="small text-secondary"><?= h($u['phone_number'] ?? '—') ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($u['office'] ?: 'All Offices') ?></span>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary fw-semibold"><?= h($u['role']) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-soft-<?= $u['status'] === 'Active' ? 'success' : 'secondary' ?>">
                                    ● <?= h($u['status']) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Edit Button -->
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $u['id'] ?>" title="Edit User">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    
                                    <!-- Delete Button (Only for non-self) -->
                                    <?php if ((int)$u['id'] !== (int)user()['id']): ?>
                                        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal<?= $u['id'] ?>" title="Delete User">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit User Modal -->
                        <div class="modal fade" id="editUserModal<?= $u['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border-0 shadow-lg">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="edit_user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">

                                        <div class="modal-header border-bottom">
                                            <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i> Edit User Account</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="mb-3 text-start">
                                                <label class="form-label small fw-semibold">Full Name *</label>
                                                <input class="form-control" name="full_name" required value="<?= h($u['full_name']) ?>">
                                            </div>

                                            <div class="row g-3 mb-3 text-start">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">Username *</label>
                                                    <input class="form-control" name="username" required value="<?= h($u['username']) ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold">New Password (Optional)</label>
                                                    <input class="form-control" type="password" name="password" placeholder="Leave blank to keep">
                                                </div>
                                            </div>

                                            <div class="mb-3 text-start">
                                                <label class="form-label small fw-semibold">Email Address *</label>
                                                <input class="form-control" type="email" name="email" required value="<?= h($u['email']) ?>">
                                            </div>

                                            <div class="mb-3 text-start">
                                                <label class="form-label small fw-semibold">Mobile Number (for SMS)</label>
                                                <input class="form-control" type="tel" name="phone_number" value="<?= h($u['phone_number'] ?? '') ?>" placeholder="09XXXXXXXXX or +639XXXXXXXXX" autocomplete="tel">
                                            </div>

                                            <div class="row g-3 mb-3 text-start">
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">Role *</label>
                                                    <select class="form-select" name="role_id" required>
                                                        <?php foreach ($roles as $r): ?>
                                                            <option value="<?= $r['id'] ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label small fw-semibold">Office</label>
                                                    <select class="form-select" name="office_id">
                                                        <option value="">National / All Offices</option>
                                                        <?php foreach ($offices as $o): ?>
                                                            <option value="<?= $o['id'] ?>" <?= (int)$u['office_id'] === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small fw-semibold">Status</label>
                                                    <select class="form-select" name="status">
                                                        <option value="Active" <?= $u['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                                        <option value="Inactive" <?= $u['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer border-top bg-light">
                                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-save me-1"></i> Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete User Modal -->
                        <?php if ((int)$u['id'] !== (int)user()['id']): ?>
                        <div class="modal fade" id="deleteUserModal<?= $u['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                <div class="modal-content border-0 shadow-lg text-start">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">

                                        <div class="modal-header border-bottom bg-danger-subtle text-danger">
                                            <h5 class="modal-title fw-bold fs-6"><i class="bi bi-exclamation-triangle me-1"></i> Delete User</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body py-3">
                                            <p class="small text-secondary mb-0">Are you sure you want to delete user <strong><?= h($u['full_name']) ?></strong> (<code><?= h($u['username']) ?></code>)?</p>
                                        </div>

                                        <div class="modal-footer border-top bg-light">
                                            <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger btn-sm fw-semibold">Delete User</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="add_user">
                
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-plus me-2 text-primary"></i> Add User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold">Full Name *</label>
                        <input class="form-control" name="full_name" required placeholder="John Doe">
                    </div>

                    <div class="row g-3 mb-3 text-start">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Username *</label>
                            <input class="form-control" name="username" required placeholder="johndoe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Password *</label>
                            <input class="form-control" type="password" name="password" required value="password">
                        </div>
                    </div>

                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold">Email Address *</label>
                        <input class="form-control" type="email" name="email" required placeholder="john@example.local">
                    </div>

                    <div class="mb-3 text-start">
                        <label class="form-label small fw-semibold">Mobile Number (for SMS)</label>
                        <input class="form-control" type="tel" name="phone_number" placeholder="09XXXXXXXXX or +639XXXXXXXXX" autocomplete="tel">
                    </div>

                    <div class="row g-3 mb-3 text-start">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Role *</label>
                            <select class="form-select" name="role_id" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Office</label>
                            <select class="form-select" name="office_id">
                                <option value="">National / All Offices</option>
                                <?php foreach ($offices as $o): ?>
                                    <option value="<?= $o['id'] ?>"><?= h($o['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-check-lg me-1"></i> Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
(function() {
    function filterUserTable() {
        var q = document.getElementById('userSearchInput').value.toLowerCase().trim();
        var rows = document.querySelectorAll('#userTable tbody tr');
        rows.forEach(function(row) {
            // Only filter data rows (not modal rows hiding inside tbody if any)
            var text = row.textContent.toLowerCase();
            row.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
    }
    window.filterUserTable = filterUserTable;
    // Also wire up the clear button dynamically
    document.addEventListener('DOMContentLoaded', function() {
        var inp = document.getElementById('userSearchInput');
        if (inp) {
            inp.addEventListener('input', filterUserTable);
        }
    });
})();
</script>
<?php require 'partials/footer.php'; ?>
