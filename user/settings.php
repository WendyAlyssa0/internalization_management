<?php
if (!isset($pdo, $userId) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=settings');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new     = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!checkCsrf()) {
        $message = 'Your session expired. Please refresh the page and try again.';
        $messageType = 'error';
    } elseif ($current === '' || $new === '' || $confirm === '') {
        $message = 'Please complete all password fields.';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!is_string($hash) || !password_verify($current, $hash)) {
            $message = 'Your current password is incorrect.';
            $messageType = 'error';
        } elseif (strlen($new) < 8) {
            $message = 'New password must be at least 8 characters long.';
            $messageType = 'error';
        } elseif ($new !== $confirm) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $message = 'Your password has been updated successfully!';
            $messageType = 'success';
        }
    }
}
?>

<style>
    /* =====================================================
       SECURITY SETTINGS STYLES (Matched to Auth Pages)
       ===================================================== */
    .settings-container {
        max-width: 600px;
    }

    .settings-form {
        margin-top: 32px;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--psu-navy);
    }

    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .lead-icon {
        position: absolute;
        left: 16px;
        width: 20px;
        height: 20px;
        color: var(--text-light);
        pointer-events: none;
        z-index: 1;
        transition: var(--transition);
    }

    .form-control {
        width: 100%;
        padding: 14px 16px 14px 48px;
        border: 1px solid var(--border-light);
        border-radius: var(--radius-sm);
        font-family: var(--font-body);
        font-size: 0.95rem;
        color: var(--text-main);
        background: var(--white);
        transition: var(--transition);
        outline: none;
    }

    .form-control:focus {
        border-color: var(--psu-navy);
        box-shadow: 0 0 0 3px rgba(11, 26, 48, 0.08);
    }

    .input-wrapper:focus-within .lead-icon {
        color: var(--psu-navy);
    }

    .form-control::placeholder {
        color: var(--text-light);
    }

    /* Toggle Password Button */
    .toggle-password {
        position: absolute;
        right: 16px;
        background: none;
        border: none;
        color: var(--text-light);
        cursor: pointer;
        padding: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition);
        z-index: 2;
    }

    .toggle-password:hover {
        color: var(--psu-navy);
    }

    /* Submit Button */
    .btn-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 16px;
        background: var(--psu-navy);
        color: var(--white);
        border: none;
        border-radius: var(--radius-sm);
        font-family: var(--font-heading);
        font-weight: 700;
        font-size: 1rem;
        cursor: pointer;
        transition: var(--transition);
        margin-top: 8px;
        gap: 8px;
    }

    .btn-submit:hover {
        background: var(--psu-blue);
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(11, 26, 48, 0.15);
    }

    /* Alerts */
    .alert-box {
        padding: 16px 20px;
        border-radius: var(--radius-sm);
        font-size: 0.9rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px;
    }
    .alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
</style>

<section class="content-card" aria-labelledby="settings-title">
    <div class="page-header">
        <div>
            <h2 id="settings-title">Security Settings</h2>
            <p>Update your account password. Make sure it's at least 8 characters long.</p>
        </div>
    </div>

    <div class="settings-container">
        <?php if ($message): ?>
            <div class="alert-box alert-<?= h($messageType) ?>" role="<?= $messageType === 'success' ? 'status' : 'alert' ?>">
                <i class="fa-solid <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            
            <!-- Current Password -->
            <div class="form-group">
                <label class="form-label" for="current-password">Current Password</label>
                <div class="input-wrapper">
                    <svg class="lead-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input id="current-password" class="form-control" type="password" name="current_password" autocomplete="current-password" required placeholder="Enter current password">
                    <button type="button" class="toggle-password" onclick="togglePassword('current-password', this)" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- New Password -->
            <div class="form-group">
                <label class="form-label" for="new-password">New Password</label>
                <div class="input-wrapper">
                    <svg class="lead-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input id="new-password" class="form-control" type="password" name="new_password" autocomplete="new-password" minlength="8" required placeholder="At least 8 characters">
                    <button type="button" class="toggle-password" onclick="togglePassword('new-password', this)" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label class="form-label" for="confirm-password">Confirm New Password</label>
                <div class="input-wrapper">
                    <svg class="lead-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input id="confirm-password" class="form-control" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required placeholder="Re-type new password">
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm-password', this)" aria-label="Show password">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-shield-halved"></i> Update Password
            </button>
        </form>
    </div>
</section>

<script>
    function togglePassword(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>