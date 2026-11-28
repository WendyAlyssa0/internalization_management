<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}

if (!canManageActivities() && !isApprover() && !isAdmin()) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to view applications.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================
   FILTERS  (S23 order: campus → degree → type → program → search)
========================= */
$search        = trim($_GET['search'] ?? '');
$campusId      = (int)($_GET['campus'] ?? 0);

/* S1: a campus coordinator only sees their own campus.
   ASSUMES the campus is stored in $_SESSION['campus_id'] - change the key if yours differs. */
$scopedCampusId = (!isSuperAdmin() && !empty($_SESSION['campus_id'])) ? (int)$_SESSION['campus_id'] : 0;
if ($scopedCampusId > 0) {
    $campusId = $scopedCampusId;
}
$degreeProgram = trim($_GET['degree_program'] ?? '');
$applicantType = trim($_GET['applicant_type'] ?? '');
$program       = trim($_GET['program'] ?? '');
$view          = (string)($_GET['view'] ?? 'all');
if (!in_array($view, ['all', 'new', 'review'], true)) {
    $view = 'all';
}
$sort          = $_GET['sort'] ?? 'created_at';
$order         = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

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
    'id'             => 'a.id',
    'applicant'      => 'applicant_name',
    'program'        => 'p.program_name',
    'degree_program' => 'u.program',
    'status'         => 'a.status',
    'country'        => 'c.country_name',
    'created_at'     => 'a.created_at',
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
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
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

if ($program !== '') {
    $where .= " AND p.program_name = ?";
    $params[] = $program;
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
$totalPages = max(1, (int)ceil($totalApplications / $limit));
if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $limit;
}

/* =========================
   FILTER OPTIONS
========================= */
$campuses = $pdo->query("SELECT id, campus_name FROM campuses WHERE LOWER(status) = 'active' ORDER BY campus_name")->fetchAll(PDO::FETCH_ASSOC);
if ($scopedCampusId > 0) {
    $campuses = array_values(array_filter($campuses, static function ($c) use ($scopedCampusId) {
        return (int)$c['id'] === $scopedCampusId;
    }));
}

$degreeQuery = $pdo->prepare("
    SELECT DISTINCT degree_program
    FROM (
        SELECT dp.program_name AS degree_program
        FROM degree_programs dp
        WHERE LOWER(dp.status) = 'active' AND (? = 0 OR dp.campus_id = ?)
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

$programNames = $pdo->query("SELECT DISTINCT program_name FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_COLUMN);

/* =========================
   DATA QUERY  (S24: degree program replaces department)
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
ORDER BY $sortColumn $order, a.id DESC
LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

function sortHeader(string $label, string $col, string $sort, string $order): string {
    $isActive = $sort === $col;
    $nextOrder = ($isActive && $order === 'DESC') ? 'ASC' : 'DESC';
    $icon = $isActive ? ($order === 'DESC' ? 'fa-sort-down' : 'fa-sort-up') : 'fa-sort';
    $cls = $isActive ? 'sort-icon sort-icon--active' : 'sort-icon';
    $url = http_build_query(array_merge($_GET, ['sort' => $col, 'order' => $nextOrder]));
    return '<span class="th-inner"><a href="?' . h($url) . '" class="th-link">' . h($label) . '</a><i class="fa ' . $cls . '"></i></span>';
}

function appStatusClass(string $status): string {
    $s = strtolower(str_replace(' ', '_', $status));
    if (in_array($s, ['approved', 'completed'], true)) return 'status-approved';
    if (in_array($s, ['rejected', 'cancelled', 'declined'], true)) return 'status-rejected';
    if (in_array($s, ['submitted', 'under_review', 'pending'], true)) return 'status-submitted';
    return 'status-submitted';
}

/* S22: show at a glance whether an applicant's requirements are complete */
function docStatusClass(string $status): string {
    $s = strtolower(str_replace(' ', '_', $status));
    if (in_array($s, ['complete', 'completed', 'verified', 'approved'], true)) return 'status-approved';
    if (in_array($s, ['incomplete', 'missing', 'rejected'], true)) return 'status-rejected';
    return 'status-submitted';
}
?>

<link rel="stylesheet" href="../asset/css/users.css">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
/* Page-specific polish layered on top of users.css */
.th-inner { display: inline-flex; align-items: center; gap: 6px; }
.th-link { color: inherit; text-decoration: none; font-weight: 600; }
.th-link:hover { color: #0f2044; }
.sort-icon { font-size: 0.7rem; color: #cbd5e1; }
.sort-icon--active { color: #2563eb; }

/* Toolbar Layout Fix */
.toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
.search-wrap { flex: 1; min-width: 250px; max-width: 400px; }
.tabs { display: flex; gap: 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 9999px; padding: 4px; }
.tabs a { text-decoration: none; font-size: 0.875rem; font-weight: 500; color: #475569; padding: 6px 20px; border-radius: 9999px; transition: 0.2s; }
.tabs a.active { background: #0f2044; color: #fff; }

/* Filter Bar Layout Fix */
.filter-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 20px; }
.filter-bar select { flex: 1; min-width: 160px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.875rem; color: #334155; background: #fff; }
.filter-bar button { padding: 8px 16px; border-radius: 6px; border: none; background: #0f2044; color: #fff; font-weight: 500; font-size: 0.875rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
.filter-bar button:hover { background: #1e3a8a; }

.status-badge.status-submitted    { background: #e0f2fe; color: #0369a1; }
.status-badge.status-under_review { background: #e0f2fe; color: #0369a1; }
.status-badge.status-approved     { background: #dcfce7; color: #15803d; }
.status-badge.status-rejected     { background: #fee2e2; color: #b91c1c; }

/* Requirement review link */
.review-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 6px;
    background: #eff6ff; color: #2563eb; font-size: 0.75rem;
    font-weight: 600; text-decoration: none; border: 1px solid #bfdbfe;
}
.review-link:hover { background: #dbeafe; }

/* Toast */
.toast {
    position: fixed; bottom: 28px; right: 28px; z-index: 2000;
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px; border-radius: 8px; font-size: 0.875rem;
    font-weight: 500; color: white; box-shadow: 0 10px 15px -3px rgba(0,0,0,.1);
    transform: translateY(12px); opacity: 0;
    transition: opacity 0.25s ease, transform 0.25s ease; pointer-events: none;
}
.toast--show { opacity: 1; transform: translateY(0); }
.toast--success { background: #0f2044; }
.toast--error   { background: #dc2626; }

/* Page-specific polish layered on top of users.css */
.th-inner { display: inline-flex; align-items: center; gap: 6px; }
.th-link { color: inherit; text-decoration: none; font-weight: 600; }
.th-link:hover { color: #0f2044; }
.sort-icon { font-size: 0.7rem; color: #cbd5e1; }
.sort-icon--active { color: #2563eb; }

.tabs { display: flex; gap: 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 9999px; padding: 4px; }
.tabs a { text-decoration: none; font-size: 0.875rem; font-weight: 500; color: #475569; padding: 6px 20px; border-radius: 9999px; }
.tabs a.active { background: #0f2044; color: #fff; }

.status-badge.status-submitted    { background: #e0f2fe; color: #0369a1; }
.status-badge.status-under_review { background: #e0f2fe; color: #0369a1; }
.status-badge.status-approved     { background: #dcfce7; color: #15803d; }
.status-badge.status-rejected     { background: #fee2e2; color: #b91c1c; }

/* Requirement review link */
.review-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 6px;
    background: #eff6ff; color: #2563eb; font-size: 0.75rem;
    font-weight: 600; text-decoration: none; border: 1px solid #bfdbfe;
}
.review-link:hover { background: #dbeafe; }

/* Toast */
.toast {
    position: fixed; bottom: 28px; right: 28px; z-index: 2000;
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px; border-radius: 8px; font-size: 0.875rem;
    font-weight: 500; color: white; box-shadow: 0 10px 15px -3px rgba(0,0,0,.1);
    transform: translateY(12px); opacity: 0;
    transition: opacity 0.25s ease, transform 0.25s ease; pointer-events: none;
}
.toast--show { opacity: 1; transform: translateY(0); }
.toast--success { background: #0f2044; }
.toast--error   { background: #dc2626; }
</style>

<div class="content">
    <div class="page-header">
        <h2>Applications Management</h2>
        <p>Handles all submitted mobility applications.</p>
    </div>

    <!-- S23: campus → degree → applicant type → program, then search with ghost text -->
    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" name="search" form="filterForm"
                   placeholder="Search by applicant name / email / program"
                   value="<?= h($search) ?>">
        </div>
        <div class="tabs">
            <?php $tabQ = $_GET; unset($tabQ['p'], $tabQ['view']); ?>
            <?php foreach (['all' => 'All', 'new' => 'New', 'review' => 'Review'] as $tabKey => $tabLabel): ?>
                <a href="?<?= h(http_build_query(array_merge($tabQ, ['view' => $tabKey]))) ?>" class="<?= $view === $tabKey ? 'active' : '' ?>"><?= $tabLabel ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="GET" class="filter-bar" id="filterForm">
        <?php if (isset($_GET['page']) && is_scalar($_GET['page'])): ?>
            <input type="hidden" name="page" value="<?= h($_GET['page']) ?>">
        <?php endif; ?>
        <input type="hidden" name="view"  value="<?= h($view) ?>">
        <input type="hidden" name="sort"  value="<?= h($sort) ?>">
        <input type="hidden" name="order" value="<?= h($order) ?>">

        <select name="campus" aria-label="Campus" id="campusFilter">
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
            <?php foreach ($programNames as $programName): ?>
                <option value="<?= h($programName) ?>" <?= $program === $programName ? 'selected' : '' ?>><?= h($programName) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit"><i class="fa fa-filter"></i> Filter</button>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th><?= sortHeader('Applicant',       'applicant',      $sort, $order) ?></th>
                    <th><?= sortHeader('Program',         'program',        $sort, $order) ?></th>
                    <th><?= sortHeader('Degree Program',  'degree_program', $sort, $order) ?></th>
                    <th><?= sortHeader('Status',          'status',         $sort, $order) ?></th>
                    <th>Documents</th>
                    <th><?= sortHeader('Country',         'country',        $sort, $order) ?></th>
                    <th><?= sortHeader('Date',            'created_at',     $sort, $order) ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if ($applications): ?>
                <?php foreach ($applications as $a): ?>
                    <?php
                        $initials = '';
                        $parts = explode(' ', trim($a['applicant_name'] ?? ''));
                        foreach ($parts as $p) {
                            if ($p !== '') $initials .= strtoupper(substr($p, 0, 1));
                            if (strlen($initials) >= 2) break;
                        }
                        $statusRaw   = (string)($a['status'] ?? 'unknown');
                        $statusLabel = ucwords(str_replace('_', ' ', $statusRaw));
                        $isPending   = in_array(strtolower(str_replace(' ', '_', $statusRaw)), ['submitted', 'under_review', 'pending'], true);
                    ?>
                <tr>
                    <td>
                        <div class="user-name-cell">
                            <div class="user-avatar"><?= h($initials ?: '?') ?></div>
                            <div>
                                <div style="font-weight:600; color:#0f172a;"><?= h($a['applicant_name']) ?></div>
                                <div style="font-size:0.78rem; color:#94a3b8;"><?= h($a['applicant_email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= h($a['program']) ?></td>
                    <td><?= h($a['degree_program'] ?? '—') ?></td>
                    <td>
                        <span class="status-badge <?= h(appStatusClass($statusRaw)) ?>">
                            <?= h($statusLabel) ?>
                        </span>
                    </td>
                    <td>
                        <?php $ds = (string)($a['documents_status'] ?? ''); ?>
                        <?php if ($ds !== ''): ?>
                            <span class="status-badge <?= h(docStatusClass($ds)) ?>"><?= h(ucwords(str_replace('_', ' ', $ds))) ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($a['country']) ?></td>
                    <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <div class="actions">
                            <button class="action-btn edit-btn" title="View" onclick='openModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)'>
                                <i class="fa fa-eye"></i>
                            </button>

                            <!-- S17/S22: Document review entry point (requirements checklist) -->
                            <a class="review-link" href="?page=documents&application_id=<?= (int)$a['id'] ?>">
                                <i class="fa-solid fa-clipboard-check"></i> Review
                            </a>

                            <?php if ($isPending && (isApprover() || isSuperAdmin())): ?>
                                <button class="action-btn edit-btn" title="Approve" onclick="confirmAction('approve', <?= (int)$a['id'] ?>)">
                                    <i class="fa fa-check"></i>
                                </button>
                                <button class="action-btn delete-btn" title="Reject" onclick="confirmAction('reject', <?= (int)$a['id'] ?>)">
                                    <i class="fa fa-close"></i>
                                </button>
                            <?php endif; ?>

                            <?php if (canManageSystem()): ?>
                                <button class="action-btn delete-btn" title="Delete" onclick="confirmAction('delete', <?= (int)$a['id'] ?>)">
                                    <i class="fa fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
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
                                    <path d="M14 2v6h6"/>
                                    <path d="M9 15h6"/>
                                    <path d="M9 11h6"/>
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

        <div class="pagination">
            <span>Showing <?= count($applications) ?> of <?= $totalApplications ?> applications</span>
            <div class="pages">
                <?php
                    $baseQ = $_GET;
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
</div>

<!-- VIEW MODAL (unchanged behavior, restyled shell) -->
<div id="appModal" class="modal-backdrop" role="dialog" aria-modal="true" onclick="closeModalOutside(event)">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3>Application Details</h3>
                <p>Review submitted application data.</p>
            </div>
            <button class="modal-close" onclick="closeModal()" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-section-title">Applicant</div>
            <div class="form-grid">
                <div class="form-group col-6"><label>Name</label><span id="m-name" style="font-weight:500;"></span></div>
                <div class="form-group col-6"><label>Email</label><span id="m-email" style="font-weight:500;"></span></div>
            </div>

            <div class="form-section-title">Application</div>
            <div class="form-grid">
                <div class="form-group col-6"><label>Program</label><span id="m-program" style="font-weight:500;"></span></div>
                <div class="form-group col-6"><label>Degree Program</label><span id="m-department" style="font-weight:500;"></span></div>
                <div class="form-group col-6"><label>Mobility Type</label><span id="m-mobility" style="font-weight:500;"></span></div>
                <div class="form-group col-6"><label>Institution</label><span id="m-institution" style="font-weight:500;"></span></div>
                <div class="form-group col-6"><label>Country</label><span id="m-country" style="font-weight:500;"></span></div>
            </div>

            <div class="form-section-title">Status</div>
            <div class="form-grid">
                <div class="form-group col-6"><label>Application Status</label><span id="m-status"></span></div>
                <div class="form-group col-6"><label>Documents Status</label><span id="m-docs-status"></span></div>
                <div class="form-group col-6"><label>Submitted</label><span id="m-created" style="font-weight:500;"></span></div>
                <div class="form-group col-6"><label>Reviewed</label><span id="m-reviewed" style="font-weight:500;"></span></div>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn-cancel" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<!-- CONFIRM MODAL -->
<div id="confirmModal" class="modal-backdrop" role="dialog" aria-modal="true" onclick="closeConfirmOutside(event)">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-header">
            <div><h3 id="confirm-title">Confirm Action</h3></div>
            <button class="modal-close" onclick="closeConfirm()" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="confirm-icon" style="text-align:center; margin-bottom:14px;"></div>
            <p id="confirm-message" style="text-align:center; color:#475569; margin:0;"></p>
        </div>
        <div class="form-actions">
            <button class="btn-cancel" onclick="closeConfirm()">Cancel</button>
            <button id="confirm-btn" class="btn-submit" onclick="executeAction()">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    /* Search: debounced submit of the filter form */
    var searchInput = document.getElementById('searchInput');
    var searchTimer;
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                document.getElementById('filterForm').submit();
            }, 400);
        });
    }

    /* S23: changing the campus resets the degree program and reloads its list */
    var campusFilter = document.getElementById('campusFilter');
    if (campusFilter) {
        campusFilter.addEventListener('change', function () {
            var degree = document.querySelector('select[name="degree_program"]');
            if (degree) degree.value = '';
            document.getElementById('filterForm').submit();
        });
    }

    /* View modal */
    window.openModal = function (data) {
        try {
            if (!data) return;
            data = (typeof data === 'string') ? JSON.parse(data) : data;
            var fmt = function (v) { return (v !== null && v !== undefined && v !== '') ? v : '—'; };
            var fmtDate = function (v) {
                if (!v) return '—';
                return new Date(String(v).replace(' ', 'T')).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            };
            var statusHtml = function (status) {
                if (!status) return '—';
                var cls = String(status).toLowerCase().replace(/[^a-z0-9]+/g, '_');
                var safe = String(status).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                return '<span class="status-badge status-' + cls + '">' + safe + '</span>';
            };
            document.getElementById('m-name').textContent         = fmt(data.applicant_name);
            document.getElementById('m-email').textContent        = fmt(data.applicant_email);
            document.getElementById('m-program').textContent      = fmt(data.program);
            document.getElementById('m-department').textContent   = fmt(data.degree_program);
            document.getElementById('m-mobility').textContent     = fmt(data.mobility_type);
            document.getElementById('m-institution').textContent  = fmt(data.institution);
            document.getElementById('m-country').textContent      = fmt(data.country);
            document.getElementById('m-status').innerHTML         = statusHtml(data.status);
            document.getElementById('m-docs-status').innerHTML    = statusHtml(data.documents_status);
            document.getElementById('m-created').textContent      = fmtDate(data.created_at);
            document.getElementById('m-reviewed').textContent     = fmtDate(data.reviewed_at);
            document.getElementById('appModal').classList.add('open');
            document.body.style.overflow = 'hidden';
        } catch (err) { console.error('Modal error:', err); }
    };

    window.closeModal = function () {
        document.getElementById('appModal').classList.remove('open');
        document.body.style.overflow = '';
    };
    window.closeModalOutside = function (e) {
        if (e.target.id === 'appModal') closeModal();
    };

    /* Confirm modal */
    var pendingAction = null;
    var pendingId = null;

    var actionConfig = {
        approve: { title: 'Approve Application', message: 'Are you sure you want to approve this application?', icon: '<i class="fa fa-check-circle" style="color:#16a34a;font-size:2.2rem;"></i>', btnLabel: 'Yes, Approve', btnClass: 'btn-submit' },
        reject:  { title: 'Reject Application',  message: 'Are you sure you want to reject this application?',  icon: '<i class="fa fa-times-circle" style="color:#dc2626;font-size:2.2rem;"></i>', btnLabel: 'Yes, Reject',  btnClass: 'btn-submit' },
        delete:  { title: 'Delete Application',  message: 'This action cannot be undone.',                     icon: '<i class="fa fa-trash" style="color:#d97706;font-size:2.2rem;"></i>',        btnLabel: 'Yes, Delete',  btnClass: 'btn-submit' }
    };

    window.confirmAction = function (action, id) {
        var cfg = actionConfig[action]; if (!cfg) return;
        pendingAction = action; pendingId = id;
        document.getElementById('confirm-title').textContent = cfg.title;
        document.getElementById('confirm-message').textContent = cfg.message;
        document.getElementById('confirm-icon').innerHTML = cfg.icon;
        var btn = document.getElementById('confirm-btn');
        btn.textContent = cfg.btnLabel;
        btn.disabled = false;
        document.getElementById('confirmModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.closeConfirm = function () {
        document.getElementById('confirmModal').classList.remove('open');
        document.body.style.overflow = '';
        pendingAction = null; pendingId = null;
    };
    window.closeConfirmOutside = function (e) {
        if (e.target.id === 'confirmModal') closeConfirm();
    };

    window.executeAction = function () {
        if (!pendingAction || !pendingId) return;
        var btn = document.getElementById('confirm-btn');
        btn.disabled = true; btn.textContent = 'Processing...';
        var routeMap = { approve: 'application_approve.php', reject: 'application_reject.php', delete: 'application_delete.php' };
        var fd = new FormData();
        fd.append('id', pendingId);
        fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
        fetch(routeMap[pendingAction], { method: 'POST', body: fd })
            .then(function (res) { return res.text().then(function (t) { try { return JSON.parse(t); } catch (e) { throw new Error('Invalid server response'); } }); })
            .then(function (data) {
                closeConfirm();
                showToast(data.success ? 'success' : 'error', data.message || 'Unknown response');
                if (data.success) setTimeout(function () { window.location.reload(); }, 1000);
            })
            .catch(function (err) { console.error(err); closeConfirm(); showToast('error', 'Server error. Please try again.'); });
    };

    /* Toast */
    window.showToast = function (type, message) {
        var existing = document.querySelector('.toast');
        if (existing) existing.remove();
        var toast = document.createElement('div');
        toast.className = 'toast toast--' + type;
        toast.innerHTML = '<i class="fa fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i><span></span>';
        toast.querySelector('span').textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('toast--show'); });
        setTimeout(function () {
            toast.classList.remove('toast--show');
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    };

    /* ESC closes both */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeModal(); closeConfirm();
    });
})();
</script>