<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   ADMIN AUTHORIZATION
========================================================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? '')));
$currentRole = str_replace('_', ' ', $currentRole);

if (!in_array($currentRole, ['admin', 'super admin'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db();
$adminId = (int) $_SESSION['user_id'];

/* FETCH ADMIN NOTIFICATIONS */
$stmt = $pdo->prepare("
    SELECT id, type, title, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$adminId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/* COUNTS */
$totalCount  = count($notifications);
$unreadCount = 0;
$typeCounts = ['application' => 0, 'approval' => 0, 'document' => 0, 'reminder' => 0, 'announcement' => 0];

foreach ($notifications as $notification) {
    if (!(int) $notification['is_read']) $unreadCount++;
    $type = $notification['type'];
    if (isset($typeCounts[$type])) $typeCounts[$type]++;
}

/* TYPE MAP */
$typeMap = [
    'application'  => ['icon' => 'ti-file-description', 'label' => 'Applications', 'mod' => ''],
    'approval'     => ['icon' => 'ti-circle-check',     'label' => 'Approvals',    'mod' => 'approval'],
    'document'     => ['icon' => 'ti-file-alert',       'label' => 'Documents',    'mod' => 'document'],
    'reminder'     => ['icon' => 'ti-alarm',            'label' => 'Reminders',    'mod' => 'reminder'],
    'announcement' => ['icon' => 'ti-speakerphone',     'label' => 'Announcements','mod' => 'announcement'],
    'default'      => ['icon' => 'ti-bell',             'label' => 'Notification', 'mod' => '']
];
?>

<link rel="stylesheet" href="../asset/css/notifications.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

<div class="content">
    <div class="notification-card">
        <!-- HEADER -->
        <div class="notification-header">
            <div class="notification-header-left">
                <div class="notification-header-icon"><i class="ti ti-bell"></i></div>
                <div>
                    <h3>Admin Notifications</h3>
                    <p>Review applications, documents, approvals, reminders, and system updates.</p>
                </div>
            </div>
            <button class="btn-mark-all" id="markAllBtn" onclick="markAllRead()" <?= $unreadCount === 0 ? 'disabled' : '' ?>>
                <i class="ti ti-checks"></i> Mark all as read
            </button>
        </div>

        <!-- FILTERS -->
        <div class="notification-filters">
            <button class="active" data-filter="all" onclick="filterNotifications('all', this)">
                <i class="ti ti-layout-grid"></i> All <span class="filter-count"><?= $totalCount ?></span>
            </button>
            <button data-filter="unread" onclick="filterNotifications('unread', this)">
                <i class="ti ti-mail"></i> Unread <span class="filter-count"><?= $unreadCount ?></span>
            </button>
            <?php foreach ($typeCounts as $type => $count): ?>
                <button data-filter="<?= h($type) ?>" onclick="filterNotifications('<?= h($type) ?>', this)">
                    <i class="ti <?= h($typeMap[$type]['icon']) ?>"></i> <?= h($typeMap[$type]['label']) ?> <span class="filter-count"><?= $count ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- TOOLBAR -->
        <div class="notification-toolbar">
            <button class="notification-sort" id="sortBtn" onclick="toggleSort()">
                <i class="ti ti-arrows-sort"></i> <span id="sortLabel">Latest first</span>
            </button>
            <div class="notification-search">
                <i class="ti ti-search"></i>
                <input type="text" id="notificationSearch" placeholder="Search admin notifications..." oninput="searchNotifications()">
            </div>
        </div>

        <!-- LIST -->
        <div class="notification-list" id="notificationList">
            <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $type = $notification['type'];
                    $map = $typeMap[$type] ?? $typeMap['default'];
                    $isUnread = !(int) $notification['is_read'];
                    $timestamp = strtotime($notification['created_at']);
                    ?>
                    <div class="notification-item <?= $isUnread ? 'unread' : '' ?>"
                         data-id="<?= (int) $notification['id'] ?>"
                         data-type="<?= h($type) ?>"
                         data-read="<?= $isUnread ? 'unread' : 'read' ?>"
                         data-time="<?= $timestamp ?>"
                         data-search="<?= h(strtolower($notification['title'] . ' ' . $notification['message'] . ' ' . $map['label'])) ?>"
                         onclick="markRead(this)">
                        
                        <div class="notification-icon <?= $map['mod'] ? 'icon-' . h($map['mod']) : '' ?>">
                            <i class="ti <?= h($map['icon']) ?>"></i>
                        </div>
                        
                        <div class="notification-content">
                            <div class="notification-title"><?= h($notification['title']) ?></div>
                            <div class="notification-message"><?= h($notification['message']) ?></div>
                            <div class="notification-tag <?= $map['mod'] ? 'tag-' . h($map['mod']) : '' ?>"><?= h($map['label']) ?></div>
                        </div>
                        
                        <div class="notification-meta">
                            <span class="notification-time"><?= date('M d, Y g:i A', $timestamp) ?></span>
                            <span class="notification-status-dot"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notification-empty">
                    <div class="notification-empty-icon"><i class="ti ti-bell-off"></i></div>
                    <h3>No admin notifications</h3>
                    <p>New applications, documents, approvals, and system alerts will appear here.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="notification-empty" id="noSearchResults" style="display:none;">
            <div class="notification-empty-icon"><i class="ti ti-search-off"></i></div>
            <h3>No notifications found</h3>
            <p>Try a different search term or filter.</p>
        </div>

        <div class="notification-footer">
            <span>Showing <strong id="visibleCount"><?= $totalCount ?></strong> notifications</span>
        </div>
    </div>
</div>

<script>
window.ADMIN_NOTIF_CSRF = <?= json_encode($csrfToken) ?>;
</script>
<!-- Replaced external JS with inline to ensure CSRF is passed -->
<script>
let currentFilter = 'all';
let currentSort = 'desc';
let searchQuery = '';

function applyFilters() {
    let visible = 0;
    document.querySelectorAll('.notification-item').forEach(el => {
        const matchType = currentFilter === 'all' || (currentFilter === 'unread' ? el.dataset.read === 'unread' : el.dataset.type === currentFilter);
        const matchSearch = !searchQuery || el.dataset.search.includes(searchQuery);
        
        if (matchType && matchSearch) {
            el.style.display = '';
            visible++;
        } else {
            el.style.display = 'none';
        }
    });
    document.getElementById('visibleCount').textContent = visible;
    document.getElementById('noSearchResults').style.display = visible === 0 ? 'block' : 'none';
}

function filterNotifications(type, btn) {
    currentFilter = type;
    document.querySelectorAll('.notification-filters button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function searchNotifications() {
    searchQuery = document.getElementById('notificationSearch').value.toLowerCase().trim();
    applyFilters();
}

function toggleSort() {
    currentSort = currentSort === 'desc' ? 'asc' : 'desc';
    document.getElementById('sortLabel').textContent = currentSort === 'desc' ? 'Latest first' : 'Oldest first';
    
    const list = document.getElementById('notificationList');
    const items = Array.from(list.querySelectorAll('.notification-item'));
    
    items.sort((a, b) => {
        const timeA = parseInt(a.dataset.time);
        const timeB = parseInt(b.dataset.time);
        return currentSort === 'desc' ? timeB - timeA : timeA - timeB;
    });
    
    items.forEach(item => list.appendChild(item));
}

function markRead(el) {
    if (el.dataset.read === 'read') return;

    fetch('../admin/markread.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: el.dataset.id, csrf_token: window.ADMIN_NOTIF_CSRF })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            el.classList.remove('unread');
            el.dataset.read = 'read';
            const dot = el.querySelector('.notification-status-dot');
            if (dot) dot.style.display = 'none';
            updateUnreadCount();
        }
    });
}

function markAllRead() {
    fetch('../admin/markallread.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window.ADMIN_NOTIF_CSRF)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.notification-item').forEach(el => {
                el.classList.remove('unread');
                el.dataset.read = 'read';
                const dot = el.querySelector('.notification-status-dot');
                if (dot) dot.style.display = 'none';
            });
            document.getElementById('markAllBtn').disabled = true;
            updateUnreadCount();
        }
    });
}

function updateUnreadCount() {
    const count = document.querySelectorAll('.notification-item.unread').length;
    document.querySelector('[data-filter="unread"] .filter-count').textContent = count;
    if (count === 0) document.getElementById('markAllBtn').disabled = true;
}
</script>