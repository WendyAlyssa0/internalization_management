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
   AJAX ENDPOINT (add requirement)
   =================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    header('Content-Type: application/json');
    checkCsrf();

    $program_id        = (int)($_POST['program_id'] ?? 0);
    $requirement_name  = trim((string)($_POST['requirement_name'] ?? ''));
    $is_required       = isset($_POST['is_required']) ? 1 : 0;

    if (!$program_id || $requirement_name === '') {
        echo json_encode(['ok' => false, 'message' => 'Program and requirement name are required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO program_requirements
            (program_id, requirement_name, is_required)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$program_id, $requirement_name, $is_required]);

        echo json_encode(['ok' => true, 'message' => 'Requirement added successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'message' => 'Database error. Please try again.']);
    }
    exit();
}

$programs = $pdo->query("
    SELECT id, program_name
    FROM programs
    ORDER BY program_name
")->fetchAll(PDO::FETCH_ASSOC);

$totalCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM program_requirements
")->fetchColumn();

$totalPages = max(1, ceil($totalCount / $limit));

$stmt = $pdo->prepare("
    SELECT
        pr.id,
        p.program_name,
        pr.requirement_name,
        pr.is_required,
        pr.created_at
    FROM program_requirements pr
    INNER JOIN programs p
        ON pr.program_id = p.id
    ORDER BY pr.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/program_requirements.css">

<div class="content-card">

    <div class="page-header">
        <h2>Program Requirements</h2>
        <p>Assign requirements to specific programs.</p>
    </div>

    <div class="toolbar">
        <button type="button" class="btn-primary" onclick="openAddModal()">
            <i class="fa fa-plus"></i> Add Requirement</button>
    </div>

    <div class="table-card">

        <table>

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Program</th>
                    <th>Requirement</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>

            <tbody id="requirementsBody">

                <?php if (!empty($requirements)): ?>
                    <?php foreach ($requirements as $row): ?>
                    <tr>
                        <td><?= h($row['program_name']) ?></td>
                        <td><?= h($row['requirement_name']) ?></td>
                        <td>
                            <?= $row['is_required']
                                ? '<span class="badge-success">Required</span>'
                                : '<span class="badge-secondary">Optional</span>' ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="5">No requirements found</td>
                        </tr>
                    <?php endif; ?>
            </tbody>
        </table>

        <!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($requirements) ?> of <?= $totalCount ?> requirement<?= $totalCount === 1 ? '' : 's' ?>
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

<!-- ===================== ADD REQUIREMENT MODAL ===================== -->
<div id="addModal" class="modal-overlay" onclick="closeModalOutside(event,'addModal')">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-file-circle-plus"></i>
                </div>
                <div>
                    <h3>Add Program Requirement</h3>
                    <p class="modal-subtitle">Assign a requirement to a program.</p>
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
                        <i class="fa fa-graduation-cap"></i> Program *
                    </label>
                    <select name="program_id" class="form-input" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= (int)$program['id'] ?>">
                                <?= h($program['program_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-file-signature"></i> Requirement Name *
                    </label>
                    <input type="text" name="requirement_name" class="form-input"
                           placeholder="Example: Medical Certificate" required maxlength="150">
                </div>

                <div class="form-group">
                    <label class="form-label" style="flex-direction:row-reverse;justify-content:flex-end;">
                        <input type="checkbox" name="is_required" checked style="width:16px;height:16px;">
                        Required
                    </label>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addModal')">
                    Cancel
                </button>
                <button type="submit" class="btn-confirm">
                    <i class="fa fa-floppy-disk"></i> Save Requirement
                </button>
            </div>
        </form>

    </div>
</div>

<script>
window.PROGRAM_REQUIREMENTS_CSRF = <?= json_encode($csrfToken) ?>;

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
        .then(res => res.json())
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
</script>