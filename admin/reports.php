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
$totalPartners = countRows($pdo, "SELECT COUNT(*) FROM partners");
$outboundStudentCount = countRows($pdo, "
    SELECT COUNT(*) FROM applications a
    LEFT JOIN users u ON u.id = a.applicant_id
    WHERE LOWER(a.mobility_type) LIKE 'outbound%'
      AND LOWER(COALESCE(NULLIF(u.account_type, ''), u.user_role, '')) IN ('student', 'applicant')
");
$outboundFacultyCount = countRows($pdo, "
    SELECT COUNT(*) FROM applications a
    LEFT JOIN users u ON u.id = a.applicant_id
    WHERE LOWER(a.mobility_type) LIKE 'outbound%'
      AND LOWER(COALESCE(NULLIF(u.account_type, ''), u.user_role, '')) = 'faculty'
");
$inboundTypeCounts = ['student' => 0, 'faculty' => 0];
$inboundCountsStmt = $pdo->query("
    SELECT participant_type, COUNT(*) AS total
    FROM inbound_participants
    GROUP BY participant_type
");
foreach ($inboundCountsStmt->fetchAll(PDO::FETCH_ASSOC) as $inboundRow) {
    $type = strtolower((string)$inboundRow['participant_type']);
    if (isset($inboundTypeCounts[$type])) {
        $inboundTypeCounts[$type] = (int)$inboundRow['total'];
    }
}
$programTypeBreakdown = $pdo->query("
    SELECT COALESCE(NULLIF(program_type, ''), 'Uncategorized') AS program_type, COUNT(*) AS total
    FROM programs
    GROUP BY COALESCE(NULLIF(program_type, ''), 'Uncategorized')
    ORDER BY program_type
")->fetchAll(PDO::FETCH_ASSOC);

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

    <!-- ============= SYSTEM OVERVIEW ============= -->
    <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-building"></i> Partners</div>
            <div class="stat-value"><?= number_format($totalPartners) ?></div>
            <div class="stat-delta"><span>Partner institutions</span></div>
        </div>
        <button type="button" class="stat-card" id="programBreakdownButton" aria-haspopup="dialog">
            <span class="stat-label"><i class="fa-solid fa-graduation-cap"></i> Programs</span>
            <span class="stat-value"><?= number_format($totalPrograms) ?></span>
            <span class="stat-delta"><span><?= number_format($activePrograms) ?> active · view by type</span></span>
        </button>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-plane-departure"></i> Outbound Students</div>
            <div class="stat-value"><?= number_format($outboundStudentCount) ?></div>
            <div class="stat-delta"><span>Applications</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-plane-arrival"></i> Inbound Students</div>
            <div class="stat-value"><?= number_format($inboundTypeCounts['student']) ?></div>
            <div class="stat-delta"><span>Admin-entered participants</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-user-tie"></i> Outbound Faculty</div>
            <div class="stat-value"><?= number_format($outboundFacultyCount) ?></div>
            <div class="stat-delta"><span>Applications</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-label"><i class="fa-solid fa-user-group"></i> Inbound Faculty</div>
            <div class="stat-value"><?= number_format($inboundTypeCounts['faculty']) ?></div>
            <div class="stat-delta"><span>Admin-entered participants</span></div>
        </div>
    </div>

    <!-- ============= MOBILITY STATUS ============= -->
    <div class="overview-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
        
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
                <span class="panel-title">Mobility Status</span>
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

    </div>

    <!-- ============= SYSTEM OVERVIEW + RECENT APPLICATIONS ============= -->
    <div class="bottom-grid">
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

        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Recent Applications</span>
                <a href="?page=applications" class="view-all-link">View all</a>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Applicant</th><th>Status</th><th>Submitted</th></tr></thead>
                    <tbody>
                    <?php if (!empty($recentApplications)): ?>
                        <?php foreach ($recentApplications as $a): ?>
                            <?php
                                $status = strtolower((string)($a['status'] ?? 'submitted'));
                                $badgeClass = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'danger' : ($status === 'under_review' ? 'info' : 'warning'));
                            ?>
                            <tr>
                                <td>
                                    <div class="applicant-cell">
                                        <span class="applicant-name"><?= h(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'Unknown') ?></span>
                                        <span class="applicant-email"><?= h($a['email'] ?? '') ?></span>
                                    </div>
                                </td>
                                <td><span class="badge <?= $badgeClass ?>"><?= h(ucwords(str_replace('_', ' ', $status))) ?></span></td>
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
    </div>

</div>

<div id="programTypeModal" role="dialog" aria-modal="true" aria-labelledby="programTypeTitle" hidden style="position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.45);align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:12px;padding:24px;width:min(100%,480px);max-height:80vh;overflow:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h2 id="programTypeTitle">Programs by Type</h2>
            <button type="button" id="closeProgramTypeModal" aria-label="Close">Close</button>
        </div>
        <ul>
            <?php foreach ($programTypeBreakdown as $breakdown): ?>
                <li><?= h($breakdown['program_type']) ?> — <?= number_format((int)$breakdown['total']) ?></li>
            <?php endforeach; ?>
            <?php if (!$programTypeBreakdown): ?><li>No programs recorded.</li><?php endif; ?>
        </ul>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const programTypeModal = document.getElementById('programTypeModal');
    document.getElementById('programBreakdownButton').addEventListener('click', () => {
        programTypeModal.hidden = false;
        programTypeModal.style.display = 'flex';
    });
    document.getElementById('closeProgramTypeModal').addEventListener('click', () => {
        programTypeModal.hidden = true;
        programTypeModal.style.display = 'none';
    });
    programTypeModal.addEventListener('click', event => {
        if (event.target === programTypeModal) {
            programTypeModal.hidden = true;
            programTypeModal.style.display = 'none';
        }
    });
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