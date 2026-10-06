<?php
declare(strict_types=1);

$message = '';
$error = '';

try {
    $host = 'localhost';
    $user = 'root';
    $pass = '';

    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS network_support CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE network_support");

    $sqlPath = __DIR__ . '/database/schema.sql';
    if (file_exists($sqlPath)) {
        $sql = file_get_contents($sqlPath);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // Ignore duplicate table/entry warnings
                }
            }
        }
    }

    // Add actual_equipment and internet_performance columns to event_reports if missing
    try {
        $pdo->exec("ALTER TABLE event_reports ADD COLUMN actual_equipment TEXT AFTER recommendations");
        $pdo->exec("ALTER TABLE event_reports ADD COLUMN internet_performance VARCHAR(100) AFTER actual_equipment");
    } catch (PDOException $e) {}

    // Generate valid bcrypt password hash
    $passwordHash = password_hash('password', PASSWORD_DEFAULT);

    $pdo->exec("INSERT IGNORE INTO roles (id, name) VALUES 
        (1, 'Super Administrator'),
        (2, 'Administrator'),
        (3, 'Network Technician'),
        (4, 'CCS Staff')");

    $pdo->exec("INSERT IGNORE INTO offices (id, name, department) VALUES 
        (1, 'National Office', 'ICT'),
        (2, 'Regional Office North', 'Administration'),
        (3, 'Regional Office South', 'Administration'),
        (4, 'Executive Office', 'Management')");

    $stmt = $pdo->prepare("INSERT INTO users (id, full_name, username, email, password_hash, office_id, position_title, role_id, status)
        VALUES (1, 'System Administrator', 'admin', 'admin@example.local', ?, 1, 'IT Manager', 1, 'Active')
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'Active'");
    $stmt->execute([$passwordHash]);

    $stmt = $pdo->prepare("INSERT INTO users (id, full_name, username, email, password_hash, office_id, position_title, role_id, status)
        VALUES (2, 'Amina Technician', 'amina', 'amina@example.local', ?, 1, 'Network Technician', 3, 'Active')
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'Active'");
    $stmt->execute([$passwordHash]);

    // Seed default availability for technicians
    $pdo->exec("INSERT IGNORE INTO technician_availability (technician_id, availability) VALUES (2, 'Available')");

    $message = "Database 'network_support' setup complete! All tables and accounts initialized.";
} catch (Exception $e) {
    $error = "Setup error: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Database Setup & Reset · NetSupport</title>
</head>
<body class="bg-light d-flex align-items-center min-vh-100 py-4">
<div class="container" style="max-width: 520px;">
    <div class="card shadow-sm border-0 p-4">
        <h3 class="fw-bold text-primary mb-1">◈ NetSupport Setup</h3>
        <p class="text-secondary small mb-4">Database initialization & system reset utility</p>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <strong>✓ Ready:</strong> <?= htmlspecialchars($message) ?>
            </div>
            <div class="card bg-body-tertiary p-3 mb-4 border-0">
                <h6 class="fw-bold mb-2">Default Account Logins:</h6>
                <div class="small">
                    <strong>Admin:</strong> <code>admin</code> &nbsp;|&nbsp; <strong>Password:</strong> <code>password</code><br>
                    <strong>Technician:</strong> <code>amina</code> &nbsp;|&nbsp; <strong>Password:</strong> <code>password</code>
                </div>
            </div>
            <a href="login.php" class="btn btn-primary w-100 py-2 fw-semibold">Go to Login Page &rarr;</a>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger mb-3">
                <strong>✗ Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
            <p class="small text-secondary">Make sure MySQL is running in XAMPP and your database credentials in <code>config.php</code> are correct.</p>
            <a href="setup.php" class="btn btn-outline-secondary w-100">Try Again</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
