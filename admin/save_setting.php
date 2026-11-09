<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/* =========================
   AUTH + ROLE
========================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$currentRole = str_replace('_', ' ', $currentRole);

if ($currentRole !== 'super admin') {
    http_response_code(403);
    exit('Access Denied — Super Admin only.');
}

/* =========================
   METHOD + CSRF
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$sentCsrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentCsrf)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

/* =========================
   WHITELIST SETTINGS KEYS
========================= */
$textKeys = [
    'system_name', 'organization', 'academic_year', 'semester',
    'maintenance_message',
    'smtp_host', 'smtp_port', 'smtp_email', 'smtp_sender',
];
$intKeys = [
    'session_timeout' => [1, 1440],
    'password_length' => [6, 64],
    'login_attempts'  => [1, 20],
];
$boolKeys = [
    'allow_registration', 'email_verification', 'maintenance_mode',
];

/* =========================
   BUILD SETTINGS ARRAY
========================= */
$new = [];

foreach ($textKeys as $k) {
    if (array_key_exists($k, $_POST)) {
        $new[$k] = trim((string)$_POST[$k]);
    }
}

foreach ($intKeys as $k => [$min, $max]) {
    if (array_key_exists($k, $_POST)) {
        $v = filter_var($_POST[$k], FILTER_VALIDATE_INT);
        if ($v !== false) {
            $new[$k] = (string)max($min, min($max, (int)$v));
        }
    }
}

foreach ($boolKeys as $k) {
    $new[$k] = isset($_POST[$k]) ? '1' : '0';
}

/* SMTP password only if user typed something */
if (!empty($_POST['smtp_password'])) {
    $new['smtp_password'] = (string)$_POST['smtp_password']; // encrypt at rest if possible
}

/* =========================
   UPSERT
========================= */
try {
    $pdo = db();
    $pdo->beginTransaction();

    $sql = "
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ";
    $stmt = $pdo->prepare($sql);

    foreach ($new as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    /* Audit log */
    try {
        $pdo->prepare("
            INSERT INTO audit_trail (user_id, action, description, created_at)
            VALUES (?, 'update', 'Updated system settings', NOW())
        ")->execute([(int)$_SESSION['user_id']]);
    } catch (Throwable $auditErr) {
        error_log('save_settings audit: ' . $auditErr->getMessage());
    }

    $pdo->commit();

    $_SESSION['settings_flash'] = [
        'type'    => 'success',
        'message' => 'Settings saved successfully.',
    ];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('save_settings.php: ' . $e->getMessage());
    $_SESSION['settings_flash'] = [
        'type'    => 'error',
        'message' => 'Failed to save settings. Please try again.',
    ];
}

header('Location: dashboard.php?page=settings');
exit;