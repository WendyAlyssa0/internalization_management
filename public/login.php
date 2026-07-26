<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// --- CSRF token setup -------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Basic brute-force throttling (per-session) ------------------------
const MAX_ATTEMPTS   = 5;
const LOCKOUT_SECONDS = 60;

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_locked_until'] = 0;
}

$error    = '';
$username = $_COOKIE['remember_email'] ?? '';
$remember = false;

function json_response(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        json_response([
            'success' => false,
            'error'   => 'Your session has expired. Please refresh the page and try again.'
        ]);
    }

    // Lockout check
    if ($_SESSION['login_locked_until'] > time()) {
        $wait = $_SESSION['login_locked_until'] - time();
        json_response([
            'success' => false,
            'error'   => "Too many failed attempts. Try again in {$wait}s."
        ]);
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if ($username === '' || $password === '') {

        json_response([
            'success' => false,
            'error'   => 'All fields are required.'
        ]);

    } elseif (!filter_var($username, FILTER_VALIDATE_EMAIL) || strlen($username) > 254) {

        json_response([
            'success' => false,
            'error'   => 'Enter a valid email address.'
        ]);

    } elseif (strlen($password) > 256) {

        json_response([
            'success' => false,
            'error'   => 'Invalid email or password.'
        ]);

    } else {

        $pdo = db();

        $stmt = $pdo->prepare("
            SELECT
                id,
                first_name,
                email,
                user_role,
                password_hash
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([':email' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {

            // Successful login — reset throttling
            $_SESSION['login_attempts']     = 0;
            $_SESSION['login_locked_until'] = 0;

            session_regenerate_id(true);

            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['email'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['user_role']  = $user['user_role'];

            // Re-issue CSRF token after privilege change
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            if ($remember) {
                setcookie('remember_email', $username, [
                    'expires'  => time() + (60 * 60 * 24 * 30),
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                setcookie('remember_email', '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            $role = strtolower(trim($user['user_role'] ?? ''));
            if ($role === '') {
                $role = 'applicant';
            }

            if (in_array($role, ['admin', 'ad', 'super admin', 'sa'], true)) {

                json_response([
                    'success'  => true,
                    'redirect' => '../admin/dashboard.php'
                ]);

            } elseif (in_array($role, ['user', 'applicant'], true)) {

                json_response([
                    'success'  => true,
                    'redirect' => '../user/home.php'
                ]);

            } else {

                json_response([
                    'success' => false,
                    'error'   => 'Invalid account role.'
                ]);
            }

        } else {

            // Failed login — count toward lockout
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                $_SESSION['login_locked_until'] = time() + LOCKOUT_SECONDS;
                $_SESSION['login_attempts'] = 0;
            }

            json_response([
                'success' => false,
                'error'   => 'Invalid email or password.'
            ]);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PSU Internalization</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/login.css">
</head>
<body>

<div class="login-card">

    <!-- LEFT PANEL -->
    <div class="login-left">

        <div class="top-line"></div>

        <!-- Dotted world-map backdrop -->
        <div class="world-map" aria-hidden="true"></div>

        <!-- Signature element: a partnership flight-path linking the -->
        <!-- university to its international nodes -->
        <svg class="flight-path" viewBox="0 0 480 380" preserveAspectRatio="none" aria-hidden="true">
            <path id="routeLine" class="route-line" d="M 40 300 Q 180 60 260 140 T 440 60" />
            <circle class="route-node" cx="40" cy="300" r="4" />
            <circle class="route-node" cx="260" cy="140" r="4" />
            <circle class="route-node route-node--home" cx="440" cy="60" r="5" />
        </svg>

        <div class="left-content">
            <div class="left-logo">
                <img src="../asset/img/psu_logo.png" alt="PSU Logo">
            </div>
            <div class="left-eyebrow">Pangasinan State University</div>
            <div class="left-title"><span class="accent">PSU</span><br>Internalization</div>
            <div class="left-underline"></div>
            <div class="left-sub">Connecting the university to its global partners &mdash; one linkage at a time.</div>
        </div>

        <div class="left-footer">
            <div class="left-dots">
                <div class="dot active"></div>
                <div class="dot"></div>
                <div class="dot"></div>
            </div>
            <div class="left-tagline">Admin Portal &nbsp;·&nbsp; Secure Access</div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="login-right">
        <div class="form-header">
            <div class="form-title">Welcome back</div>
            <div class="form-subtitle">Sign in to your administrator account</div>
        </div>

        <div id="loginError" class="error" style="display: none;"></div>

        <form id="loginForm" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="field">
                <label>Email Address</label>
                <div class="field-wrap">
                    <svg class="icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 6.5 8 6 8-6"/></svg>
                    <input type="email" name="username"
                        placeholder="AD@psuizn.com"
                        value="<?= htmlspecialchars($username) ?>"
                        maxlength="254"
                        required autocomplete="email" autofocus>
                </div>
            </div>

            <div class="field">
                <label>Password</label>
                <div class="field-wrap">
                    <svg class="icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input type="password" id="passwordInput" name="password"
                        placeholder="Enter your password"
                        maxlength="256"
                        required autocomplete="current-password">
                    <button type="button" class="toggle-password" aria-label="Show password" aria-pressed="false">
                        <svg class="icon-svg eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-svg eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 10.6a3 3 0 0 0 4.24 4.24"/><path d="M9.9 4.24A11 11 0 0 1 12 4c7 0 11 8 11 8a17.9 17.9 0 0 1-3.09 4.19M6.1 6.1A17.7 17.7 0 0 0 1 12s4 8 11 8a10.6 10.6 0 0 0 5-1.24"/></svg>
                    </button>
                </div>
            </div>

            <div class="options">
                <label>
                    <input type="checkbox" name="remember" <?= $remember ? 'checked' : '' ?>>
                    Remember me
                </label>
                <a href="forgot_pass.php" target="_blank" rel="noopener">Forgot password?</a>
            </div>

            <button type="submit" class="login-btn">
                <span class="btn-label">Sign In</span>
                <svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>
            </button>
        </form>

        <div class="form-footer">
            &copy; <?= date('Y') ?> Pangasinan State University &nbsp;·&nbsp; PSU Internalization
        </div>
    </div>

</div>

<!-- LOADING OVERLAY -->
<div id="loadingOverlay">
    <div class="loader-content">
        <div class="globe-loader">
            <svg viewBox="0 0 100 100" class="globe-svg" overflow="visible">
                <defs>
                    <clipPath id="globeClip">
                        <circle cx="50" cy="50" r="46"/>
                    </clipPath>
                    <path id="planeArc" d="M -6 30 A 60 60 0 0 1 106 30" />
                </defs>
                <circle cx="50" cy="50" r="46" class="globe-outline"/>
                <rect x="0" y="100" width="100" height="100" class="globe-fill" clip-path="url(#globeClip)"/>
                <g class="globe-lines" clip-path="url(#globeClip)">
                    <ellipse cx="50" cy="50" rx="20" ry="46" />
                    <ellipse cx="50" cy="50" rx="40" ry="46" />
                    <line x1="4" y1="50" x2="96" y2="50" />
                    <line x1="8" y1="30" x2="92" y2="30" />
                    <line x1="8" y1="70" x2="92" y2="70" />
                </g>
                <use href="#planeArc" class="plane-arc-guide" />
                <g class="plane-icon">
                    <animateMotion dur="1.1s" begin="0.1s" fill="freeze" rotate="auto" path="M -6 30 A 60 60 0 0 1 106 30" />
                    <path d="M0 0 L7 2 L0 4 L1.5 2 Z" />
                </g>
            </svg>
        </div>

        <h2>Signing you in...</h2>

        <div class="progress-bar">
            <div class="progress"></div>
        </div>
    </div>
</div>

</body>

<script>
const form = document.getElementById("loginForm");
const loadingOverlay = document.getElementById("loadingOverlay");
const loginError = document.getElementById("loginError");
const button = form.querySelector(".login-btn");

const ARROW_SVG = '<svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>';

function setButtonIdle() {
    button.disabled = false;
    button.innerHTML = '<span class="btn-label">Sign In</span>' + ARROW_SVG;
}

// Password show/hide toggle
const toggleBtn = document.querySelector(".toggle-password");
const passwordInput = document.getElementById("passwordInput");

toggleBtn.addEventListener("click", function () {
    const showing = toggleBtn.classList.toggle("is-visible");
    passwordInput.type = showing ? "text" : "password";
    toggleBtn.setAttribute("aria-pressed", String(showing));
    toggleBtn.setAttribute("aria-label", showing ? "Hide password" : "Show password");
});

form.addEventListener("submit", function (e) {
    e.preventDefault();

    loginError.style.display = "none";
    loginError.textContent = "";

    button.disabled = true;
    button.innerHTML = '<span class="btn-label">Signing In...</span>';

    fetch("", {
        method: "POST",
        body: new FormData(form)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadingOverlay.classList.add("show");

            // Short, honest loading animation — matches the globe
            // fill + plane arc (~1.1s), then redirect.
            setTimeout(() => {
                window.location.href = data.redirect;
            }, 1300);

        } else {
            setButtonIdle();
            loginError.textContent = data.error;
            loginError.style.display = "block";
        }
    })
    .catch(() => {
        setButtonIdle();
        loginError.textContent = "Unable to contact the server.";
        loginError.style.display = "block";
    });
});
</script>
</html>