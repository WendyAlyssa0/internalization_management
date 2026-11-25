<?php
/**
 * auth/login.php
 * Admin portal login (controller + view).
 */

require_once __DIR__ . '/../config/bootstrap.php';   // hardened session
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

// --- Config -----------------------------------------------------------
const MAX_ATTEMPTS    = 5;
const LOCKOUT_SECONDS = 60;

const REDIRECT_AFTER_LOGIN = '/admin/dashboard.php';

// --- CSRF token -------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Attempt tracking -------------------------------------------------
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts']     = 0;
    $_SESSION['login_locked_until'] = 0;
}

$error    = '';
$username = $_COOKIE['remember_email'] ?? '';
$remember = $username !== '';   // FIX: reflect cookie on GET

// --- Helpers ----------------------------------------------------------
function json_response(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Count a failed attempt and return the generic error.
 * Does NOT reset the counter when a lockout is set (FIX).
 */
function fail_login(string $message = 'Invalid email or password.'): void
{
    $_SESSION['login_attempts']++;

    if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
        $_SESSION['login_locked_until'] = time() + LOCKOUT_SECONDS;
        // Intentionally do not reset login_attempts here.
    }

    json_response(['success' => false, 'error' => $message]);
}

// --- Handle POST ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        json_response([
            'success' => false,
            'error'   => 'Your session has expired. Please refresh the page and try again.',
        ]);
    }

    // 2. Lockout
    if ($_SESSION['login_locked_until'] > time()) {
        $wait = $_SESSION['login_locked_until'] - time();
        json_response([
            'success' => false,
            'error'   => "Too many failed attempts. Try again in {$wait}s.",
        ]);
    }

    // 3. Inputs
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if ($username === '' || $password === '') {
        json_response(['success' => false, 'error' => 'All fields are required.']);
    }

    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || strlen($username) > 254) {
        json_response(['success' => false, 'error' => 'Enter a valid email address.']);
    }

    // Password length guard counts as a failed attempt.
    if (strlen($password) > 256) {
        fail_login();
    }

    // 4. Lookup
    try {
        $pdo = db();

        $stmt = $pdo->prepare("
            SELECT id, first_name, email, user_role, password_hash
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Login DB error: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error'   => 'Unable to sign in right now. Please try again later.',
        ]);
    }

    // 5. Verify
    if ($user && password_verify($password, $user['password_hash'])) {

        if (!is_admin_portal_role($user['user_role'])) {
            // Do NOT create a session. Use the failed-attempt counter.
            fail_login();
        }

        // --- Success -------------------------------------------------
        $_SESSION['login_attempts']     = 0;
        $_SESSION['login_locked_until'] = 0;

        session_regenerate_id(true);

        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['username']      = $user['email'];
        $_SESSION['first_name']    = $user['first_name'];
        $_SESSION['user_role']     = $user['user_role'];
        $_SESSION['_admin_portal'] = true;
        $_SESSION['_last_activity'] = time();

        // Rotate CSRF after privilege change.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        if ($remember) {
            setcookie('remember_email', $username, [
                'expires'  => time() + (60 * 60 * 24 * 30),
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie('remember_email', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        json_response([
            'success'  => true,
            'redirect' => REDIRECT_AFTER_LOGIN,
        ]);
    }

    // 6. Bad credentials
    fail_login();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PSU Internationalization — Admin Sign In</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/login.css">
</head>
<body>

<div class="login-card">

    <!-- LEFT PANEL -->
    <div class="login-left">
        <div class="top-line"></div>
        <div class="world-map" aria-hidden="true"></div>

        <svg class="flight-path" viewBox="0 0 480 380" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <path id="routeLine" class="route-line" d="M 40 300 Q 180 60 260 140 T 440 60" />
            <circle class="route-node" cx="40" cy="300" r="4" />
            <circle class="route-node" cx="260" cy="140" r="4" />
            <circle class="route-node route-node--home" cx="440" cy="60" r="5" />
        </svg>

        <div class="left-content">
            <div class="left-eyebrow">Pangasinan State University</div>
            <div class="left-title"><span class="accent">PSU</span><br>Internationalization</div>
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

        <div id="loginError" class="error" style="display: none;" role="alert"></div>

        <form id="loginForm" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="field">
                <label for="usernameInput">Email Address</label>
                <div class="field-wrap">
                    <svg class="icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 6.5 8 6 8-6"/></svg>
                    <input type="email" id="usernameInput" name="username"
                        placeholder="admin@psu.edu.ph"
                        value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                        maxlength="254"
                        required autocomplete="email" autofocus>
                </div>
            </div>

            <div class="field">
                <label for="passwordInput">Password</label>
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
                <a href="forgot_pass.php" rel="noopener">Forgot password?</a>
            </div>

            <button type="submit" class="login-btn">
                <span class="btn-label">Sign In</span>
                <svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>
            </button>
        </form>

        <div class="form-footer">
            &copy; <?= date('Y') ?> Pangasinan State University &nbsp;·&nbsp; PSU Internationalization
        </div>
    </div>

</div>

<!-- LOADING OVERLAY -->
<div id="loadingOverlay">
    <div class="loader-content">
        <div class="globe-loader">
            <svg viewBox="0 0 100 100" class="globe-svg" overflow="visible">
                <defs>
                    <clipPath id="globeClip"><circle cx="50" cy="50" r="46"/></clipPath>
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

        <div class="progress-bar"><div class="progress"></div></div>
    </div>
</div>

<script>
(function () {
    "use strict";

    const REDIRECT_DELAY_MS = 1300;

    const form           = document.getElementById("loginForm");
    const loadingOverlay = document.getElementById("loadingOverlay");
    const loginError     = document.getElementById("loginError");
    const button         = form.querySelector(".login-btn");
    const toggleBtn      = document.querySelector(".toggle-password");
    const passwordInput  = document.getElementById("passwordInput");

    const ARROW_SVG =
        '<svg class="icon-svg btn-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<line x1="3" y1="12" x2="21" y2="12"/><polyline points="14 5 21 12 14 19"/></svg>';

    function setButtonIdle() {
        button.disabled = false;
        button.innerHTML = '<span class="btn-label">Sign In</span>' + ARROW_SVG;
    }

    function showError(msg) {
        loginError.textContent = msg || "Something went wrong.";
        loginError.style.display = "flex";
    }

    function clearError() {
        loginError.style.display = "none";
        loginError.textContent = "";
    }

    // Password visibility toggle
    toggleBtn.addEventListener("click", function () {
        const showing = toggleBtn.classList.toggle("is-visible");
        passwordInput.type = showing ? "text" : "password";
        toggleBtn.setAttribute("aria-pressed", String(showing));
        toggleBtn.setAttribute("aria-label", showing ? "Hide password" : "Show password");
    });

    // Submit
    form.addEventListener("submit", function (e) {
        e.preventDefault();
        clearError();

        button.disabled = true;
        button.innerHTML = '<span class="btn-label">Signing In...</span>';

        fetch("", { method: "POST", body: new FormData(form) })
            .then(function (res) {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(function (data) {
                if (data.success) {
                    loadingOverlay.classList.add("show");
                    setTimeout(function () {
                        window.location.href = data.redirect;
                    }, REDIRECT_DELAY_MS);
                } else {
                    setButtonIdle();
                    showError(data.error);
                }
            })
            .catch(function () {
                setButtonIdle();
                showError("Unable to contact the server. Please try again.");
            });
    });
})();
</script>
</body>
</html>