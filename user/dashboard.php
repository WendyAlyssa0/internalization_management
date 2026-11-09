link rel="stylesheet" href="../asset/css/dashboard.css">
<?php
if (!isset($userId)) {
    session_start();
    $userId = $_SESSION['user_id'] ?? 0;
}
require_once __DIR__ . '/../config/db.php';
$pdo = db();

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$applications = [];
$notifications = [];
$latestApplication = null;
$dbError = null;
$counts = ['all' => 0, 'submitted' => 0, 'under_review' => 0, 'approved' => 0, 'rejected' => 0, 'completed' => 0];

try {
    // Fetch applications
    $stmt = $pdo->prepare("
        SELECT
            a.id, a.status, a.created_at, a.reviewed_at, a.approval_date,
            a.documents_status, a.mobility_type,
            p.program_name, p.start_date, p.end_date, p.description,
            pr.institution_name AS partner,
            c.country_name AS country
        FROM applications a
        LEFT JOIN programs p ON p.id = a.program_id
        LEFT JOIN partners pr ON pr.id = p.partner_id
        LEFT JOIN countries c ON c.id = p.country_id
        WHERE a.applicant_id = ?
        ORDER BY a.id DESC
    ");
    $stmt->execute([$userId]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count by status
    $counts['all'] = count($applications);
    foreach ($applications as $app) {
        $s = strtolower($app['status']);
        if (isset($counts[$s])) $counts[$s]++;
    }
    $latestApplication = $applications[0] ?? null;

    // Recent notifications
    $nStmt = $pdo->prepare("
        SELECT title, message, is_read, created_at, type
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $nStmt->execute([$userId]);
    $notifications = $nStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log('Dashboard error: ' . $ex->getMessage());
    $dbError = 'Some dashboard data could not be loaded.';
}

/* Donut chart gradient — calculated from real counts */
$total = $counts['all'];
$donutGradient = 'conic-gradient(#e8edf5 0deg 360deg)';

if ($total > 0) {
    $segments = [
        ['count' => $counts['submitted'],    'color' => '#d4af37'], // gold
        ['count' => $counts['under_review'], 'color' => '#1d4ed8'], // blue
        ['count' => $counts['approved'],     'color' => '#16a34a'], // green
        ['count' => $counts['rejected'],     'color' => '#dc2626'], // red
    ];
    $stops = [];
    $cursor = 0;
    foreach ($segments as $seg) {
        if ($seg['count'] > 0) {
            $start = $cursor;
            $cursor += ($seg['count'] / $total) * 360;
            $stops[] = "{$seg['color']} {$start}deg {$cursor}deg";
        }
    }
    $donutGradient = 'conic-gradient(' . implode(', ', $stops) . ')';
}

/* Progress step for latest application */
$progressMap = ['submitted' => 1, 'under_review' => 2, 'approved' => 3, 'completed' => 4, 'rejected' => 3];
$latestProgress = $latestApplication ? ($progressMap[strtolower($latestApplication['status'])] ?? 1) : 0;
$stepNames = ['Submitted', 'Review', 'Decision', 'Complete'];
$isRejected = $latestApplication && strtolower($latestApplication['status']) === 'rejected';
?>

<div class="dash-wrap">

    <?php if ($dbError): ?>
        <div style="background:#fef3c7; color:#92400e; padding:14px 18px; border-radius:10px; margin-bottom:20px; font-size:13px;">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= e($dbError) ?>
        </div>
    <?php endif; ?>

    <!-- GREETING -->
    <div class="dash-greeting">
        <div>
            <h1>Welcome back, <?= e($firstName) ?>!</h1>
            <p>Here's an overview of your international mobility journey.</p>
        </div>
        <div class="greeting-date">
            <i class="fa-regular fa-calendar"></i>
            <?= date('l, F j, Y') ?>
        </div>
    </div>

    <!-- STATS -->
    <div class="stat-strip">
        <a href="?page=my_applications" class="stat-pill">
            <span class="pill-icon gold"><i class="fa-solid fa-file-lines"></i></span>
            <div><b><?= $counts['submitted'] ?></b><small>Submitted</small></div>
        </a>
        <a href="?page=my_applications" class="stat-pill">
            <span class="pill-icon blue"><i class="fa-solid fa-clock"></i></span>
            <div><b><?= $counts['under_review'] ?></b><small>Under Review</small></div>
        </a>
        <a href="?page=my_applications" class="stat-pill">
            <span class="pill-icon green"><i class="fa-solid fa-circle-check"></i></span>
            <div><b><?= $counts['approved'] ?></b><small>Approved</small></div>
        </a>
        <a href="?page=my_applications" class="stat-pill">
            <span class="pill-icon red"><i class="fa-solid fa-circle-xmark"></i></span>
            <div><b><?= $counts['rejected'] ?></b><small>Rejected</small></div>
        </a>
    </div>

    <!-- MAIN GRID -->
    <div class="dash-grid">

        <!-- LEFT: Latest Application + Activity -->
        <div>
            <!-- Latest Application -->
            <div class="panel" style="margin-bottom:20px;">
                <div class="panel-head">
                    <h2>Latest Application</h2>
                    <p>Your most recent submission</p>
                </div>

                <?php if ($latestApplication): ?>
                    <div class="latest-app">
                        <h3><?= e($latestApplication['program_name'] ?? 'Program') ?></h3>
                        <div class="meta">
                            <span><i class="fa-solid fa-building"></i> <?= e($latestApplication['partner'] ?? 'TBD') ?></span>
                            <span><i class="fa-solid fa-location-dot"></i> <?= e($latestApplication['country'] ?? 'TBD') ?></span>
                            <span><i class="fa-solid fa-calendar"></i> <?= date('M d, Y', strtotime($latestApplication['created_at'])) ?></span>
                        </div>
                    </div>

                    <div class="steps">
                        <?php for ($i = 1; $i <= 4; $i++):
                            $cls = '';
                            if ($isRejected && $i == 3) $cls = 'rejected';
                            elseif ($i < $latestProgress) $cls = 'done';
                            elseif ($i == $latestProgress) $cls = 'current';
                        ?>
                            <div class="step <?= $cls ?>"><?= $stepNames[$i - 1] ?></div>
                        <?php endfor; ?>
                    </div>

                    <div style="margin-top:20px;">
                        <a href="?page=my_applications" class="btn-primary">
                            View All Applications <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fa-regular fa-compass"></i>
                        No applications yet. Start exploring programs to get started.
                    </div>
                    <div style="text-align:center; margin-top:16px;">
                        <a href="?page=available_programs" class="btn-primary">
                            <i class="fa-solid fa-search"></i> Browse Programs
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Activity -->
            <div class="panel">
                <div class="panel-head">
                    <h2>Recent Activity</h2>
                    <p>Latest updates on your account</p>
                </div>

                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $n): ?>
                        <div class="activity-item <?= !$n['is_read'] ? 'unread' : '' ?>">
                            <div class="activity-icon">
                                <i class="fa-solid fa-bell"></i>
                            </div>
                            <div>
                                <b><?= e($n['title']) ?></b>
                                <small><?= e($n['message']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">
                        <i class="fa-regular fa-bell-slash"></i>
                        You're all caught up.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Donut Chart -->
        <div>
            <div class="panel">
                <div class="panel-head">
                    <h2>Application Breakdown</h2>
                    <p>Status distribution of all your applications</p>
                </div>

                <?php if ($total > 0): ?>
                    <div class="chart-wrap">
                        <div class="donut">
                            <div class="donut-center">
                                <?= $total ?>
                                <small>Total</small>
                            </div>
                        </div>
                        <div class="legend">
                            <div class="legend-row">
                                <i style="background:#d4af37"></i>
                                <span>Submitted</span>
                                <b><?= $counts['submitted'] ?></b>
                            </div>
                            <div class="legend-row">
                                <i style="background:#1d4ed8"></i>
                                <span>Under Review</span>
                                <b><?= $counts['under_review'] ?></b>
                            </div>
                            <div class="legend-row">
                                <i style="background:#16a34a"></i>
                                <span>Approved</span>
                                <b><?= $counts['approved'] ?></b>
                            </div>
                            <div class="legend-row">
                                <i style="background:#dc2626"></i>
                                <span>Rejected</span>
                                <b><?= $counts['rejected'] ?></b>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fa-solid fa-chart-pie"></i>
                        No application data yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>