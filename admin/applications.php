<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

// Allow Coordinators, Approvers, Admins, and Super Admins to view this page
if (!canManageActivities() && !isApprover() && !isAdmin()) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to view applications.');
}

// Generate CSRF for the AJAX actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db();
// ... rest of your code (function h, filters, pagination, etc.) ...

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================
   FILTERS
========================= */
$search     = trim($_GET['search'] ?? '');
$program    = trim($_GET['program'] ?? '');
$campusId   = (int)($_GET['campus'] ?? 0);
$degreeProgram = trim($_GET['degree_program'] ?? '');
$applicantType = trim($_GET['applicant_type'] ?? '');
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
========================= */
$allowedSort = [
    'id'         => 'a.id',
    'applicant'  => 'applicant_name',
    'program'    => 'p.program_name',
    'degree_program' => 'u.program',
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

if ($campusId > 0) {
    $where .= " AND u.campus_id = ?";
    $params[] = $campusId;
}

if ($degreeProgram !== '') {
    $where .= " AND u.program = ?";
    $params[] = $degreeProgram;
}

if ($applicantType !== '') {
    $where .= " AND LOWER(COALESCE(NULLIF(u.account_type, ''), u.user_role, '')) = ?";
    $params[] = strtolower($applicantType);
}

if ($view === 'new') {
    $where .= " AND a.status = 'submitted'";
} elseif ($view === 'review') {
    $where .= " AND a.status = 'under_review'";
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

$campuses = $pdo->query("SELECT id, campus_name FROM campuses WHERE status = 'active' ORDER BY campus_name")->fetchAll(PDO::FETCH_ASSOC);
$degreeQuery = $pdo->prepare("
    SELECT DISTINCT degree_program
    FROM (
        SELECT dp.program_name AS degree_program
        FROM degree_programs dp
        WHERE dp.status = 'active' AND (? = 0 OR dp.campus_id = ?)
        UNION
        SELECT u.program AS degree_program
        FROM users u
        WHERE u.program IS NOT NULL AND u.program <> ''
          AND (? = 0 OR u.campus_id = ?)
    ) degree_options
    ORDER BY degree_program
");
$degreeQuery->execute([$campusId, $campusId, $campusId, $campusId]);
$degreePrograms = $degreeQuery->fetchAll(PDO::FETCH_COLUMN);
$applicantTypes = $pdo->query("
    SELECT DISTINCT COALESCE(NULLIF(account_type, ''), user_role) AS applicant_type
    FROM users
    WHERE COALESCE(NULLIF(account_type, ''), user_role) IS NOT NULL
    ORDER BY applicant_type
")->fetchAll(PDO::FETCH_COLUMN);

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
    u.program AS degree_program,
    campus.campus_name AS campus,
    COALESCE(NULLIF(u.account_type, ''), u.user_role) AS applicant_type,
    c.country_name AS country,
    a.status       AS status,
    CONCAT(u.first_name,' ',u.last_name) AS applicant_name,
    u.email AS applicant_email
FROM applications a
LEFT JOIN users u ON a.applicant_id = u.id
LEFT JOIN programs p ON a.program_id = p.id
LEFT JOIN campuses campus ON campus.id = u.campus_id
LEFT JOIN countries c ON c.id = p.country_id
$where
ORDER BY $sortColumn $order
LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, ceil($totalApplications / $limit));

function sortHeader(string $label, string $col, string $sort, string $order): string {
    $isActive = $sort === $col;
    $nextOrder = ($isActive && $order === 'DESC') ? 'ASC' : 'DESC';
    $icon = $isActive ? ($order === 'DESC' ? 'fa-sort-down' : 'fa-sort-up') : 'fa-sort';
    $cls = $isActive ? 'sort-icon sort-icon--active' : 'sort-icon';
    $url = http_build_query(array_merge($_GET, ['sort' => $col, 'order' => $nextOrder]));
    return '<span class="th-inner"><a href="?' . h($url) . '" class="th-link">' . h($label) . '</a><i class="fa ' . $cls . '"></i></span>';
}
?>

<!-- GUARANTEED FALLBACK CSS -->
<style>
    .content { padding: 32px 36px; font-family: 'Inter', sans-serif; color: #0f172a; }
    .page-header { margin-bottom: 28px; }
    .page-header h2 { font-size: 1.75rem; font-weight: 700; margin: 0 0 6px; }
    .page-header p { font-size: 0.9rem; color: #475569; margin: 0; padding-left: 12px; border-left: 3px solid #2563eb; }
    
    .toolbar { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
    .search-wrap { flex: 1; min-width: 240px; display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 9999px; padding: 0 18px; height: 44px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
    .search-wrap input { flex: 1; border: none; outline: none; background: transparent; font-size: 0.9rem; }
    
    .tabs { display: flex; gap: 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 9999px; padding: 4px; }
    .tabs a { text-decoration: none; font-size: 0.875rem; font-weight: 500; color: #475569; padding: 6px 20px; border-radius: 9999px; }
    .tabs a.active { background: #0f2044; color: #fff; }

    .filter-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; }
    .filter-bar select { padding: 9px 36px 9px 14px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; height: 40px; }
    .filter-bar button { background: #0f2044; color: #fff; border: none; border-radius: 8px; padding: 9px 20px; height: 40px; cursor: pointer; }

    .table-section { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,.1); }
    .table-card { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    
    /* THE FIX: Light header */
    .table-card thead { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .table-card thead th { padding: 16px 20px; text-align: left; font-size: 0.75rem; text-transform: uppercase; color: #94a3b8; }
    
    .table-card tbody tr { border-bottom: 1px solid #e2e8f0; }
    .table-card tbody tr:hover { background: #f8fafc; }
    .table-card tbody td { padding: 16px 20px; color: #475569; vertical-align: middle; }


/* Applicant Information - Horizontal Layout */
.applicant-cell {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 12px !important;
    text-align: left;
    width: 100%;
}

.applicant-avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0284c7;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.95rem;
    flex-shrink: 0;
}

.applicant-info {
    text-align: left;
}

.applicant-info strong {
    display: block;
    color: #0f172a;
    font-weight: 600;
    margin-bottom: 4px;
}

.applicant-info small {
    display: block;
    color: #94a3b8;
    font-size: 0.8rem;
}

    /* =========================
       MODAL CSS
    ========================= */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 32, 68, 0.45);
        backdrop-filter: blur(3px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .modal-overlay.open {
        display: flex;
    }

    .modal-box {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        width: 100%;
        max-width: 540px;
        display: flex;
        flex-direction: column;
        animation: modalSlideUp 0.22s ease;
    }
    .modal-box--sm {
        max-width: 440px;
    }

    @keyframes modalSlideUp {
        from { opacity: 0; transform: translateY(16px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid #e2e8f0;
    }
    .modal-header h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    .modal-close {
        width: 32px;
        height: 32px;
        border: none;
        background: #f8fafc;
        border-radius: 6px;
        cursor: pointer;
        font-size: 1.2rem;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.2s;
    }
    .modal-close:hover {
        background: #fee2e2;
        color: #dc2626;
    }

    .modal-body {
        padding: 24px;
        overflow-y: auto;
    }

    .modal-section {
        margin-bottom: 24px;
    }
    .modal-section:last-child {
        margin-bottom: 0;
    }
    .modal-section h4 {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #94a3b8;
        margin: 0 0 14px;
        padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
    }

    .modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .modal-field label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: #94a3b8;
        text-transform: uppercase;
        margin-bottom: 4px;
    }
    .modal-field span {
        font-size: 0.9rem;
        color: #0f172a;
        font-weight: 500;
        word-break: break-word;
    }

    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        background: #fafafa;
        border-radius: 0 0 16px 16px;
    }

    /* Confirm Modal Specifics */
    .confirm-icon {
        text-align: center;
        margin-bottom: 14px;
    }
    .confirm-message {
        text-align: center;
        font-size: 0.95rem;
        color: #475569;
        margin: 0;
        line-height: 1.6;
    }

    /* Modal Buttons */
    .btn-secondary {
        padding: 9px 20px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        transition: 0.2s;
    }
    .btn-secondary:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }

    .btn-confirm {
        padding: 9px 20px;
        border: none;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        color: white;
        cursor: pointer;
        transition: 0.2s;
    }
    .btn-confirm:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .btn-confirm--approve { background: #16a34a; }
    .btn-confirm--approve:hover { background: #15803d; }
    .btn-confirm--reject { background: #dc2626; }
    .btn-confirm--reject:hover { background: #b91c1c; }
    .btn-confirm--delete { background: #dc2626; }
    .btn-confirm--delete:hover { background: #b91c1c; }

    /* Toast */
    .toast {
        position: fixed;
        bottom: 28px;
        right: 28px;
        z-index: 2000;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 20px;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 500;
        color: white;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,.1);
        transform: translateY(12px);
        opacity: 0;
        transition: opacity 0.25s ease, transform 0.25s ease;
        pointer-events: none;
    }
    .toast--show {
        opacity: 1;
        transform: translateY(0);
    }
    .toast--success { background: #0f2044; }
    .toast--error { background: #dc2626; }

/* Application Status Badges */
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 9999px; font-size: 0.76rem; font-weight: 600; }
.status-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

/* Green */
.status-approved { background: #dcfce7; color: #15803d; }

/* Red */
.status-rejected { background: #fee2e2; color: #b91c1c; }

/* Blue */
.status-submitted { background: #e0f2fe; color: #0369a1; }
.status-under_review { background: #e0f2fe; color: #0369a1; }
    /* Actions */
    .action-btns { display: flex; gap: 6px; }
    .action-btns button { width: 34px; height: 34px; border-radius: 8px; border: 1px solid transparent; background: transparent; color: #94a3b8; cursor: pointer; transition: 0.2s; }
    .btn-view:hover { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
    .btn-approve:hover { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
    .btn-reject:hover { background: #fff7ed; color: #ea580c; border-color: #fed7aa; }
    .btn-delete:hover { background: #fff1f2; color: #dc2626; border-color: #fecdd3; }

    /* Pagination */
    .pagination { display: flex; justify-content: space-between; padding: 16px 20px; background: #fff; border-top: 1px solid #e2e8f0; }
    .pages { display: flex; gap: 6px; }
    .pages a, .pages button { min-width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none; color: #475569; border: 1px solid transparent; background: transparent; }
    .pages a.active { background: #0f2044; color: white; }

    
</style>

<div class="content">
    <div class="page-header">
        <h2>Applications Management</h2>
        <p>Handles all submitted mobility applications.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" name="search" form="filterForm" placeholder="Search by name / program" value="<?= h($search) ?>">
        </div>
        <div class="tabs">
            <a href="?view=all" class="<?= $view === 'all' ? 'active' : '' ?>">All</a>
            <a href="?view=new" class="<?= $view === 'new' ? 'active' : '' ?>">New</a>
            <a href="?view=review" class="<?= $view === 'review' ? 'active' : '' ?>">Review</a>
        </div>
    </div>

    <form method="GET" class="filter-bar" id="filterForm">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="hidden" name="order" value="<?= h($order) ?>">
        <select name="campus" aria-label="Campus">
            <option value="0">All Campuses</option>
            <?php foreach ($campuses as $campus): ?>
                <option value="<?= (int)$campus['id'] ?>" <?= $campusId === (int)$campus['id'] ? 'selected' : '' ?>><?= h($campus['campus_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="degree_program" aria-label="Degree program">
            <option value="">All Degree Programs</option>
            <?php foreach ($degreePrograms as $degree): ?>
                <option value="<?= h($degree) ?>" <?= $degreeProgram === $degree ? 'selected' : '' ?>><?= h($degree) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="applicant_type" aria-label="Applicant type">
            <option value="">All Applicant Types</option>
            <?php foreach ($applicantTypes as $type): ?>
                <option value="<?= h($type) ?>" <?= strtolower($applicantType) === strtolower((string)$type) ? 'selected' : '' ?>><?= h(ucwords((string)$type)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="program" aria-label="Program or event">
            <option value="">All Programs / Events</option>
            <?php foreach ($pdo->query("SELECT DISTINCT program_name FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_COLUMN) as $programName): ?>
                <option value="<?= h($programName) ?>" <?= $program === $programName ? 'selected' : '' ?>><?= h($programName) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><i class="fa fa-filter"></i> Filter</button>
    </form>

    <div class="table-section">
        <table class="table-card">
            <thead>
                <tr>
                    <th><?= sortHeader('Applicant', 'applicant', $sort, $order) ?></th>
                    <th><?= sortHeader('Program', 'program', $sort, $order) ?></th>
                    <th><?= sortHeader('Degree Program', 'degree_program', $sort, $order) ?></th>
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
                    <td><?= h($a['degree_program'] ?? '—') ?></td>
<td>
    <?php 
        $statusRaw = $a['status'] ?? 'unknown';
        // Replaces underscore with space (e.g. under_review -> under review)
        $statusLabel = ucwords(str_replace('_', ' ', $statusRaw)); 
        $statusKey = strtolower($statusRaw);
    ?>
    <span class="status-badge status-<?= h($statusKey) ?>">
        <?= h($statusLabel) ?>
    </span>
</td>
                    <td><?= h($a['country']) ?></td>
                    <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <div class="action-btns">
                            <button class="btn-view" onclick='openModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)' title="View">
                                <i class="fa fa-eye"></i>
                            </button>
                            
                            <!-- Only Approvers and Super Admin can Approve/Reject -->
                            <?php if (isApprover() || isSuperAdmin()): ?>
                                <button class="btn-approve" onclick="confirmAction('approve', <?= h($a['id']) ?>)" title="Approve">
                                    <i class="fa fa-check"></i>
                                </button>
                                <button class="btn-reject" onclick="confirmAction('reject', <?= h($a['id']) ?>) " title="Reject">
                                    <i class="fa fa-close"></i>
                                </button>
                            <?php endif; ?>

                            <!-- Only Admins and Super Admin can Delete -->
                            <?php if (canManageSystem()): ?>
                                <button class="btn-delete" onclick="confirmAction('delete', <?= h($a['id']) ?>)" title="Delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state" style="text-align:center; padding: 40px;">
                            <h3>No applications found</h3>
                            <p>Try adjusting your search or filters.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <span>Showing <?= count($applications) ?> of <?= $totalApplications ?> applications</span>
        <div class="pages">
            <?php
                $baseQ = array_merge($_GET, []);
                unset($baseQ['p']);
            ?>
            <?php if ($page > 1): ?>
                <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>"><i class="fa fa-angle-left"></i></a>
            <?php else: ?>
                <button class="page-arrow" disabled><i class="fa fa-angle-left"></i></button>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a class="page-arrow" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>"><i class="fa fa-angle-right"></i></a>
            <?php else: ?>
                <button class="page-arrow" disabled><i class="fa fa-angle-right"></i></button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODALS (Keep your existing modal HTML here, no changes needed) -->
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
                    <div class="modal-field"><label>Name</label><span id="m-name"></span></div>
                    <div class="modal-field"><label>Email</label><span id="m-email"></span></div>
                </div>
            </div>
            <div class="modal-section">
                <h4>Application</h4>
                <div class="modal-grid">
                    <div class="modal-field"><label>Program</label><span id="m-program"></span></div>
                    <div class="modal-field"><label>Degree Program</label><span id="m-department"></span></div>
                    <div class="modal-field"><label>Mobility Type</label><span id="m-mobility"></span></div>
                    <div class="modal-field"><label>Institution</label><span id="m-institution"></span></div>
                    <div class="modal-field"><label>Country</label><span id="m-country"></span></div>
                </div>
            </div>
            <div class="modal-section">
                <h4>Status</h4>
                <div class="modal-grid">
                    <div class="modal-field"><label>Application Status</label><span id="m-status"></span></div>
                    <div class="modal-field"><label>Documents Status</label><span id="m-docs-status"></span></div>
                    <div class="modal-field"><label>Submitted</label><span id="m-created"></span></div>
                    <div class="modal-field"><label>Reviewed</label><span id="m-reviewed"></span></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

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
    // ADD THIS LINE: Send the CSRF token
    formData.append('csrf_token', '<?= $csrfToken ?>'); 

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