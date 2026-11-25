<?php
/**
 * config/bootstrap.php
 * Include this BEFORE session_start() in every entry point.
 */

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Regenerate session id periodically for admin sessions.
if (!empty($_SESSION['_admin_portal'])) {
    if (!isset($_SESSION['_last_regen'])) {
        $_SESSION['_last_regen'] = time();
    } elseif (time() - $_SESSION['_last_regen'] > 1800) { // 30 min
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }

    // Idle timeout: 30 minutes
    $idleLimit = 1800;
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $idleLimit) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['_last_activity'] = time();
}