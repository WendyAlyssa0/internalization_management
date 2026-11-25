<?php
// apply.php — handles ?page=apply&program_id=X
if (!isset($pdo, $userId, $user) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=available_programs');
    exit;
}

$message = '';
$msgType = '';
$programId = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);

if ($programId <= 0) {
    header('Location: dashboard.php?page=available_programs');
    exit;
}

// Verify program is eligible for this user
$stmt = $pdo->prepare("
    SELECT p.id, p.program_name, p.description, p.start_date, p.end_date,
           country.country_name
    FROM programs p
    LEFT JOIN countries country ON country.id = p.country_id
    WHERE p.id = ?
      AND p.status IN ('Active', 'Upcoming')
      AND p.end_date >= CURDATE()
      AND p.eligible_account_type = ?
      AND (p.eligible_department = 'All Departments' OR p.eligible_department = ?)
");
$stmt->execute([$programId, $user['account_type'] ?? '', $user['department'] ?? '']);
$program = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    header('Location: dashboard.php?page=available_programs');
    exit;
}

// Check for an existing application
$check = $pdo->prepare("SELECT id, status FROM applications WHERE applicant_id = ? AND program_id = ? LIMIT 1");
$check->execute([$userId, $programId]);
$existing = $check->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_application'])) {
    if (!checkCsrf()) {
        $message = 'Your session expired. Please refresh and try again.';
        $msgType = 'error';
    } elseif ($existing) {
        $message = 'You have already applied to this program.';
        $msgType = 'warning';
    } else {
        try {
            // CRITICAL: program_id MUST be stored here so documents.php can find it
            $ins = $pdo->prepare("
                INSERT INTO applications (applicant_id, program_id, status, created_at, updated_at)
                VALUES (?, ?, 'pending', NOW(), NOW())
            ");
            $ins->execute([$userId, $programId]);

            $message = 'Application submitted successfully! You will be notified once it is reviewed.';
            $msgType = 'success';
            $existing = ['id' => (int)$pdo->lastInsertId(), 'status' => 'pending'];
        } catch (PDOException $e) {
            error_log('Application insert failed: ' . $e->getMessage());
            $message = 'Could not submit your application. Please try again.';
            $msgType = 'error';
        }
    }
}

// Fetch requirements for this program (for the preview checklist)
$reqStmt = $pdo->prepare("
    SELECT pr.id, pr.is_required, r.requirement_name, r.description
    FROM program_requirements pr
    INNER JOIN requirements r ON pr.requirement_id = r.id
    WHERE pr.program_id = ?
    ORDER BY r.requirement_name
");
$reqStmt->execute([$programId]);
$requirements = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<style>
    .apply-page { padding: 32px; background: var(--white); border: 1px solid var(--border-light); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); }
    .apply-page h2 { font-family: var(--font-heading); color: var(--psu-navy); margin-bottom: 8px; }
    .apply-page .lead { color: var(--text-muted); margin-bottom: 24px; }
    .apply-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 24px 0; }
    .apply-meta div { padding: 16px; background: var(--bg-main); border-radius: var(--radius-sm); }
    .apply-meta small { display: block; text-transform: uppercase; font-size: 0.7rem; color: var(--text-light); font-weight: 700; margin-bottom: 4px; }
    .apply-meta strong { color: var(--psu-navy); font-family: var(--font-heading); }
    .req-list { list-style: none; padding: 0; margin: 16px 0 24px; }
    .req-list li { padding: 12px 16px; border: 1px solid var(--border-light); border-radius: var(--radius-sm); margin-bottom: 8px; }
    .req-list li strong { color: var(--psu-navy); }
    .req-list li small { color: var(--text-muted); display: block; margin-top: 2px; }
    .apply-actions { display: flex; gap: 12px; margin-top: 24px; }
    .btn-apply { padding: 12px 24px; background: var(--psu-navy); color: #fff; border: 1px solid var(--psu-navy); border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; }
    .btn-apply:hover { background: var(--psu-blue); border-color: var(--psu-blue); }
    .btn-back { padding: 12px 24px; background: var(--white); color: var(--psu-navy); border: 1px solid var(--border-light); border-radius: var(--radius-sm); text-decoration: none; font-weight: 600; }
    .program-alert { padding: 16px 20px; border-radius: var(--radius-sm); margin-bottom: 24px; border: 1px solid; font-weight: 500; }
    .program-alert.success { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
    .program-alert.error { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
    .program-alert.warning { color: #92400e; background: #fffbeb; border-color: #fde68a; }
</style>

<div class="apply-page">
    <h2>Apply to <?= h($program['program_name']) ?></h2>
    <p class="lead">Review the program details and requirements before submitting your application.</p>

    <?php if ($message): ?>
        <div class="program-alert <?= h($msgType) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <p><?= nl2br(h($program['description'] ?? '')) ?></p>

    <div class="apply-meta">
        <div><small>Location</small><strong><?= h($program['country_name'] ?? 'TBA') ?></strong></div>
        <div><small>Duration</small><strong>
            <?= h(date('M d, Y', strtotime($program['start_date']))) ?> – <?= h(date('M d, Y', strtotime($program['end_date']))) ?>
        </strong></div>
    </div>

    <?php if ($requirements): ?>
        <h3 style="font-family: var(--font-heading); color: var(--psu-navy);">Requirements Preview</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem;">You will upload these after your application is approved.</p>
        <ul class="req-list">
            <?php foreach ($requirements as $r): ?>
                <li>
                    <strong><?= h($r['requirement_name']) ?><?= !empty($r['is_required']) ? ' *' : '' ?></strong>
                    <small><?= h($r['description'] ?? '') ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="apply-actions">
        <a class="btn-back" href="?page=available_programs">Back</a>
        <?php if (!$existing): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="program_id" value="<?= (int)$programId ?>">
                <button type="submit" name="submit_application" class="btn-apply">Submit Application</button>
            </form>
        <?php else: ?>
            <a class="btn-back" href="?page=my_applications">View My Application</a>
        <?php endif; ?>
    </div>
</div>