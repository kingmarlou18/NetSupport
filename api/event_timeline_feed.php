<?php
require_once __DIR__ . '/../config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$eventId = max(0, (int)($_GET['event_id'] ?? 0));
$afterId = max(0, (int)($_GET['after_id'] ?? 0));
$me = user();

if ($eventId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event.']);
    exit;
}

if (is_field_staff()) {
    $access = db()->prepare('SELECT 1 FROM event_assignments WHERE event_id = ? AND technician_id = ? LIMIT 1');
    $access->execute([$eventId, $me['id']]);
    if (!$access->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
}

$stmt = db()->prepare('SELECT l.id, l.action, l.created_at, u.full_name
                       FROM activity_logs l
                       JOIN users u ON u.id = l.user_id
                       WHERE l.event_id = ? AND l.id > ?
                       ORDER BY l.id ASC
                       LIMIT 100');
$stmt->execute([$eventId, $afterId]);
$items = $stmt->fetchAll();
foreach ($items as &$item) {
    // Include the Bayawan timezone so browsers in other timezones render the
    // same wall-clock time as the initial server-rendered timeline.
    $item['created_at'] = (new DateTimeImmutable($item['created_at'], new DateTimeZone('Asia/Manila')))
        ->format(DateTimeInterface::ATOM);
}
unset($item);
echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
