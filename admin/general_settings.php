<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/auth_middleware.php';
requireAdmin();

/* =========================
   ROLE CHECK — normalize both spellings
========================= */
$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$currentRole = str_replace('_', ' ', $currentRole);

if ($currentRole !== 'super admin') {
    http_response_code(403);
    echo '<p style="padding:24px;font-family:sans-serif;">Access Denied — Super Admin only.</p>';
    exit;
}

/* =========================
   CSRF
========================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* =========================
   HELPERS
========================= */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================
   LOAD CURRENT SETTINGS
   settings table: key (PK) | value (text)
   If your table is different, adjust the SELECT + loop.
========================= */
$settings = [];

try {
    $pdo = db();
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $e) {
    error_log('general_settings load: ' . $e->getMessage());
}

/* Fallback to defaults if a key is missing */
function setting(array $settings, string $key, string $default = ''): string {
    return $settings[$key] ?? $default;
}
function settingBool(array $settings, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $settings)) return $default;
    return in_array(strtolower($settings[$key]), ['1', 'true', 'yes', 'on'], true);
}

/* Flash message from save_settings.php (optional) */
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

/* Save the URL to POST back to — same page, handled by this file's POST block */
$saveUrl = 'dashboard.php?page=settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../asset/css/general_settings.css">
    <link rel="stylesheet" href="../asset/css/dashboard.css">
</head>
<body>

<div class="page-header">
    <h2>General Settings</h2>
    <p>Configure system-wide settings for the Internalization Management System.</p>
</div>

<?php if ($flash): ?>
    <div class="flash flash--<?= h($flash['type'] ?? 'info') ?>" style="margin:16px 0;padding:12px 16px;border-radius:8px;background:<?= ($flash['type'] ?? '') === 'error' ? '#fef2f2' : '#eff6ff' ?>;color:<?= ($flash['type'] ?? '') === 'error' ? '#b91c1c' : '#1e40af' ?>;">
        <?= h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<form method="POST" action="<?= h($saveUrl) ?>" class="settings-form">

    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

    <!-- System Information -->
    <div class="settings-card">
        <h3>System Information</h3>

        <div class="form-group">
            <label for="system_name">System Name</label>
            <input type="text" id="system_name" name="system_name"
                   value="<?= h(setting($settings, 'system_name', 'PSU Internalization Management System')) ?>"
                   maxlength="150">
        </div>

        <div class="form-group">
            <label for="organization">Organization</label>
            <input type="text" id="organization" name="organization"
                   value="<?= h(setting($settings, 'organization', 'Pangasinan State University')) ?>"
                   maxlength="150">
        </div>

        <div class="form-group">
            <label for="academic_year">Academic Year</label>
            <input type="text" id="academic_year" name="academic_year"
                   value="<?= h(setting($settings, 'academic_year', '2026-2027')) ?>"
                   maxlength="20"
                   pattern="\d{4}\s*[-–]\s*\d{4}"
                   title="Format: 2026-2027">
        </div>

        <div class="form-group">
            <label for="semester">Semester</label>
            <?php $sem = setting($settings, 'semester', 'First Semester'); ?>
            <select id="semester" name="semester">
                <option value="First Semester"  <?= $sem === 'First Semester'  ? 'selected' : '' ?>>First Semester</option>
                <option value="Second Semester" <?= $sem === 'Second Semester' ? 'selected' : '' ?>>Second Semester</option>
                <option value="Summer"          <?= $sem === 'Summer'          ? 'selected' : '' ?>>Summer</option>
            </select>
        </div>
    </div>

    <!-- Security -->
    <div class="settings-card">
        <h3>Security</h3>

        <div class="form-group">
            <label for="session_timeout">Session Timeout (minutes)</label>
            <input type="number" id="session_timeout" name="session_timeout"
                   value="<?= h(setting($settings, 'session_timeout', '30')) ?>"
                   min="1" max="1440">
        </div>

        <div class="form-group">
            <label for="password_length">Password Minimum Length</label>
            <input type="number" id="password_length" name="password_length"
                   value="<?= h(setting($settings, 'password_length', '8')) ?>"
                   min="6" max="64">
        </div>

        <div class="form-group">
            <label for="login_attempts">Maximum Login Attempts</label>
            <input type="number" id="login_attempts" name="login_attempts"
                   value="<?= h(setting($settings, 'login_attempts', '5')) ?>"
                   min="1" max="20">
        </div>
    </div>

    <!-- Registration -->
    <div class="settings-card">
        <h3>Registration</h3>

        <label class="switch">
            <input type="checkbox" name="allow_registration" value="1"
                   <?= settingBool($settings, 'allow_registration', true) ? 'checked' : '' ?>>
            <span>Allow Applicant Registration</span>
        </label>

        <label class="switch">
            <input type="checkbox" name="email_verification" value="1"
                   <?= settingBool($settings, 'email_verification', true) ? 'checked' : '' ?>>
            <span>Rrequire Email Verification</span>
        </label>
    </div>

    <!-- Maintenance -->
    <div class="settings-card">
        <h3>Maintenance</h3>

        <label class="switch">
            <input type="checkbox" name="maintenance_mode" value="1"
                   <?= settingBool($settings, 'maintenance_mode', false) ? 'checked' : '' ?>>
            <span>Enable Maintenance Mode</span>
        </label>

        <textarea name="maintenance_message" rows="3"
                  placeholder="Maintenance message..."
                  maxlength="1000"><?= h(setting($settings, 'maintenance_message')) ?></textarea>
    </div>

    <!-- Email -->
    <div class="settings-card">
        <h3>Email (SMTP)</h3>

        <div class="form-group">
            <label for="smtp_host">SMTP Host</label>
            <input type="text" id="smtp_host" name="smtp_host"
                   placeholder="smtp.gmail.com"
                   value="<?= h(setting($settings, 'smtp_host')) ?>"
                   maxlength="150">
        </div>

        <div class="form-group">
            <label for="smtp_port">SMTP Port</label>
            <input type="number" id="smtp_port" name="smtp_port"
                   placeholder="587"
                   value="<?= h(setting($settings, 'smtp_port')) ?>"
                   min="1" max="65535">
        </div>

        <div class="form-group">
            <label for="smtp_email">SMTP Email</label>
            <input type="email" id="smtp_email" name="smtp_email"
                   placeholder="noreply@psu.edu.ph"
                   value="<?= h(setting($settings, 'smtp_email')) ?>"
                   maxlength="150">
        </div>

        <div class="form-group">
            <label for="smtp_sender">Sender Name</label>
            <input type="text" id="smtp_sender" name="smtp_sender"
                   placeholder="PSU Internationalization"
                   value="<?= h(setting($settings, 'smtp_sender')) ?>"
                   maxlength="150">
        </div>

        <div class="form-group">
            <label for="smtp_password">
                SMTP Password
                <small style="color:#64748b;font-weight:400;">
                    (leave blank to keep current)
                </small>
            </label>
            <input type="password" id="smtp_password" name="smtp_password"
                   placeholder="••••••••"
                   autocomplete="new-password">
        </div>
    </div>

    <div class="settings-actions">
        <button type="submit" class="btn-primary">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
    </div>

</form>

</body>
</html>