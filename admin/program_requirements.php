<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth_middleware.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireAdmin();

$pdo = db();

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function checkCsrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
}

/* ===================================================
   AJAX ENDPOINT (upload requirement file)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_file') {
    header('Content-Type: application/json');
    checkCsrf();

    $programRequirementId = (int)($_POST['program_requirement_id'] ?? 0);
    $file = $_FILES['requirement_file'] ?? null;

    if ($programRequirementId <= 0 || !$file || !isset($file['error'], $file['tmp_name'], $file['size'])) {
        echo json_encode(['ok' => false, 'message' => 'Choose a requirement and a file to upload.']);
        exit();
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'The file exceeds the 10 MB upload limit.'
            : 'The file could not be uploaded. Please try again.';
        echo json_encode(['ok' => false, 'message' => $message]);
        exit();
    }

    if ($file['size'] <= 0 || $file['size'] > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['ok' => false, 'message' => 'Choose a valid file no larger than 10 MB.']);
        exit();
    }

    $requirementStmt = $pdo->prepare("SELECT requirement_file FROM program_requirements WHERE id = ?");
    $requirementStmt->execute([$programRequirementId]);
    $oldFilePath = $requirementStmt->fetchColumn();

    if ($oldFilePath === false) {
        echo json_encode(['ok' => false, 'message' => 'The selected program requirement was not found.']);
        exit();
    }

    $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        echo json_encode(['ok' => false, 'message' => 'Only PDF, DOC, or DOCX files are allowed.']);
        exit();
    }

    $uploadDirectory = __DIR__ . '/../uploads/requirement_templates';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        error_log('Unable to create the requirement template upload directory.');
        echo json_encode(['ok' => false, 'message' => 'The upload location is unavailable. Please try again.']);
        exit();
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    $relativePath = 'uploads/requirement_templates/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode(['ok' => false, 'message' => 'The file could not be saved. Please try again.']);
        exit();
    }

    try {
        $updateStmt = $pdo->prepare("UPDATE program_requirements SET requirement_file = ? WHERE id = ?");
        $updateStmt->execute([$relativePath, $programRequirementId]);
    } catch (PDOException $e) {
        unlink($destination);
        error_log('program_requirements.php upload error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'The requirement file could not be saved. Please try again.']);
        exit();
    }

    if (
        is_string($oldFilePath) &&
        strpos($oldFilePath, 'uploads/requirement_templates/') === 0 &&
        basename($oldFilePath) !== $filename
    ) {
        $oldFile = $uploadDirectory . DIRECTORY_SEPARATOR . basename($oldFilePath);
        if (is_file($oldFile) && !unlink($oldFile)) {
            error_log('Unable to remove a replaced requirement template: ' . $oldFile);
        }
    }

    echo json_encode(['ok' => true, 'message' => 'Requirement file uploaded successfully.']);
    exit();
}

/* ===================================================
   AJAX ENDPOINT (add requirement)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    header('Content-Type: application/json');
    checkCsrf();

    $program_id        = (int)($_POST['program_id'] ?? 0);
    $requirement_id    = (int)($_POST['requirement_id'] ?? 0);
    $is_required       = isset($_POST['is_required']) ? 1 : 0;

    if (!$program_id || !$requirement_id) {
        echo json_encode(['ok' => false, 'message' => 'Program and Requirement are required.']);
        exit();
    }

    try {
        $check = $pdo->prepare("SELECT id FROM program_requirements WHERE program_id = ? AND requirement_id = ?");
        $check->execute([$program_id, $requirement_id]);
        if ($check->fetch()) {
            echo json_encode(['ok' => false, 'message' => 'This requirement is already assigned to this program.']);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO program_requirements (program_id, requirement_id, is_required) VALUES (?, ?, ?)");
        $stmt->execute([$program_id, $requirement_id, $is_required]);

        echo json_encode(['ok' => true, 'message' => 'Requirement assigned successfully.']);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
    }
    exit();
}

/* Fetch Programs & Requirements */
$programs = $pdo->query("SELECT id, program_name FROM programs ORDER BY program_name")->fetchAll(PDO::FETCH_ASSOC);
$masterRequirements = $pdo->query("SELECT id, requirement_name FROM requirements ORDER BY requirement_name")->fetchAll(PDO::FETCH_ASSOC);

$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM program_requirements")->fetchColumn();
$totalPages = max(1, ceil($totalCount / $limit));

$stmt = $pdo->prepare("
    SELECT pr.id, p.program_name, r.requirement_name, pr.is_required, pr.requirement_file, pr.created_at
    FROM program_requirements pr
    INNER JOIN programs p ON pr.program_id = p.id
    INNER JOIN requirements r ON pr.requirement_id = r.id
    ORDER BY pr.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/program_requirements.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content-card">
    <div class="page-header">
        <h2>Program Requirements</h2>
        <p>Assign specific documents from your master list to individual programs.</p>
    </div>

    <div class="toolbar">
        <button type="button" class="btn-primary" onclick="openAddModal()">
            <i class="fa fa-plus"></i> Assign Requirement
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Program</th>
                    <th>Requirement</th>
                    <th>Status</th>
                    <th>Applicant File</th>
                    <th>Created</th>
                </tr>
            </thead>

            <tbody id="requirementsBody">
                <?php if (!empty($requirements)): ?>
                    <?php foreach ($requirements as $row): ?>
                    <tr>
                        <td style="font-weight:600;"><?= h($row['program_name']) ?></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <i class="fa-solid fa-file-lines" style="color:#94a3b8;"></i>
                                <?= h($row['requirement_name']) ?>
                            </div>
                        </td>
                        <td>
                            <?= $row['is_required']
                                ? '<span class="badge-success">Required</span>'
                                : '<span class="badge-secondary">Optional</span>' ?>
                        </td>
                        <td>
                            <?php if (!empty($row['requirement_file'])): ?>
                                <a class="requirement-file-link" href="../<?= h($row['requirement_file']) ?>" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-eye"></i> View current file
                                </a>
                            <?php endif; ?>
                            <form class="requirement-file-form" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="upload_file">
                                <input type="hidden" name="program_requirement_id" value="<?= (int)$row['id'] ?>">
                                <input class="requirement-file-input" type="file" name="requirement_file" accept=".pdf,.doc,.docx" required>
                                <button type="submit" class="btn-file-upload">
                                    <i class="fa-solid fa-upload"></i>
                                    <?= empty($row['requirement_file']) ? 'Upload file' : 'Replace file' ?>
                                </button>
                                <small>PDF, DOC, or DOCX (max 10 MB)</small>
                            </form>
                        </td>
                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="5">No requirements assigned to any program yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <div class="pagination">
            <span>Showing <?= count($requirements) ?> of <?= $totalCount ?> requirement<?= $totalCount === 1 ? '' : 's' ?></span>
            <div class="pages">
                <?php $baseQ = array_merge($_GET, []); unset($baseQ['p']); ?>
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

<!-- ===================== ADD REQUIREMENT MODAL ===================== -->
<div id="addModal" class="modal-overlay" onclick="closeRequirementModalOutside(event,'addModal')">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon"><i class="fa fa-file-circle-plus"></i></div>
                <div>
                    <h3>Assign Program Requirement</h3>
                    <p class="modal-subtitle">Select a program and a requirement from the master list.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeRequirementModal('addModal')"><i class="fa fa-times"></i></button>
        </div>

        <form id="addForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="add">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-graduation-cap"></i> Program *</label>
                    <select name="program_id" class="form-input" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= (int)$program['id'] ?>"><?= h($program['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fa fa-file-signature"></i> Document Requirement *</label>
                    <select name="requirement_id" class="form-input" required>
                        <option value="">Select Requirement</option>
                        <?php foreach ($masterRequirements as $req): ?>
                            <option value="<?= (int)$req['id'] ?>"><?= h($req['requirement_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#94a3b8; font-size:11px; margin-top:4px; display:block;">
                        Can't find it? <a href="?page=requirements" style="color:#2563eb;">Manage Master List</a>
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="flex-direction:row-reverse;justify-content:flex-end; cursor:pointer;">
                        <input type="checkbox" name="is_required" checked style="width:16px;height:16px; margin-left:8px;">
                        Is this requirement mandatory?
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeRequirementModal('addModal')">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Save Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
window.PROGRAM_REQUIREMENTS_CSRF = <?= json_encode($csrfToken) ?>;

/* Modal Helpers */
function openRequirementModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeRequirementModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
function closeRequirementModalOutside(e, id) {
    if (e.target.id === id) closeRequirementModal(id);
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeRequirementModal('addModal');
});

function openAddModal() {
    document.getElementById('addForm').reset();
    openRequirementModal('addModal');
}

/* 1. Handle Add Requirement Form Submit */
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
            catch { throw new Error("Invalid server response"); }
        })
        .then(data => {
            showToast(data.ok ? 'success' : 'error', data.message);
            if (data.ok) {
                closeRequirementModal('addModal');
                setTimeout(() => location.reload(), 1200);
            }
        })
        .catch((err) => {
            console.error(err);
            showToast('error', 'Server error. Please try again.')
        })
        .finally(() => {
            btn.disabled  = false;
            btn.innerHTML = original;
        });
});

/* 2. Handle File Uploads (Moved OUTSIDE the addForm listener) */
document.querySelectorAll('.requirement-file-form').forEach(form => {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const button = this.querySelector('[type="submit"]');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading...';

        fetch('', { method: 'POST', body: new FormData(this) })
            .then(async response => {
                const text = await response.text();
                try { return JSON.parse(text); }
                catch { throw new Error('Invalid server response'); }
            })
            .then(data => {
                showToast(data.ok ? 'success' : 'error', data.message);
                if (data.ok) setTimeout(() => location.reload(), 900);
            })
            .catch(error => {
                console.error(error);
                showToast('error', 'The file could not be uploaded. Please try again.');
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = original;
            });
    });
});

/* Toast Helper */
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