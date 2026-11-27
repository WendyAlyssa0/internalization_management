<?php
declare(strict_types=1);

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

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db();

/* =========================
   USER CONTEXT
========================= */
$user_id = (int)$_SESSION['user_id'];
$role    = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
$role    = str_replace('_', ' ', $role);

/* =========================
   HELPERS
========================= */
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================
   FILTERS
========================= */
$search = trim((string)($_GET['search'] ?? ''));
$type   = trim((string)($_GET['type'] ?? ''));
$view   = (string)($_GET['view'] ?? 'all');
$applicationId = max(0, (int)($_GET['application_id'] ?? 0));

/* =========================
   PAGINATION
========================= */
$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* ROLE ACCESS: non-admins only see their own uploads */
$isAdminRole = in_array($role, ['admin', 'super admin', 'ad', 'sa'], true);
$like = '%' . addcslashes($search, '%_\\') . '%';

/* S1: a campus coordinator only sees uploads from their own campus.
   ASSUMES $_SESSION['campus_id'] (same key as applications.php). */
$scopedCampusId = ($isAdminRole && !isSuperAdmin() && !empty($_SESSION['campus_id'])) ? (int)$_SESSION['campus_id'] : 0;

/* =========================================================
   SOURCE A: documents table (general uploaded documents)
========================================================= */
$whereA  = "WHERE 1=1";
$paramsA = [];

if (!$isAdminRole) {
    $whereA .= " AND d.user_id = :user_id";
    $paramsA[':user_id'] = $user_id;
}
if ($search !== '') {
    $whereA .= " AND (
        d.id LIKE :s1 OR
        d.title LIKE :s2 OR
        d.file_type LIKE :s3 OR
        COALESCE(CONCAT(u.first_name,' ',u.last_name), '') LIKE :s4
    )";
    $paramsA[':s1'] = $like;
    $paramsA[':s2'] = $like;
    $paramsA[':s3'] = $like;
    $paramsA[':s4'] = $like;
}
if ($type !== '') {
    $whereA .= " AND LOWER(d.file_type) = LOWER(:type)";
    $paramsA[':type'] = $type;
}
if ($view === 'new') {
    $whereA .= " AND LOWER(d.status) = 'pending'";
}
if ($applicationId > 0) {
    $whereA .= " AND EXISTS (
        SELECT 1 FROM applications app
        WHERE app.id = :application_id AND app.applicant_id = d.user_id
    )";
    $paramsA[':application_id'] = $applicationId;
}
if ($scopedCampusId > 0) {
    $whereA .= " AND u.campus_id = :campus_id";
    $paramsA[':campus_id'] = $scopedCampusId;
}

$docsFromDocuments = [];
$stmtA = $pdo->prepare("
    SELECT
        d.id,
        d.title,
        d.file_type,
        d.file_path,
        d.status,
        d.created_at,
        COALESCE(CONCAT(u.first_name,' ',u.last_name), 'Unknown') AS uploader
    FROM documents d
    LEFT JOIN users u ON d.user_id = u.id
    $whereA
    ORDER BY d.created_at DESC
    LIMIT 2000
");
$stmtA->execute($paramsA);
foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['source'] = 'document';
    $docsFromDocuments[] = $row;
}

/* =========================================================
   SOURCE B: user_documents table (applicant requirement uploads)
========================================================= */
$docsFromApplicants = [];

if ($view !== 'new') {
    $extExpr = "LOWER(SUBSTRING_INDEX(ud.file_path, '.', -1))";
    $whereB  = "WHERE 1=1";
    $paramsB = [];

    if (!$isAdminRole) {
        $whereB .= " AND ud.user_id = :user_id";
        $paramsB[':user_id'] = $user_id;
    }
    if ($search !== '') {
        $whereB .= " AND (
            ud.id LIKE :s1 OR
            r.requirement_name LIKE :s2 OR
            COALESCE(p.program_name, '') LIKE :s3 OR
            COALESCE(CONCAT(u.first_name,' ',u.last_name), '') LIKE :s4
        )";
        $paramsB[':s1'] = $like;
        $paramsB[':s2'] = $like;
        $paramsB[':s3'] = $like;
        $paramsB[':s4'] = $like;
    }
    if ($type !== '') {
        if (strtolower($type) === 'image') {
            $whereB .= " AND $extExpr IN ('jpg','jpeg','png')";
        } else {
            $whereB .= " AND $extExpr = LOWER(:type)";
            $paramsB[':type'] = $type;
        }
    }
    if ($applicationId > 0) {
        $whereB .= " AND EXISTS (
            SELECT 1 FROM applications app
            WHERE app.id = :application_id
              AND app.applicant_id = ud.user_id
              AND app.program_id = pr.program_id
        )";
        $paramsB[':application_id'] = $applicationId;
    }
    if ($scopedCampusId > 0) {
        $whereB .= " AND u.campus_id = :campus_id";
        $paramsB[':campus_id'] = $scopedCampusId;
    }

    try {
        $stmtB = $pdo->prepare("
            SELECT
                ud.id,
                CONCAT_WS(' - ', r.requirement_name, p.program_name) AS title,
                $extExpr AS file_type,
                ud.file_path,
                COALESCE(ud.status, 'pending') AS status,
                ud.uploaded_at AS created_at,
                COALESCE(CONCAT(u.first_name,' ',u.last_name), 'Unknown') AS uploader
            FROM user_documents ud
            INNER JOIN program_requirements pr ON pr.id = ud.requirement_id
            INNER JOIN requirements r          ON r.id  = pr.requirement_id
            LEFT JOIN programs p               ON p.id  = pr.program_id
            LEFT JOIN users u                  ON u.id  = ud.user_id
            $whereB
            ORDER BY ud.uploaded_at DESC
            LIMIT 2000
        ");
        $stmtB->execute($paramsB);
        foreach ($stmtB->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['source'] = 'applicant';
            $docsFromApplicants[] = $row;
        }
    } catch (PDOException $e) {
        error_log('documents page: applicant uploads query failed: ' . $e->getMessage());
    }
}

/* =========================
   MERGE + SORT + PAGINATE
========================= */
$allDocs = array_merge($docsFromDocuments, $docsFromApplicants);
usort($allDocs, static function (array $a, array $b): int {
    return strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']);
});

$totalDocs  = count($allDocs);
$totalPages = max(1, (int)ceil($totalDocs / $limit));
$documents  = array_slice($allDocs, $offset, $limit);

/* =========================
   PERMISSION FLAGS FOR UI
========================= */
$canReview = isApprover() || isAdmin() || isSuperAdmin();
$canDelete = canManageSystem() || isAdmin() || isSuperAdmin();

$applicationReviews = [];
if ($isAdminRole) {
$reviewWhere = $scopedCampusId > 0 ? 'WHERE u.campus_id = ?' : '';
$stmtR = $pdo->prepare("
    SELECT a.id, a.applicant_id, a.program_id, a.status,
           CONCAT_WS(' ', u.first_name, u.last_name) AS applicant,
           p.program_name,
           COUNT(DISTINCT CASE WHEN pr.is_required = 1 THEN pr.id END) AS required_count,
           COUNT(DISTINCT CASE WHEN pr.is_required = 1 AND ud.id IS NOT NULL THEN pr.id END) AS uploaded_count
    FROM applications a
    LEFT JOIN users u ON u.id = a.applicant_id
    LEFT JOIN programs p ON p.id = a.program_id
    LEFT JOIN program_requirements pr ON pr.program_id = a.program_id
    LEFT JOIN user_documents ud
        ON ud.user_id = a.applicant_id
       AND ud.requirement_id = pr.id
    $reviewWhere
    GROUP BY a.id, a.applicant_id, a.program_id, a.status, u.first_name, u.last_name, p.program_name
    ORDER BY a.created_at DESC
    LIMIT 100
");
$stmtR->execute($scopedCampusId > 0 ? [$scopedCampusId] : []);
$applicationReviews = $stmtR->fetchAll(PDO::FETCH_ASSOC);
}
?>

<link rel="stylesheet" href="../asset/css/applications.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<!-- Added FontAwesome just in case it's missing from your header -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* =========================================================
   INJECTED MODAL CSS - FIXES THE UNSTYLED LAYOUT IN SCREENSHOT
========================================================= */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.5);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.open {
    display: flex;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    transform: scale(0.95);
    opacity: 0;
    transition: all 0.2s ease;
}
.modal-overlay.open .modal-box {
    transform: scale(1);
    opacity: 1;
}
.modal-box--sm { max-width: 400px; }
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #0f172a;
}
.modal-close {
    background: transparent;
    border: none;
    cursor: pointer;
    color: #64748b;
    padding: 4px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}
.modal-close:hover { background: #f1f5f9; }

.confirm-body {
    padding: 24px 20px;
    text-align: center;
}
.confirm-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
}
.confirm-icon-wrap--view    { background: #dbeafe; color: #2563eb; }
.confirm-icon-wrap--approve { background: #d1fae5; color: #10b981; }
.confirm-icon-wrap--reject  { background: #fee2e2; color: #ef4444; }
.confirm-icon-wrap--delete  { background: #fef3c7; color: #f59e0b; }

.confirm-title {
    font-size: 18px;
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 8px;
}
.confirm-message {
    font-size: 14px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}
.btn-cancel {
    padding: 8px 16px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #475569;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-cancel:hover { background: #f1f5f9; }
.btn-action {
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    color: #fff;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.btn-action:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-action--view    { background: #2563eb; }
.btn-action--view:hover { background: #1d4ed8; }
.btn-action--approve { background: #10b981; }
.btn-action--approve:hover { background: #059669; }
.btn-action--reject  { background: #ef4444; }
.btn-action--reject:hover { background: #dc2626; }
.btn-action--delete  { background: #f59e0b; }
.btn-action--delete:hover { background: #d97706; }
</style>

<div class="page-header">
    <h2>Document Management</h2>
    <p>Handles all uploaded documents, including applicant requirement uploads.</p>
</div>

<?php if ($isAdminRole): ?>
<div class="table-section" style="margin-bottom:20px;">
    <h3 style="padding:16px 20px;margin:0;">Application requirement review</h3>
    <table class="table-card">
        <thead><tr><th>Applicant</th><th>Program</th><th>Required uploads</th><th>Application</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($applicationReviews as $review): ?>
            <tr>
                <td><?= h($review['applicant'] ?: 'Unknown applicant') ?> (#<?= (int)$review['id'] ?>)</td>
                <td><?= h($review['program_name'] ?? '—') ?></td>
                <td><?= (int)$review['uploaded_count'] ?> / <?= (int)$review['required_count'] ?> uploaded</td>
                <td><?= h(ucwords(str_replace('_', ' ', (string)$review['status']))) ?></td>
                <td><a href="?page=documents&application_id=<?= (int)$review['id'] ?>">Review documents</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$applicationReviews): ?><tr><td colspan="5">No applications to review.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php if ($applicationId > 0): ?>
    <p style="margin:0 0 12px;"><a href="?page=documents">Show documents from all applications</a></p>
<?php endif; ?>

<!-- SEARCH -->
<div class="toolbar">
    <div class="search-wrap">
        <i class="fa fa-search"></i>
        <input type="text" id="searchInput" placeholder="Search by uploader / title / type" value="<?= h($search) ?>">
    </div>
</div>

<!-- FILTER -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <?php if ($applicationId > 0): ?><input type="hidden" name="application_id" value="<?= $applicationId ?>"><?php endif; ?>

    <select name="type">
        <option value="">All Types</option>
        <option value="pdf"   <?= $type === 'pdf'   ? 'selected' : '' ?>>PDF</option>
        <option value="docx"  <?= $type === 'docx'  ? 'selected' : '' ?>>DOCX</option>
        <option value="image" <?= $type === 'image' ? 'selected' : '' ?>>Image</option>
    </select>

    <button type="submit">Filter</button>
</form>

<!-- TABLE -->
<div class="table-section">
<table class="table-card">
<thead>
<tr>
    <th>Uploader</th>
    <th>Title</th>
    <th>Type</th>
    <th>Status</th>
    <th>Date</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>
<?php if (!empty($documents)): ?>
    <?php foreach ($documents as $d): ?>
        <?php
            $isApplicantUpload = ($d['source'] === 'applicant');
            $statusKey = strtolower((string)$d['status']);
            $safePath  = ltrim((string)$d['file_path'], '/');
            $uploadsRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
            $absolutePath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $safePath));
            $linkable = $uploadsRoot && $absolutePath &&
                str_starts_with($absolutePath, $uploadsRoot . DIRECTORY_SEPARATOR) &&
                is_file($absolutePath);
            $href = '../' . $safePath;
        ?>
<tr>
    <td><?= h($d['uploader']) ?></td>
    <td>
        <?= h($d['title']) ?>
        <?php if ($isApplicantUpload): ?>
            <br><small style="color:#64748b;">Applicant requirement</small>
        <?php endif; ?>
    </td>
    <td><?= h($d['file_type']) ?></td>

    <td>
        <span class="status-badge status-<?= h($statusKey) ?>">
            <?= h(ucfirst($statusKey)) ?>
        </span>
    </td>

    <td><?= h(date('M d, Y', strtotime((string)$d['created_at']))) ?></td>

    <td class="action-btns">
        <?php if ($linkable): ?>
            <button type="button" class="btn-view" title="View document"
                    onclick="confirmAction('view', <?= (int)$d['id'] ?>, '<?= h($d['source']) ?>', <?= h(json_encode($href, JSON_UNESCAPED_SLASHES)) ?>)">
                <i class="fa fa-eye"></i>
            </button>
        <?php else: ?>
            <!-- Fallback if file path check fails, show the button anyway to avoid missing buttons -->
            <button type="button" class="btn-view" title="View document" onclick="alert('File not found or path invalid.')">
                <i class="fa fa-eye"></i>
            </button>
        <?php endif; ?>

        <?php if ($canReview && $statusKey === 'pending'): ?>
            <button type="button" class="btn-approve" title="Approve document"
                    onclick="confirmAction('approve', <?= (int)$d['id'] ?>, '<?= h($d['source']) ?>')">
                <i class="fa fa-check"></i>
            </button>
            <button type="button" class="btn-reject" title="Reject document"
                    onclick="confirmAction('reject', <?= (int)$d['id'] ?>, '<?= h($d['source']) ?>')">
                <i class="fa fa-close"></i>
            </button>
        <?php endif; ?>

        <?php if ($canDelete): ?>
            <button type="button" class="btn-delete" title="Delete document"
                    onclick="confirmAction('delete', <?= (int)$d['id'] ?>, '<?= h($d['source']) ?>')">
                <i class="fa fa-trash"></i>
            </button>
        <?php endif; ?>
    </td>
</tr>
    <?php endforeach; ?>
<?php else: ?>
<tr>
    <td colspan="6" class="empty-row">No documents found</td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- PAGINATION -->
<div class="pagination">
    <span>Showing <?= count($documents) ?> of <?= $totalDocs ?> documents</span>
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

</div>
</div>

<!-- CONFIRM MODAL -->
<div id="confirmModal" class="modal-overlay" role="dialog" aria-modal="true"
     aria-labelledby="confirm-title" onclick="closeConfirmOutside(event)">
    <div class="modal-box modal-box--sm">

        <div class="modal-header">
            <h3 id="confirm-title">Confirm Action</h3>
            <button type="button" class="modal-close" onclick="closeConfirm()" aria-label="Close">
                <i class="ti ti-x"></i>
            </button>
        </div>

        <div class="confirm-body">
            <div class="confirm-icon-wrap" id="confirm-icon-wrap">
                <i id="confirm-icon" style="font-size:26px"></i>
            </div>
            <p class="confirm-title" id="confirm-heading"></p>
            <p class="confirm-message" id="confirm-message"></p>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeConfirm()">Cancel</button>
            <button type="button" id="confirm-btn" class="btn-action" onclick="executeAction()">
                <i id="confirm-btn-icon" style="font-size:13px"></i>
                <span id="confirm-btn-label">Confirm</span>
            </button>
        </div>

    </div>
</div>

<script>
const DOCS_CSRF = <?= json_encode($csrfToken) ?>;

let pendingAction = null;
let pendingId     = null;
let pendingSource = 'document';
let pendingHref   = null;
let isSubmitting  = false;

const ACTION_CONFIG = {
    view: {
        title:     'View Document',
        heading:   'Open this document?',
        message:   'The document will open in a new tab.',
        icon:      'ti ti-eye',
        iconColor: '#2563eb',
        wrapClass: 'confirm-icon-wrap--view',
        btnClass:  'btn-action--view',
        btnIcon:   'ti ti-external-link',
        btnLabel:  'Open',
        endpoint:  null
    },
    approve: {
        title:     'Approve Document',
        heading:   'Approve this document?',
        message:   'This will mark the document as approved and notify the uploader.',
        icon:      'ti ti-circle-check',
        iconColor: '#10b981',
        wrapClass: 'confirm-icon-wrap--approve',
        btnClass:  'btn-action--approve',
        btnIcon:   'ti ti-check',
        btnLabel:  'Approve',
        endpoint:  './document_approve.php'
    },
    reject: {
        title:     'Reject Document',
        heading:   'Reject this document?',
        message:   'This will mark the document as rejected. The uploader will be informed.',
        icon:      'ti ti-circle-x',
        iconColor: '#ef4444',
        wrapClass: 'confirm-icon-wrap--reject',
        btnClass:  'btn-action--reject',
        btnIcon:   'ti ti-x',
        btnLabel:  'Reject',
        endpoint:  './document_reject.php'
    },
    delete: {
        title:     'Delete Document',
        heading:   'Delete this document?',
        message:   'This action is permanent and cannot be undone. The file will be removed.',
        icon:      'ti ti-trash',
        iconColor: '#f59e0b',
        wrapClass: 'confirm-icon-wrap--delete',
        btnClass:  'btn-action--delete',
        btnIcon:   'ti ti-trash',
        btnLabel:  'Delete',
        endpoint:  './document_delete.php'
    }
};

function confirmAction(action, id, source = 'document', href = null) {
    const c = ACTION_CONFIG[action];
    if (!c) return;

    pendingAction = action;
    pendingId     = id;
    pendingSource = source;
    pendingHref   = href;
    isSubmitting  = false;

    document.getElementById('confirm-title').textContent   = c.title;
    document.getElementById('confirm-heading').textContent = c.heading;
    document.getElementById('confirm-message').textContent = c.message;

    const iconEl = document.getElementById('confirm-icon');
    iconEl.className   = c.icon;
    iconEl.style.color = c.iconColor;

    document.getElementById('confirm-icon-wrap').className =
        'confirm-icon-wrap ' + c.wrapClass;

    const btn = document.getElementById('confirm-btn');
    btn.className = 'btn-action ' + c.btnClass;
    btn.disabled  = false;

    document.getElementById('confirm-btn-icon').className = c.btnIcon;
    document.getElementById('confirm-btn-label').textContent = c.btnLabel;

    document.getElementById('confirmModal').classList.add('open');
    btn.focus();
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
    pendingAction = null;
    pendingId     = null;
    pendingSource = 'document';
    pendingHref   = null;
    isSubmitting  = false;
}

function closeConfirmOutside(e) {
    if (e.target.id === 'confirmModal') closeConfirm();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeConfirm();
});

function executeAction() {
    if (isSubmitting || !pendingAction || pendingId === null) return;

    const cfg = ACTION_CONFIG[pendingAction];

    /* View: no AJAX call, just open the file in a new tab */
    if (pendingAction === 'view') {
        if (pendingHref) window.open(pendingHref, '_blank', 'noopener');
        closeConfirm();
        return;
    }

    if (!cfg.endpoint) return;

    isSubmitting = true;
    const btn = document.getElementById('confirm-btn');
    const labelEl = document.getElementById('confirm-btn-label');
    const originalLabel = labelEl.textContent;
    btn.disabled = true;
    labelEl.textContent = 'Working…';

    const formData = new FormData();
    formData.append('id', pendingId);
    formData.append('csrf_token', DOCS_CSRF);
    formData.append('source', pendingSource);

    fetch(cfg.endpoint, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(async r => {
        const text = await r.text();
        let data;
        try { data = JSON.parse(text); }
        catch { throw new Error('Invalid server response'); }
        if (!r.ok) throw new Error(data.message || 'Request failed (' + r.status + ')');
        return data;
    })
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Action failed');
            btn.disabled = false;
            labelEl.textContent = originalLabel;
            isSubmitting = false;
        }
    })
    .catch(err => {
        alert(err.message || 'Server error.');
        btn.disabled = false;
        labelEl.textContent = originalLabel;
        isSubmitting = false;
    });
}

/* Debounced search → reload with ?search= */
let searchTimer;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.set('search', searchInput.value.trim());
            url.searchParams.delete('p');
            window.location.href = url.toString();
        }, 400);
    });
}
</script>