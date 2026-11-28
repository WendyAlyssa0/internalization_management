<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_middleware.php';
requireAdmin();

/* =========================
   ROLE CHECK — normalize both spellings
========================= */
$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$currentRole = str_replace('_', ' ', $currentRole);

if ($currentRole !== 'super admin') {
    http_response_code(403);
    echo '<p style="padding:24px;font-family:Oswald,sans-serif;">Access Denied — Super Admin only.</p>';
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
   SAVE SETTINGS (POST HANDLER)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    try {
        $pdo = db();
        
        // Array of text/number fields
        $fields = [
            'system_name', 'organization', 'academic_year', 'semester',
            'session_timeout', 'password_length', 'login_attempts',
            'maintenance_message', 'smtp_host', 'smtp_port', 'smtp_email', 'smtp_sender'
        ];

        $updateStmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $updateStmt->execute([$key, trim($_POST[$key])]);
            }
        }

        // Handle Checkboxes (1 if checked, 0 if not)
        $toggles = ['allow_registration', 'email_verification', 'maintenance_mode'];
        foreach ($toggles as $key) {
            $val = isset($_POST[$key]) ? '1' : '0';
            $updateStmt->execute([$key, $val]);
        }

        // Handle Password (Only update if not blank)
        if (!empty($_POST['smtp_password'])) {
            // Note: If your system encrypts/hashes passwords, do it here.
            $updateStmt->execute(['smtp_password', trim($_POST['smtp_password'])]);
        }

        $_SESSION['settings_flash'] = ['type' => 'success', 'message' => 'Settings saved successfully.'];
        
    } catch (Throwable $e) {
        error_log('settings save: ' . $e->getMessage());
        $_SESSION['settings_flash'] = ['type' => 'error', 'message' => 'Failed to save settings.'];
    }

    // Redirect to prevent form resubmission on refresh
    header('Location: dashboard.php?page=settings');
    exit;
}

/* =========================
   LOAD CURRENT SETTINGS
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

function setting(array $settings, string $key, string $default = ''): string {
    return $settings[$key] ?? $default;
}
function settingBool(array $settings, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $settings)) return $default;
    return in_array(strtolower($settings[$key]), ['1', 'true', 'yes', 'on'], true);
}

/* Flash message */
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
?>

<link rel="stylesheet" href="../asset/css/general_settings.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>General Settings</h2>
        <p>Configure system-wide settings for the Internationalization Management System.</p>
    </div>

    <?php if ($flash): ?>
        <div class="flash flash--<?= h($flash['type'] ?? 'info') ?>">
            <i class="fa-solid fa-circle-info"></i>
            <?= h($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <!-- Remove action so it POSTs to the current URL (dashboard.php?page=settings) -->
    <form method="POST" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <!-- System Information -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3><i class="fa-solid fa-server"></i> System Information</h3>
                <p>Basic system identity and academic period settings.</p>
            </div>

            <div class="form-grid">
                <div class="form-group col-6">
                    <label for="system_name">System Name</label>
                    <input type="text" id="system_name" name="system_name"
                           value="<?= h(setting($settings, 'system_name', 'PSU Internationalization Management System')) ?>"
                           maxlength="150">
                </div>

                <div class="form-group col-6">
                    <label for="organization">Organization</label>
                    <input type="text" id="organization" name="organization"
                           value="<?= h(setting($settings, 'organization', 'Pangasinan State University')) ?>"
                           maxlength="150">
                </div>

                <div class="form-group col-6">
                    <label for="academic_year">Academic Year</label>
                    <input type="text" id="academic_year" name="academic_year"
                           value="<?= h(setting($settings, 'academic_year', '2026-2027')) ?>"
                           maxlength="20"
                           pattern="\d{4}\s*[-–]\s*\d{4}"
                           title="Format: 2026-2027">
                </div>

                <div class="form-group col-6">
                    <label for="semester">Semester</label>
                    <?php $sem = setting($settings, 'semester', 'First Semester'); ?>
                    <select id="semester" name="semester">
                        <option value="First Semester"  <?= $sem === 'First Semester'  ? 'selected' : '' ?>>First Semester</option>
                        <option value="Second Semester" <?= $sem === 'Second Semester' ? 'selected' : '' ?>>Second Semester</option>
                        <option value="Summer"          <?= $sem === 'Summer'          ? 'selected' : '' ?>>Summer</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3><i class="fa-solid fa-shield-halved"></i> Security</h3>
                <p>Configure session timeouts and password policies.</p>
            </div>

            <div class="form-grid">
                <div class="form-group col-4">
                    <label for="session_timeout">Session Timeout (mins)</label>
                    <input type="number" id="session_timeout" name="session_timeout"
                           value="<?= h(setting($settings, 'session_timeout', '30')) ?>"
                           min="1" max="1440">
                </div>

                <div class="form-group col-4">
                    <label for="password_length">Password Min Length</label>
                    <input type="number" id="password_length" name="password_length"
                           value="<?= h(setting($settings, 'password_length', '8')) ?>"
                           min="6" max="64">
                </div>

                <div class="form-group col-4">
                    <label for="login_attempts">Max Login Attempts</label>
                    <input type="number" id="login_attempts" name="login_attempts"
                           value="<?= h(setting($settings, 'login_attempts', '5')) ?>"
                           min="1" max="20">
                </div>
            </div>
        </div>

        <!-- Registration -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3><i class="fa-solid fa-user-plus"></i> Registration</h3>
                <p>Control who can register and how they verify their accounts.</p>
            </div>

            <div class="toggle-group">
                <label class="toggle-switch">
                    <input type="checkbox" name="allow_registration" value="1" <?= settingBool($settings, 'allow_registration', true) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Allow Applicant Registration</span>
                </label>

                <label class="toggle-switch">
                    <input type="checkbox" name="email_verification" value="1" <?= settingBool($settings, 'email_verification', true) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Require Email Verification</span>
                </label>
            </div>
        </div>

        <!-- Maintenance -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Maintenance</h3>
                <p>Enable this to temporarily lock the system for applicants.</p>
            </div>

            <div class="toggle-group" style="margin-bottom: 16px;">
                <label class="toggle-switch">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= settingBool($settings, 'maintenance_mode', false) ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">Enable Maintenance Mode</span>
                </label>
            </div>

            <div class="form-group">
                <label for="maintenance_message">Maintenance Message</label>
                <textarea id="maintenance_message" name="maintenance_message" rows="3"
                          placeholder="E.g., The system is currently undergoing scheduled maintenance. Please check back later."
                          maxlength="1000"><?= h(setting($settings, 'maintenance_message')) ?></textarea>
            </div>
        </div>

        <!-- Email -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3><i class="fa-solid fa-envelope"></i> Email (SMTP)</h3>
                <p>Configure the mail server used for system notifications.</p>
            </div>

            <div class="form-grid">
                <div class="form-group col-8">
                    <label for="smtp_host">SMTP Host</label>
                    <input type="text" id="smtp_host" name="smtp_host"
                           placeholder="smtp.gmail.com"
                           value="<?= h(setting($settings, 'smtp_host')) ?>"
                           maxlength="150">
                </div>

                <div class="form-group col-4">
                    <label for="smtp_port">SMTP Port</label>
                    <input type="number" id="smtp_port" name="smtp_port"
                           placeholder="587"
                           value="<?= h(setting($settings, 'smtp_port')) ?>"
                           min="1" max="65535">
                </div>

                <div class="form-group col-6">
                    <label for="smtp_email">SMTP Email</label>
                    <input type="email" id="smtp_email" name="smtp_email"
                           placeholder="noreply@psu.edu.ph"
                           value="<?= h(setting($settings, 'smtp_email')) ?>"
                           maxlength="150">
                </div>

                <div class="form-group col-6">
                    <label for="smtp_sender">Sender Name</label>
                    <input type="text" id="smtp_sender" name="smtp_sender"
                           placeholder="PSU Internationalization"
                           value="<?= h(setting($settings, 'smtp_sender')) ?>"
                           maxlength="150">
                </div>

                <div class="form-group col-12">
                    <label for="smtp_password">
                        SMTP Password
                        <small>(leave blank to keep current)</small>
                    </label>
                    <input type="password" id="smtp_password" name="smtp_password"
                           placeholder="••••••••"
                           autocomplete="new-password">
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-confirm">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </form>
</div>