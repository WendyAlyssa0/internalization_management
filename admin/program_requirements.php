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

    $requirementStmt = $pdo->prepare("SELECT template_path FROM program_requirements WHERE id = ?");
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
        $updateStmt = $pdo->prepare("UPDATE program_requirements SET template_path = ? WHERE id = ?");
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
    $applicantType     = strtolower(trim((string)($_POST['applicant_type'] ?? '')));
    $is_required       = isset($_POST['is_required']) ? 1 : 0;

    if (!$program_id || !$requirement_id || !in_array($applicantType, ['student', 'faculty', 'non-teaching'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Program, requirement, and a valid applicant type are required.']);
        exit();
    }

    try {
        $check = $pdo->prepare("SELECT id FROM program_requirements WHERE program_id = ? AND requirement_id = ? AND applicant_type = ?");
        $check->execute([$program_id, $requirement_id, $applicantType]);
        if ($check->fetch()) {
            echo json_encode(['ok' => false, 'message' => 'This requirement is already assigned to this program.']);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO program_requirements (program_id, requirement_id, applicant_type, is_required) VALUES (?, ?, ?, ?)");
        $stmt->execute([$program_id, $requirement_id, $applicantType, $is_required]);

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
    SELECT pr.id, p.program_name, r.requirement_name, pr.applicant_type, pr.is_required, pr.template_path, pr.created_at
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

<link rel="stylesheet" href="../asset/css/users.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
    .review-link {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 6px;
        background: #eff6ff; color: #2563eb; font-size: 0.75rem;
        font-weight: 600; text-decoration: none; border: 1px solid #bfdbfe;
        white-space: nowrap;
    }
    .review-link:hover { background: #dbeafe; }

    .file-upload-inline {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        margin-top: 6px;
    }
    .file-upload-inline input[type="file"] {
        font-size: 0.75rem; max-width: 220px;
        border: 1px solid #e2e8f0; border-radius: 6px;
        padding: 4px 6px; background: #fff; color: #475569;
    }
    .file-upload-inline input[type="file"]::file-selector-button {
        font-size: 0.75rem; padding: 3px 8px;
        border: none; background: #f1f5f9; color: #475569;
        border-radius: 4px; margin-right: 6px; cursor: pointer;
    }
    .file-upload-inline input[type="file"]::file-selector-button:hover {
        background: #e2e8f0;
    }
    .upload-hint {
        display: block; font-size: 0.7rem; color: #94a3b8; margin-top: 4px;
    }

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
        <h2>Program Requirements</h2>
        <p>Assign specific documents from your master list to individual programs.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search by program or requirement...">
        </div>
        <select class="filter-select" id="typeFilter">
            <option value="">All Applicant Types</option>
            <option value="student">Student</option>
            <option value="faculty">Faculty</option>
            <option value="non-teaching">Non-teaching</option>
        </select>
        <select class="filter-select" id="requiredFilter">
            <option value="">All Statuses</option>
            <option value="required">Required</option>
            <option value="optional">Optional</option>
        </select>
        <button class="create-btn" id="openModalBtn" type="button">
            <i class="fa fa-plus"></i> Assign Requirement
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Program</th>
                    <th>Applicant Type</th>
                    <th>Requirement</th>
                    <th>Status</th>
                    <th>Template</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (!empty($requirements)): ?>
                    <?php foreach ($requirements as $row): ?>
                        <?php
                            $typeKey     = strtolower($row['applicant_type']);
                            $requiredKey = $row['is_required'] ? 'required' : 'optional';
                        ?>
                        <tr data-name="<?= h(strtolower($row['program_name'] . ' ' . $row['requirement_name'])) ?>"
                            data-type="<?= h($typeKey) ?>"
                            data-required="<?= h($requiredKey) ?>">
                            <td><span style="font-weight:600; color:#0f172a;"><?= h($row['program_name']) ?></span></td>
                            <td><span class="badge"><?= h(ucwords($row['applicant_type'])) ?></span></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-file-lines" style="color:#94a3b8;"></i>
                                    <?= h($row['requirement_name']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($row['is_required']): ?>
                                    <span class="status-badge status-active">Required</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">Optional</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['template_path'])): ?>
                                    <a class="review-link" href="../<?= h($row['template_path']) ?>" target="_blank" rel="noopener" style="margin-bottom:6px;">
                                        <i class="fa-solid fa-eye"></i> View current file
                                    </a>
                                <?php endif; ?>
                                <form class="requirement-file-form" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="upload_file">
                                    <input type="hidden" name="program_requirement_id" value="<?= (int)$row['id'] ?>">
                                    <div class="file-upload-inline">
                                        <input class="requirement-file-input" type="file" name="requirement_file" accept=".pdf,.doc,.docx" required>
                                        <button type="submit" class="action-btn edit-btn" title="<?= empty($row['template_path']) ? 'Upload template' : 'Replace template' ?>">
                                            <i class="fa-solid fa-upload"></i>
                                        </button>
                                    </div>
                                    <span class="upload-hint">PDF, DOC, or DOCX (max 10 MB)</span>
                                </form>
                            </td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <path d="M14 2v6h6"/>
                                        <path d="M9 15h6"/>
                                        <path d="M9 11h6"/>
                                    </svg>
                                </div>
                                <h3>No requirements assigned yet</h3>
                                <p>Click "Assign Requirement" to attach documents to a program.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

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

<!-- ASSIGN REQUIREMENT MODAL -->
<div id="addModal" class="modal-backdrop" role="dialog" aria-modal="true" onclick="closeModalOutside(event)">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3>Assign Program Requirement</h3>
                <p>Select a program and a requirement from the master list.</p>
            </div>
            <button class="modal-close" onclick="closeModal()" aria-label="Close"><i class="fa fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <form id="addForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-section-title">Assignment Details</div>
                <div class="form-grid">
                    <div class="form-group col-12">
                        <label>Program <span class="required">*</span></label>
                        <select name="program_id" required>
                            <option value="">Select Program</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>"><?= h($program['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-12">
                        <label>Document Requirement <span class="required">*</span></label>
                        <select name="requirement_id" required>
                            <option value="">Select Requirement</option>
                            <?php foreach ($masterRequirements as $req): ?>
                                <option value="<?= (int)$req['id'] ?>"><?= h($req['requirement_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-instructions">
                            Can't find it? <a href="?page=requirements" style="color:#2563eb;">Manage Master List</a>
                        </p>
                    </div>

                    <div class="form-group col-6">
                        <label>Applicant Type <span class="required">*</span></label>
                        <select name="applicant_type" required>
                            <option value="student">Student</option>
                            <option value="faculty">Faculty</option>
                            <option value="non-teaching">Non-teaching</option>
                        </select>
                    </div>

                    <div class="form-group col-6">
                        <label>Mandatory?</label>
                        <label style="display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;background:#fff;">
                            <input type="checkbox" name="is_required" checked style="width:16px;height:16px;">
                            <span style="font-size:0.875rem;color:#475569;">Yes, this requirement is mandatory</span>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fa fa-floppy-disk"></i> Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    /* ---------- Filters ---------- */
    var searchInput    = document.getElementById('searchInput');
    var typeFilter     = document.getElementById('typeFilter');
    var requiredFilter = document.getElementById('requiredFilter');

    function filterTable() {
        var search   = searchInput.value.toLowerCase();
        var type     = typeFilter.value.toLowerCase();
        var required = requiredFilter.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(function (row) {
            var name    = row.dataset.name     || '';
            var rowType = row.dataset.type     || '';
            var rowReq  = row.dataset.required || '';
            row.style.display =
                (!search   || name.includes(search)) &&
                (!type     || rowType === type) &&
                (!required || rowReq === required)
                ? '' : 'none';
        });
    }
    if (searchInput)    searchInput.addEventListener('input',  filterTable);
    if (typeFilter)     typeFilter.addEventListener('change', filterTable);
    if (requiredFilter) requiredFilter.addEventListener('change', filterTable);

    /* ---------- Modal ---------- */
    var modal     = document.getElementById('addModal');
    var openBtn   = document.getElementById('openModalBtn');
    var form      = document.getElementById('addForm');

    function openModal()  { modal.classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeModal() { modal.classList.remove('open'); document.body.style.overflow = ''; }

    window.closeModal = closeModal;
    window.closeModalOutside = function (e) { if (e.target === modal) closeModal(); };

    if (openBtn) openBtn.addEventListener('click', function () { form.reset(); openModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    /* ---------- Add Requirement (unchanged backend) ---------- */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[type="submit"]');
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        fetch('', { method: 'POST', body: new FormData(form) })
            .then(async function (res) {
                var text = await res.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Invalid server response'); }
            })
            .then(function (data) {
                showToast(data.ok ? 'success' : 'error', data.message);
                if (data.ok) {
                    closeModal();
                    setTimeout(function () { location.reload(); }, 1200);
                }
            })
            .catch(function (err) {
                console.error(err);
                showToast('error', 'Server error. Please try again.');
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    });

    /* ---------- Template Upload (unchanged backend) ---------- */
    document.querySelectorAll('.requirement-file-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var button = this.querySelector('[type="submit"]');
            var original = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

            fetch('', { method: 'POST', body: new FormData(this) })
                .then(async function (response) {
                    var text = await response.text();
                    try { return JSON.parse(text); }
                    catch (e) { throw new Error('Invalid server response'); }
                })
                .then(function (data) {
                    showToast(data.ok ? 'success' : 'error', data.message);
                    if (data.ok) setTimeout(function () { location.reload(); }, 900);
                })
                .catch(function (error) {
                    console.error(error);
                    showToast('error', 'The file could not be uploaded. Please try again.');
                })
                .finally(function () {
                    button.disabled = false;
                    button.innerHTML = original;
                });
        });
    });

    /* ---------- Toast ---------- */
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
})();
</script>