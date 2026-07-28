<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = db();

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function checkCsrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit();
    }
}

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* ===================================================
   AJAX ENDPOINT (add form)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    header('Content-Type: application/json');
    checkCsrf();

    $form_name = trim((string)($_POST['form_name'] ?? ''));
    $form_type = trim((string)($_POST['form_type'] ?? ''));
    $file_path = trim((string)($_POST['file_path'] ?? ''));
    $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($form_name === '' || $file_path === '') {
        echo json_encode(['ok' => false, 'message' => 'Form name and file path are required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO forms (form_name, form_type, file_path, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$form_name, $form_type, $file_path, $status]);
        echo json_encode(['ok' => true, 'message' => 'Form added successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
    }
    exit();
}

/* ===================================================
   AJAX ENDPOINT (delete form)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    checkCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Invalid form.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM forms WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'message' => 'Form deleted successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
    }
    exit();
}

/* TOTAL RECORDS */
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $limit));

/* FETCH PAGINATED DATA */
$stmt = $pdo->prepare("SELECT * FROM forms ORDER BY id DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/forms.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content-card">

    <div class="page-header">
        <h2>Application Forms</h2>
        <p>Manage the downloadable forms available to applicants.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="searchInput" placeholder="Search forms on this page...">
        </div>
        <button type="button" class="btn-primary" onclick="openAddModal()">
            <i class="fa fa-plus"></i> Add Form
        </button>
    </div>

    <div class="table-card">
        <table id="formsTable">
            <thead>
                <tr>
                    <th>Form Name</th>
                    <th>Form Type</th>
                    <th>File</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="formsBody">
                <?php if (!empty($forms)): ?>
                    <?php foreach ($forms as $f): ?>
                        <tr data-id="<?= (int)$f['id'] ?>"
                            data-search="<?= h(strtolower(($f['form_name'] ?? '') . ' ' . ($f['form_type'] ?? ''))) ?>">
                            <td>
                                <div class="form-name-cell">
                                    <div class="form-icon">
                                        <i class="fa fa-file-lines"></i>
                                    </div>
                                    <div class="form-info">
                                        <strong><?= h($f['form_name'] ?? '') ?></strong>
                                    </div>
                                </div>
                            </td>
                            <td><?= h($f['form_type'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if (!empty($f['file_path'])): ?>
                                    <a href="<?= h($f['file_path']) ?>" target="_blank" rel="noopener" class="download-link">
                                        <i class="fa-solid fa-file-arrow-down"></i> Download
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (!empty($f['status']) && $f['status'] === 'active')
                                    ? '<span class="badge-success">Active</span>'
                                    : '<span class="badge-secondary">Inactive</span>' ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <button type="button"
                                            class="action-btn delete-btn"
                                            title="Delete"
                                            data-id="<?= (int)$f['id'] ?>">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                </div>
                                <h3>No forms found</h3>
                                <p>Click "Add Form" to create your first downloadable form.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($forms) ?> of <?= $totalCount ?> form<?= $totalCount === 1 ? '' : 's' ?>
            </span>
            <div class="pages">
                <?php
                    $baseQ = $_GET;
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

<!-- ===================== ADD FORM MODAL ===================== -->
<div id="addModal" class="modal-overlay" onclick="closeModalOutside(event,'addModal')">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-file-circle-plus"></i>
                </div>
                <div>
                    <h3>Add Application Form</h3>
                    <p class="modal-subtitle">Register a new downloadable form.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('addModal')">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <form id="addForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="add">

            <div class="modal-body">

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-file-signature"></i> Form Name *
                    </label>
                    <input type="text" name="form_name" class="form-input"
                           placeholder="Example: Application Form for Exchange Program"
                           required maxlength="150">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-tags"></i> Form Type
                    </label>
                    <input type="text" name="form_type" class="form-input"
                           placeholder="Example: PDF, DOCX"
                           maxlength="50">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-link"></i> File Path / URL *
                    </label>
                    <input type="text" name="file_path" class="form-input"
                           placeholder="Example: /uploads/forms/exchange_application.pdf"
                           required maxlength="255">
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-toggle-on"></i> Status
                    </label>
                    <select name="status" class="form-input">
                        <option value="active" selected>Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addModal')">
                    Cancel
                </button>
                <button type="submit" class="btn-confirm">
                    <i class="fa fa-floppy-disk"></i> Save Form
                </button>
            </div>
        </form>

    </div>
</div>

<script>
window.FORMS_CSRF = <?= json_encode($csrfToken) ?>;

/* ── Modal helpers ── */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

function closeModalOutside(e, id) {
    if (e.target.id === id) closeModal(id);
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal('addModal');
});

function openAddModal() {
    document.getElementById('addForm').reset();
    openModal('addModal');
}

/* ── ADD form submit (AJAX) ── */
document.getElementById('addForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form = this;
    const btn  = form.querySelector('[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    fetch('', { method: 'POST', body: new FormData(form) })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Server did not return JSON'); }
        })
        .then(data => {
            showToast(data.ok ? 'success' : 'error', data.message);
            if (data.ok) {
                closeModal('addModal');
                setTimeout(() => location.reload(), 1200);
            }
        })
        .catch(() => showToast('error', 'Something went wrong. Please try again.'))
        .finally(() => {
            btn.disabled  = false;
            btn.innerHTML = original;
        });
});

/* ── DELETE (AJAX) ── */
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        if (!confirm('Delete this form? This action cannot be undone.')) return;

        const id  = this.dataset.id;
        const row = this.closest('tr');

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        formData.append('csrf_token', window.FORMS_CSRF);

        fetch('', { method: 'POST', body: formData })
            .then(async res => {
                const text = await res.text();
                try { return JSON.parse(text); }
                catch { throw new Error('Server did not return JSON'); }
            })
            .then(data => {
                showToast(data.ok ? 'success' : 'error', data.message);
                if (data.ok) {
                    row.style.transition = 'opacity 0.3s ease';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
            })
            .catch(() => showToast('error', 'Something went wrong. Please try again.'));
    });
});

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

/* Live search (current page only) */
document.getElementById('searchInput')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#formsBody tr[data-search]').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
});
</script>
