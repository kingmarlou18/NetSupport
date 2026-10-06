<?php
require_once 'config.php';
allow(['Super Administrator']);
$title = 'Audit Log';
require 'partials/header.php';

$rows = db()->query('SELECT l.*, u.full_name, e.title 
                     FROM activity_logs l 
                     JOIN users u ON u.id = l.user_id 
                     LEFT JOIN events e ON e.id = l.event_id 
                     ORDER BY l.created_at DESC 
                     LIMIT 200')->fetchAll();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">System Audit Trail</h3>
        <p class="text-secondary small mb-0">Security and activity logs for administrative tracking and compliance.</p>
    </div>
</div>

<div class="card stat-card border-0">
    <div class="app-table-container">
        <div class="table-responsive">
            <table class="table app-table align-middle">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action Performed</th>
                        <th>Target Event</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="small text-muted font-monospace"><?= date('M j, Y — g:i:s A', strtotime($r['created_at'])) ?></td>
                            <td>
                                <span class="fw-semibold text-dark"><i class="bi bi-person me-1"></i> <?= h($r['full_name']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($r['action']) ?></span>
                            </td>
                            <td>
                                <?php if ($r['event_id']): ?>
                                    <a href="event.php?id=<?= $r['event_id'] ?>" class="text-primary text-decoration-none fw-semibold">
                                        <?= h($r['title'] ?: '#' . $r['event_id']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace small text-muted"><?= h($r['ip_address'] ?: '127.0.0.1') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                No audit log records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require 'partials/footer.php'; ?>
