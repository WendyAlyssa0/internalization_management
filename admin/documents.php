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

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

$user_id = $_SESSION['user_id'];
$role = strtolower($_SESSION['user_role'] ?? '');
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================
   FILTERS
========================= */
$search = trim($_GET['search'] ?? '');
$type   = trim($_GET['type'] ?? '');
$view   = $_GET['view'] ?? 'all';

/* =========================
   PAGINATION
========================= */
$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* =========================
   WHERE BUILDER
========================= */
$where = "WHERE 1=1";
$params = [];

/* ROLE ACCESS */
if ($role !== 'admin') {
    $where .= " AND d.user_id = ?";
    $params[] = $user_id;
}

/* SEARCH */
if ($search !== '') {
    $where .= " AND (
        d.id LIKE ? OR
        d.title LIKE ? OR
        d.file_type LIKE ? OR
        COALESCE(CONCAT(u.first_name,' ',u.last_name), '') LIKE ?
    )";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}

/* TYPE FILTER */
if ($type !== '') {
    $where .= " AND LOWER(d.file_type) = LOWER(?)";
    $params[] = $type;
}

/* VIEW FILTER */
if ($view === 'new') {
    $where .= " AND LOWER(d.status) = 'pending'";
}

/* =========================
   COUNT QUERY
========================= */
$countSql = "
    SELECT COUNT(*)
    FROM documents d
    LEFT JOIN users u ON d.user_id = u.id
    $where
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalDocs = (int)$countStmt->fetchColumn();

/* =========================
   DATA QUERY
========================= */
$sql = "
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
    $where
    ORDER BY d.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $i => $val) {
    $stmt->bindValue($i + 1, $val);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$documents  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, ceil($totalDocs / $limit));
?>

<link rel="stylesheet" href="../asset/css/applications.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">

<div class="content">

<div class="page-header">
    <h2>Document Management</h2>
    <p>Handles all uploaded documents.</p>
</div>

<!-- SEARCH -->
<div class="toolbar">
    <div class="search-wrap">
        <i class="fa fa-search"></i>
        <input type="text" id="searchInput" placeholder="Search documents..." value="<?= h($search) ?>">
    </div>
</div>

<!-- FILTER -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="view" value="<?= h($view) ?>">

    <select name="type">
        <option value="">All Types</option>
        <option value="pdf">PDF</option>
        <option value="docx">DOCX</option>
        <option value="image">Image</option>
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
<tr>
    <td><?= h($d['uploader']) ?></td>
    <td><?= h($d['title']) ?></td>
    <td><?= h($d['file_type']) ?></td>

    <td>
<span class="status-badge status-<?= strtolower($d['status']) ?>">
                <?= ucfirst($d['status']) ?>
        </span>
    </td>

    <td><?= date('M d, Y', strtotime($d['created_at'])) ?></td>

    <td class="action-btns">

        <a href="<?= h($d['file_path']) ?>" target="_blank" class="btn-view">
            <i class="fa fa-eye"></i>
        </a>

        <button class="btn-approve" onclick="confirmAction('approve', <?= (int)$d['id'] ?>)">
            <i class="fa fa-check"></i>
        </button>

        <button class="btn-reject" onclick="confirmAction('reject', <?= (int)$d['id'] ?>)">
            <i class="fa fa-close"></i>
        </button>

        <button class="btn-delete" onclick="confirmAction('delete', <?= (int)$d['id'] ?>)">
            <i class="fa fa-trash"></i>
        </button>

    </td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr>
    <td colspan="7" class="empty-row">No documents found</td>
</tr>
<?php endif; ?>
</tbody>
</table>

<!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($documents) ?> of <?= $totalDocs ?> documents
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

</div>

</div>

<!-- CONFIRM MODAL -->
<!-- CONFIRM MODAL -->
<div id="confirmModal" class="modal-overlay" onclick="closeConfirmOutside(event)">
    <div class="modal-box modal-box--sm">

        <div class="modal-header">
            <h3 id="confirm-title">Confirm Action</h3>
            <button class="modal-close" onclick="closeConfirm()">
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
            <button class="btn-cancel" onclick="closeConfirm()">Cancel</button>
            <button id="confirm-btn" class="btn-action" onclick="executeAction()">
                <i id="confirm-btn-icon" style="font-size:13px"></i>
                <span id="confirm-btn-label">Confirm</span>
            </button>
        </div>

    </div>
</div>

<script>
let pendingAction = null;
let pendingId = null;

const ACTION_CONFIG = {
    approve: {
        title:    'Approve Document',
        heading:  'Approve this document?',
        message:  'This will mark the document as approved and notify the uploader.',
        icon:     'ti ti-circle-check',
        iconColor:'#10b981',
        wrapClass:'confirm-icon-wrap--approve',
        btnClass: 'btn-action--approve',
        btnIcon:  'ti ti-check',
        btnLabel: 'Approve',
        endpoint: './document_approve.php'
    },
    reject: {
        title:    'Reject Document',
        heading:  'Reject this document?',
        message:  'This will mark the document as rejected. The uploader will be informed.',
        icon:     'ti ti-circle-x',
        iconColor:'#ef4444',
        wrapClass:'confirm-icon-wrap--reject',
        btnClass: 'btn-action--reject',
        btnIcon:  'ti ti-x',
        btnLabel: 'Reject',
        endpoint: './document_reject.php'
    },
    delete: {
        title:    'Delete Document',
        heading:  'Delete this document?',
        message:  'This action is permanent and cannot be undone. The file will be removed.',
        icon:     'ti ti-trash',
        iconColor:'#f59e0b',
        wrapClass:'confirm-icon-wrap--delete',
        btnClass: 'btn-action--delete',
        btnIcon:  'ti ti-trash',
        btnLabel: 'Delete',
        endpoint: './document_delete.php'
    }
};

function confirmAction(action, id) {
    pendingAction = action;
    pendingId = id;
    const c = ACTION_CONFIG[action];

    document.getElementById('confirm-title').textContent   = c.title;
    document.getElementById('confirm-heading').textContent = c.heading;
    document.getElementById('confirm-message').textContent = c.message;

    const iconEl = document.getElementById('confirm-icon');
    iconEl.className = c.icon;
    iconEl.style.color = c.iconColor;

    const wrap = document.getElementById('confirm-icon-wrap');
    wrap.className = 'confirm-icon-wrap ' + c.wrapClass;

    const btn = document.getElementById('confirm-btn');
    btn.className = 'btn-action ' + c.btnClass;
    document.getElementById('confirm-btn-icon').className = c.btnIcon;
    document.getElementById('confirm-btn-label').textContent = c.btnLabel;

    document.getElementById('confirmModal').classList.add('open');
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('open');
}

function closeConfirmOutside(e) {
    if (e.target.id === 'confirmModal') closeConfirm();
}

function executeAction() {
    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('id', pendingId);

    fetch(ACTION_CONFIG[pendingAction].endpoint, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        })
        .catch(() => alert('Server error.'))
        .finally(() => { closeConfirm(); btn.disabled = false; });
}
</script>