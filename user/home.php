<?php
require __DIR__ . '/download_handler.php';
if (!isset($pdo, $userId, $user) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=home');
    exit;
}

/* Approved application (if any) */
$stmt = $pdo->prepare("
    SELECT
        a.id,
        p.program_name,
        c.country_name AS host_country,
        p.start_date AS program_start_date,
        p.end_date AS program_end_date
    FROM applications a
    LEFT JOIN programs p ON p.id = a.program_id
    LEFT JOIN countries c ON c.id = p.country_id
    WHERE a.applicant_id = ? AND a.status = 'approved'
    ORDER BY COALESCE(a.reviewed_at, a.updated_at, a.created_at) DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$approved = $stmt->fetch(PDO::FETCH_ASSOC);

/* Application counts for stats */
$stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS application_count
    FROM applications
    WHERE applicant_id = ?
    GROUP BY status
");
$stmt->execute([$userId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingCount = (int)($statusCounts['pending'] ?? 0) + (int)($statusCounts['submitted'] ?? 0);
$underReviewCount = (int)($statusCounts['under_review'] ?? 0);

/* =====================================================
   MY PROGRESS — the logged-in user's own data only
   Every query below is scoped by applicant_id / user_id = $userId
   ===================================================== */
$progressApps = [];
$travelInfo   = null;
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id, a.status, a.documents_status, a.created_at, a.reviewed_at,
            p.program_name, p.start_date, p.end_date,
            c.country_name,
            (SELECT COUNT(*) FROM program_requirements pr
              WHERE pr.program_id = a.program_id AND pr.is_required = 1) AS req_total,
            (SELECT COUNT(DISTINCT ud.requirement_id) FROM user_documents ud
              INNER JOIN program_requirements pr2 ON pr2.id = ud.requirement_id
              WHERE ud.user_id = a.applicant_id AND pr2.program_id = a.program_id
                AND pr2.is_required = 1) AS req_done
        FROM applications a
        LEFT JOIN programs p ON p.id = a.program_id
        LEFT JOIN countries c ON c.id = p.country_id
        WHERE a.applicant_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$userId]);
    $progressApps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT destination, departure_date, return_date
        FROM travel_information
        WHERE user_id = ?
        ORDER BY departure_date DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $travelInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log('home progress query error: ' . $e->getMessage());
}

/* Simple status indicators (label, color tone, icon) */
function appStatusBadge(string $status): array {
    return match ($status) {
        'submitted'    => ['Pending',    'gold',  'fa-clock'],
        'under_review' => ['For Review', 'blue',  'fa-magnifying-glass'],
        'approved'     => ['Approved',   'green', 'fa-circle-check'],
        'rejected'     => ['Rejected',   'red',   'fa-circle-xmark'],
        'completed'    => ['Completed',  'navy',  'fa-flag-checkered'],
        default        => [ucwords(str_replace('_', ' ', $status ?: 'pending')), 'gray', 'fa-circle-info'],
    };
}
function docStatusBadge(string $status): array {
    return match ($status) {
        'complete'   => ['Documents complete',   'green', 'fa-circle-check'],
        'incomplete' => ['Documents incomplete', 'red',   'fa-triangle-exclamation'],
        default      => ['Documents pending',    'gold',  'fa-clock'],
    };
}

/* Application pipeline: step index per status */
$appSteps = ['Submitted', 'Under Review', 'Decision', 'Completed'];
function appStepIndex(string $status): int {
    return match ($status) {
        'under_review'          => 1,
        'approved', 'rejected'  => 2,
        'completed'             => 3,
        default                 => 0,
    };
}

/* Mobility progress derived from program dates + status */
function mobilityProgress(array $a): array {
    $status = (string)($a['status'] ?? '');
    $today  = new DateTimeImmutable('today');
    $start  = !empty($a['start_date']) ? new DateTimeImmutable($a['start_date']) : null;
    $end    = !empty($a['end_date'])   ? new DateTimeImmutable($a['end_date'])   : null;

    if ($status === 'rejected')  return ['label' => 'Not proceeding', 'pct' => 0,   'tone' => 'red',   'note' => 'This application was not approved.'];
    if ($status !== 'approved' && $status !== 'completed')
                                 return ['label' => 'Awaiting approval', 'pct' => 0, 'tone' => 'gold',  'note' => 'Mobility starts once your application is approved.'];
    if ($status === 'completed' || ($end && $today > $end))
                                 return ['label' => 'Completed', 'pct' => 100, 'tone' => 'green', 'note' => 'Your mobility period has ended.'];
    if (!$start || !$end)        return ['label' => 'Approved — dates pending', 'pct' => 0, 'tone' => 'blue', 'note' => 'Program dates have not been set yet.'];
    if ($today < $start) {
        $days = (int)$today->diff($start)->days;
        return ['label' => 'Upcoming', 'pct' => 0, 'tone' => 'blue', 'note' => "Departs in {$days} day" . ($days === 1 ? '' : 's') . '.'];
    }
    $total = max(1, (int)$start->diff($end)->days);
    $done  = (int)$start->diff($today)->days;
    $left  = (int)$today->diff($end)->days;
    return ['label' => 'Ongoing', 'pct' => (int)min(100, round($done / $total * 100)), 'tone' => 'green',
            'note' => "{$left} day" . ($left === 1 ? '' : 's') . ' remaining.'];
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
?>

<style>
    /* Local scoped styles for home dashboard to ensure consistency */
    .home-dashboard {
        display: flex;
        flex-direction: column;
        gap: 24px;
        width: 100%;
    }

    /* Welcome Header */
    .welcome-header {
        margin-bottom: 8px;
    }
    .welcome-header h2 {
        font-family: var(--font-heading);
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--psu-navy);
        margin-bottom: 6px;
        letter-spacing: -0.02em;
    }
    .welcome-header p {
        color: var(--text-muted);
        font-size: 1rem;
    }

    /* Stat Cards Grid */
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
    }

    .stat-card {
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
    }

    .stat-card:hover {
        border-color: var(--psu-gold);
        box-shadow: var(--shadow-card);
        transform: translateY(-2px);
    }

    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .stat-icon.blue  { background: #eff6ff; color: #2563eb; }
    .stat-icon.gold  { background: #fefce8; color: #ca8a04; }
    .stat-icon.green { background: #f0fdf4; color: #16a34a; }
    .stat-icon.red   { background: #fef2f2; color: #dc2626; }

    .stat-content {
        display: flex;
        flex-direction: column;
    }
    .stat-value {
        font-family: var(--font-heading);
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--psu-navy);
        line-height: 1.1;
    }
    .stat-label {
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--text-muted);
        margin-top: 4px;
    }

    /* Approved Banner */
    .approval-banner {
        background: linear-gradient(135deg, var(--psu-navy) 0%, #162c4d 100%);
        background-image: 
            linear-gradient(rgba(255, 255, 255, 0.04) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px),
            linear-gradient(135deg, var(--psu-navy) 0%, #162c4d 100%);
        background-size: 40px 40px, 40px 40px, 100% 100%;
        border-radius: var(--radius-lg);
        padding: 32px;
        display: flex;
        gap: 24px;
        align-items: flex-start;
        color: var(--white);
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 10px 25px -5px rgba(11, 26, 48, 0.4);
        margin-top: 8px;
    }

    .banner-icon {
        width: 56px;
        height: 56px;
        background: rgba(228, 177, 52, 0.15);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--psu-gold);
        font-size: 1.75rem;
        flex-shrink: 0;
        border: 1px solid rgba(228, 177, 52, 0.3);
    }

    .banner-body h3 {
        color: var(--white);
        font-family: var(--font-heading);
        font-size: 1.35rem;
        margin-bottom: 8px;
    }
    .banner-body p {
        color: var(--text-light);
        font-size: 0.95rem;
        margin-bottom: 6px;
    }
    .banner-body p strong {
        color: var(--white);
        font-weight: 600;
    }
    .banner-dates {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        color: #cbd5e1 !important;
        margin-bottom: 20px !important;
    }

    .btn-gold {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--psu-gold);
        color: var(--psu-navy);
        font-family: var(--font-heading);
        font-weight: 700;
        font-size: 0.9rem;
        padding: 12px 24px;
        border-radius: var(--radius-sm);
        transition: var(--transition);
        box-shadow: 0 4px 12px rgba(228, 177, 52, 0.2);
    }
    .btn-gold:hover {
        background: var(--psu-gold-light);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(228, 177, 52, 0.3);
    }

    /* My Progress */
    .progress-section { display: flex; flex-direction: column; gap: 20px; }
    .progress-section > h3 { font-family: var(--font-heading); font-size: 1.2rem; font-weight: 800; color: var(--psu-navy); }
    .progress-card {
        background: var(--white); border: 1px solid var(--border-light);
        border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow-sm);
    }
    .progress-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
    .progress-title { font-family: var(--font-heading); font-weight: 700; font-size: 1.05rem; color: var(--psu-navy); }
    .progress-sub { font-size: .85rem; color: var(--text-muted); margin-top: 2px; }
    .progress-link { font-size: .85rem; font-weight: 700; color: var(--psu-blue); }
    .progress-link:hover { color: var(--psu-gold); }

    .stepper { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 20px; }
    .step { text-align: center; position: relative; font-size: .78rem; font-weight: 600; color: var(--text-light); }
    .step::before { content: ''; position: absolute; top: 11px; left: -50%; width: 100%; height: 3px; background: var(--border-light); z-index: 0; }
    .step:first-child::before { display: none; }
    .step-dot {
        position: relative; z-index: 1; width: 24px; height: 24px; margin: 0 auto 8px;
        border-radius: 50%; background: var(--white); border: 3px solid var(--border-light);
        display: flex; align-items: center; justify-content: center; font-size: .6rem;
    }
    .step.done { color: var(--psu-navy); }
    .step.done::before { background: #16a34a; }
    .step.done .step-dot { background: #16a34a; border-color: #16a34a; color: #fff; }
    .step.current { color: var(--psu-navy); }
    .step.current::before { background: #16a34a; }
    .step.current .step-dot { border-color: var(--psu-gold); background: #fffbeb; }
    .step.rejected { color: #b91c1c; }
    .step.rejected::before { background: #fecaca; }
    .step.rejected .step-dot { background: #dc2626; border-color: #dc2626; color: #fff; }

    .bar { height: 10px; background: var(--border-light); border-radius: 999px; overflow: hidden; }
    .bar > span { display: block; height: 100%; border-radius: 999px; background: var(--psu-blue); }
    .bar.green > span { background: #16a34a; }
    .bar.gold  > span { background: var(--psu-gold); }
    .bar-label { display: flex; justify-content: space-between; font-size: .8rem; color: var(--text-muted); margin: 6px 0 14px; }
    .progress-meta { display: flex; flex-wrap: wrap; gap: 10px 24px; font-size: .85rem; color: var(--text-muted); }
    .progress-meta strong { color: var(--psu-navy); }
    .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .pill.green { background: #ecfdf5; color: #047857; }
    .pill.gold  { background: #fffbeb; color: #92400e; }
    .pill.blue  { background: #eff6ff; color: #1d4ed8; }
    .pill.red   { background: #fef2f2; color: #b91c1c; }
    .pill.navy { background: #e2e8f0; color: var(--psu-navy); }
    .pill.gray { background: #f1f5f9; color: var(--text-muted); }
    .pill i { margin-right: 4px; }
    .badge-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .progress-empty { text-align: center; color: var(--text-muted); padding: 28px 16px; border: 2px dashed var(--border-light); border-radius: var(--radius); }
    @media (max-width: 600px) { .step { font-size: .68rem; } }

    @media (max-width: 600px) {
        .approval-banner {
            flex-direction: column;
            gap: 16px;
            padding: 24px;
        }
    }
</style>

<div class="home-dashboard">

    <!-- Welcome Header -->
    <div class="welcome-header" id="overview">
        <h2>Welcome back, <?= h($user['first_name'] ?? 'Applicant') ?>!</h2>
        <p>Here's an overview of your international application activity.</p>
    </div>

    <!-- Approved Banner (Moved to top for immediate visibility) -->
    <?php if ($approved): ?>
    <div class="approval-banner">
        <div class="banner-icon">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="banner-body">
            <h3>Your application was approved!</h3>
            <p><strong><?= h($approved['program_name'] ?? 'Approved program') ?></strong>
               <?php if (!empty($approved['host_country'])): ?> — <?= h($approved['host_country']) ?><?php endif; ?>
            </p>
            <?php if (!empty($approved['program_start_date']) && !empty($approved['program_end_date'])): ?>
                <p class="banner-dates">
                    <i class="fa-regular fa-calendar"></i> 
                    <?= h(date('M d, Y', strtotime($approved['program_start_date']))) ?> – <?= h(date('M d, Y', strtotime($approved['program_end_date']))) ?>
                </p>
            <?php endif; ?>
            <a class="btn-gold" href="?page=my_application_detail&id=<?= (int)$approved['id'] ?>">
                View Full Details <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="stat-grid" id="stats">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-regular fa-file-lines"></i></div>
            <div class="stat-content">
                <span class="stat-value"><?= array_sum($statusCounts) ?></span>
                <span class="stat-label">Total Applications</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-content">
                <span class="stat-value"><?= $pendingCount + $underReviewCount ?></span>
                <span class="stat-label">Pending / In Review</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-content">
                <span class="stat-value"><?= (int)($statusCounts['approved'] ?? 0) ?></span>
                <span class="stat-label">Approved</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-regular fa-bell"></i></div>
            <div class="stat-content">
                <span class="stat-value"><?= $unreadCount ?? 0 ?></span>
                <span class="stat-label">Unread Alerts</span>
            </div>
        </div>
    </div>

    <!-- My Progress: application + mobility (own data only) -->
    <section class="progress-section" id="my-progress">
        <h3>My Application &amp; Mobility Progress</h3>

        <?php if (!$progressApps): ?>
            <div class="progress-empty">
                You haven't applied to a program yet.
                <a class="progress-link" href="?page=available_programs">Browse programs</a>
            </div>
        <?php endif; ?>

        <?php foreach ($progressApps as $pa):
            $status  = (string)$pa['status'];
            $idx     = appStepIndex($status);
            $mob     = mobilityProgress($pa);
            $reqTot  = (int)$pa['req_total'];
            $reqDone = min((int)$pa['req_done'], $reqTot);
            $docPct  = $reqTot > 0 ? (int)round($reqDone / $reqTot * 100) : 0;
            [$stLabel, $stTone, $stIcon] = appStatusBadge($status);
            [$dcLabel, $dcTone, $dcIcon] = docStatusBadge((string)($pa['documents_status'] ?? 'pending'));
        ?>
        <div class="progress-card">
            <div class="progress-head">
                <div>
                    <div class="progress-title"><?= h($pa['program_name'] ?? 'Program unavailable') ?></div>
                    <div class="progress-sub">
                        <?php if (!empty($pa['country_name'])): ?><?= h($pa['country_name']) ?> &bull; <?php endif; ?>
                        Applied <?= h(date('M j, Y', strtotime((string)$pa['created_at']))) ?>
                    </div>
                    <div class="badge-row">
                        <span class="pill <?= h($stTone) ?>"><i class="fa-solid <?= h($stIcon) ?>"></i><?= h($stLabel) ?></span>
                        <span class="pill <?= h($dcTone) ?>"><i class="fa-solid <?= h($dcIcon) ?>"></i><?= h($dcLabel) ?></span>
                    </div>
                </div>
                <a class="progress-link" href="?page=my_application_detail&id=<?= (int)$pa['id'] ?>">View details &rarr;</a>
            </div>

            <!-- Application progress -->
            <div class="stepper" role="list" aria-label="Application progress">
                <?php foreach ($appSteps as $i => $label):
                    $cls = '';
                    $text = $label;
                    $icon = '';
                    if ($i === 2 && $status === 'rejected') { $cls = 'rejected'; $text = 'Rejected'; $icon = '<i class="fa-solid fa-xmark"></i>'; }
                    elseif ($i < $idx || ($i === $idx && in_array($status, ['approved', 'completed'], true))) {
                        $cls = 'done'; $icon = '<i class="fa-solid fa-check"></i>';
                        if ($i === 2 && $status !== 'rejected') $text = 'Approved';
                    }
                    elseif ($i === $idx) { $cls = 'current'; }
                ?>
                <div class="step <?= $cls ?>" role="listitem"><div class="step-dot"><?= $icon ?></div><?= h($text) ?></div>
                <?php endforeach; ?>
            </div>

            <!-- Document progress -->
            <?php if ($reqTot > 0): ?>
                <div class="bar gold" aria-hidden="true"><span style="width: <?= $docPct ?>%"></span></div>
                <div class="bar-label"><span>Required documents uploaded</span><span><?= $reqDone ?> / <?= $reqTot ?></span></div>
            <?php endif; ?>

            <!-- Mobility progress -->
            <div style="border-top: 1px solid var(--border-light); padding-top: 16px;">
                <div class="progress-head" style="margin-bottom: 10px;">
                    <div class="progress-title" style="font-size: .95rem;">Mobility</div>
                    <span class="pill <?= h($mob['tone']) ?>"><?= h($mob['label']) ?></span>
                </div>
                <div class="bar <?= $mob['tone'] === 'green' ? 'green' : '' ?>" aria-hidden="true"><span style="width: <?= (int)$mob['pct'] ?>%"></span></div>
                <div class="bar-label"><span><?= h($mob['note']) ?></span><span><?= (int)$mob['pct'] ?>%</span></div>
                <div class="progress-meta">
                    <?php if (!empty($pa['start_date']) && !empty($pa['end_date'])): ?>
                        <span><i class="fa-regular fa-calendar"></i> <strong><?= h(date('M j, Y', strtotime($pa['start_date']))) ?></strong> – <strong><?= h(date('M j, Y', strtotime($pa['end_date']))) ?></strong></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($travelInfo): ?>
        <div class="progress-card">
            <div class="progress-title" style="margin-bottom: 10px;">My Travel Details</div>
            <div class="progress-meta">
                <span><i class="fa-solid fa-location-dot"></i> <strong><?= h($travelInfo['destination'] ?? '—') ?></strong></span>
                <?php if (!empty($travelInfo['departure_date'])): ?><span><i class="fa-solid fa-plane-departure"></i> Departure: <strong><?= h(date('M j, Y', strtotime($travelInfo['departure_date']))) ?></strong></span><?php endif; ?>
                <?php if (!empty($travelInfo['return_date'])): ?><span><i class="fa-solid fa-plane-arrival"></i> Return: <strong><?= h(date('M j, Y', strtotime($travelInfo['return_date']))) ?></strong></span><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

</div>
