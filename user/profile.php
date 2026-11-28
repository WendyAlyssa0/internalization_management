<?php
if (!isset($pdo, $userId, $user) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=profile');
    exit;
}

require_once __DIR__ . '/../config/participant_profile.php';
$campuses         = participantCampuses($pdo);
$programsByCampus = participantProgramsByCampus($pdo);
$activeTab = $_GET['tab'] ?? 'profile'; // profile, security, danger

$saved = false;
$errors = [];
$successMsg = '';

/* =========================================================
   HANDLE FORM SUBMISSIONS
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? 'update_profile';

        /* --- 1. UPDATE PROFILE --- */
        if ($action === 'update_profile') {
            $first     = is_string($_POST['first_name'] ?? null) ? trim($_POST['first_name']) : '';
            $middle    = is_string($_POST['middle_name'] ?? null) ? trim($_POST['middle_name']) : '';
            $last      = is_string($_POST['last_name'] ?? null) ? trim($_POST['last_name']) : '';
            $suffix    = is_string($_POST['suffix'] ?? null) ? trim($_POST['suffix']) : '';
            $contact   = is_string($_POST['contact'] ?? null) ? trim($_POST['contact']) : '';
            $birthdate = is_string($_POST['birthdate'] ?? null) ? trim($_POST['birthdate']) : '';
            $sex       = is_string($_POST['sex'] ?? null) ? $_POST['sex'] : '';
            $altEmail  = is_string($_POST['alternate_email'] ?? null) ? strtolower(trim($_POST['alternate_email'])) : '';

            if ($first === '') $errors[] = 'First name is required.';
            if ($last === '')  $errors[] = 'Last name is required.';
            if (mb_strlen($first) > 100 || mb_strlen($middle) > 100 || mb_strlen($last) > 100) {
                $errors[] = 'Names must be 100 characters or fewer.';
            }
            if (!in_array($sex, ['', 'Male', 'Female', 'Prefer not to say'], true)) {
                $errors[] = 'Select a valid option for sex.';
            }
            if ($altEmail !== '') {
                if (!filter_var($altEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($altEmail) > 150) {
                    $errors[] = 'Enter a valid alternative personal email.';
                } elseif ($altEmail === strtolower((string)($user['email'] ?? ''))) {
                    $errors[] = 'Your alternative email must be different from your institutional email.';
                }
            }

            [$details, $detailErrors] = validateParticipantDetails($_POST, $campuses, $programsByCampus);
            $errors = array_merge($errors, $detailErrors);

            if (!$errors) {
                $columns = [
                    'first_name'      => $first,
                    'middle_name'     => $middle !== '' ? $middle : null,
                    'last_name'       => $last,
                    'suffix'          => $suffix !== '' ? $suffix : null,
                    'contact'         => $contact !== '' ? $contact : null,
                    'birthdate'       => $birthdate !== '' ? $birthdate : null,
                    'sex'             => $sex !== '' ? $sex : null,
                    'alternate_email' => $altEmail !== '' ? $altEmail : null,
                ] + participantDetailColumns($details);

                $set = implode(', ', array_map(static fn($c) => "$c = :$c", array_keys($columns)));
                $stmt = $pdo->prepare("UPDATE users SET $set WHERE id = :id");
                $stmt->execute($columns + ['id' => $userId]);

                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $_SESSION['first_name'] = $first;
                $saved = true;
                $successMsg = 'Profile updated successfully.';
            }
        }

        /* --- 2. UPDATE PASSWORD --- */
        if ($action === 'update_password') {
            $activeTab = 'security';
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                $errors[] = 'Please complete all password fields.';
            } else {
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();

                if (!is_string($hash) || !password_verify($current, $hash)) {
                    $errors[] = 'Your current password is incorrect.';
                } elseif (strlen($new) < 8) {
                    $errors[] = 'New password must be at least 8 characters long.';
                } elseif ($new !== $confirm) {
                    $errors[] = 'New passwords do not match.';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
                    $successMsg = 'Password updated successfully!';
                }
            }
        }

        /* --- 3. DEACTIVATE ACCOUNT --- */
        if ($action === 'deactivate_account') {
            $activeTab = 'danger';
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$userId]);
                session_destroy();
                header('Location: ../public/login.php?msg=account_deactivated');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Could not deactivate account. Please contact support.';
            }
        }

        /* --- 4. DELETE ACCOUNT --- */
        if ($action === 'delete_account') {
            $activeTab = 'danger';
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                session_destroy();
                header('Location: ../public/login.php?msg=account_deleted');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Could not delete account. Please contact support.';
            }
        }
    }
}

/* Affiliation values shown in the form: what the user just submitted if the save failed, else what is stored. */
if (isset($details) && $errors) {
    $pv = $details;
    $pv['alternate_email'] = $altEmail ?? '';
} else {
    $storedType = strtolower((string)($user['account_type'] ?? 'student'));
    $pv = [
        'account_type'    => array_key_exists($storedType, PARTICIPANT_CLASSIFICATIONS) ? $storedType : 'student',
        'campus_id'       => (int)($user['campus_id'] ?? 0),
        'program'         => (string)($user['program'] ?? ''),
        'student_number'  => (string)($user['student_id_number'] ?? ''),
        'year_level'      => (string)($user['year_level'] ?? ''),
        'position_rank'   => (string)($user['faculty_rank'] ?? ''),
        'alternate_email' => (string)($user['alternate_email'] ?? ''),
    ];
}
$pvPrograms = $programsByCampus[$pv['campus_id']] ?? [];

$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$initials = strtoupper(substr($user['first_name'] ?? 'A', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
$profileNotifications = [];
if (isset($pdo, $userId)) {
    $notificationStmt = $pdo->prepare("
        SELECT id, type, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $notificationStmt->execute([$userId]);
    $profileNotifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
    /* =====================================================
       PROFILE PAGE STYLES
       ===================================================== */
    /* Tabs */
    .profile-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 1px solid var(--border-light);
        margin-bottom: 32px;
        overflow-x: auto;
    }
    .profile-tabs::-webkit-scrollbar { height: 0; }
    
    .profile-tab {
        padding: 14px 24px;
        font-family: var(--font-heading);
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-muted);
        border-bottom: 3px solid transparent;
        cursor: pointer;
        transition: var(--transition);
        white-space: nowrap;
        text-decoration: none;
    }
    .profile-tab:hover { color: var(--psu-navy); }
    .profile-tab.active { color: var(--psu-navy); border-bottom-color: var(--psu-gold); }
    .profile-tab.danger-tab { color: #b91c1c; }
    .profile-tab.danger-tab.active { border-bottom-color: #b91c1c; }

    /* Profile Header */
    .profile-header-card { 
        display: flex; 
        align-items: center; 
        gap: 24px; 
        margin-bottom: 40px; 
        padding-bottom: 32px;
        border-bottom: 1px solid var(--border-light);
    }
    
    .profile-avatar-large {
        width: 96px; 
        height: 96px; 
        border-radius: 50%;
        background: var(--psu-gold);
        color: var(--psu-navy); 
        display: flex; 
        align-items: center; 
        justify-content: center;
        font-size: 2.2rem; 
        font-family: var(--font-heading); 
        font-weight: 800;
        box-shadow: 0 4px 12px rgba(228, 177, 52, 0.3);
    }
    
    .profile-header-info h2 { 
        font-family: var(--font-heading);
        font-size: 1.75rem; 
        color: var(--psu-navy); 
        margin-bottom: 6px; 
    }
    .profile-header-info p { 
        color: var(--text-muted); 
        font-size: 0.95rem; 
        display: flex; 
        align-items: center; 
        gap: 8px; 
        font-weight: 500;
    }
    .profile-header-info i { color: var(--psu-gold); }

    /* Form Grid */
    .profile-form-grid { 
        display: grid; 
        grid-template-columns: repeat(2, 1fr); 
        gap: 24px; 
        margin-bottom: 32px; 
    }
    .profile-form-group { 
        display: flex; 
        flex-direction: column; 
        gap: 8px;
    }
    .profile-form-group.full-width { grid-column: span 2; }
    .profile-form-group[hidden] { display: none; }
    .profile-section-title {
        font-size: 1rem;
        font-weight: 800;
        color: var(--psu-navy);
        margin: 8px 0 0;
        padding-top: 20px;
        border-top: 1px solid var(--border-light);
    }
    .profile-section-note {
        margin: 0;
        font-size: 0.85rem;
        color: #92400e;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        padding: 10px 14px;
    }
    .profile-form-group select:disabled { opacity: 0.6; cursor: not-allowed; }

    .profile-form-group label { 
        font-size: 0.85rem; 
        font-weight: 700; 
        color: var(--psu-navy); 
    }
    
    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    
    .input-wrapper svg {
        position: absolute;
        left: 16px;
        width: 20px;
        height: 20px;
        color: var(--text-light);
        pointer-events: none;
        transition: var(--transition);
    }

    .profile-form-group input, 
    .profile-form-group select {
        width: 100%; 
        padding: 14px 16px 14px 48px; 
        border: 1px solid var(--border-light);
        border-radius: var(--radius-sm); 
        font-family: var(--font-body);
        font-size: 0.95rem; 
        color: var(--psu-navy); 
        background: var(--white);
        transition: var(--transition);
        outline: none;
    }
    
    .profile-form-group input:focus, 
    .profile-form-group select:focus {
        border-color: var(--psu-navy); 
        box-shadow: 0 0 0 3px rgba(11, 26, 48, 0.08);
    }
    
    .input-wrapper:focus-within svg { color: var(--psu-navy); }
    
    .profile-form-group input:disabled { 
        background: var(--bg-main); 
        color: var(--text-light); 
        cursor: not-allowed; 
        border-color: var(--border-light); 
    }

    /* Danger Zone */
    .danger-zone {
        border: 1px solid #fecaca;
        background: #fef2f2;
        border-radius: var(--radius);
        padding: 32px;
        margin-top: 24px;
    }
    .danger-zone h3 { 
        font-family: var(--font-heading);
        color: #b91c1c; 
        font-size: 1.25rem; 
        margin-bottom: 12px; 
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .danger-zone p { 
        color: #991b1b; 
        font-size: 0.95rem; 
        margin-bottom: 24px; 
        line-height: 1.6;
    }
    
    .danger-actions { display: flex; gap: 16px; flex-wrap: wrap; }
    
    .btn-danger-outline {
        padding: 12px 24px; 
        border-radius: var(--radius-sm); 
        font-family: var(--font-heading);
        font-weight: 700; 
        font-size: 0.9rem;
        background: transparent; 
        border: 2px solid #ef4444; 
        color: #ef4444; 
        cursor: pointer; 
        transition: var(--transition);
    }
    .btn-danger-outline:hover { background: #ef4444; color: white; }
    
    .btn-danger-solid {
        padding: 12px 24px; 
        border-radius: var(--radius-sm); 
        font-family: var(--font-heading);
        font-weight: 700; 
        font-size: 0.9rem;
        background: #dc2626; 
        border: 2px solid #dc2626; 
        color: white; 
        cursor: pointer; 
        transition: var(--transition);
    }
    .btn-danger-solid:hover { background: #b91c1c; border-color: #b91c1c; }

    /* Utilities */
    .alert-box { 
        padding: 16px 20px; 
        border-radius: var(--radius-sm); 
        font-size: 0.9rem; 
        font-weight: 600; 
        margin-bottom: 24px; 
        display: flex; 
        gap: 12px; 
        align-items: center;
    }
    .alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    
    .btn-save {
        display: inline-flex; 
        align-items: center; 
        justify-content: center;
        padding: 14px 28px; 
        background: var(--psu-navy); 
        color: var(--white); 
        border: none;
        border-radius: var(--radius-sm); 
        font-family: var(--font-heading);
        font-weight: 700; 
        font-size: 1rem; 
        cursor: pointer; 
        transition: var(--transition);
        gap: 8px;
    }
    .btn-save:hover { 
        background: var(--psu-blue); 
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(11, 26, 48, 0.15);
    }
    
    @media (max-width: 600px) {
        .profile-form-grid { grid-template-columns: 1fr; }
        .profile-form-group.full-width { grid-column: span 1; }
        .profile-header-card { flex-direction: column; text-align: center; }
    }
</style>

<section class="content-card" aria-labelledby="profile-title">
    
    <div class="profile-header-card">
        <div class="profile-avatar-large"><?= h($initials) ?></div>
        <div class="profile-header-info">
            <h2 id="profile-title"><?= h($fullName ?: 'My Account') ?></h2>
            <p><i class="fa-solid fa-envelope"></i> <?= h($user['email'] ?? 'No email') ?></p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="profile-tabs">
        <a href="?page=profile&tab=profile" class="profile-tab <?= $activeTab === 'profile' ? 'active' : '' ?>">Personal Info</a>
        <a href="?page=profile&tab=security" class="profile-tab <?= $activeTab === 'security' ? 'active' : '' ?>">Security</a>
        <a href="?page=profile&tab=notifications" class="profile-tab <?= $activeTab === 'notifications' ? 'active' : '' ?>">Notifications</a>
        <a href="?page=profile&tab=danger" class="profile-tab danger-tab <?= $activeTab === 'danger' ? 'active' : '' ?>">Danger Zone</a>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert-box alert-success"><i class="fa-solid fa-circle-check"></i> <?= h($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert-box alert-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><ul style="margin:0; padding-left:16px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
        </div>
    <?php endif; ?>

    <!-- ==========================================
         TAB 3: NOTIFICATIONS
         ========================================== -->
    <?php if ($activeTab === 'notifications'): ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php if (empty($profileNotifications)): ?>
                <p style="color:var(--text-muted);">No notifications yet.</p>
            <?php else: ?>
                <?php foreach ($profileNotifications as $notification): ?>
                    <div style="padding:16px;border:1px solid var(--border-light);border-radius:12px;background:<?= empty($notification['is_read']) ? '#fffbeb' : 'var(--white)' ?>;">
                        <strong><?= h($notification['title']) ?></strong>
                        <p style="margin:6px 0;color:var(--text-muted);"><?= h($notification['message']) ?></p>
                        <small style="color:var(--text-muted);"><?= h(date('M d, Y g:i A', strtotime($notification['created_at']))) ?></small>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ==========================================
         TAB 1: PERSONAL INFO
         ========================================== -->
    <?php if ($activeTab === 'profile'): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="update_profile">
        
        <div class="profile-form-grid">
            <div class="profile-form-group">
                <label>First Name *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" name="first_name" maxlength="100" value="<?= h($user['first_name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="profile-form-group">
                <label>Middle Name</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" name="middle_name" maxlength="100" value="<?= h($user['middle_name'] ?? '') ?>">
                </div>
            </div>
            <div class="profile-form-group">
                <label>Last Name *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" name="last_name" maxlength="100" value="<?= h($user['last_name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="profile-form-group">
                <label>Suffix</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/></svg>
                    <input type="text" name="suffix" maxlength="20" value="<?= h($user['suffix'] ?? '') ?>" placeholder="e.g. Jr., III">
                </div>
            </div>
            <div class="profile-form-group">
                <label>Email Address (Locked)</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                    <input type="email" value="<?= h($user['email'] ?? '') ?>" disabled title="Email cannot be changed">
                </div>
            </div>
            <div class="profile-form-group">
                <label for="pf_alt_email">Alternative Personal Email</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 6L2 7"/></svg>
                    <input type="email" name="alternate_email" id="pf_alt_email" maxlength="150" value="<?= h($pv['alternate_email']) ?>" placeholder="Optional backup email">
                </div>
            </div>

            <div class="profile-form-group">
                <label>Contact Number</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <input type="text" name="contact" maxlength="20" value="<?= h($user['contact'] ?? '') ?>">
                </div>
            </div>
            <div class="profile-form-group">
                <label>Birthdate</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <input type="date" name="birthdate" value="<?= h($user['birthdate'] ?? '') ?>">
                </div>
            </div>
            <div class="profile-form-group">
                <label>Sex</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="10" r="4"/><path d="M12 14v7M8 18h8M19 5l-3 3M17 3l2 2"/></svg>
                    <select name="sex">
                        <option value="">Prefer not to specify</option>
                        <option value="Male" <?= ($user['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= ($user['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Prefer not to say" <?= ($user['sex'] ?? '') === 'Prefer not to say' ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>
            </div>
            <div class="profile-form-group full-width">
                <h3 class="profile-section-title">PSU Affiliation</h3>
                <?php if (!$pv['campus_id'] || ($pv['account_type'] !== 'non-teaching' && !in_array($pv['program'], $pvPrograms, true))): ?>
                    <p class="profile-section-note">Please select your campus and department / program — they decide which mobility programs you can apply for.</p>
                <?php endif; ?>
            </div>

            <div class="profile-form-group">
                <label for="pf_account_type">Classification *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <select name="account_type" id="pf_account_type" required>
                        <?php foreach (PARTICIPANT_CLASSIFICATIONS as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $pv['account_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="profile-form-group">
                <label for="pf_campus">Campus *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V10l7-5 7 5v11M9 21v-6h6v6"/></svg>
                    <select name="campus_id" id="pf_campus" required>
                        <option value="">Select your campus</option>
                        <?php foreach ($campuses as $id => $name): ?>
                            <option value="<?= (int)$id ?>" <?= $pv['campus_id'] === $id ? 'selected' : '' ?>><?= h($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="profile-form-group full-width">
                <label for="pf_program"><span id="pf_program_label"><?= $pv['account_type'] === 'student' ? 'Degree Program' : 'Department / Program' ?></span> <span id="pf_program_req"><?= $pv['account_type'] === 'non-teaching' ? '(optional)' : '*' ?></span></label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                    <select name="program" id="pf_program" <?= $pv['campus_id'] ? '' : 'disabled' ?>>
                        <?php if (!$pv['campus_id']): ?>
                            <option value="">Select a campus first</option>
                        <?php else: ?>
                            <option value=""><?= $pvPrograms ? 'Select' : 'No programs listed for this campus' ?></option>
                            <?php foreach ($pvPrograms as $prog): ?>
                                <option value="<?= h($prog) ?>" <?= $pv['program'] === $prog ? 'selected' : '' ?>><?= h($prog) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <!-- Student -->
            <div class="profile-form-group pf-type" data-type="student" <?= $pv['account_type'] === 'student' ? '' : 'hidden' ?>>
                <label for="student_number">Student Number *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M15 9h3M15 13h3M6 17c.5-2 2-3 3-3s2.5 1 3 3"/></svg>
                    <input type="text" name="student_number" id="student_number" maxlength="30" value="<?= h($pv['student_number']) ?>">
                </div>
            </div>
            <div class="profile-form-group pf-type" data-type="student" <?= $pv['account_type'] === 'student' ? '' : 'hidden' ?>>
                <label for="year_level">Year Level *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h16M4 15h16M4 11h16"/></svg>
                    <select name="year_level" id="year_level">
                        <option value="">Select</option>
                        <?php foreach (PARTICIPANT_YEAR_LEVELS as $yl): ?>
                            <option value="<?= h($yl) ?>" <?= $pv['year_level'] === $yl ? 'selected' : '' ?>><?= h($yl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Faculty -->
            <div class="profile-form-group pf-type" data-type="faculty" <?= $pv['account_type'] === 'faculty' ? '' : 'hidden' ?>>
                <label for="faculty_rank">Academic Rank *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    <select name="faculty_rank" id="faculty_rank">
                        <option value="">Select</option>
                        <?php foreach (PARTICIPANT_FACULTY_RANKS as $rank): ?>
                            <option value="<?= h($rank) ?>" <?= ($pv['account_type'] === 'faculty' && $pv['position_rank'] === $rank) ? 'selected' : '' ?>><?= h($rank) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Non-teaching -->
            <div class="profile-form-group pf-type" data-type="non-teaching" <?= $pv['account_type'] === 'non-teaching' ? '' : 'hidden' ?>>
                <label for="position">Position / Designation *</label>
                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    <input type="text" name="position" id="position" maxlength="120" value="<?= h($pv['account_type'] === 'non-teaching' ? $pv['position_rank'] : '') ?>" placeholder="e.g. Administrative Officer II">
                </div>
            </div>
        </div>
        <button type="submit" class="btn-save">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
    </form>
    <script>
    (function () {
        const PROGRAMS_BY_CAMPUS = <?= json_encode((object)$programsByCampus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const TYPE_REQUIRED = { 'student': ['student_number', 'year_level'], 'faculty': ['faculty_rank'], 'non-teaching': ['position'] };
        const typeSelect = document.getElementById('pf_account_type');
        const campus = document.getElementById('pf_campus');
        const program = document.getElementById('pf_program');

        function applyType() {
            const type = typeSelect.value;
            document.querySelectorAll('.pf-type').forEach(group => {
                const active = group.dataset.type === type;
                group.hidden = !active;
                group.querySelectorAll('input, select').forEach(el => {
                    el.disabled = !active;
                    el.required = active && TYPE_REQUIRED[type].includes(el.id);
                });
            });
            const optional = type === 'non-teaching';
            program.required = !optional && !program.disabled;
            document.getElementById('pf_program_label').textContent = type === 'student' ? 'Degree Program' : 'Department / Program';
            document.getElementById('pf_program_req').textContent = optional ? '(optional)' : '*';
        }

        function populatePrograms() {
            const previous = program.value;
            const list = PROGRAMS_BY_CAMPUS[campus.value] || [];
            program.innerHTML = '';
            program.add(new Option(!campus.value ? 'Select a campus first' : (list.length ? 'Select' : 'No programs listed for this campus'), ''));
            list.forEach(name => program.add(new Option(name, name, false, name === previous)));
            program.disabled = !campus.value;
            applyType();
        }

        typeSelect.addEventListener('change', applyType);
        campus.addEventListener('change', populatePrograms);
        applyType();
    })();
    </script>
    <?php endif; ?>

    <!-- ==========================================
         TAB 2: SECURITY (PASSWORD)
         ========================================== -->
    <?php if ($activeTab === 'security'): ?>
    <form method="post" style="max-width: 500px;">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="update_password">
        
        <div class="profile-form-group" style="margin-bottom: 24px;">
            <label>Current Password</label>
            <div class="input-wrapper">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input type="password" name="current_password" required placeholder="Enter current password">
            </div>
        </div>
        <div class="profile-form-group" style="margin-bottom: 24px;">
            <label>New Password (min 8 chars)</label>
            <div class="input-wrapper">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input type="password" name="new_password" minlength="8" required placeholder="At least 8 characters">
            </div>
        </div>
        <div class="profile-form-group" style="margin-bottom: 32px;">
            <label>Confirm New Password</label>
            <div class="input-wrapper">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input type="password" name="confirm_password" minlength="8" required placeholder="Re-type new password">
            </div>
        </div>
        <button type="submit" class="btn-save">
            <i class="fa-solid fa-shield-halved"></i> Update Password
        </button>
    </form>
    <?php endif; ?>

    <!-- ==========================================
         TAB 3: DANGER ZONE
         ========================================== -->
    <?php if ($activeTab === 'danger'): ?>
        <div class="danger-zone">
            <h3><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone</h3>
            <p>Once you delete your account, there is no going back. All of your applications, documents, and data will be permanently erased. If you just want to take a break, you can deactivate your account instead.</p>
            
            <div class="danger-actions">
                <!-- Deactivate -->
                <form method="post" onsubmit="return confirm('Are you sure you want to deactivate your account? You can contact support to reactivate it later.');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="deactivate_account">
                    <button type="submit" class="btn-danger-outline">Deactivate Account</button>
                </form>

                <!-- Delete -->
                <form method="post" onsubmit="return confirm('WARNING: This will permanently delete your account and all data. This cannot be undone. Are you absolutely sure?');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="delete_account">
                    <button type="submit" class="btn-danger-solid">Permanently Delete Account</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

</section>