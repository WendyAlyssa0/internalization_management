<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php'; // Includes session_start()

/* =========================
   AUTHENTICATION
========================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

/* =========================
   AUTHORIZATION (Strictly Admins)
   Based on the DFD, the Administrator receives "Activity logs".
   Approvers, Coordinators, and Applicants should NOT see this.
========================= */
if (!canManageSystem()) { // Checks for 'admin' or 'super_admin'
    http_response_code(403);
    exit('Forbidden — only Administrators can view the Audit Trail.');
}

$pdo = db();

/* =========================
   PAGINATION & FILTERS
========================= */
$limit  = 20;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

$search = trim($_GET['q'] ?? '');

$where = '';
$params = [];

if ($search !== '') {
    $where = " WHERE action LIKE ? OR description LIKE ? ";
    $like = "%$search%";
    $params = [$like, $like];
}

// Count total for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_trail $where");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $limit));

// Fetch the logs
$stmt = $pdo->prepare("
    SELECT *
    FROM audit_trail
    $where
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");

// Fix parameter binding (using named parameters for limit/offset is safer)
$i = 1;
foreach ($params as $val) {
    $stmt->bindValue($i++, $val);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   HELPERS
========================= */
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function actionIcon($action)
{
    $action = strtolower($action);
    return match (true) {
        str_contains($action, 'create') => 'fa-solid fa-plus',
        str_contains($action, 'update') => 'fa-solid fa-pen',
        str_contains($action, 'delete') => 'fa-solid fa-trash',
        str_contains($action, 'login')  => 'fa-solid fa-right-to-bracket',
        str_contains($action, 'logout') => 'fa-solid fa-right-from-bracket',
        str_contains($action, 'approve')=> 'fa-solid fa-check',
        str_contains($action, 'reject') => 'fa-solid fa-xmark',
        str_contains($action, 'view')   => 'fa-solid fa-eye',
        default                         => 'fa-solid fa-circle-dot',
    };
}

function actionTone($action)
{
    $action = strtolower($action);
    return match (true) {
        str_contains($action, 'create') => 'tone-success',
        str_contains($action, 'update') => 'tone-info',
        str_contains($action, 'delete') => 'tone-error',
        str_contains($action, 'login')  => 'tone-info',
        str_contains($action, 'logout') => 'tone-warning',
        str_contains($action, 'approve')=> 'tone-success',
        str_contains($action, 'reject') => 'tone-error',
        str_contains($action, 'view')   => 'tone-neutral',
        default                         => 'tone-neutral',
    };
}
?>

<link rel="stylesheet" href="../asset/css/audit_trail.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Audit Trail</h2>
        <p>System activity records and user tracking.</p>
    </div>

    <div class="toolbar">
        <form class="search-wrap" method="get" action="dashboard.php">
            <input type="hidden" name="page" value="audit_trail">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search actions or descriptions...">
        </form>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Description</th>
                    <th>Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log):
                        $icon = actionIcon($log['action']);
                        $tone = actionTone($log['action']);
                        $date = date('M d, Y g:i A', strtotime($log['created_at']));
                    ?>
                    <tr>
                        <td>
                            <div class="action-cell">
                                <div class="action-icon <?= h($tone) ?>">
                                    <i class="<?= h($icon) ?>"></i>
                                </div>
                                <span style="font-weight:600; color:#0f172a;"><?= h(ucwords(str_replace('_', ' ', $log['action']))) ?></span>
                            </div>
                        </td>
                        <td style="color:#475569;">
                            <?= h($log['description']) ?>
                        </td>
                        <td style="color:#64748b; white-space:nowrap;">
                            <?= h($date) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="text-align:center; padding:40px; color:#94a3b8;">
                            No activity recorded<?= $search ? ' matching your search' : '' ?>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <span>Showing <?= count($logs) ?> of <?= $totalLogs ?> records</span>
            <div class="pages">
                <?php
                    $baseQ = array_merge($_GET, []);
                    unset($baseQ['p']);
                ?>
                <?php if ($page > 1): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>">
                        <i class="fa fa-angle-left"></i>
                    </a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>" class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>">
                        <i class="fa fa-angle-right"></i>
                    </a>
                <?php else: ?>
                    <button class="page-arrow" disabled><i class="fa fa-angle-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div>