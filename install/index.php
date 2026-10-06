<?php
// If already installed, block access
if (file_exists(__DIR__ . '/installed.lock')) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Already Installed</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="installer-wrapper">
            <div class="installer-header">
                <div class="installer-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.955 11.955 0 003 12c0 6.627 5.373 12 12 12s12-5.373 12-12c0-2.129-.554-4.13-1.526-5.867A11.955 11.955 0 0112 2.964z" /></svg>
                </div>
                <h1>Already Installed</h1>
                <p>Network Support System is already set up.</p>
            </div>
            <div class="installer-card" style="text-align:center;">
                <div class="alert alert-warning">
                    <span class="alert-icon">⚠️</span>
                    <div>The installer has been locked for security. To reinstall, delete the <code>install/installed.lock</code> file from the server.</div>
                </div>
                <a href="../login.php" class="btn btn-primary btn-lg" style="margin-top:16px;">Go to Login →</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — Network Support System</title>
    <meta name="description" content="Web-based installation wizard for the Network Support System.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="installer-wrapper">

    <!-- Header -->
    <div class="installer-header">
        <div class="installer-logo">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253" />
            </svg>
        </div>
        <h1>Network Support System</h1>
        <p>Installation Wizard &mdash; Follow the steps to set up your system</p>
    </div>

    <!-- Step Indicator -->
    <div class="step-indicator" id="stepIndicator">
        <div class="step-item active" id="si-1">
            <div class="step-bubble">1</div>
            <span class="step-label">Requirements</span>
        </div>
        <div class="step-line" id="sl-1"></div>
        <div class="step-item" id="si-2">
            <div class="step-bubble">2</div>
            <span class="step-label">Database</span>
        </div>
        <div class="step-line" id="sl-2"></div>
        <div class="step-item" id="si-3">
            <div class="step-bubble">3</div>
            <span class="step-label">Admin</span>
        </div>
        <div class="step-line" id="sl-3"></div>
        <div class="step-item" id="si-4">
            <div class="step-bubble">4</div>
            <span class="step-label">Installing</span>
        </div>
        <div class="step-line" id="sl-4"></div>
        <div class="step-item" id="si-5">
            <div class="step-bubble">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            </div>
            <span class="step-label">Done</span>
        </div>
    </div>

    <!-- Card -->
    <div class="installer-card">

        <!-- ═══════ STEP 1: Requirements ═══════ -->
        <div id="step-1" class="step-panel">
            <div class="section-title">👋 Welcome to the Installer</div>
            <div class="section-subtitle">Let's check your server meets the requirements before we begin.</div>

            <ul class="req-list" id="reqList">
                <!-- Populated by JS -->
            </ul>

            <div id="req-alert" style="margin-top:16px;"></div>

            <div class="btn-actions">
                <button class="btn btn-primary" id="btnStep1Next" onclick="goStep(2)" disabled>
                    Continue &rarr;
                </button>
            </div>
        </div>

        <!-- ═══════ STEP 2: Database ═══════ -->
        <div id="step-2" class="step-panel" style="display:none;">
            <div class="section-title">🗄️ Database Configuration</div>
            <div class="section-subtitle">Enter your MySQL / MariaDB connection details.</div>

            <div id="db-test-result"></div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="db_host">Database Host</label>
                    <input type="text" id="db_host" class="form-control" value="localhost" placeholder="localhost">
                </div>
                <div class="form-group">
                    <label class="form-label" for="db_port">Port</label>
                    <input type="number" id="db_port" class="form-control" value="3306" placeholder="3306">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="db_name">Database Name</label>
                <input type="text" id="db_name" class="form-control" value="network_support" placeholder="network_support">
                <div class="form-hint">The database will be created automatically if it doesn't exist.</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="db_user">Username</label>
                    <input type="text" id="db_user" class="form-control" value="root" placeholder="root">
                </div>
                <div class="form-group">
                    <label class="form-label" for="db_pass">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="db_pass" class="form-control" placeholder="Leave blank if none">
                        <button type="button" class="toggle-pass" onclick="togglePass('db_pass',this)" title="Toggle visibility">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="btn-actions">
                <button class="btn btn-outline" onclick="goStep(1)">&larr; Back</button>
                <button class="btn btn-outline" id="btnTestDb" onclick="testDB()">
                    🔌 Test Connection
                </button>
                <button class="btn btn-primary" id="btnStep2Next" onclick="goStep(3)">
                    Continue &rarr;
                </button>
            </div>
        </div>

        <!-- ═══════ STEP 3: Admin Account ═══════ -->
        <div id="step-3" class="step-panel" style="display:none;">
            <div class="section-title">👤 Administrator Account</div>
            <div class="section-subtitle">Create your super administrator credentials.</div>

            <div class="form-group">
                <label class="form-label" for="admin_name">Full Name</label>
                <input type="text" id="admin_name" class="form-control" placeholder="System Administrator">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="admin_user">Username</label>
                    <input type="text" id="admin_user" class="form-control" placeholder="admin">
                </div>
                <div class="form-group">
                    <label class="form-label" for="admin_email">Email Address</label>
                    <input type="email" id="admin_email" class="form-control" placeholder="admin@example.com">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="admin_pass">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="admin_pass" class="form-control" placeholder="Min. 8 characters">
                        <button type="button" class="toggle-pass" onclick="togglePass('admin_pass',this)" title="Toggle visibility">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="admin_pass2">Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="admin_pass2" class="form-control" placeholder="Re-enter password">
                        <button type="button" class="toggle-pass" onclick="togglePass('admin_pass2',this)" title="Toggle visibility">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div id="admin-alert"></div>

            <div class="btn-actions">
                <button class="btn btn-outline" onclick="goStep(2)">&larr; Back</button>
                <button class="btn btn-primary" onclick="validateAdmin()">
                    Review &amp; Install &rarr;
                </button>
            </div>
        </div>

        <!-- ═══════ STEP 4: Installing ═══════ -->
        <div id="step-4" class="step-panel" style="display:none;">
            <div class="section-title">⚙️ Installing System</div>
            <div class="section-subtitle">Please wait while we set up your system&hellip;</div>

            <div class="progress-wrap">
                <div class="progress-label">
                    <span id="prog-label">Initializing&hellip;</span>
                    <span id="prog-pct">0%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
            </div>

            <div class="install-log" id="installLog">
                <div class="log-entry info">» Starting installation process&hellip;</div>
            </div>

            <div id="install-alert" style="margin-top:16px;"></div>
        </div>

        <!-- ═══════ STEP 5: Complete ═══════ -->
        <div id="step-5" class="step-panel" style="display:none; text-align:center;">
            <div class="success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
            </div>

            <div class="section-title" style="text-align:center;">🎉 Installation Complete!</div>
            <div class="section-subtitle" style="text-align:center; margin-bottom:20px;">
                Your Network Support System is ready to use.
            </div>

            <div class="cred-box">
                <div class="cred-row">
                    <span class="cred-key">🌐 Login URL</span>
                    <span class="cred-val" id="cred-url"></span>
                </div>
                <div class="cred-row">
                    <span class="cred-key">👤 Username</span>
                    <span class="cred-val" id="cred-user"></span>
                </div>
                <div class="cred-row">
                    <span class="cred-key">🔑 Password</span>
                    <span class="cred-val">Your chosen password</span>
                </div>
            </div>

            <div class="security-notice">
                <span style="font-size:1.1rem;">⚠️</span>
                <div>
                    <strong>Security Notice:</strong> The <code>install/</code> folder has been locked.
                    For extra security, you may delete the entire <code>install/</code> directory from your server.
                </div>
            </div>

            <div style="margin-top:24px;">
                <a href="../login.php" class="btn btn-success btn-lg btn-block" id="btnGoLogin">
                    Go to Login Page →
                </a>
            </div>
        </div>

    </div><!-- /installer-card -->

    <p style="text-align:center; font-size:0.78rem; color:var(--text-muted); margin-top:18px;">
        Network Support System &copy; <?= date('Y') ?> &mdash; Installation Wizard
    </p>

</div><!-- /installer-wrapper -->

<script>
// ─── Requirements Data ────────────────────────────────────────────────────────
const requirements = <?php
$reqs = [
    ['name' => 'PHP Version', 'pass' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION, 'required' => true],
    ['name' => 'PDO Extension', 'pass' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? 'Loaded' : 'Missing', 'required' => true],
    ['name' => 'PDO MySQL Driver', 'pass' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing', 'required' => true],
    ['name' => 'OpenSSL Extension', 'pass' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? 'Loaded' : 'Missing', 'required' => true],
    ['name' => 'Mbstring Extension', 'pass' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'Loaded' : 'Missing', 'required' => false],
    ['name' => 'config.php Writable', 'pass' => is_writable(dirname(__DIR__) . '/config.php') || is_writable(dirname(__DIR__)), 'value' => (is_writable(dirname(__DIR__) . '/config.php') || is_writable(dirname(__DIR__))) ? 'Writable ✓' : 'Not Writable ✗', 'required' => true],
    ['name' => 'schema.sql Found', 'pass' => file_exists(dirname(__DIR__) . '/database/schema.sql'), 'value' => file_exists(dirname(__DIR__) . '/database/schema.sql') ? 'Found ✓' : 'Missing ✗', 'required' => true],
];
echo json_encode($reqs);
?>;

// ─── Render Requirements ──────────────────────────────────────────────────────
const reqList = document.getElementById('reqList');
let allRequired = true;

requirements.forEach(req => {
    const li = document.createElement('li');
    li.className = 'req-item';
    const badgeClass = req.pass ? 'pass' : (req.required ? 'fail' : 'warn');
    const badgeText  = req.pass ? '✓ Pass' : (req.required ? '✗ Fail' : '⚠ Warn');
    li.innerHTML = `
        <span class="req-name">${req.name}</span>
        <span class="req-value">${req.value}</span>
        <span class="req-badge ${badgeClass}">${badgeText}</span>
    `;
    reqList.appendChild(li);
    if (!req.pass && req.required) allRequired = false;
});

const reqAlert = document.getElementById('req-alert');
const btnNext  = document.getElementById('btnStep1Next');

if (allRequired) {
    reqAlert.innerHTML = `<div class="alert alert-success"><span class="alert-icon">✅</span><div>All requirements are met! You may proceed.</div></div>`;
    btnNext.disabled = false;
} else {
    reqAlert.innerHTML = `<div class="alert alert-error"><span class="alert-icon">❌</span><div>Some required extensions are missing. Please install them before continuing.</div></div>`;
}

// ─── Step Navigation ──────────────────────────────────────────────────────────
let currentStep = 1;

function goStep(n) {
    document.getElementById(`step-${currentStep}`).style.display = 'none';
    document.getElementById(`step-${n}`).style.display = 'block';

    // Update indicators
    for (let i = 1; i <= 5; i++) {
        const si = document.getElementById(`si-${i}`);
        si.classList.remove('active','done');
        if (i < n) si.classList.add('done');
        else if (i === n) si.classList.add('active');
    }
    for (let i = 1; i <= 4; i++) {
        const sl = document.getElementById(`sl-${i}`);
        sl.classList.toggle('done', i < n);
    }

    currentStep = n;
    if (n === 4) runInstall();
}

// ─── Test DB Connection ───────────────────────────────────────────────────────
function testDB() {
    const btn = document.getElementById('btnTestDb');
    btn.innerHTML = '<span class="spinner"></span> Testing...';
    btn.disabled = true;

    const data = new FormData();
    data.append('action', 'test_db');
    data.append('db_host', document.getElementById('db_host').value);
    data.append('db_port', document.getElementById('db_port').value);
    data.append('db_user', document.getElementById('db_user').value);
    data.append('db_pass', document.getElementById('db_pass').value);

    fetch('install.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => {
            const el = document.getElementById('db-test-result');
            if (res.success) {
                el.innerHTML = `<div class="alert alert-success"><span class="alert-icon">✅</span><div>${res.message}</div></div>`;
                document.getElementById('btnStep2Next').style.display = 'inline-flex';
            } else {
                el.innerHTML = `<div class="alert alert-error"><span class="alert-icon">❌</span><div>${res.message}</div></div>`;
            }
        })
        .catch(() => {
            document.getElementById('db-test-result').innerHTML =
                `<div class="alert alert-error"><span class="alert-icon">❌</span><div>Request failed. Check server connectivity.</div></div>`;
        })
        .finally(() => {
            btn.innerHTML = '🔌 Test Connection';
            btn.disabled = false;
        });
}

// ─── Validate Admin Form ──────────────────────────────────────────────────────
function validateAdmin() {
    const name  = document.getElementById('admin_name').value.trim();
    const user  = document.getElementById('admin_user').value.trim();
    const email = document.getElementById('admin_email').value.trim();
    const pass  = document.getElementById('admin_pass').value;
    const pass2 = document.getElementById('admin_pass2').value;
    const alert = document.getElementById('admin-alert');

    if (!name || !user || !email || !pass) {
        alert.innerHTML = `<div class="alert alert-error"><span class="alert-icon">❌</span><div>All fields are required.</div></div>`;
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert.innerHTML = `<div class="alert alert-error"><span class="alert-icon">❌</span><div>Please enter a valid email address.</div></div>`;
        return;
    }
    if (pass.length < 8) {
        alert.innerHTML = `<div class="alert alert-error"><span class="alert-icon">❌</span><div>Password must be at least 8 characters.</div></div>`;
        return;
    }
    if (pass !== pass2) {
        alert.innerHTML = `<div class="alert alert-error"><span class="alert-icon">❌</span><div>Passwords do not match.</div></div>`;
        return;
    }

    alert.innerHTML = '';
    goStep(4);
}

// ─── Run Installation ─────────────────────────────────────────────────────────
function runInstall() {
    const log      = document.getElementById('installLog');
    const fill     = document.getElementById('progressFill');
    const pctEl    = document.getElementById('prog-pct');
    const labelEl  = document.getElementById('prog-label');

    function addLog(msg, cls = 'info') {
        const div = document.createElement('div');
        div.className = `log-entry ${cls}`;
        div.textContent = msg;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function setProgress(pct, label) {
        fill.style.width = pct + '%';
        pctEl.textContent = pct + '%';
        labelEl.textContent = label;
    }

    setProgress(10, 'Connecting to database…');

    const data = new FormData();
    data.append('action',      'install');
    data.append('db_host',     document.getElementById('db_host').value);
    data.append('db_port',     document.getElementById('db_port').value);
    data.append('db_name',     document.getElementById('db_name').value);
    data.append('db_user',     document.getElementById('db_user').value);
    data.append('db_pass',     document.getElementById('db_pass').value);
    data.append('admin_name',  document.getElementById('admin_name').value);
    data.append('admin_user',  document.getElementById('admin_user').value);
    data.append('admin_email', document.getElementById('admin_email').value);
    data.append('admin_pass',  document.getElementById('admin_pass').value);

    // Simulate progress while waiting
    const progSteps = [
        [20, 'Creating database…'],
        [40, 'Importing schema…'],
        [65, 'Setting up tables…'],
        [80, 'Configuring admin account…'],
        [90, 'Writing configuration…'],
    ];

    let pi = 0;
    const progTimer = setInterval(() => {
        if (pi < progSteps.length) {
            setProgress(progSteps[pi][0], progSteps[pi][1]);
            pi++;
        } else {
            clearInterval(progTimer);
        }
    }, 600);

    fetch('install.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => {
            clearInterval(progTimer);

            if (res.steps) {
                res.steps.forEach(s => addLog(s.msg, s.ok ? 'success' : 'error'));
            }

            if (res.success) {
                setProgress(100, 'Installation complete!');
                setTimeout(() => {
                    // Populate success screen
                    const baseUrl = window.location.href.replace(/\/install\/?.*$/, '');
                    document.getElementById('cred-url').textContent  = baseUrl + '/login.php';
                    document.getElementById('cred-user').textContent = document.getElementById('admin_user').value;
                    document.getElementById('btnGoLogin').href        = '../login.php';
                    goStep(5);
                }, 1200);
            } else {
                setProgress(0, 'Installation failed.');
                document.getElementById('install-alert').innerHTML =
                    `<div class="alert alert-error"><span class="alert-icon">❌</span><div>${res.message || 'Installation failed. Check the log above.'}</div></div>`;
            }
        })
        .catch(err => {
            clearInterval(progTimer);
            setProgress(0, 'Error occurred.');
            addLog('✘ Request failed: ' + err.message, 'error');
            document.getElementById('install-alert').innerHTML =
                `<div class="alert alert-error"><span class="alert-icon">❌</span><div>A network error occurred. Please try again.</div></div>`;
        });
}

// ─── Toggle Password Visibility ───────────────────────────────────────────────
function togglePass(id, btn) {
    const input = document.getElementById(id);
    input.type  = input.type === 'password' ? 'text' : 'password';
    btn.style.color = input.type === 'text' ? 'var(--accent)' : 'var(--text-muted)';
}
</script>

</body>
</html>
