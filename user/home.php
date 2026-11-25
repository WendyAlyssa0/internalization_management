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

    /* Charts Grid */
    .charts-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }

    .chart-card {
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 24px;
        box-shadow: var(--shadow-sm);
        display: flex;
        flex-direction: column;
    }

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .chart-header h3 {
        font-family: var(--font-heading);
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--psu-navy);
    }

    .chart-container {
        position: relative;
        flex: 1;
        min-height: 240px;
        width: 100%;
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

    @media (max-width: 1024px) {
        .charts-grid {
            grid-template-columns: 1fr;
        }
    }
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
    <div class="welcome-header">
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
    <div class="stat-grid">
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

    <!-- Charts Section -->
    <div class="charts-grid">
        <!-- Bar Chart: Application Statuses -->
        <div class="chart-card">
            <div class="chart-header">
                <h3>Application Status Overview</h3>
                <i class="fa-solid fa-chart-simple" style="color: var(--text-light);"></i>
            </div>
            <div class="chart-container">
                <canvas id="statusBarChart"></canvas>
            </div>
        </div>

        <!-- Doughnut Chart: Total Progress -->
        <div class="chart-card">
            <div class="chart-header">
                <h3>Application Distribution</h3>
                <i class="fa-solid fa-chart-pie" style="color: var(--text-light);"></i>
            </div>
            <div class="chart-container">
                <canvas id="statusDoughnutChart"></canvas>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get PHP data safely
    const statusCounts = <?= json_encode([
        'pending'      => $pendingCount,
        'under_review' => $underReviewCount,
        'approved'     => (int)($statusCounts['approved'] ?? 0),
        'rejected'     => (int)($statusCounts['rejected'] ?? 0),
    ]) ?>;

    // Common Font Settings (Matched to Dashboard)
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';
    Chart.defaults.font.size = 12;

    // --- Bar Chart ---
    const barCtx = document.getElementById('statusBarChart').getContext('2d');
    new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: ['Pending', 'Under Review', 'Approved', 'Rejected'],
            datasets: [{
                label: 'Applications',
                data: [
                    statusCounts.pending, 
                    statusCounts.under_review, 
                    statusCounts.approved, 
                    statusCounts.rejected
                ],
                backgroundColor: [
                    '#e4b134', // Pending (Gold)
                    '#2563eb', // Under Review (Blue)
                    '#16a34a', // Approved (Green)
                    '#dc2626'  // Rejected (Red)
                ],
                borderRadius: 6,
                barThickness: 32
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false } 
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0, color: '#94a3b8' },
                    grid: { color: '#f1f5f9', drawBorder: false }
                },
                x: {
                    ticks: { color: '#64748b', font: { weight: '600' } },
                    grid: { display: false, drawBorder: false }
                }
            }
        }
    });

    // --- Doughnut Chart ---
    const doughnutCtx = document.getElementById('statusDoughnutChart').getContext('2d');
    new Chart(doughnutCtx, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Under Review', 'Approved', 'Rejected'],
            datasets: [{
                data: [
                    statusCounts.pending, 
                    statusCounts.under_review, 
                    statusCounts.approved, 
                    statusCounts.rejected
                ],
                backgroundColor: [
                    '#e4b134',
                    '#2563eb',
                    '#16a34a',
                    '#dc2626'
                ],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 20,
                        font: { family: "'Inter', sans-serif", size: 12 }
                    }
                }
            }
        }
    });
});
</script>