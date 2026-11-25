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
$userId = (int)$_SESSION['user_id'];

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================
   HANDLE AJAX MARK AS READ
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    // Basic CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
        exit;
    }

    try {
        if ($action === 'mark_read' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([(int)$_POST['id'], $userId]);
            echo json_encode(['success' => true]);
            exit;
        } 
        
        if ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
        exit;
    }
}

/* =========================
   FETCH NOTIFICATIONS
   ========================= */
$stmt = $pdo->prepare("
    SELECT id, type, title, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
      AND type IN ('application', 'approval', 'rejected', 'document', 'reminder', 'announcement')
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Counts */
$totalCount  = count($notifications);
$unreadCount = 0;
$typeCounts  = ['application' => 0, 'approval' => 0, 'rejected' => 0, 'document' => 0, 'reminder' => 0, 'announcement' => 0];

foreach ($notifications as $n) {
    if (!$n['is_read']) $unreadCount++;
    if (isset($typeCounts[$n['type']])) $typeCounts[$n['type']]++;
}

$typeMap = [
    'application'  => ['fa-file-lines',      'Application Update',   ''],
    'approval'     => ['fa-check-circle',    'Approved',             'approval'],
    'rejected'     => ['fa-times-circle',    'Rejected',             'rejected'],
    'document'     => ['fa-file-excel',      'Document Requirement', ''],
    'reminder'     => ['fa-clock',           'Travel Reminder',      'reminder'],
    'announcement' => ['fa-bullhorn',        'Announcement',         'announcement'],
    'default'      => ['fa-bell',            'Notification',         ''],
];

$tagLabels = [
    'application'  => 'Applications',
    'approval'     => 'Approvals',
    'rejected'     => 'Rejections',
    'document'     => 'Documents',
    'reminder'     => 'Reminders',
    'announcement' => 'Announcements',
];
?>

<style>
    /* =====================================================
       NOTIFICATIONS STYLES
       ===================================================== */
    .notif-page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 32px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--border-light);
    }

    .notif-header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .notif-header-icon {
        width: 56px;
        height: 56px;
        background: var(--bg-main);
        color: var(--psu-navy);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }

    .notif-header-left h3 { 
        font-family: var(--font-heading);
        font-size: 1.5rem; 
        font-weight: 800;
        color: var(--psu-navy); 
    }
    .notif-header-left p { 
        font-size: 0.95rem; 
        color: var(--text-muted); 
    }

    .btn-mark-all {
        background: var(--white);
        border: 1px solid var(--border-light);
        padding: 10px 20px;
        border-radius: var(--radius-sm);
        font-family: var(--font-heading);
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--psu-navy);
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-mark-all:hover:not(:disabled) {
        background: var(--bg-main);
        border-color: var(--psu-navy);
    }
    .btn-mark-all:disabled { opacity: 0.5; cursor: not-allowed; }

    /* Filters */
    .notif-filters {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        overflow-x: auto;
        padding-bottom: 8px;
    }
    .notif-filters::-webkit-scrollbar { height: 0; }

    .notif-filter-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius-full);
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-muted);
        cursor: pointer;
        white-space: nowrap;
        transition: var(--transition);
    }

    .notif-filter-btn:hover { 
        background: var(--bg-main); 
        color: var(--psu-navy); 
        border-color: var(--psu-navy);
    }
    
    .notif-filter-btn.active {
        background: var(--psu-navy);
        color: var(--white);
        border-color: var(--psu-navy);
        box-shadow: 0 4px 12px rgba(11, 26, 48, 0.15);
    }

    .filter-count {
        background: var(--bg-main);
        color: var(--text-muted);
        padding: 2px 8px;
        border-radius: var(--radius-full);
        font-size: 0.75rem;
    }
    .notif-filter-btn.active .filter-count { 
        background: rgba(255,255,255,0.2); 
        color: var(--white); 
    }

    /* List Items */
    .notif-list { display: flex; flex-direction: column; gap: 16px; }

    .notif-item {
        display: flex;
        gap: 20px;
        padding: 20px 24px;
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        transition: var(--transition);
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    
    .notif-item:hover { 
        border-color: var(--psu-gold); 
        box-shadow: var(--shadow-sm); 
        transform: translateY(-1px);
    }
    
    /* Unread left accent */
    .notif-item.unread { 
        background: #fcfdfd; 
        border-left: 4px solid var(--psu-gold); 
    }

    .notif-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
        background: var(--bg-main);
        color: var(--psu-navy);
    }

    /* Icon Color Modifiers */
    .notif-icon.icon-approval { background: #ecfdf5; color: #047857; }
    .notif-icon.icon-rejected { background: #fef2f2; color: #b91c1c; }
    .notif-icon.icon-announcement { background: #eff6ff; color: #2563eb; }
    .notif-icon.icon-reminder { background: #fefce8; color: #ca8a04; }

    .notif-content { flex: 1; }
    .notif-title { 
        font-family: var(--font-heading);
        font-weight: 700; 
        font-size: 1rem; 
        color: var(--psu-navy); 
        margin-bottom: 6px; 
    }
    .notif-message { 
        font-size: 0.9rem; 
        color: var(--text-muted); 
        line-height: 1.5; 
        margin-bottom: 12px; 
    }
    
    .notif-tag {
        display: inline-block;
        padding: 4px 10px;
        border-radius: var(--radius-sm);
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: var(--bg-main);
        color: var(--text-light);
    }

    .notif-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        justify-content: space-between;
        flex-shrink: 0;
    }
    
    .notif-time { 
        font-size: 0.75rem; 
        color: var(--text-light); 
        font-weight: 600; 
    }
    
    .notif-status-dot { 
        width: 10px; 
        height: 10px; 
        border-radius: 50%; 
        background: transparent; 
        transition: var(--transition);
    }
    .notif-item.unread .notif-status-dot { 
        background: var(--psu-gold); 
        box-shadow: 0 0 0 4px rgba(228, 177, 52, 0.15); 
    }

    /* Empty State */
    .notif-empty { 
        text-align: center; 
        padding: 60px 20px; 
        background: var(--bg-main);
        border-radius: var(--radius-lg);
        border: 2px dashed var(--border-light);
    }
    .notif-empty i { 
        font-size: 3rem; 
        color: var(--text-light); 
        margin-bottom: 16px; 
    }
    .notif-empty h3 { 
        font-family: var(--font-heading);
        font-size: 1.25rem; 
        color: var(--psu-navy); 
        margin-bottom: 8px; 
    }
    .notif-empty p { 
        color: var(--text-muted); 
        font-size: 0.95rem; 
    }

    @media (max-width: 640px) {
        .notif-page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 16px;
        }
        .btn-mark-all { width: 100%; justify-content: center; }
        .notif-item { padding: 16px; gap: 16px; }
        .notif-icon { width: 40px; height: 40px; font-size: 1rem; }
    }
</style>

<div class="content-card">
    <div class="notif-page-header">
        <div class="notif-header-left">
            <div class="notif-header-icon"><i class="fa-regular fa-bell"></i></div>
            <div>
                <h3>Notifications</h3>
                <p>Stay informed about important updates, alerts, and activities.</p>
            </div>
        </div>
        <button class="btn-mark-all" id="markAllBtn" onclick="markAllRead()" <?= $unreadCount === 0 ? 'disabled' : '' ?>>
            <i class="fa-solid fa-check-double"></i> Mark all read
        </button>
    </div>

    <div class="notif-filters">
        <button class="notif-filter-btn active" data-filter="all" onclick="filter('all', this)">
            <i class="fa-solid fa-layer-group"></i> All <span class="filter-count"><?= $totalCount ?></span>
        </button>
        <button class="notif-filter-btn" data-filter="unread" onclick="filter('unread', this)">
            <i class="fa-solid fa-envelope"></i> Unread <span class="filter-count"><?= $unreadCount ?></span>
        </button>
        <?php foreach ($tagLabels as $key => $label): ?>
            <button class="notif-filter-btn" data-filter="<?= $key ?>" onclick="filter('<?= $key ?>', this)">
                <?= $label ?> <span class="filter-count"><?= $typeCounts[$key] ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="notif-list" id="notifList">
        <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $n):
                $map      = $typeMap[$n['type']] ?? $typeMap['default'];
                $icon     = $map[0];
                $mod      = $map[2];
                $tagLabel = $tagLabels[$n['type']] ?? 'Notification';
                $isUnread = !$n['is_read'];
            ?>
            <div class="notif-item <?= $isUnread ? 'unread' : '' ?>"
                 data-id="<?= (int)$n['id'] ?>"
                 data-type="<?= h($n['type']) ?>"
                 data-read="<?= $isUnread ? 'unread' : 'read' ?>"
                 onclick="markRead(this)">
                 
                <div class="notif-icon <?= $mod ? 'icon-' . h($mod) : '' ?>">
                    <i class="fa-solid <?= h($icon) ?>"></i>
                </div>

                <div class="notif-content">
                    <div class="notif-title"><?= h($n['title']) ?></div>
                    <div class="notif-message"><?= h($n['message']) ?></div>
                    <div class="notif-tag"><?= h($tagLabel) ?></div>
                </div>

                <div class="notif-meta">
                    <span class="notif-time"><?= date('M d, g:i A', strtotime($n['created_at'])) ?></span>
                    <span class="notif-status-dot"></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="notif-empty">
                <i class="fa-regular fa-bell-slash"></i>
                <h3>No notifications yet</h3>
                <p>You're all caught up. New alerts will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

    function markRead(el) {
        if (el.dataset.read === 'read') return;

        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('id', el.dataset.id);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    el.classList.remove('unread');
                    el.dataset.read = 'read';
                    updateCounts();
                }
            })
            .catch(() => alert('Error marking notification as read.'));
    }

    function markAllRead() {
        const formData = new FormData();
        formData.append('action', 'mark_all_read');
        formData.append('csrf_token', CSRF_TOKEN);

        fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    document.querySelectorAll('.notif-item').forEach(el => {
                        el.classList.remove('unread');
                        el.dataset.read = 'read';
                    });
                    updateCounts();
                }
            })
            .catch(() => alert('Error marking all as read.'));
    }

    function updateCounts() {
        const items = document.querySelectorAll('.notif-item');
        const counts = { all: items.length, unread: 0 };
        
        items.forEach(el => {
            if (el.dataset.read === 'unread') counts.unread++;
            counts[el.dataset.type] = (counts[el.dataset.type] || 0) + 1;
        });

        document.querySelectorAll('.notif-filter-btn').forEach(btn => {
            const key = btn.dataset.filter;
            const span = btn.querySelector('.filter-count');
            if (span && counts[key] !== undefined) {
                span.textContent = counts[key];
            }
        });

        const markAllBtn = document.getElementById('markAllBtn');
        if (markAllBtn) markAllBtn.disabled = counts.unread === 0;
    }

    function filter(type, btn) {
        document.querySelectorAll('.notif-item').forEach(el => {
            let show = false;
            if (type === 'all')         show = true;
            else if (type === 'unread') show = el.dataset.read === 'unread';
            else                        show = el.dataset.type === type;
            el.style.display = show ? '' : 'none';
        });

        document.querySelectorAll('.notif-filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
</script>