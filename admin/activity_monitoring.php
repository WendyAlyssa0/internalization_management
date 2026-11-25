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
   AUTHORIZATION (ROLE CHECK)
   Aligned with the PSU Internationalization DFD
========================= */
$role = $_SESSION['role'] ?? 'applicant';
$allowedRoles = [
    'super_admin', 
    'admin', 
    'applicant',        // Covers Student / Faculty
    'student',          // Fallback for existing DB records
    'faculty',          // Fallback for existing DB records
    'campus_coordinator', 
    'ovplia_staff', 
    'approving_official', // VPAA, President
    'bor_secretary'
];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = db();
$userId = (int)$_SESSION['user_id'];

/* =========================
   ROLE-BASED PERMISSION FLAGS (Based on DFD)
========================= */
$isSuperAdmin   = ($role === 'super_admin');
$isAdmin        = ($role === 'admin');
$isApplicant    = in_array($role, ['applicant', 'student', 'faculty'], true); // End-users
$isCoordinator  = in_array($role, ['campus_coordinator', 'ovplia_staff'], true);
$isApprover     = in_array($role, ['approving_official', 'bor_secretary'], true);

// Permissions based on the diagram
$canViewAll     = $isSuperAdmin || $isAdmin || $isCoordinator || $isApprover; 
$canEditStatus  = $isSuperAdmin || $isAdmin || $isCoordinator; // Coordinators update statuses
$canDelete      = $isSuperAdmin; // Only Super Admin deletes
$isReadOnly     = $isApprover;   // Approvers only review, they don't edit

/* =========================
   PAGINATION SETUP
========================= */
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/* =========================
   FETCH ACTIVITY DATA (SCOPED BY ROLE)
========================= */
$where = '';
$params = [];

if ($isApplicant) {
    // Applicants (Students/Faculty) see ONLY their own records
    $where = ' WHERE am.student_id = :sid';
    $params['sid'] = $userId;
}
// Admins, Coordinators, and Approvers see ALL records (No WHERE clause)

// Count total for pagination
$countSql = "SELECT COUNT(*) FROM activity_monitoring am
             LEFT JOIN students s ON s.id = am.student_id" . $where;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT am.*, 
           s.first_name, s.last_name
    FROM activity_monitoring am
    LEFT JOIN students s ON s.id = am.student_id
    $where
    ORDER BY am.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

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
    return $name !== '' ? $name : 'Unknown Applicant';
}

function getInitials(array $a): string {
    $first = $a['first_name'] ?? '';
    $last  = $a['last_name'] ?? '';
    if (trim($first . $last) === '') return '?';
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
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

$total     = $totalRecords;
$ongoing   = $counts['ongoing'];
$completed = $counts['completed'];
$delayed   = $counts['delayed'];

/* =========================
   ROLE DESCRIPTIONS (Matching DFD)
========================= */
$roleDescriptions = [
    'super_admin'        => 'Super Admin — Full system oversight, reporting, and deletion rights.',
    'admin'              => 'Administrator — Monitor activity logs and generate system reports.',
    'campus_coordinator' => 'Campus Coordinator — Verify requirements, update statuses, and generate reports.',
    'ovplia_staff'       => 'OVPLIA Staff — Verify requirements, update statuses, and generate reports.',
    'approving_official' => 'Approving Official — Review applications and proposals (Read-Only).',
    'bor_secretary'      => 'BOR Secretary — Review applications and proposals (Read-Only).',
];
$roleDescription = $roleDescriptions[$role] ?? 'Applicant View — Track your weekly reports and status.';
?>

<link rel="stylesheet" href="../asset/css/activity_monitoring.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

<!-- ================= HEADER ================= -->
<div class="page-header">
    <h2>Activity & Application Monitoring</h2>
    <p><?= h($roleDescription) ?></p>
</div>

<!-- ================= ROLE BADGE ================= -->
<div class="role-badge role-<?= h($role) ?>">
    <i class="fa-solid fa-user-shield"></i>
    Logged in as: <strong><?= h(ucwords(str_replace('_', ' ', $role))) ?></strong>
    <?php if ($isReadOnly): ?>
        <span class="badge-readonly"><i class="fa-solid fa-eye"></i> Read-only</span>
    <?php endif; ?>
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
    <th>Report / Proposal</th>
    <th>Feedback / Review</th>
    <th>Progress</th>
    <th>Status</th>
    
    <!-- Actions column only shows if the user has editing/deleting rights -->
    <?php if ($canEditStatus || $canDelete): ?>
        <th>Actions</th>
    <?php endif; ?>
</tr>
</thead>

<tbody>
<?php if (!empty($activities)): ?>
<?php foreach ($activities as $a): ?>
<?php
    $status   = $a['computed_status'];
    $progress = $a['progress'];
    $initials = getInitials($a);
?>
<tr>
    <?php if (!$isApplicant): ?>
    <td>
        <div class="user-name-cell">
            <div class="user-avatar"><?= h($initials) ?></div>
            <span><?= h(studentName($a)) ?></span>
        </div>
    </td>
    <?php endif; ?>

    <td style="font-weight:600;">Week <?= h($a['week_number'] ?? '—') ?></td>
    <td><?= h(mb_strimwidth($a['report'] ?? '', 0, 80, '...')) ?></td>
    <td><?= h(mb_strimwidth($a['supervisor_feedback'] ?? '', 0, 80, '...')) ?></td>

    <td>
        <div class="progress-bar" role="progressbar"
             aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
        </div>
        <small><?= $progress ?>%</small>
    </td>

    <td>
        <span class="status-badge status-<?= h($status) ?>">
            <?= ucfirst(h($status)) ?>
        </span>
    </td>

    <?php if ($canEditStatus || $canDelete): ?>
    <td class="actions-cell">
        <!-- Coordinators & Admins can update status/feedback -->
        <?php if ($canEditStatus): ?>
            <a href="update_status.php?id=<?= (int)$a['id'] ?>" class="btn-action btn-edit" title="Update Status / Feedback">
                <i class="fa-solid fa-pen-to-square"></i>
            </a>
        <?php endif; ?>
        
        <!-- Only Super Admin can delete -->
        <?php if ($canDelete): ?>
            <a href="delete_activity.php?id=<?= (int)$a['id'] ?>"
               class="btn-action btn-delete"
               onclick="return confirm('Delete this record permanently?');"
               title="Delete (Super Admin only)">
                <i class="fa-solid fa-trash"></i>
            </a>
        <?php endif; ?>
    </td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr class="empty-row">
    <td colspan="<?= (!$isApplicant ? 6 : 5) + (($canEditStatus || $canDelete) ? 1 : 0) ?>">
        No records found.
    </td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- ================= PAGINATION ================= -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>" class="page-link">&laquo; Prev</a>
    <?php else: ?>
        <span class="page-link disabled">&laquo; Prev</span>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="page-link active"><?= $i ?></span>
        <?php else: ?>
            <a href="?page=<?= $i ?>" class="page-link"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?>" class="page-link">Next &raquo;</a>
    <?php else: ?>
        <span class="page-link disabled">Next &raquo;</span>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
</div>