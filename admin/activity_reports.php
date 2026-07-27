<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

$pdo = db();

/* =========================
   FETCH ACTIVITY DATA
========================= */
$stmt = $pdo->prepare("
    SELECT am.*, 
           s.first_name, s.last_name
    FROM activity_monitoring am
    LEFT JOIN students s ON s.id = am.student_id
    ORDER BY am.created_at DESC
");

$stmt->execute();
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* =========================
   STATUS LOGIC
========================= */
function getStatus($progress, $status){
    if ($progress >= 100) return 'completed';
    if ($status === 'delayed') return 'delayed';
    return 'ongoing';
}

/* =========================
   STATS
========================= */
$total = count($activities);
$ongoing = 0;
$completed = 0;
$delayed = 0;

foreach ($activities as $a) {
    $s = getStatus($a['progress'], $a['status']);
    if ($s === 'ongoing') $ongoing++;
    if ($s === 'completed') $completed++;
    if ($s === 'delayed') $delayed++;
}
?>

<link rel="stylesheet" href="../asset/css/activity_monitoring.css">

<div class="content">

<!-- ================= HEADER ================= -->
<div class="page-header">
    <h2>Activity Monitoring</h2>
    <p>Tracks student performance, weekly reports, and supervisor feedback.</p>
</div>

<!-- ================= STATS ================= -->
<div class="stats-grid">

    <div class="stat-card">
        <h3><?= $total ?></h3>
        <p>Total Activities</p>
    </div>

    <div class="stat-card">
        <h3><?= $ongoing ?></h3>
        <p>Ongoing</p>
    </div>

    <div class="stat-card">
        <h3><?= $completed ?></h3>
        <p>Completed</p>
    </div>

    <div class="stat-card">
        <h3><?= $delayed ?></h3>
        <p>Delayed</p>
    </div>

</div>

<!-- ================= TABLE ================= -->
<div class="table-card">

<table>

<thead>
<tr>
    <th>Student</th>
    <th>Week</th>
    <th>Report</th>
    <th>Supervisor Feedback</th>
    <th>Progress</th>
    <th>Status</th>
</tr>
</thead>

<tbody>

<?php if (!empty($activities)): ?>

<?php foreach ($activities as $a):

$status = getStatus($a['progress'], $a['status']);
$progress = (int)$a['progress'];

?>

<tr>

    <td class="name-cell">
        <?= h($a['first_name'].' '.$a['last_name']) ?>
    </td>

    <td>Week <?= h($a['week_number']) ?></td>

    <td>
        <?= h(mb_strimwidth($a['report'] ?? '', 0, 80, '...')) ?>
    </td>

    <td>
        <?= h(mb_strimwidth($a['supervisor_feedback'] ?? '', 0, 80, '...')) ?>
    </td>

    <td>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
        </div>
        <small><?= $progress ?>%</small>
    </td>

    <td>
        <span class="status-badge status-<?= $status ?>">
            <?= ucfirst($status) ?>
        </span>
    </td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr class="empty-row">
    <td colspan="6">No activity records found.</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>