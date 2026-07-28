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

$stmt = $pdo->prepare("
    SELECT *
    FROM audit_trail
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function actionIcon($action)
{
    $action = strtolower($action);
    return match (true) {
        str_contains($action, 'create') => 'ti ti-plus',
        str_contains($action, 'update') => 'ti ti-edit',
        str_contains($action, 'delete') => 'ti ti-trash',
        str_contains($action, 'login')  => 'ti ti-login',
        str_contains($action, 'logout') => 'ti ti-logout',
        str_contains($action, 'view')   => 'ti ti-eye',
        default                         => 'ti ti-point',
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
        str_contains($action, 'view')   => 'tone-neutral',
        default                         => 'tone-neutral',
    };
}

function timeAgo($datetime)
{
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff / 60) . 'm ago';
    if ($diff < 86400)     return floor($diff / 3600) . 'h ago';
    if ($diff < 604800)    return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $ts);
}

?>

<link rel="stylesheet" href="../asset/css/audits.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.34.0/tabler-icons.min.css">

<div class="content">

    <div class="notification-card">

        <div class="notification-header">
            <h3>
                <i class="ti ti-history"></i>
                Audit Trail
            </h3>
            <p>System activity records</p>
        </div>

        <?php if (!empty($logs)): ?>

            <div class="notification-list">

                <?php foreach ($logs as $log):
                    $icon = actionIcon($log['action']);
                    $tone = actionTone($log['action']);
                    $ago  = timeAgo($log['created_at']);
                    $date = date('M d, Y g:i A', strtotime($log['created_at']));
                ?>

                    <div class="notification-item <?= h($tone) ?>">
                        <div class="notification-icon">
                            <i class="<?= h($icon) ?>"></i>
                        </div>

                        <div class="notification-body">
                            <div class="notification-title">
                                <?= h($log['action']) ?>
                            </div>
                            <div class="notification-desc">
                                <?= h($log['description']) ?>
                            </div>
                            <div class="notification-time">
                                <span class="time-ago"><?= h($ago) ?></span>
                                <span class="time-sep">·</span>
                                <span class="time-full"><?= h($date) ?></span>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="notification-empty">
                <i class="ti ti-history-off"></i>
                <p>No activity recorded.</p>
            </div>

        <?php endif; ?>

    </div>

</div>
