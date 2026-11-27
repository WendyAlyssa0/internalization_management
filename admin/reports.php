<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   ACCESS CONTROL
   dashboard.php only calls requireLogin(), so guard here.
========================= */
if (!(canManageActivities() || isApprover() || isAdmin())) {
    echo '<div class="alert alert-info" style="margin:16px 0;padding:12px 16px;border-radius:8px;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;">You do not have access to this dashboard.</div>';
    return;
}

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* ===================================================
   S34: FETCH DASHBOARD METRICS
=================================================== */

// 1. Total Partners
$totalPartners = (int)$pdo->query("SELECT COUNT(*) FROM partners")->fetchColumn();

// 2. Total Programs + Breakdown by Type
$programTypes = $pdo->query("
    SELECT COALESCE(NULLIF(program_type, ''), 'Uncategorized') AS type_name, COUNT(*) AS cnt
    FROM programs
    GROUP BY type_name
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);
$totalPrograms = (int)array_sum(array_column($programTypes, 'cnt'));

// 3. Mobility counts (approved applications only)
if (!function_exists('getMobilityCount')) {
    function getMobilityCount(PDO $pdo, string $accountType, string $direction): int {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM applications a
            INNER JOIN users u ON a.applicant_id = u.id
            WHERE LOWER(a.status) = 'approved'
              AND LOWER(u.account_type) = ?
              AND LOWER(a.mobility_type) = ?
        ");
        $stmt->execute([strtolower($accountType), strtolower($direction)]);
        return (int)$stmt->fetchColumn();
    }
}

$outboundStudents = getMobilityCount($pdo, 'student', 'outbound');
$inboundStudents  = getMobilityCount($pdo, 'student', 'inbound');
$outboundFaculty  = getMobilityCount($pdo, 'faculty', 'outbound');
$inboundFaculty   = getMobilityCount($pdo, 'faculty', 'inbound');

// 4. Mobility Status
$mobilityStatuses = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM applications
    GROUP BY status
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 5. Recent Applications
$recentApplications = $pdo->query("
    SELECT a.id, a.created_at, a.status, a.mobility_type,
           CONCAT(u.first_name, ' ', u.last_name) AS applicant_name,
           p.program_name
    FROM applications a
    LEFT JOIN users u ON a.applicant_id = u.id
    LEFT JOIN programs p ON a.program_id = p.id
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$canDownloadReports = (canManageActivities() || isAdmin());
?>

<style>
    /* S34 Dashboard Cards Layout */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        gap: 16px;
        position: relative;
        overflow: hidden;
    }
    .stat-icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 24px; flex-shrink: 0;
    }
    .stat-icon--blue   { background: #e0f2fe; color: #0284c7; }
    .stat-icon--green  { background: #dcfce7; color: #16a34a; }
    .stat-icon--purple { background: #f3e8ff; color: #7e22ce; }
    .stat-icon--orange { background: #ffedd5; color: #ea580c; }
    .stat-icon--cyan   { background: #cffafe; color: #0891b2; }
    .stat-icon--pink   { background: #fce7f3; color: #db2777; }

    .stat-info h4 { margin: 0 0 4px; font-size: 0.85rem; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-info h2 { margin: 0; font-size: 1.8rem; font-weight: 700; color: #0f172a; }
    .stat-info a { font-size: 0.8rem; color: #2563eb; text-decoration: none; display: inline-block; margin-top: 8px; font-weight: 600; cursor: pointer; }
    .stat-info a:hover { text-decoration: underline; }

    /* S34 Bottom Grid */
    .dashboard-bottom {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
    }
    @media (max-width: 900px) { .dashboard-bottom { grid-template-columns: 1fr; } }

    .panel-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .panel-card h3 {
        margin: 0 0 16px;
        font-size: 1rem;
        font-weight: 600;
        color: #0f172a;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }

    .status-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; }
    .status-row:last-child { border-bottom: none; }
    .status-label { color: #475569; font-weight: 500; text-transform: capitalize; }
    .status-count { font-weight: 700; color: #0f172a; background: #f1f5f9; padding: 2px 10px; border-radius: 20px; font-size: 0.8rem; }

    .recent-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .recent-table th { text-align: left; padding: 10px 0; color: #64748b; font-weight: 600; border-bottom: 1px solid #e2e8f0; font-size: 0.8rem; text-transform: uppercase; }
    .recent-table td { padding: 12px 0; border-bottom: 1px solid #f1f5f9; color: #334155; }

    .badge-status { font-size: 0.75rem; padding: 2px 8px; border-radius: 12px; font-weight: 600; text-transform: capitalize; background: #f1f5f9; color: #475569; }
    .badge-status--approved  { background: #dcfce7; color: #15803d; }
    .badge-status--pending   { background: #fef9c3; color: #a16207; }
    .badge-status--rejected  { background: #fee2e2; color: #b91c1c; }
    .badge-status--submitted { background: #e0f2fe; color: #0369a1; }

    /* S35 Accreditation Reports Grid */
    .accreditation-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-top: 16px;
    }
    .accreditation-card {
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 24px;
        text-align: center;
        background: #f8fafc;
        transition: all 0.2s;
    }
    .accreditation-card:hover { border-color: #2563eb; background: #eff6ff; }
    .accreditation-card i { font-size: 2rem; color: #94a3b8; margin-bottom: 12px; }
    .accreditation-card h4 { margin: 0 0 8px; font-size: 1rem; color: #0f172a; }
    .accreditation-card p { margin: 0 0 16px; font-size: 0.8rem; color: #64748b; }
    .accreditation-card .btn-download {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 16px; border-radius: 6px; font-size: 0.8rem;
        font-weight: 600; color: #fff; background: #2563eb; text-decoration: none;
    }
    .accreditation-card .btn-download:hover { background: #1d4ed8; }

    /* Modal Styles */
    .modal-backdrop {
        display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px);
        z-index: 9999; align-items: center; justify-content: center; padding: 20px;
    }
    .modal-backdrop.open { display: flex; }
    .modal-box {
        background: #fff; border-radius: 12px; width: 100%; max-width: 440px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); padding: 24px;
    }
    .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px; }
    .modal-header h3 { margin: 0; font-size: 1.1rem; color: #0f172a; }
    .modal-close { background: none; border: none; font-size: 1.2rem; color: #64748b; cursor: pointer; }
</style>

<div class="page-header">
    <h2>Dashboard Overview</h2>
    <p>Welcome back, <?= h($_SESSION['first_name'] ?? 'Administrator') ?>. Here is your mobility snapshot.</p>
</div>

<!-- S34: Top Stat Cards -->
<div class="dashboard-grid">
    <div class="stat-card accent-blue">
        <div class="stat-icon stat-icon--blue"><i class="fa-solid fa-handshake"></i></div>
        <div class="stat-info">
            <h4>Total Partners</h4>
            <h2><?= number_format($totalPartners) ?></h2>
            <a href="?page=partners">View all partners <i class="fa fa-arrow-right"></i></a>
        </div>
    </div>

    <div class="stat-card accent-green">
        <div class="stat-icon stat-icon--green"><i class="fa-solid fa-graduation-cap"></i></div>
        <div class="stat-info">
            <h4>Total Programs</h4>
            <h2><?= number_format($totalPrograms) ?></h2>
            <a href="javascript:void(0)" onclick="openProgramModal()"><i class="fa fa-chart-pie"></i> Breakdown by type</a>
        </div>
    </div>

    <div class="stat-card accent-purple">
        <div class="stat-icon stat-icon--purple"><i class="fa-solid fa-plane-departure"></i></div>
        <div class="stat-info">
            <h4>Outbound Students</h4>
            <h2><?= number_format($outboundStudents) ?></h2>
        </div>
    </div>

    <div class="stat-card accent-teal">
        <div class="stat-icon stat-icon--cyan"><i class="fa-solid fa-plane-arrival"></i></div>
        <div class="stat-info">
            <h4>Inbound Students</h4>
            <h2><?= number_format($inboundStudents) ?></h2>
        </div>
    </div>

    <div class="stat-card accent-orange">
        <div class="stat-icon stat-icon--orange"><i class="fa-solid fa-chalkboard-user"></i></div>
        <div class="stat-info">
            <h4>Outbound Faculty</h4>
            <h2><?= number_format($outboundFaculty) ?></h2>
        </div>
    </div>

    <div class="stat-card" style="--accent:#db2777;">
        <div class="stat-icon stat-icon--pink"><i class="fa-solid fa-user-tie"></i></div>
        <div class="stat-info">
            <h4>Inbound Faculty</h4>
            <h2><?= number_format($inboundFaculty) ?></h2>
        </div>
    </div>
</div>

<!-- U13: Recent applications directly under the overview cards -->
<div class="panel-card" style="margin-bottom:20px;">
    <h3>Recent Applications</h3>
    <?php if (empty($recentApplications)): ?>
        <p style="color:#94a3b8; font-size:0.875rem;">No recent applications found.</p>
    <?php else: ?>
        <table class="recent-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Program</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentApplications as $app): ?>
                    <?php $statusClass = 'badge-status--' . strtolower(str_replace(' ', '_', (string)$app['status'])); ?>
                    <tr>
                        <td><strong><?= h($app['applicant_name'] ?? 'Unknown') ?></strong></td>
                        <td><?= h($app['program_name'] ?? 'N/A') ?></td>
                        <td><span class="badge-status <?= h($statusClass) ?>"><?= h(str_replace('_', ' ', (string)$app['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align:right; margin-top:12px;">
            <a href="?page=applications" style="font-size:0.8rem; color:#2563eb; text-decoration:none; font-weight:600;">View all applications <i class="fa fa-arrow-right"></i></a>
        </div>
    <?php endif; ?>
</div>

<!-- Mobility status -->
<div class="dashboard-bottom">
    <div class="panel-card">
        <h3>Mobility Status</h3>
        <?php if (empty($mobilityStatuses)): ?>
            <p style="color:#94a3b8; font-size:0.875rem;">No application data available yet.</p>
        <?php else: ?>
            <?php foreach ($mobilityStatuses as $ms): ?>
                <div class="status-row">
                    <span class="status-label"><?= h(str_replace('_', ' ', (string)$ms['status'])) ?></span>
                    <span class="status-count"><?= h($ms['cnt']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- S35: Accreditation Reports -->
<div class="panel-card" style="margin-top: 20px;">
    <h3>Accreditation &amp; Documentation Reports</h3>
    <p style="margin:0 0 16px; font-size:0.875rem; color:#64748b;">Download required documentation for institutional accreditation, evaluation, and reporting.</p>

    <?php if (!$canDownloadReports): ?>
        <p style="color:#94a3b8; font-size:0.875rem;">Report downloads are available to coordinators and administrators.</p>
    <?php else: ?>
    <div class="accreditation-grid">
        <div class="accreditation-card">
            <i class="fa-solid fa-file-invoice"></i>
            <h4>Evaluation Reports</h4>
            <p>Annual performance and mobility evaluations.</p>
            <a href="report_download.php?type=evaluation" class="btn-download"><i class="fa fa-download"></i> Download PDF</a>
        </div>

        <div class="accreditation-card">
            <i class="fa-solid fa-book-open"></i>
            <h4>Narrative Reports</h4>
            <p>Descriptive summaries of internationalization efforts.</p>
            <a href="report_download.php?type=narrative" class="btn-download"><i class="fa fa-download"></i> Download PDF</a>
        </div>

        <div class="accreditation-card">
            <i class="fa-solid fa-images"></i>
            <h4>Pictures / Documentation</h4>
            <p>Visual evidence of programs and events.</p>
            <a href="report_download.php?type=pictures" class="btn-download"><i class="fa fa-download"></i> Download PDF</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- PROGRAM BREAKDOWN MODAL (S34) -->
<div id="programModal" class="modal-backdrop" onclick="closeProgramModalOutside(event)">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Program Breakdown by Type</h3>
            <button class="modal-close" onclick="closeProgramModal()" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div>
            <?php if (empty($programTypes)): ?>
                <p style="color:#94a3b8; font-size:0.875rem;">No program data available.</p>
            <?php else: ?>
                <?php foreach ($programTypes as $pt): ?>
                    <div class="status-row">
                        <span class="status-label"><?= h($pt['type_name']) ?></span>
                        <span class="status-count"><?= h($pt['cnt']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="text-align:right; margin-top:20px;">
            <button class="btn-cancel" onclick="closeProgramModal()" style="padding:8px 16px; border-radius:6px; border:1px solid #cbd5e1; background:#fff; cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
    function openProgramModal() {
        document.getElementById('programModal').classList.add('open');
    }
    function closeProgramModal() {
        document.getElementById('programModal').classList.remove('open');
    }
    function closeProgramModalOutside(e) {
        if (e.target.id === 'programModal') closeProgramModal();
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeProgramModal();
    });
</script>