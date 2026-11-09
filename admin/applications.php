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

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================
   FILTERS
========================= */
$search     = trim($_GET['search'] ?? '');
$program    = trim($_GET['program'] ?? '');
$department = trim($_GET['department'] ?? '');
$view       = $_GET['view'] ?? 'all';
$sort       = $_GET['sort'] ?? 'created_at';
$order      = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

/* =========================
   PAGINATION
========================= */
$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* =========================
   ALLOWED SORT COLUMNS
   (whitelist prevents SQL injection via ORDER BY)
========================= */
$allowedSort = [
    'id'         => 'a.id',
    'applicant'  => 'applicant_name',
    'program'    => 'p.program_name',
    'department' => 'p.program_type',
    'status'     => 'p.status',
    'country'    => 'p.country_id',
    'created_at' => 'a.created_at',
];
$sortColumn = $allowedSort[$sort] ?? 'a.created_at';

/* =========================
   WHERE BUILDER
========================= */
$where  = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (
        a.id LIKE ? OR
        p.program_name LIKE ? OR
        p.program_type LIKE ? OR
        CONCAT(u.first_name,' ',u.last_name) LIKE ? OR
        u.email LIKE ?
    )";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

if ($program !== '') {
    $where .= " AND p.program_name = ?";
    $params[] = $program;
}

if ($department !== '') {
    $where .= " AND p.program_type = ?";
    $params[] = $department;
}

if ($view === 'new') {
    $where .= " AND p.status = 'pending'";
} elseif ($view === 'review') {
    $where .= " AND p.status = 'under evaluation'";
}

/* =========================
   COUNT QUERY
========================= */
$countSql = "
    SELECT COUNT(*)
    FROM applications a
    LEFT JOIN users u ON a.applicant_id = u.id
    LEFT JOIN programs p ON a.program_id = p.id
    $where
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalApplications = (int)$countStmt->fetchColumn();

/* =========================
   DATA QUERY
========================= */
$sql = "
SELECT
    a.id,
    a.mobility_type,
    a.institution,
    a.documents_status,
    a.created_at,
    a.reviewed_at,

    p.program_name AS program,
    p.program_type AS department,
    p.country_id   AS country,
    p.status,

    CONCAT(u.first_name,' ',u.last_name) AS applicant_name,
    u.email AS applicant_email

FROM applications a

LEFT JOIN users u
    ON a.applicant_id = u.id

LEFT JOIN programs p
    ON a.program_id = p.id

$where

ORDER BY $sortColumn $order
LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, ceil($totalApplications / $limit));

/* Helper: build a sort-aware column header */
function sortHeader(string $label, string $col, string $sort, string $order): string {
    $isActive = $sort === $col;
    $nextOrder = ($isActive && $order === 'DESC') ? 'ASC' : 'DESC';
    $icon = $isActive
        ? ($order === 'DESC' ? 'fa-sort-down' : 'fa-sort-up')
        : 'fa-sort';
    $cls = $isActive ? 'sort-icon sort-icon--active' : 'sort-icon';
    $url = http_build_query(array_merge($_GET, ['sort' => $col, 'order' => $nextOrder]));
    return '<span class="th-inner">'
        . '<a href="?' . h($url) . '" class="th-link">' . h($label) . '</a>'
        . '<i class="fa ' . $cls . '"></i>'
        . '</span>';
}
?>

<link rel="stylesheet" href="../asset/css/applications.css">

<div class="content">

    <!-- HEADER -->
    <div class="page-header">
        <h2>Applications Management</h2>
        <p>Handles all submitted mobility applications.</p>
    </div>

    <!-- SEARCH + TABS -->
    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text"
                   id="searchInput"
                   name="search"
                   form="filterForm"
                   placeholder="Search applications..."
                   value="<?= h($search) ?>">
        </div>

        <div class="tabs">
            <a href="?view=all"    class="<?= $view === 'all'    ? 'active' : '' ?>">All</a>
            <a href="?view=new"    class="<?= $view === 'new'    ? 'active' : '' ?>">New</a>
            <a href="?view=review" class="<?= $view === 'review' ? 'active' : '' ?>">Review</a>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" class="filter-bar" id="filterForm">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="order" value="<?= h($order) ?>">

        <select name="program">
            <option value="">All Programs</option>
            <option value="BSIT" <?= $program === 'BSIT' ? 'selected' : '' ?>>BSIT</option>
            <option value="BSCS" <?= $program === 'BSCS' ? 'selected' : '' ?>>BSCS</option>
        </select>

        <select name="department">
            <option value="">All Departments</option>
            <option value="IT"          <?= $department === 'IT'          ? 'selected' : '' ?>>IT</option>
            <option value="Engineering" <?= $department === 'Engineering' ? 'selected' : '' ?>>Engineering</option>
            <option value="Business"    <?= $department === 'Business'    ? 'selected' : '' ?>>Business</option>
        </select>

        <button type="submit"><i class="fa fa-filter"></i> Filter</button>
    </form>

    <!-- TABLE -->
    <div class="table-section">
        <table class="table-card">
            <thead>
                <tr>
                    <th><?= sortHeader('Applicant', 'applicant', $sort, $order) ?></th>
                    <th><?= sortHeader('Program', 'program', $sort, $order) ?></th>
                    <th><?= sortHeader('Department', 'department', $sort, $order) ?></th>
                    <th><?= sortHeader('Status', 'status', $sort, $order) ?></th>
                    <th><?= sortHeader('Country', 'country', $sort, $order) ?></th>
                    <th><?= sortHeader('Date', 'created_at', $sort, $order) ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($applications): ?>
                <?php foreach ($applications as $a): ?>
                    <?php
                        $initials = '';
                        $parts = explode(' ', trim($a['applicant_name'] ?? ''));
                        foreach ($parts as $p) {
                            if ($p !== '') $initials .= strtoupper(substr($p, 0, 1));
                            if (strlen($initials) >= 2) break;
                        }
                        $statusKey = strtolower(str_replace(' ', '-', $a['status'] ?? ''));
                    ?>
                <tr>
                    <td>
                        <div class="applicant-cell">
                            <div class="applicant-avatar"><?= h($initials ?: '?') ?></div>
                            <div class="applicant-info">
                                <strong><?= h($a['applicant_name']) ?></strong>
                                <small><?= h($a['applicant_email']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td><?= h($a['program']) ?></td>
                    <td><?= h($a['department']) ?></td>
                    <td>
                        <span class="status-badge status-<?= h($statusKey) ?>">
                            <?= h(ucfirst($a['status'] ?? '')) ?>
                        </span>
                    </td>
                    <td><?= h($a['country']) ?></td>
                    <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
                    <td class="action-btns">
                        <button class="btn-view"
                                onclick='openModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)'
                                title="View">
                            <i class="fa fa-eye"></i>
                        </button>
                        <button class="btn-approve"
                                onclick="confirmAction('approve', <?= h($a['id']) ?>)"
                                title="Approve">
                            <i class="fa fa-check"></i>
                        </button>
                        <button class="btn-reject"
                                onclick="confirmAction('reject', <?= h($a['id']) ?>)"
                                title="Reject">
                            <i class="fa fa-close"></i>
                        </button>
                        <button class="btn-delete"
                                onclick="confirmAction('delete', <?= h($a['id']) ?>)"
                                title="Delete">
                            <i class="fa fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="9" y1="15" x2="15" y2="15"/>
                                </svg>
                            </div>
                            <h3>No applications found</h3>
                            <p>Try adjusting your search or filters.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="pagination">
        <span>
            Showing <?= count($applications) ?> of <?= $totalApplications ?> applications
        </span>
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
                <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>"
                   class="<?= $i === $page ? 'active' : '' ?>">
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

</div><!-- end .content -->

<!-- ================================
     VIEW MODAL
================================ -->
<div id="appModal" class="modal-overlay" onclick="closeModalOutside(event)">
    <div class="modal-box">

        <div class="modal-header">
            <h3>Application Details</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>

        <div class="modal-body">

            <div class="modal-section">
                <h4>Applicant</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Name</label>
                        <span id="m-name"></span>
                    </div>
                    <div class="modal-field">
                        <label>Email</label>
                        <span id="m-email"></span>
                    </div>
                </div>
            </div>

            <div class="modal-section">
                <h4>Application</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>ID</label>
                        <span id="m-id"></span>
                    </div>
                    <div class="modal-field">
                        <label>Program</label>
                        <span id="m-program"></span>
                    </div>
                    <div class="modal-field">
                        <label>Department</label>
                        <span id="m-department"></span>
                    </div>
                    <div class="modal-field">
                        <label>Mobility Type</label>
                        <span id="m-mobility"></span>
                    </div>
                    <div class="modal-field">
                        <label>Institution</label>
                        <span id="m-institution"></span>
                    </div>
                    <div class="modal-field">
                        <label>Country</label>
                        <span id="m-country"></span>
                    </div>
                </div>
            </div>

            <div class="modal-section">
                <h4>Status</h4>
                <div class="modal-grid">
                    <div class="modal-field">
                        <label>Application Status</label>
                        <span id="m-status"></span>
                    </div>
                    <div class="modal-field">
                        <label>Documents Status</label>
                        <span id="m-docs-status"></span>
                    </div>
                    <div class="modal-field">
                        <label>Submitted</label>
                        <span id="m-created"></span>
                    </div>
                    <div class="modal-field">
                        <label>Reviewed</label>
                        <span id="m-reviewed"></span>
                    </div>
                </div>
            </div>

        </div>

        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeModal()">Close</button>
        </div>

    </div>
</div>
<!-- END #appModal -->

<!-- ================================
     CONFIRM ACTION MODAL
================================ -->
<div id="confirmModal" class="modal-overlay" onclick="closeConfirmOutside(event)">
    <div class="modal-box modal-box--sm">

        <div class="modal-header">
            <h3 id="confirm-title">Confirm Action</h3>
            <button class="modal-close" onclick="closeConfirm()">&times;</button>
        </div>

        <div class="modal-body">
            <div class="confirm-icon" id="confirm-icon"></div>
            <p id="confirm-message" class="confirm-message"></p>
        </div>

        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeConfirm()">Cancel</button>
            <button id="confirm-btn" class="btn-confirm" onclick="executeAction()">Confirm</button>
        </div>

    </div>
</div>
<!-- END #confirmModal -->

<script>
/* =====================================
   SEARCH (DEBOUNCED) — submits filter form
===================================== */
let searchTimer;
const searchInput = document.getElementById('searchInput');

if (searchInput) {
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 400);
    });
}

/* =====================================
   MODAL: VIEW APPLICATION
===================================== */
function openModal(data) {
    try {
        if (!data) return;

        data = (typeof data === "string") ? JSON.parse(data) : data;

        const fmt = (val) =>
            (val !== null && val !== undefined && val !== '') ? val : '—';

        const formatDate = (val) => {
            if (!val) return '—';
            return new Date(val).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        };

        const statusHtml = (status) => {
            if (!status) return '—';
            const cls = status.toLowerCase().replace(/\s+/g, '-');
            return `<span class="status-badge status-${cls}">${status}</span>`;
        };

        document.getElementById('m-id').textContent           = '#' + fmt(data.id);
        document.getElementById('m-name').textContent         = fmt(data.applicant_name);
        document.getElementById('m-email').textContent         = fmt(data.applicant_email);
        document.getElementById('m-program').textContent       = fmt(data.program);
        document.getElementById('m-department').textContent   = fmt(data.department);
        document.getElementById('m-mobility').textContent     = fmt(data.mobility_type);
        document.getElementById('m-institution').textContent  = fmt(data.institution);
        document.getElementById('m-country').textContent       = fmt(data.country);
        document.getElementById('m-status').innerHTML          = statusHtml(data.status);
        document.getElementById('m-docs-status').innerHTML     = statusHtml(data.documents_status);
        document.getElementById('m-created').textContent       = formatDate(data.created_at);
        document.getElementById('m-reviewed').textContent      = formatDate(data.reviewed_at);

        document.getElementById('appModal').classList.add('open');
        document.body.style.overflow = 'hidden';

    } catch (err) {
        console.error('Modal error:', err);
    }
}

function closeModal() {
    document.getElementById('appModal').classList.remove('open');
    document.body.style.overflow = '';
}

function closeModalOutside(e) {
    if (e.target.id === 'appModal') closeModal();
}

/* =====================================
   CONFIRM ACTION MODAL
===================================== */
let pendingAction = null;
let pendingId = null;

const actionConfig = {
    approve: {
        title: "Approve Application",
        message: "Are you sure you want to approve this application?",
        icon: '<i class="fa fa-check-circle" style="color:#16a34a;font-size:2.2rem;"></i>',
        btnClass: "btn-confirm--approve",
        btnLabel: "Yes, Approve"
    },
    reject: {
        title: "Reject Application",
        message: "Are you sure you want to reject this application?",
        icon: '<i class="fa fa-times-circle" style="color:#dc2626;font-size:2.2rem;"></i>',
        btnClass: "btn-confirm--reject",
        btnLabel: "Yes, Reject"
    },
    delete: {
        title: "Delete Application",
        message: "This action cannot be undone.",
        icon: '<i class="fa fa-trash" style="color:#d97706;font-size:2.2rem;"></i>',
        btnClass: "btn-confirm--delete",
        btnLabel: "Yes, Delete"
    }
};

function confirmAction(action, id) {
    const cfg = actionConfig[action];
    if (!cfg) return;

    pendingAction = action;
    pendingId = id;

    document.getElementById('confirm-title').textContent = cfg.title;
    document.getElementById('confirm-message').textContent = cfg.message;
    document.getElementById('confirm-icon').innerHTML = cfg.icon;

    const btn = document.getElementById('confirm-btn');
    btn.textContent = cfg.btnLabel;
    btn.className = 'btn-confirm ' + cfg.btnClass;

    document.getElementById('confirmModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
    document.body.style.overflow = '';
    pendingAction = null;
    pendingId = null;
}

function closeConfirmOutside(e) {
    if (e.target.id === 'confirmModal') closeConfirm();
}

/* =====================================
   EXECUTE ACTION (AJAX)
===================================== */
function executeAction() {
    if (!pendingAction || !pendingId) return;

    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    const routeMap = {
        approve: 'application_approve.php',
        reject:  'application_reject.php',
        delete:  'application_delete.php'
    };

    const formData = new FormData();
    formData.append('id', pendingId);

    fetch(routeMap[pendingAction], {
        method: 'POST',
        body: formData
    })
    .then(async (res) => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error("Invalid server response");
        }
    })
    .then(data => {
        closeConfirm();

        showToast(
            data.success ? 'success' : 'error',
            data.message || 'Unknown response'
        );

        if (data.success) {
            setTimeout(() => window.location.reload(), 1000);
        } else {
            btn.disabled = false;
            btn.textContent = actionConfig[pendingAction]?.btnLabel || 'Confirm';
        }
    })
    .catch(err => {
        console.error(err);
        closeConfirm();
        showToast('error', 'Server error. Please try again.');
    });
}

/* =====================================
   TOAST NOTIFICATION
===================================== */
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

/* =====================================
   ESC KEY HANDLER
===================================== */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeModal();
        closeConfirm();
    }
});
</script>