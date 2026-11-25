<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

/* =========================
   AUTHORIZATION (Based on DFD)
========================= */
$role = strtolower(trim($_SESSION['role'] ?? 'applicant'));
$allowedRoles = [
    'super_admin', 'super admin',
    'admin',
    'applicant', 'student', 'faculty',
    'campus_coordinator', 'campus coordinator',
    'ovplia_staff', 'ovplia staff',
    'approving_official', 'approving official',
    'bor_secretary', 'bor secretary'
];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

/* =========================
   ROLE FLAGS
========================= */
$isApplicant   = in_array($role, ['applicant', 'student', 'faculty'], true);
$isCoordinator = in_array($role, ['campus_coordinator', 'campus coordinator', 'ovplia_staff', 'ovplia staff'], true);
$isApprover    = in_array($role, ['approving_official', 'approving official', 'bor_secretary', 'bor secretary'], true);
$isAdmin       = in_array($role, ['admin', 'super_admin', 'super admin'], true);

/* =========================
   FETCH ACTIVITY DATA (SCOPED)
========================= */
$sql = "SELECT am.*, s.first_name, s.last_name
        FROM activity_monitoring am
        LEFT JOIN students s ON s.id = am.student_id";
$params = [];

if ($isApplicant) {
    // Applicants see only their own records
    $sql .= " WHERE am.student_id = :sid";
    $params['sid'] = $userId;
}
// Admins, Coordinators, Approvers see ALL records (no WHERE clause)

$sql .= " ORDER BY am.created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   HELPERS
========================= */
function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getStatus(int $progress, ?string $status): string {
    if ($status === 'completed' || $progress >= 100) return 'completed';
    if ($status === 'delayed') return 'delayed';
    return 'ongoing';
}

function studentName(array $a): string {
    $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
    return $name !== '' ? $name : 'Unknown applicant';
}

/* =========================
   PRE-COMPUTE STATUSES & STATS
========================= */
$counts = ['ongoing' => 0, 'completed' => 0, 'delayed' => 0];

foreach ($activities as &$a) {
    $a['progress']        = max(0, min(100, (int)($a['progress'] ?? 0)));
    $a['computed_status'] = getStatus($a['progress'], $a['status'] ?? null);
    $counts[$a['computed_status']]++;
}
unset($a);

$total     = count($activities);
$ongoing   = $counts['ongoing'];
$completed = $counts['completed'];
$delayed   = $counts['delayed'];

/* =========================
   COLUMN COUNT
========================= */
$colCount = $isApplicant ? 4 : 5; // Hide "Student" column for applicants
?>
<link rel="stylesheet" href="../asset/css/activity_monitoring.css">

<div class="content">

<!-- ================= HEADER ================= -->
<div class="page-header">
    <h2>Activity Monitoring</h2>
    <p>
        <?php if ($isAdmin): ?>
            Administrator — Monitor all activity logs and generate reports.
        <?php elseif ($isCoordinator): ?>
            Coordinator — Verify requirements, update statuses, and generate reports.
        <?php elseif ($isApprover): ?>
            Approving Official — Review applications and proposals (Read-Only).
        <?php else: ?>
            Applicant View — Track your own weekly reports and progress.
        <?php endif; ?>
    </p>
</div>

<!-- ================= STATS ================= -->
<div class="stats-grid">
    <div class="stat-card"><h3><?= $total ?></h3><p>Total Records</p></div>
    <div class="stat-card"><h3><?= $ongoing ?></h3><p>Ongoing</p></div>
    <div class="stat-card"><h3><?= $completed ?></h3><p>Completed</p></div>
    <div class="stat-card"><h3><?= $delayed ?></h3><p>Delayed</p></div>
</div>

<!-- ================= TABLE ================= -->
<div class="table-card">

<table>

<thead>
<tr>
    <?php if (!$isApplicant): ?><th>Applicant</th><?php endif; ?>
    <th>Week</th>
    <th>Report</th>
    <th>Progress</th>
    <th>Status</th>
</tr>
</thead>

<tbody>

<?php if (!empty($activities)): ?>

<?php foreach ($activities as $a): ?>

<?php
    $status   = $a['computed_status'];
    $progress = $a['progress'];
?>

<tr>

    <?php if (!$isApplicant): ?>
    <td class="name-cell">
        <?= h(studentName($a)) ?>
    </td>
    <?php endif; ?>

    <td>Week <?= h($a['week_number'] ?? '—') ?></td>

    <td>
        <?= h(mb_strimwidth($a['report'] ?? '', 0, 80, '...')) ?>
    </td>

    <td>
        <div class="progress-bar"
             role="progressbar"
             aria-valuenow="<?= $progress ?>"
             aria-valuemin="0"
             aria-valuemax="100">
            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
        </div>
        <small><?= $progress ?>%</small>
    </td>

    <td>
        <span class="status-badge status-<?= h($status) ?>">
            <?= ucfirst(h($status)) ?>
        </span>
    </td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr class="empty-row">
    <td colspan="<?= $colCount ?>">No activity records found.</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>