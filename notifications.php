<?php
$title = 'Notifications';
require 'partials/header.php';

// Mark user's notifications as read
db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([user()['id']]);

$q = db()->prepare('SELECT n.*, e.title 
                    FROM notifications n 
                    LEFT JOIN events e ON e.id = n.event_id 
                    WHERE n.user_id = ? 
                    ORDER BY n.created_at DESC');
$q->execute([user()['id']]);
$notifications = $q->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Your Notifications</h3>
        <p class="text-secondary small mb-0">System alerts and technician assignment updates.</p>
    </div>
</div>

<div class="card stat-card border-0 p-3">
    <?php if ($notifications): ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $n): ?>
                <div class="list-group-item px-3 py-3 border-bottom d-flex align-items-start gap-3">
                    <div class="bg-primary-subtle text-primary rounded-circle p-2 fs-5 mt-1">
                        <i class="bi bi-bell"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark mb-1"><?= h($n['message']) ?></div>
                        <?php if ($n['event_id']): ?>
                            <a href="event.php?id=<?= $n['event_id'] ?>" class="btn btn-sm btn-light border text-primary fw-semibold mb-2">
                                <i class="bi bi-arrow-right-circle me-1"></i> View Event #<?= $n['event_id'] ?> — <?= h($n['title']) ?>
                            </a>
                        <?php endif; ?>
                        <div class="small text-muted font-monospace"><i class="bi bi-clock me-1"></i> <?= date('M j, Y — g:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-5">
            <i class="bi bi-bell-slash fs-1 text-secondary d-block mb-3"></i>
            <h5 class="fw-bold text-dark">No Notifications</h5>
            <p class="text-secondary small mb-0">You have no unread or recent notifications at this time.</p>
        </div>
    <?php endif; ?>
</div>

<?php require 'partials/footer.php'; ?>
