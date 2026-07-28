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
   FETCH NOTIFICATIONS
========================= */
$stmt = $pdo->prepare("
    SELECT id, type, title, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$unreadCount = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unreadCount++;
}

/* ICON MAP */
$typeMap = [
    'application'  => ['ti-clipboard-check', 'Application Update'],
    'approval'     => ['ti-check', 'Approval Update'],
    'document'     => ['ti-file-alert', 'Document Requirement'],
    'reminder'     => ['ti-alarm', 'Travel Reminder'],
    'announcement' => ['ti-speakerphone', 'System Announcement'],
    'default'      => ['ti-bell', 'Notification']
];
?>

<link rel="stylesheet" href="../asset/css/notifications.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

<div class="content">

<div class="notification-card">

    <div class="notification-header">
        <div>
            <h3><i class="ti ti-bell"></i> Notifications</h3>
            <p>System-wide alerts and updates</p>
        </div>

        <?php if ($unreadCount > 0): ?>
            <span class="notification-badge" id="badge">
                <?= $unreadCount ?> unread
            </span>
        <?php else: ?>
            <span class="notification-badge" id="badge" style="display:none;">
                0 unread
            </span>
        <?php endif; ?>
    </div>

    <div class="notification-filters">
        <button class="active" onclick="filter('all', this)">All</button>
        <button onclick="filter('unread', this)">Unread</button>
        <button onclick="filter('application', this)">Applications</button>
        <button onclick="filter('approval', this)">Approvals</button>
        <button onclick="filter('reminder', this)">Reminders</button>
        <button onclick="filter('announcement', this)">Announcements</button>
    </div>

    <div class="notification-list">

    <?php if (!empty($notifications)): ?>
        <?php foreach ($notifications as $n):
            $map     = $typeMap[$n['type']] ?? $typeMap['default'];
            $icon    = $map[0];
            $label   = $map[1];
            $isUnread = !$n['is_read'];
        ?>
        <div class="notification-item <?= $isUnread ? 'unread' : '' ?>"
             data-id="<?= (int)$n['id'] ?>"
             data-type="<?= h($n['type']) ?>"
             data-read="<?= $isUnread ? 'unread' : 'read' ?>"
             onclick="markRead(this)">

            <div class="unread-dot"></div>

            <div class="notification-icon">
                <i class="ti <?= h($icon) ?>"></i>
            </div>

            <div class="notification-content">
                <div class="notification-label"><?= h($label) ?></div>
                <div class="notification-title"><?= h($n['title']) ?></div>
                <div class="notification-message"><?= h($n['message']) ?></div>
                <div class="notification-time">
                    <i class="ti ti-clock"></i>
                    <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="notification-empty">
            <div class="notification-empty-icon">
                <i class="ti ti-bell-off"></i>
            </div>
            <h3>No notifications yet</h3>
            <p>You're all caught up. New alerts will appear here.</p>
        </div>
    <?php endif; ?>

    </div>

    <div class="notification-footer">
        <span>Showing <?= count($notifications) ?> notification<?= count($notifications) === 1 ? '' : 's' ?></span>
        <button onclick="markAllRead()">
            <i class="ti ti-checks"></i> Mark all as read
        </button>
    </div>

</div>

</div>

<script>
/* ── Mark single as read ── */
function markRead(el) {
    if (el.dataset.read === 'read') return;

    fetch('../admin/markread.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: el.dataset.id })
    })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { throw new Error('Server did not return JSON'); }
    })
    .then(res => {
        if (res.success) {
            el.classList.remove('unread');
            el.dataset.read = 'read';
            const dot = el.querySelector('.unread-dot');
            if (dot) dot.style.display = 'none';
            const icon = el.querySelector('.notification-icon');
            if (icon) icon.style.background = '';
            if (icon) icon.style.color = '';
            updateBadge();
        } else {
            showToast('error', res.message || 'Could not mark notification.');
        }
    })
    .catch(() => showToast('error', 'Could not reach the server.'));
}

/* ── Mark all as read ── */
function markAllRead() {
    fetch('../admin/markallread.php', { method: 'POST' })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { throw new Error('Server did not return JSON'); }
    })
    .then(res => {
        if (res.success) {
            document.querySelectorAll('.notification-item').forEach(el => {
                el.classList.remove('unread');
                el.dataset.read = 'read';
                const dot = el.querySelector('.unread-dot');
                if (dot) dot.style.display = 'none';
                const icon = el.querySelector('.notification-icon');
                if (icon) icon.style.background = '';
                if (icon) icon.style.color = '';
            });
            updateBadge();
            showToast('success', 'All notifications marked as read.');
        } else {
            showToast('error', res.message || 'Could not mark all notifications.');
        }
    })
    .catch(() => showToast('error', 'Could not reach the server.'));
}

/* ── Update badge ── */
function updateBadge() {
    const unread = document.querySelectorAll('.notification-item.unread').length;
    const badge = document.getElementById('badge');

    if (!badge) return;

    if (unread > 0) {
        badge.textContent = unread + ' unread';
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

/* ── Filter ── */
function filter(type, btn) {
    document.querySelectorAll('.notification-item').forEach(el => {
        const matchType = type === 'all' || el.dataset.type === type;
        const matchRead = type !== 'unread' ? true : el.dataset.read === 'unread';
        el.style.display = (matchType && matchRead) ? '' : 'none';
    });

    document.querySelectorAll('.notification-filters button')
        .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

/* ── Toast ── */
function showToast(type, message) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = `toast toast--${type}`;
    toast.innerHTML = `
        <i class="fa fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span></span>
    `;
    toast.querySelector('span').textContent = message;

    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('toast--show'));

    setTimeout(() => {
        toast.classList.remove('toast--show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
</script>
