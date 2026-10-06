<?php
require_once 'config.php';

if (user()) {
    header('Location: index.php');
    exit;
}

$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $loginInput    = trim($_POST['login'] ?? '');
    $passwordInput = $_POST['password'] ?? '';

    try {
        $q = db()->prepare('SELECT u.id, u.full_name, u.username, u.password_hash, u.status, r.name role
                            FROM users u
                            JOIN roles r ON r.id = u.role_id
                            WHERE u.username = ? OR u.email = ?
                            LIMIT 1');
        $q->execute([$loginInput, $loginInput]);
        $u = $q->fetch();

        if (!$u) {
            $error = 'Invalid username or password.';
        } elseif ($u['status'] !== 'Active') {
            $error = 'This account is currently inactive. Please contact the administrator.';
        } elseif (password_verify($passwordInput, $u['password_hash']) || $passwordInput === $u['password_hash']) {
            unset($u['password_hash']);
            $_SESSION['user'] = $u;
            log_activity('Logged in');

            if (!empty($_POST['remember'])) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + 30 * 24 * 60 * 60,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ]);
            }

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } catch (PDOException $e) {
        $error = 'Database error. Please check database connection.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0C1B1F">
    <meta name="description" content="Bayawan City ICT Field Support Dispatch System — Authorized Staff Sign-in">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/svg+xml" href="assets/netsupport-mark.svg?v=<?= (int) filemtime(__DIR__ . '/assets/netsupport-mark.svg') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>Sign in · NetSupport</title>
    <script>
        // Apply theme immediately to prevent flash
        (function() {
            var saved = localStorage.getItem('netsupport-theme');
            if (saved) {
                document.documentElement.setAttribute('data-theme', saved);
            } else {
                var isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
            }
        })();
    </script>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            /* Light theme (default) - Soft clean ambient gradient, no box pattern */
            --bg-page:        radial-gradient(ellipse at 50% 10%, #d8eae3 0%, #e8f1ed 45%, #eff5f2 100%);
            --ambient-glow:   rgba(14, 124, 102, 0.08);
            --card-bg:        #ffffff;
            --card-border:    #d0ded8;
            --card-shadow:    0 24px 60px -15px rgba(12, 27, 31, 0.14), 0 0 0 1px rgba(0, 0, 0, 0.03);
            --text-heading:   #0c1b1f;
            --text-main:      #2d3f44;
            --text-muted:     #5a7076;
            --text-sub:       #7d9399;
            --input-bg:       #f8fafb;
            --input-border:   #c2d4ce;
            --input-color:    #0c1b1f;
            --input-placeholder: #7d9399;
            --signal:         #0E7C66;
            --signal-deep:    #0A5F4E;
            --signal-light:   #059669;
            --signal-ring:    rgba(14, 124, 102, 0.18);
            --toggle-bg:      #ffffff;
            --toggle-border:  #d0ded8;
            --toggle-color:   #5a7076;
            --toggle-hover:   #0c1b1f;
            --badge-bg:       #e8f5f1;
            --badge-border:   #c2e7dc;
            --badge-text:     #0A5F4E;
            --error-bg:       #fef2f2;
            --error-border:   #fecaca;
            --error-text:     #b91c1c;
            --footer-text:    #5a7076;
            --font: 'Archivo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        /* System Dark Theme (auto-applied when no manual light override) */
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg-page:        radial-gradient(ellipse at 50% 12%, #103429 0%, #0b1c18 45%, #06110e 100%);
                --ambient-glow:   rgba(16, 185, 129, 0.15);
                --card-bg:        #122226;
                --card-border:    #1c353b;
                --card-shadow:    0 28px 75px -15px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.06);
                --text-heading:   #f1f5f9;
                --text-main:      #cbd5e1;
                --text-muted:     #8ca3a8;
                --text-sub:       #647b80;
                --input-bg:       #0c1b1f;
                --input-border:   #233f46;
                --input-color:    #f8fafc;
                --input-placeholder: #647b80;
                --signal:         #10b981;
                --signal-deep:    #059669;
                --signal-light:   #34d399;
                --signal-ring:    rgba(16, 185, 129, 0.22);
                --toggle-bg:      #162a2f;
                --toggle-border:  #25454d;
                --toggle-color:   #8ca3a8;
                --toggle-hover:   #f1f5f9;
                --badge-bg:       rgba(16, 185, 129, 0.1);
                --badge-border:   rgba(16, 185, 129, 0.22);
                --badge-text:     #34d399;
                --error-bg:       rgba(239, 68, 68, 0.12);
                --error-border:   rgba(239, 68, 68, 0.3);
                --error-text:     #f87171;
                --footer-text:    rgba(255, 255, 255, 0.45);
            }
        }

        /* Explicit Dark Theme */
        [data-theme="dark"] {
            --bg-page:        radial-gradient(ellipse at 50% 12%, #103429 0%, #0b1c18 45%, #06110e 100%);
            --ambient-glow:   rgba(16, 185, 129, 0.15);
            --card-bg:        #122226;
            --card-border:    #1c353b;
            --card-shadow:    0 28px 75px -15px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.06);
            --text-heading:   #f1f5f9;
            --text-main:      #cbd5e1;
            --text-muted:     #8ca3a8;
            --text-sub:       #647b80;
            --input-bg:       #0c1b1f;
            --input-border:   #233f46;
            --input-color:    #f8fafc;
            --input-placeholder: #647b80;
            --signal:         #10b981;
            --signal-deep:    #059669;
            --signal-light:   #34d399;
            --signal-ring:    rgba(16, 185, 129, 0.22);
            --toggle-bg:      #162a2f;
            --toggle-border:  #25454d;
            --toggle-color:   #8ca3a8;
            --toggle-hover:   #f1f5f9;
            --badge-bg:       rgba(16, 185, 129, 0.1);
            --badge-border:   rgba(16, 185, 129, 0.22);
            --badge-text:     #34d399;
            --error-bg:       rgba(239, 68, 68, 0.12);
            --error-border:   rgba(239, 68, 68, 0.3);
            --error-text:     #f87171;
            --footer-text:    rgba(255, 255, 255, 0.45);
        }

        /* Explicit Light Theme */
        [data-theme="light"] {
            --bg-page:        radial-gradient(ellipse at 50% 10%, #d8eae3 0%, #e8f1ed 45%, #eff5f2 100%);
            --ambient-glow:   rgba(14, 124, 102, 0.08);
            --card-bg:        #ffffff;
            --card-border:    #d0ded8;
            --card-shadow:    0 24px 60px -15px rgba(12, 27, 31, 0.14), 0 0 0 1px rgba(0, 0, 0, 0.03);
            --text-heading:   #0c1b1f;
            --text-main:      #2d3f44;
            --text-muted:     #5a7076;
            --text-sub:       #7d9399;
            --input-bg:       #f8fafb;
            --input-border:   #c2d4ce;
            --input-color:    #0c1b1f;
            --input-placeholder: #7d9399;
            --signal:         #0E7C66;
            --signal-deep:    #0A5F4E;
            --signal-light:   #059669;
            --signal-ring:    rgba(14, 124, 102, 0.18);
            --toggle-bg:      #ffffff;
            --toggle-border:  #d0ded8;
            --toggle-color:   #5a7076;
            --toggle-hover:   #0c1b1f;
            --badge-bg:       #e8f5f1;
            --badge-border:   #c2e7dc;
            --badge-text:     #0A5F4E;
            --error-bg:       #fef2f2;
            --error-border:   #fecaca;
            --error-text:     #b91c1c;
            --footer-text:    #5a7076;
        }

        html, body {
            min-height: 100vh;
            margin: 0;
            font-family: var(--font);
            -webkit-font-smoothing: antialiased;
        }

        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--bg-page);
            background-attachment: fixed;
            padding: 1.5rem 1rem;
            position: relative;
            color: var(--text-main);
            overflow-x: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* Ambient glowing halo behind card — clean & smooth without any box/grid lines */
        body::before {
            content: '';
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 700px;
            height: 700px;
            background: radial-gradient(circle, var(--ambient-glow) 0%, transparent 65%);
            pointer-events: none;
            z-index: 1;
        }

        /* Floating Theme Toggle */
        .theme-toggle-wrapper {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            z-index: 50;
        }

        .theme-toggle-btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--toggle-bg);
            border: 1px solid var(--toggle-border);
            color: var(--toggle-color);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            outline: none;
            transition: all 0.2s ease;
        }

        .theme-toggle-btn:hover {
            color: var(--toggle-hover);
            transform: scale(1.05);
            border-color: var(--signal);
        }

        /* Single Login Card */
        .login-card {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 22px;
            padding: 2.5rem 2.25rem 2rem;
            box-shadow: var(--card-shadow);
            animation: cardIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
            transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Card Header */
        .card-header-brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .brand-logo-mark {
            width: 52px;
            height: 52px;
            display: block;
            object-fit: contain;
            margin: 0 auto 0.85rem;
        }

        .brand-title {
            font-size: 1.55rem;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.025em;
            margin: 0 0 0.25rem;
            line-height: 1.2;
        }

        .brand-title span {
            color: var(--signal-light);
        }

        .brand-subtitle {
            font-size: 0.8125rem;
            color: var(--text-muted);
            margin: 0;
            font-weight: 500;
        }

        /* Greeting */
        .welcome-title {
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--text-heading);
            text-align: center;
            margin: 0.25rem 0 0.2rem;
        }

        .welcome-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-align: center;
            margin: 0 0 1.5rem;
        }

        /* Alert */
        .alert-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            border-radius: 12px;
            padding: 0.65rem 0.9rem;
            font-size: 0.82rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 1.25rem;
        }

        /* Form Controls */
        .form-group-item {
            margin-bottom: 1.25rem;
        }

        .form-group-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 0.45rem;
            letter-spacing: 0.02em;
        }

        .input-box-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-box-wrapper .field-icon {
            position: absolute;
            left: 14px;
            color: var(--input-placeholder);
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-box-wrapper:focus-within .field-icon {
            color: var(--signal);
        }

        .form-field-input {
            width: 100%;
            height: 46px;
            padding-left: 42px;
            padding-right: 42px;
            border-radius: 14px;
            border: 1.5px solid var(--input-border);
            font-size: 0.93rem;
            color: var(--input-color);
            background: var(--input-bg);
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }

        .form-field-input::placeholder {
            color: var(--input-placeholder);
            opacity: 1;
        }

        .form-field-input:focus {
            background: var(--card-bg);
            border-color: var(--signal);
            box-shadow: 0 0 0 3.5px var(--signal-ring);
        }

        .btn-toggle-pw {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: var(--input-placeholder);
            font-size: 1.05rem;
            padding: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            transition: color 0.15s;
        }

        .btn-toggle-pw:hover {
            color: var(--text-heading);
        }

        /* Extras Row */
        .row-extras {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: -0.15rem;
            margin-bottom: 1.4rem;
            font-size: 0.8125rem;
        }

        .check-remember {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        .check-remember input[type="checkbox"] {
            accent-color: var(--signal);
            width: 15px;
            height: 15px;
            cursor: pointer;
        }

        .link-forgot {
            font-size: 0.8125rem;
            color: var(--signal);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .link-forgot:hover {
            text-decoration: underline;
        }

        /* Submit Button */
        .btn-signin-submit {
            width: 100%;
            height: 46px;
            border-radius: 14px;
            background: var(--signal);
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 6px 20px rgba(14, 124, 102, 0.28);
            transition: all 0.2s;
            cursor: pointer;
        }

        .btn-signin-submit:hover {
            background: var(--signal-deep);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(14, 124, 102, 0.38);
            color: #ffffff;
        }

        .btn-signin-submit:active {
            transform: translateY(0);
        }

        /* Divider & Badge */
        .card-divider-wrap {
            border-top: 1px solid var(--input-border);
            margin-top: 1.5rem;
            padding-top: 1.15rem;
            text-align: center;
        }

        .badge-access {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.74rem;
            font-weight: 600;
            color: var(--badge-text);
            background: var(--badge-bg);
            border: 1px solid var(--badge-border);
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
        }

        /* Footer below card */
        .page-footer-note {
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.74rem;
            color: var(--footer-text);
            position: relative;
            z-index: 10;
        }

    </style>
</head>
<body>

    <!-- Floating Theme Toggle -->
    <div class="theme-toggle-wrapper">
        <button class="theme-toggle-btn" id="themeToggle" type="button" title="Toggle Light / Dark theme">
            <i class="bi bi-moon-fill" id="themeIcon"></i>
        </button>
    </div>

    <!-- The 1 Simple Login Card -->
    <div class="login-card">

        <!-- Header: NetSupport branding -->
        <div class="card-header-brand">
            <img class="brand-logo-mark" src="assets/netsupport-mark.svg" alt="">
            <h1 class="brand-title">Net<span>Support</span></h1>
            <p class="brand-subtitle">NMS · Field Support Scheduling System</p>
        </div>
        <?php if ($error): ?>
            <div class="alert-error" role="alert">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                <span><?= h($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" novalidate autocomplete="on">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">

            <!-- Username / Email -->
            <div class="form-group-item">
                <label class="form-group-label" for="loginInput">Username or Email</label>
                <div class="input-box-wrapper">
                    <i class="bi bi-person field-icon"></i>
                    <input class="form-field-input" id="loginInput" name="login" required autofocus
                           autocomplete="username" autocapitalize="none" spellcheck="false"
                           placeholder="Enter your username or email">
                </div>
            </div>

            <!-- Password -->
            <div class="form-group-item">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-group-label mb-0" for="passwordInput">Password</label>
                    <a href="javascript:void(0)" class="link-forgot" data-bs-toggle="modal" data-bs-target="#forgotModal">Forgot password?</a>
                </div>
                <div class="input-box-wrapper">
                    <i class="bi bi-lock field-icon"></i>
                    <input class="form-field-input" id="passwordInput" type="password" name="password"
                           required autocomplete="current-password" placeholder="Enter your password">
                    <button class="btn-toggle-pw" type="button" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Remember me -->
            <div class="row-extras">
                <label class="check-remember" for="rememberMe">
                    <input type="checkbox" name="remember" value="1" id="rememberMe">
                    <span>Remember me on this device</span>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn-signin-submit">
                <i class="bi bi-box-arrow-in-right"></i>
                <span>Sign in</span>
            </button>
        </form>



        <div class="card-divider-wrap">
            <span class="badge-access">
                <i class="bi bi-shield-lock-fill"></i>
                Authorized ICT Staff Access Only
            </span>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotModal" tabindex="-1" aria-labelledby="forgotModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 18px; overflow: hidden; background: var(--card-bg); color: var(--text-main);">
                <div style="background: linear-gradient(135deg, var(--signal) 0%, var(--signal-deep) 100%); padding: 1.5rem 1.5rem 1.25rem; text-align: center;">
                    <div style="width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.15);display:inline-flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;margin-bottom:.75rem;">
                        <i class="bi bi-telephone-fill"></i>
                    </div>
                    <h5 id="forgotModalLabel" style="color:#fff;font-weight:800;margin:0;font-size:1.1rem;">Need help accessing your account?</h5>
                </div>
                <div class="modal-body" style="padding: 1.5rem; text-align: center;">
                    <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.25rem;line-height:1.6;">
                        Please contact our <strong style="color:var(--text-heading);">CCS Customer Care Services</strong> for assistance with your account credentials.
                    </p>
                    <div style="display:inline-flex;align-items:center;gap:.5rem;background:var(--badge-bg);border:1.5px solid var(--badge-border);border-radius:12px;padding:.75rem 1.5rem;">
                        <i class="bi bi-telephone-fill" style="color:var(--signal);font-size:1.1rem;"></i>
                        <span style="font-size:1.25rem;font-weight:800;color:var(--signal);letter-spacing:.02em;">Dial 248</span>
                    </div>
                    <p style="color:var(--text-sub);font-size:.75rem;margin-top:1rem;margin-bottom:0;">CCS Customer Care Services &bull; Bayawan City Government</p>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--card-border);padding:.875rem 1.5rem;justify-content:center;">
                    <button type="button" class="btn btn-sm" data-bs-dismiss="modal"
                            style="border-radius:10px;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-main);font-weight:600;padding:.4rem 1.5rem;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="page-footer-note">
        Bayawan City Government &bull; BITS &bull; NMS Support Portal
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Theme Management
        var themeToggle = document.getElementById('themeToggle');
        var themeIcon   = document.getElementById('themeIcon');

        function updateIcon(theme) {
            if (themeIcon) {
                themeIcon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
            }
        }

        function getActiveTheme() {
            var explicit = document.documentElement.getAttribute('data-theme');
            if (explicit) return explicit;
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        function setTheme(theme, save) {
            document.documentElement.setAttribute('data-theme', theme);
            if (save) {
                localStorage.setItem('netsupport-theme', theme);
            }
            updateIcon(theme);
        }

        updateIcon(getActiveTheme());

        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                var current = getActiveTheme();
                var next = current === 'dark' ? 'light' : 'dark';
                setTheme(next, true);
            });
        }

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
            if (!localStorage.getItem('netsupport-theme')) {
                setTheme(e.matches ? 'dark' : 'light', false);
            }
        });

        // Password toggle
        var toggleBtn = document.getElementById('togglePassword');
        var pwInput   = document.getElementById('passwordInput');
        if (toggleBtn && pwInput) {
            toggleBtn.addEventListener('click', function () {
                var isPw = pwInput.type === 'password';
                pwInput.type = isPw ? 'text' : 'password';
                var icon = toggleBtn.querySelector('i');
                if (icon) icon.className = isPw ? 'bi bi-eye-slash' : 'bi bi-eye';
            });
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
