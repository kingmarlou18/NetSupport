<?php
require_once 'config.php';
allow(['Super Administrator']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $exists = db()->prepare('SELECT id FROM event_categories WHERE name = ?');
            $exists->execute([$name]);
            if ($exists->fetch()) {
                $error = "A category named \"$name\" already exists.";
            } else {
                $maxOrder = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM event_categories')->fetchColumn();
                $q = db()->prepare('INSERT INTO event_categories (name, sort_order) VALUES (?, ?)');
                $q->execute([$name, $maxOrder + 10]);
                log_activity("Created category: $name");
                header('Location: categories.php');
                exit;
            }
        } else {
            $error = 'Category name is required.';
        }
    }

    if ($action === 'edit_category') {
        $id   = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        if ($id && $name !== '') {
            $dupe = db()->prepare('SELECT id FROM event_categories WHERE name = ? AND id <> ?');
            $dupe->execute([$name, $id]);
            if ($dupe->fetch()) {
                $error = "A category named \"$name\" already exists.";
            } else {
                // Renaming here does not touch existing events' stored category
                // text — this only changes what appears going forward. Keeping
                // history literal (what was requested at the time) rather than
                // rewriting it is the same choice made for barangay records.
                $q = db()->prepare('UPDATE event_categories SET name = ? WHERE id = ?');
                $q->execute([$name, $id]);
                log_activity("Renamed category #$id to: $name");
                header('Location: categories.php');
                exit;
            }
        } else {
            $error = 'Category name is required.';
        }
    }

    if ($action === 'delete_category') {
        $id = (int)$_POST['id'];
        $cat = db()->prepare('SELECT name FROM event_categories WHERE id = ?');
        $cat->execute([$id]);
        $catName = $cat->fetchColumn();

        if ($catName === false) {
            $error = 'That category no longer exists.';
        } else {
            // No foreign key ties events.category to this table — it is a
            // plain string column — so a delete here would silently orphan
            // any request already filed under this name rather than fail
            // loudly. Block it instead and say how many requests are affected.
            $inUse = db()->prepare('SELECT COUNT(*) FROM events WHERE category = ?');
            $inUse->execute([$catName]);
            $count = (int)$inUse->fetchColumn();

            if ($count > 0) {
                $error = "Cannot delete \"$catName\" — $count "
                       . ($count === 1 ? 'request uses' : 'requests use')
                       . ' it. Rename it instead, or move those requests to another category first.';
            } else {
                db()->prepare('DELETE FROM event_categories WHERE id = ?')->execute([$id]);
                log_activity("Deleted category: $catName");
                header('Location: categories.php');
                exit;
            }
        }
    }

    if ($action === 'move_category') {
        $id  = (int)$_POST['id'];
        $dir = $_POST['direction'] ?? '';
        $all = db()->query('SELECT id, sort_order FROM event_categories ORDER BY sort_order, id')->fetchAll();
        $idx = null;
        foreach ($all as $i => $row) {
            if ((int)$row['id'] === $id) { $idx = $i; break; }
        }
        if ($idx !== null) {
            $swapWith = $dir === 'up' ? $idx - 1 : $idx + 1;
            if ($swapWith >= 0 && $swapWith < count($all)) {
                $a = $all[$idx];
                $b = $all[$swapWith];
                $db = db();
                $db->prepare('UPDATE event_categories SET sort_order = ? WHERE id = ?')->execute([$b['sort_order'], $a['id']]);
                $db->prepare('UPDATE event_categories SET sort_order = ? WHERE id = ?')->execute([$a['sort_order'], $b['id']]);
            }
        }
        header('Location: categories.php');
        exit;
    }
}

$title = 'Categories';
require 'partials/header.php';

$categories = db()->query(
    'SELECT c.*, COUNT(e.id) usage_count
     FROM event_categories c
     LEFT JOIN events e ON e.category = c.name
     GROUP BY c.id
     ORDER BY c.sort_order, c.id'
)->fetchAll();
?>

<header class="page-head">
    <div>
        <h1 class="page-title">Categories</h1>
        <p class="page-sub">What a support request is scheduled as — shown on the request form and the map filter.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
        <i class="bi bi-plus-lg"></i> Add category
    </button>
</header>

<?php if ($error): ?>
    <div class="notice notice-warn">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?= h($error) ?></div>
    </div>
<?php endif; ?>


<!-- Search Bar -->
<div class="card stat-card border-0 p-3 mb-4">
    <div class="input-group">
        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="catSearchInput" class="form-control bg-light border-start-0" placeholder="Search category name..." oninput="filterCatTable()">
        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('catSearchInput').value='';filterCatTable();" title="Clear"><i class="bi bi-x-lg"></i></button>
    </div>
</div>
<div class="stat-card">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="table app-table align-middle mb-0" id="catTable">
                <thead>
                    <tr>
                        <th style="width: 90px;">Order</th>
                        <th>Category</th>
                        <th class="text-center">In use</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $i => $c): ?>
                        <tr>
                            <td>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Reorder">
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="move_category">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button class="btn btn-quiet" type="submit" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move up">
                                            <i class="bi bi-chevron-up"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="m-0">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="move_category">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button class="btn btn-quiet" type="submit" <?= $i === count($categories) - 1 ? 'disabled' : '' ?> aria-label="Move down">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <td class="fw-semibold"><?= h($c['name']) ?></td>
                            <td class="text-center">
                                <?php if ((int)$c['usage_count'] > 0): ?>
                                    <span class="badge-soft badge-soft-primary"><?= (int)$c['usage_count'] ?> <?= $c['usage_count'] == 1 ? 'request' : 'requests' ?></span>
                                <?php else: ?>
                                    <span class="badge-soft badge-soft-secondary">Unused</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-quiet" data-bs-toggle="modal" data-bs-target="#editCategoryModal<?= $c['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-quiet" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal<?= $c['id'] ?>">
                                        <i class="bi bi-trash text-danger"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit modal -->
                        <div class="modal fade" id="editCategoryModal<?= $c['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="edit_category">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <div class="modal-header">
                                            <h2 class="h5 mb-0">Rename category</h2>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <label class="form-label" for="editName<?= $c['id'] ?>">Name</label>
                                            <input class="form-control" id="editName<?= $c['id'] ?>" name="name" required value="<?= h($c['name']) ?>">
                                            <?php if ((int)$c['usage_count'] > 0): ?>
                                                <p class="small text-muted mt-2 mb-0">
                                                    <?= (int)$c['usage_count'] ?> existing <?= $c['usage_count'] == 1 ? 'request keeps' : 'requests keep' ?> the name it had when it was filed. This changes the name going forward.
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Delete modal -->
                        <div class="modal fade" id="deleteCategoryModal<?= $c['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-sm">
                                <div class="modal-content">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <div class="modal-header">
                                            <h2 class="h6 mb-0">Delete category</h2>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php if ((int)$c['usage_count'] > 0): ?>
                                                <p class="small mb-0">
                                                    <?= (int)$c['usage_count'] ?> existing <?= $c['usage_count'] == 1 ? 'request uses' : 'requests use' ?>
                                                    "<?= h($c['name']) ?>". Rename it instead, or reassign those requests before deleting.
                                                </p>
                                            <?php else: ?>
                                                <p class="small mb-0">Delete "<?= h($c['name']) ?>"? Nothing currently uses it.</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-quiet btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger btn-sm" <?= (int)$c['usage_count'] > 0 ? 'disabled' : '' ?>>Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($categories)): ?>
                        <tr><td colspan="4" class="empty">No categories yet. Add one to get started.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="add_category">
                <div class="modal-header">
                    <h2 class="h5 mb-0">Add category</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="newCategoryName">Name</label>
                    <input class="form-control" id="newCategoryName" name="name" required autofocus placeholder="e.g. Site Survey">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-quiet" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add category</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
(function() {
    function filterCatTable() {
        var q = document.getElementById('catSearchInput').value.toLowerCase().trim();
        var rows = document.querySelectorAll('#catTable tbody tr');
        rows.forEach(function(row) {
            // Only filter data rows (not modal rows hiding inside tbody if any)
            var text = row.textContent.toLowerCase();
            row.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
    }
    window.filterCatTable = filterCatTable;
    // Also wire up the clear button dynamically
    document.addEventListener('DOMContentLoaded', function() {
        var inp = document.getElementById('catSearchInput');
        if (inp) {
            inp.addEventListener('input', filterCatTable);
        }
    });
})();
</script>
<?php require 'partials/footer.php'; ?>
