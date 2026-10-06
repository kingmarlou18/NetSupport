<?php
declare(strict_types=1);

// Prevent direct access if already installed
if (file_exists(__DIR__ . '/installed.lock')) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'System is already installed. Delete install/installed.lock to reinstall.']));
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

// ─── ACTION: Test DB Connection ───────────────────────────────────────────────
if ($action === 'test_db') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = (int)($_POST['db_port'] ?? 3306);
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo json_encode(['success' => true, 'message' => "Connected successfully! MySQL/MariaDB version: $version"]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ACTION: Run Full Installation ────────────────────────────────────────────
if ($action === 'install') {
    $host    = trim($_POST['db_host']     ?? 'localhost');
    $port    = (int)($_POST['db_port']    ?? 3306);
    $dbname  = trim($_POST['db_name']     ?? 'network_support');
    $user    = trim($_POST['db_user']     ?? '');
    $pass    = $_POST['db_pass']          ?? '';

    $adminName  = trim($_POST['admin_name']  ?? 'System Administrator');
    $adminUser  = trim($_POST['admin_user']  ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass']       ?? '';

    $steps = [];

    function step(string $msg, bool $ok = true): array {
        return ['ok' => $ok, 'msg' => $msg];
    }

    // 1. Connect to MySQL (no DB selected yet)
    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;charset=utf8mb4",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $steps[] = step("✔ Connected to MySQL/MariaDB server");
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'steps' => [step("✘ Database connection failed: " . $e->getMessage(), false)]]);
        exit;
    }

    // 2. Create database
    try {
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbname);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$safeName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$safeName`");
        $steps[] = step("✔ Database `$safeName` created/selected");
    } catch (PDOException $e) {
        $steps[] = step("✘ Failed to create database: " . $e->getMessage(), false);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // 3. Run schema SQL (tables + default data, skip CREATE DATABASE line)
    $schemaFile = __DIR__ . '/../database/schema.sql';
    if (!file_exists($schemaFile)) {
        $steps[] = step("✘ Schema file not found at database/schema.sql", false);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    try {
        $sql = file_get_contents($schemaFile);

        // Remove CREATE DATABASE and USE lines (we already handled that)
        $sql = preg_replace('/^CREATE DATABASE.*?;\s*/im', '', $sql);
        $sql = preg_replace('/^USE\s+.*?;\s*/im', '', $sql);

        // Execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s)
        );

        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $steps[] = step("✔ Database schema and tables imported successfully");
    } catch (PDOException $e) {
        $steps[] = step("✘ Schema import failed: " . $e->getMessage(), false);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // 4. Update admin account with user-provided credentials
    try {
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);

        // Update the default admin user that was seeded by schema.sql
        $stmt = $pdo->prepare("
            UPDATE users SET
                full_name     = ?,
                username      = ?,
                email         = ?,
                password_hash = ?
            WHERE role_id = 1
            LIMIT 1
        ");
        $stmt->execute([$adminName, $adminUser, $adminEmail, $hash]);

        if ($stmt->rowCount() === 0) {
            // Insert fresh if not found
            $pdo->prepare("
                INSERT INTO users (full_name, username, email, password_hash, office_id, position_title, role_id)
                VALUES (?, ?, ?, ?, 1, 'IT Manager', 1)
            ")->execute([$adminName, $adminUser, $adminEmail, $hash]);
        }

        $steps[] = step("✔ Administrator account configured");
    } catch (PDOException $e) {
        $steps[] = step("✘ Admin account setup failed: " . $e->getMessage(), false);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // 5. Write config.php with new credentials
    try {
        $configPath = __DIR__ . '/../config.php';
        $configContent = generateConfig($host, $port, $dbname, $user, $pass);
        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception("Cannot write to config.php — check file permissions.");
        }
        $steps[] = step("✔ Configuration file (config.php) written successfully");
    } catch (Exception $e) {
        $steps[] = step("✘ Config write failed: " . $e->getMessage(), false);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // 6. Create lock file to prevent re-installation
    file_put_contents(__DIR__ . '/installed.lock', date('Y-m-d H:i:s'));
    $steps[] = step("✔ Installation locked (install/installed.lock created)");
    $steps[] = step("✔ Installation complete! Redirecting to login...");

    echo json_encode(['success' => true, 'steps' => $steps]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;

// ─── Helper: Generate config.php content ─────────────────────────────────────
function generateConfig(string $host, int $port, string $dbname, string $user, string $pass): string {
    $safeHost   = addslashes($host);
    $safeDbname = addslashes($dbname);
    $safeUser   = addslashes($user);
    $safePass   = addslashes($pass);
    $now        = date('Y-m-d H:i:s');

    return <<<PHP
<?php
declare(strict_types=1);
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

// Database configuration — generated by installer on $now
const DB_HOST = '$safeHost';
const DB_PORT = $port;
const DB_NAME = '$safeDbname';
const DB_USER = '$safeUser';
const DB_PASS = '$safePass';

function db(): PDO {
    static \$pdo;
    if (!\$pdo) {
        \$pdo = new PDO(
            'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return \$pdo;
}
function h(?string \$value): string { return htmlspecialchars((string)\$value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return \$_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void {
    if (!hash_equals(\$_SESSION['csrf'] ?? '', \$_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid request token.'); }
}
function user(): ?array { return \$_SESSION['user'] ?? null; }
function require_login(): void { if (!user()) { header('Location: login.php'); exit; } }
function allow(array \$roles): void { require_login(); if (!in_array(user()['role'], \$roles, true)) { http_response_code(403); exit('Access denied.'); } }
function log_activity(string \$action, ?int \$eventId = null): void {
    if (!user()) return;
    \$q = db()->prepare('INSERT INTO activity_logs (user_id, event_id, action, ip_address) VALUES (?,?,?,?)');
    \$q->execute([user()['id'], \$eventId, \$action, \$_SERVER['REMOTE_ADDR'] ?? null]);
}
function notify(int \$userId, string \$message, ?int \$eventId = null): void {
    db()->prepare('INSERT INTO notifications (user_id,event_id,message) VALUES (?,?,?)')->execute([\$userId,\$eventId,\$message]);
}
function status_class(string \$status): string { return match(\$status) { 'Completed' => 'success', 'In Progress' => 'primary', 'Assigned','Accepted' => 'info', 'Urgent' => 'danger', 'Cancelled' => 'danger', 'Rescheduled','Pending' => 'warning', default => 'secondary' }; }
PHP;
}
