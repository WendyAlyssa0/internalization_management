<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_middleware.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

$pdo = db();

/* ===================================================
   DOWNLOAD HANDLER — available to any logged-in user
   =================================================== */
if (isset($_GET['download_id'])) {
    $formId = (int)$_GET['download_id'];
    $stmt = $pdo->prepare("SELECT file_path FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    $relativePath = $stmt->fetchColumn();

    if (!is_string($relativePath) || !str_starts_with($relativePath, 'uploads/forms/')) {
        http_response_code(404);
        exit('Form not found.');
    }

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    $fullPath    = realpath(__DIR__ . '/../' . $relativePath);

    if (
        $uploadsRoot === false ||
        $fullPath === false ||
        !str_starts_with($fullPath, $uploadsRoot . DIRECTORY_SEPARATOR) ||
        !is_file($fullPath)
    ) {
        http_response_code(404);
        exit('Form not found.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
    $filename = str_replace('"', '', basename($fullPath));

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
}

/* ===================================================
   STAFF-ONLY from this point
   =================================================== */
if (!canManagePrograms()) {
    http_response_code(403);
    exit('Forbidden — you do not have permission to manage forms.');
}

/* ===================================================
   PAGINATION
   =================================================== */
$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* ===================================================
   CSRF TOKEN
   =================================================== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function checkCsrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit();
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* ===================================================
   FILE HELPERS
   =================================================== */
function saveManagedFormFile(array $file): string
{
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('The form file exceeds the 10 MB upload limit.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a form file to upload.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Choose a valid form file no larger than 10 MB.');
    }

    $extensions = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Only PDF, DOC, or DOCX forms are allowed.');
    }

    $directory = __DIR__ . '/../uploads/forms';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log('Unable to create the managed forms upload directory.');
        throw new RuntimeException('The upload location is unavailable. Please try again.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The form file could not be saved.');
    }

    return 'uploads/forms/' . $filename;
}

function deleteManagedFormFile(?string $path): void
{
    if (!is_string($path) || !str_starts_with($path, 'uploads/forms/')) {
        return;
    }

    $file = __DIR__ . '/../uploads/forms/' . basename($path);
    if (is_file($file) && !unlink($file)) {
        error_log('Unable to delete managed form file: ' . $file);
    }
}

/* ===================================================
   AJAX ENDPOINT (create or update form)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');
    checkCsrf();

    $formId   = (int)($_POST['id'] ?? 0);
    $formName = trim((string)($_POST['form_name'] ?? ''));
    $status   = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $formType = ($_POST['form_type'] ?? 'downloadable') === 'online' ? 'online' : 'downloadable';
    $formSchema = trim((string)($_POST['form_schema'] ?? ''));

    if ($formName === '') {
        echo json_encode(['ok' => false, 'message' => 'Form name is required.']);
        exit();
    }

    $oldPath = null;
    $oldType = null;
    if ($formId > 0) {
        $existing = $pdo->prepare('SELECT file_path, form_type FROM forms WHERE id = ?');
        $existing->execute([$formId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$existingRow) {
            echo json_encode(['ok' => false, 'message' => 'Form not found.']);
            exit();
        }
        $oldPath = $existingRow['file_path'];
        $oldType = $existingRow['form_type'];
    }

    $file    = $_FILES['form_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $newPath = null;

    try {
        if ($formType === 'downloadable') {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newPath = saveManagedFormFile($file);
            } elseif ($formId === 0 || !is_string($oldPath) || !str_starts_with($oldPath, 'uploads/forms/')) {
                echo json_encode(['ok' => false, 'message' => 'Upload a PDF, DOC, or DOCX form file.']);
                exit();
            }
        } elseif ($formSchema === '') {
            echo json_encode(['ok' => false, 'message' => 'Enter the JSON field schema for the online form.']);
            exit();
        } else {
            json_decode($formSchema, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['ok' => false, 'message' => 'The online form schema must be valid JSON.']);
                exit();
            }
        }

        $filePath = $newPath ?? $oldPath;

        if ($formId > 0) {
            $stmt = $pdo->prepare("
                UPDATE forms
                SET form_name = ?, form_type = ?, file_path = ?, form_schema = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$formName, $formType, $formType === 'downloadable' ? $filePath : null, $formType === 'online' ? $formSchema : null, $status, $formId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO forms (form_name, form_type, file_path, form_schema, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$formName, $formType, $formType === 'downloadable' ? $filePath : null, $formType === 'online' ? $formSchema : null, $status]);
        }

        if ($newPath !== null && is_string($oldPath) && $oldPath !== $newPath) {
            deleteManagedFormFile($oldPath);
        }

        echo json_encode(['ok' => true, 'message' => 'Form saved successfully.']);
    } catch (RuntimeException $e) {
        if ($newPath !== null) deleteManagedFormFile($newPath);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (PDOException $e) {
        if ($newPath !== null) deleteManagedFormFile($newPath);
        error_log('Managed form save failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'The form could not be saved. Please try again.']);
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
        $stmt = $pdo->prepare('SELECT file_path FROM forms WHERE id = ?');
        $stmt->execute([$id]);
        $filePath = $stmt->fetchColumn();

        if ($filePath === false) {
            echo json_encode(['ok' => false, 'message' => 'Form not found.']);
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM forms WHERE id = ?");
        $stmt->execute([$id]);

        deleteManagedFormFile(is_string($filePath) ? $filePath : null);

        echo json_encode(['ok' => true, 'message' => 'Form deleted successfully.']);
    } catch (PDOException $e) {
        error_log('Managed form delete failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
    }
    exit();
}

/* ===================================================
   FETCH DATA
   =================================================== */
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $limit));

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
                            <td>
                                <?php if (!empty($f['file_path'])): ?>
                                    <a href="dashboard.php?page=forms&amp;download_id=<?= (int)$f['id'] ?>" class="download-link">
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
                                            class="action-btn edit-btn"
                                            title="Edit"
                                            data-form="<?= h(json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
                                        <i class="fa fa-pen"></i>
                                    </button>
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
                        <td colspan="4">
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
                    <h3 id="formModalTitle">Add Application Form</h3>
                    <p class="modal-subtitle">Upload a downloadable application form for students.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('addModal')">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <form id="addForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="">

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
                        <i class="fa fa-list-check"></i> Form Type *
                    </label>
                    <select name="form_type" class="form-input" onchange="toggleFormFields(this.value)">
                        <option value="downloadable">Downloadable File</option>
                        <option value="online">Fill Online</option>
                    </select>
                </div>

                <div class="form-group" id="formFileGroup">
                    <label class="form-label"><i class="fa fa-upload"></i> Form File *</label>
                    <input type="file" name="form_file" class="form-input" accept=".pdf,.doc,.docx" required>
                    <small>PDF, DOC, or DOCX (max 10 MB).</small>
                </div>

                <div class="form-group" id="formSchemaGroup" style="display:none;">
                    <label class="form-label"><i class="fa fa-code"></i> Online Field Schema (JSON) *</label>
                    <textarea name="form_schema" class="form-input" rows="8" placeholder='[{"name":"passport_number","label":"Passport Number","type":"text","required":true}]'></textarea>
                    <small>Allowed types: text, email, date, textarea, select.</small>
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

function toggleFormFields(type) {
    const fileGroup = document.getElementById('formFileGroup');
    const schemaGroup = document.getElementById('formSchemaGroup');
    const fileInput = document.querySelector('#addForm [name="form_file"]');
    if (type === 'online') {
        fileGroup.style.display = 'none';
        schemaGroup.style.display = '';
        fileInput.required = false;
    } else {
        fileGroup.style.display = '';
        schemaGroup.style.display = 'none';
        fileInput.required = document.querySelector('#addForm [name="id"]').value === '';
    }
}

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
    const form = document.getElementById('addForm');
    form.reset();
    form.elements.id.value = '';
    form.elements.action.value = 'save';
    form.elements.form_file.required = true;
    form.elements.form_type.value = 'downloadable';
    form.elements.form_schema.value = '';
    toggleFormFields('downloadable');
    document.getElementById('formModalTitle').textContent = 'Add Application Form';
    form.querySelector('[type="submit"]').innerHTML = '<i class="fa fa-floppy-disk"></i> Save Form';
    openModal('addModal');
}

document.getElementById('formsBody').addEventListener('click', function (event) {
    const button = event.target.closest('.edit-btn');
    if (!button) return;

    const formData = JSON.parse(button.dataset.form);
    const form = document.getElementById('addForm');
    form.reset();
    form.elements.id.value = formData.id;
    form.elements.action.value = 'save';
    form.elements.form_name.value = formData.form_name || '';
    form.elements.status.value = formData.status || 'active';
    form.elements.form_type.value = formData.form_type || 'downloadable';
    form.elements.form_schema.value = formData.form_schema || '';
    toggleFormFields(formData.form_type || 'downloadable');
    form.elements.form_file.required = (formData.form_type || 'downloadable') === 'downloadable';
    document.getElementById('formModalTitle').textContent = 'Edit Application Form';
    form.querySelector('[type="submit"]').innerHTML = '<i class="fa fa-floppy-disk"></i> Update Form';
    openModal('addModal');
});

/* ── Save form (AJAX) ── */
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