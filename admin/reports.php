<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin(); // Ensure user is logged in

$pdo = db();

/* =========================
   HELPERS
========================= */
if (!function_exists('h')) {
    function h($v){
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function countRows(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('countRows failed: ' . $e->getMessage());
        return 0;
    }
}

/* =========================
   MONTHLY APPLICATIONS (Last 12 Months)
========================= */
$monthLabels = [];
$monthCounts = [];

try {
    $monthlyStmt = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
        FROM applications
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY ym
    ");
    
    $byMonth = [];
    foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byMonth[$row['ym']] = (int)$row['total'];
    }

    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $monthLabels[] = date('M', strtotime($ym . '-01'));
        $monthCounts[] = $byMonth[$ym] ?? 0;
    }
} catch (PDOException $e) {
    error_log('monthly chart failed: ' . $e->getMessage());
}

/* =========================
   HEADLINE COUNTS
========================= */
// Assuming your users table uses 'role' column (if it uses 'user_role', change it back)
$totalApplicants = countRows($pdo, "SELECT COUNT(*) FROM users WHERE role = 'applicant' OR user_role = 'applicant'");

$totalPrograms     = countRows($pdo, "SELECT COUNT(*) FROM programs");
$activePrograms    = countRows($pdo, "SELECT COUNT(*) FROM programs WHERE status = 'Active'");
$upcomingPrograms  = countRows($pdo, "SELECT COUNT(*) FROM programs WHERE status = 'Upcoming'");
$completedPrograms = countRows($pdo, "SELECT COUNT(*) FROM programs WHERE status = 'Completed'");

// Status buckets
$pending  = countRows($pdo, "SELECT COUNT(*) FROM applications WHERE status IN ('submitted', 'under_review')");
$approved = countRows($pdo, "SELECT COUNT(*) FROM applications WHERE status = 'approved'");
$rejected = countRows($pdo, "SELECT COUNT(*) FROM applications WHERE status = 'rejected'");

$totalUsers   = countRows($pdo, "SELECT COUNT(*) FROM users");
$approvalRate = ($approved + $rejected) > 0
    ? round(($approved / ($approved + $rejected)) * 100)
    : 0;

$totalApplications = countRows($pdo, "SELECT COUNT(*) FROM applications");
$pendingPct  = $totalApplications > 0 ? round($pending  / $totalApplications * 100) : 0;
$approvedPct = $totalApplications > 0 ? round($approved / $totalApplications * 100) : 0;
$rejectedPct = $totalApplications > 0 ? round($rejected / $totalApplications * 100) : 0;

/* =========================
   RECENT APPLICATIONS
========================= */
$recentApplications = [];
try {
    $recentStmt = $pdo->query("
        SELECT a.*, u.first_name, u.last_name, u.email
        FROM applications a
        LEFT JOIN users u ON u.id = a.applicant_id
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $recentApplications = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$firstName = $_SESSION['first_name'] ?? 'Administrator';
?>

<!-- The UI remains completely unchanged -->
<div class="db-wrap">

    <!-- ============= PAGE HEAD ============= -->
    <div class="page-head">
        <div>
            <div class="page-eyebrow">Overview</div>
            <h1 class="page-title">Welcome back, <?= h($firstName) ?></h1>
            <p class="page-sub">A snapshot of applications, programs, and activity across the system.</p>
        </div>
        <a href="?page=applications" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Review Applications
        </a>
    </div>

    <!-- ============= ROW 1: KPI CARDS ============= -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-users"></i> Total Applicants</div>
            <div class="stat-value"><?= number_format($totalApplicants) ?></div>
            <div class="stat-delta up"><i class="fa-solid fa-user-plus"></i> <span>Registered accounts</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-graduation-cap"></i> Active Programs</div>
            <div class="stat-value"><?= number_format($activePrograms) ?></div>
            <div class="stat-delta"><span class="delta-label"><?= $totalPrograms ?> total · <?= $upcomingPrograms ?> upcoming</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-clock"></i> Pending Applications</div>
            <div class="stat-value pending"><?= number_format($pending) ?></div>
            <div class="stat-delta"><span class="delta-label"><?= $pendingPct ?>% of all applications</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-chart-line"></i> Approval Rate</div>
            <div class="stat-value"><?= $approvalRate ?>%</div>
            <div class="stat-delta up"><i class="fa-solid fa-circle-check"></i> <span><?= number_format($approved) ?> approved</span></div>
        </div>
    </div>

    <!-- ============= ROW 2: CHARTS + SIDEBAR ============= -->
    <div class="overview-grid">
        
        <!-- Line Chart -->
        <div class="panel linechart-panel">
            <div class="panel-header">
                <span class="panel-title">Applications Per Month</span>
                <a href="?page=applications" class="view-all-link">View all</a>
            </div>
            <div class="panel-body" style="height: 280px;">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        <!-- Donut Chart -->
        <div class="panel donutchart-panel">
            <div class="panel-header">
                <span class="panel-title">Application Status</span>
                <a href="?page=applications" class="view-all-link">View all</a>
            </div>
            <div class="panel-body">
                <div class="donut-wrap">
                    <div class="donut-canvas-wrap">
                        <canvas id="doughnutChart"></canvas>
                        <div class="donut-center">
                            <span class="donut-total"><?= number_format($totalApplications) ?></span>
                            <span class="donut-total-label">Total</span>
                        </div>
                    </div>
                    <ul class="donut-legend">
                        <li>
                            <span class="legend-dot success"></span>
                            <span class="legend-name">Approved</span>
                            <span class="legend-count"><?= number_format($approved) ?></span>
                            <span class="legend-pct">(<?= $approvedPct ?>%)</span>
                        </li>
                        <li>
                            <span class="legend-dot warning"></span>
                            <span class="legend-name">Pending</span>
                            <span class="legend-count"><?= number_format($pending) ?></span>
                            <span class="legend-pct">(<?= $pendingPct ?>%)</span>
                        </li>
                        <li>
                            <span class="legend-dot danger"></span>
                            <span class="legend-name">Rejected</span>
                            <span class="legend-count"><?= number_format($rejected) ?></span>
                            <span class="legend-pct">(<?= $rejectedPct ?>%)</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Side Column -->
        <div class="side-col">
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Quick Actions</span>
                </div>
                <div class="quick-actions-grid">
                    <a class="quick-action" href="?page=programs">
                        <span class="qa-icon material-symbols-outlined">add_circle</span>
                        <span class="qa-text">
                            <span class="qa-title">New Program</span>
                            <span class="qa-sub">Create a mobility program</span>
                        </span>
                    </a>
                    <a class="quick-action" href="?page=users">
                        <span class="qa-icon material-symbols-outlined">person_add</span>
                        <span class="qa-text">
                            <span class="qa-title">Add User</span>
                            <span class="qa-sub">Invite an administrator</span>
                        </span>
                    </a>
                    <a class="quick-action" href="?page=announcements">
                        <span class="qa-icon material-symbols-outlined">campaign</span>
                        <span class="qa-text">
                            <span class="qa-title">Announcement</span>
                            <span class="qa-sub">Broadcast to applicants</span>
                        </span>
                    </a>
                    <!-- Fixed link from ?page=reports to ?page=home -->
                    <a class="quick-action" href="?page=home">
                        <span class="qa-icon material-symbols-outlined">description</span>
                        <span class="qa-text">
                            <span class="qa-title">Reports</span>
                            <span class="qa-sub">Export system data</span>
                        </span>
                    </a>
                </div>
            </div>
            
            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Programs Overview</span>
                </div>
                <div class="panel-body" style="padding-top: 10px;">
                     <ul class="deadline-list">
                         <li><span class="deadline-name">Active Programs</span><span class="deadline-date"><?= $activePrograms ?></span></li>
                         <li><span class="deadline-name">Upcoming Programs</span><span class="deadline-date"><?= $upcomingPrograms ?></span></li>
                         <li><span class="deadline-name">Completed Programs</span><span class="deadline-date"><?= $completedPrograms ?></span></li>
                     </ul>
                </div>
            </div>
        </div>

    </div>

    <!-- ============= ROW 3: RECENT APPS + SYSTEM ============= -->
    <div class="bottom-grid">
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Recent Applications</span>
                <a href="?page=applications" class="view-all-link">View all</a>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($recentApplications)): ?>
                        <?php foreach ($recentApplications as $a): ?>
                            <tr>
                                <td>
                                    <div class="applicant-cell">
                                        <span class="applicant-name">
                                            <?= h(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'Unknown') ?>
                                        </span>
                                        <span class="applicant-email"><?= h($a['email'] ?? '') ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        // Replaced PHP 8 'match' with switch for backward compatibility
                                        $status = strtolower($a['status'] ?? 'submitted');
                                        $badgeClass = 'neutral';
                                        $label = ucfirst($status);
                                        
                                        switch($status) {
                                            case 'approved':
                                                $badgeClass = 'success'; $label = 'Approved'; break;
                                            case 'rejected':
                                                $badgeClass = 'danger'; $label = 'Rejected'; break;
                                            case 'under_review':
                                                $badgeClass = 'info'; $label = 'Under Review'; break;
                                            case 'submitted':
                                                $badgeClass = 'warning'; $label = 'Submitted'; break;
                                        }
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= h($label) ?></span>
                                </td>
                                <td><?= h(date('M j, Y', strtotime($a['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="text-center" style="padding:20px;">No applications yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">System Overview</span>
            </div>
            <div class="panel-body">
                <div class="system-overview-grid">
                    <div class="so-item">
                        <span class="so-icon material-symbols-outlined" style="background:var(--bg);color:var(--text-soft);display:flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);">group</span>
                        <div class="so-text">
                            <span class="so-label">Total Users</span>
                            <span class="so-value"><?= number_format($totalUsers) ?></span>
                        </div>
                    </div>
                    <div class="so-item">
                        <span class="so-icon material-symbols-outlined" style="background:var(--bg);color:var(--text-soft);display:flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);">school</span>
                        <div class="so-text">
                            <span class="so-label">Total Programs</span>
                            <span class="so-value"><?= number_format($totalPrograms) ?></span>
                        </div>
                    </div>
                    <div class="so-item">
                        <span class="so-icon material-symbols-outlined" style="background:var(--bg);color:var(--success);display:flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);">verified</span>
                        <div class="so-text">
                            <span class="so-label">Approved</span>
                            <span class="so-value so-good"><?= number_format($approved) ?></span>
                        </div>
                    </div>
                    <div class="so-item">
                        <span class="so-icon material-symbols-outlined" style="background:var(--bg);color:var(--danger);display:flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);">cancel</span>
                        <div class="so-text">
                            <span class="so-label">Rejected</span>
                            <span class="so-value"><?= number_format($rejected) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    Chart.defaults.font.family = "'Oswald', sans-serif";
    Chart.defaults.color = '#64748b';

    new Chart(document.getElementById('lineChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($monthLabels) ?>,
            datasets: [{
                label: 'Applications',
                data: <?= json_encode($monthCounts) ?>,
                borderColor: '#005fd5',
                backgroundColor: 'rgba(0, 95, 213, 0.08)',
                fill: true,
                tension: 0.4,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,0.04)' } }
            }
        }
    });

    new Chart(document.getElementById('doughnutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Approved', 'Pending', 'Rejected'],
            datasets: [{
                data: [<?= (int)$approved ?>, <?= (int)$pending ?>, <?= (int)$rejected ?>],
                backgroundColor: ['#1a9c6b', '#c47a00', '#c40000'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { display: false } }
        }
    });
</script>