<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (empty($_SESSION['user_id']) || !canManageSystem()) {
    http_response_code(empty($_SESSION['user_id']) ? 401 : 403);
    exit('Access denied.');
}

$pdo = db();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
        exit;
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($action === 'save') {
                $campusId = (int)($_POST['campus_id'] ?? 0);
                $programName = trim((string)($_POST['program_name'] ?? ''));
                if ($campusId <= 0 || $programName === '' || mb_strlen($programName) > 255) {
                    throw new RuntimeException('Choose a campus and enter a degree-program name up to 255 characters.');
                }
                $campusCheck = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND status = 'active'");
                $campusCheck->execute([$campusId]);
                if (!$campusCheck->fetchColumn()) {
                    throw new RuntimeException('Choose an active campus.');
                }
                if ($id > 0) {
                    $existingCheck = $pdo->prepare('SELECT id FROM degree_programs WHERE id = ?');
                    $existingCheck->execute([$id]);
                    if (!$existingCheck->fetchColumn()) {
                        throw new RuntimeException('Degree program not found.');
                    }
                    $stmt = $pdo->prepare('UPDATE degree_programs SET campus_id = ?, program_name = ? WHERE id = ?');
                    $stmt->execute([$campusId, $programName, $id]);
                    echo json_encode(['success' => true, 'message' => 'Degree program updated.']);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO degree_programs (campus_id, program_name, status) VALUES (?, ?, 'active')");
                    $stmt->execute([$campusId, $programName]);
                    echo json_encode(['success' => true, 'message' => 'Degree program added.']);
                }
                exit;
            }
            if ($action === 'toggle' && $id > 0) {
                $existingCheck = $pdo->prepare('SELECT id FROM degree_programs WHERE id = ?');
                $existingCheck->execute([$id]);
                if (!$existingCheck->fetchColumn()) {
                    throw new RuntimeException('Degree program not found.');
                }
                $stmt = $pdo->prepare("
                    UPDATE degree_programs
                    SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Degree program status updated.']);
                exit;
            }
            throw new RuntimeException('Unknown degree-program action.');
        } catch (PDOException $exception) {
            error_log('degree program save failed: ' . $exception->getMessage());
            $message = $exception->getCode() === '23000'
                ? 'That degree program is already listed for this campus.'
                : 'Unable to save the degree program. Check the database and try again.';
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        } catch (RuntimeException $exception) {
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
            exit;
        }
    }
}

$campuses = $pdo->query("SELECT id, campus_name FROM campuses WHERE status = 'active' ORDER BY campus_name")->fetchAll(PDO::FETCH_ASSOC);
$programs = $pdo->query("
    SELECT dp.id, dp.program_name, dp.status, c.campus_name, dp.campus_id
    FROM degree_programs dp
    INNER JOIN campuses c ON c.id = dp.campus_id
    ORDER BY c.campus_name, dp.program_name
")->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($str): string {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}
?>

<link rel="stylesheet" href="../asset/css/users.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <?php if ($error !== ''): ?>
        <div class="error-box" style="margin-bottom:20px;">
            <i class="fa-solid fa-circle-exclamation"></i> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2>Degree Program Lists</h2>
        <p>Maintain the campus-specific degree programs used for eligibility and applicant accounts.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search degree programs...">
        </div>
        <select class="filter-select" id="campusFilter">
            <option value="">All Campuses</option>
            <?php foreach ($campuses as $campus): ?>
                <option value="<?= h($campus['campus_name']) ?>"><?= h($campus['campus_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-select" id="statusFilter">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <button class="create-btn" id="openModalBtn">
            <i class="fa fa-plus"></i> Add Degree Program
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Campus</th>
                    <th>Degree Program</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!empty($programs)): ?>
                <?php foreach ($programs as $program): ?>
                    <?php
                        $status = strtolower($program['status']);
                        $campusName = $program['campus_name'];
                        $programName = $program['program_name'];
                    ?>
                    <tr data-name="<?= h(strtolower($programName)) ?>"
                        data-campus="<?= h(strtolower($campusName)) ?>"
                        data-status="<?= h($status) ?>">
                        <td><?= h($campusName) ?></td>
                        <td>
                            <span style="font-weight:600; color:#0f172a;"><?= h($programName) ?></span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= h($status) ?>">
                                <?= h(ucfirst($program['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <button type="button" class="action-btn edit-degree-btn"
                                        title="Edit degree program"
                                        data-id="<?= (int)$program['id'] ?>"
                                        data-campus-id="<?= (int)$program['campus_id'] ?>"
                                        data-program-name="<?= h($programName) ?>">
                                    <i class="fa fa-pen"></i>
                                </button>
                                <form method="post" data-ajax style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$program['id'] ?>">
                                    <button type="submit"
                                            class="action-btn <?= $status === 'active' ? 'delete-btn' : 'edit-btn' ?>"
                                            title="<?= $status === 'active' ? 'Deactivate' : 'Activate' ?>">
                                        <i class="fa <?= $status === 'active' ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                    </button>
                                </form>
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
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                            </div>
                            <h3>No degree programs yet</h3>
                            <p>Click "Add Degree Program" to create your first one.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD DEGREE PROGRAM MODAL -->
<div id="createModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 id="createModalTitle">Add Degree Program</h3>
                <p>Assign a degree program to an active campus. It will be available to applicants for that campus.</p>
            </div>
            <button class="modal-close" id="closeCreateModalBtn" aria-label="Close modal">
                <i class="fa fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">
            <form method="post" data-ajax id="createProgramForm">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="">

                <div class="form-section-title">Degree Program Details</div>
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label>Campus <span class="required">*</span></label>
                        <select name="campus_id" required>
                            <option value="">Select campus</option>
                            <?php foreach ($campuses as $campus): ?>
                                <option value="<?= (int)$campus['id'] ?>"><?= h($campus['campus_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label>Degree Program <span class="required">*</span></label>
                        <input type="text" name="program_name" maxlength="255" required placeholder="e.g. BS Information Technology">
                        <p class="form-instructions">Up to 255 characters. Must be unique per campus.</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" id="cancelCreateModalBtn">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-plus"></i> Add Degree Program
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var searchInput  = document.getElementById('searchInput');
    var campusFilter = document.getElementById('campusFilter');
    var statusFilter = document.getElementById('statusFilter');

    function filterTable() {
        var search = searchInput.value.toLowerCase();
        var campus = campusFilter.value.toLowerCase();
        var status = statusFilter.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(function (row) {
            var name   = row.dataset.name   || '';
            var rowCmp = row.dataset.campus || '';
            var rowSt  = row.dataset.status || '';
            row.style.display =
                (!search || name.includes(search)) &&
                (!campus || rowCmp === campus) &&
                (!status || rowSt === status)
                ? '' : 'none';
        });
    }

    if (searchInput)  searchInput.addEventListener('input',  filterTable);
    if (campusFilter) campusFilter.addEventListener('change', filterTable);
    if (statusFilter) statusFilter.addEventListener('change', filterTable);

    function openModal(modal)  { modal.classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeModal(modal) { modal.classList.remove('open'); document.body.style.overflow = ''; }

    function bindModal(modalId, openBtnId, closeBtnId, cancelBtnId) {
        var modal     = document.getElementById(modalId);
        var openBtn   = openBtnId   ? document.getElementById(openBtnId)   : null;
        var closeBtn  = closeBtnId  ? document.getElementById(closeBtnId)  : null;
        var cancelBtn = cancelBtnId ? document.getElementById(cancelBtnId) : null;

        if (openBtn)   openBtn.addEventListener('click',  function() { openModal(modal); });
        if (closeBtn)  closeBtn.addEventListener('click', function() { closeModal(modal); });
        if (cancelBtn) cancelBtn.addEventListener('click',function() { closeModal(modal); });

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal(modal);
        });
    }

    bindModal('createModal', 'openModalBtn', 'closeCreateModalBtn', 'cancelCreateModalBtn');

    document.getElementById('openModalBtn').addEventListener('click', function () {
        var form = document.getElementById('createProgramForm');
        form.reset();
        form.elements.id.value = '';
        document.getElementById('createModalTitle').textContent = 'Add Degree Program';
        form.querySelector('button[type="submit"]').innerHTML = '<i class="fa-solid fa-plus"></i> Add Degree Program';
    });

    document.getElementById('tableBody').addEventListener('click', function (event) {
        var button = event.target.closest('.edit-degree-btn');
        if (!button) return;

        var form = document.getElementById('createProgramForm');
        form.reset();
        form.elements.id.value = button.dataset.id;
        form.elements.campus_id.value = button.dataset.campusId;
        form.elements.program_name.value = button.dataset.programName;
        document.getElementById('createModalTitle').textContent = 'Edit Degree Program';
        form.querySelector('button[type="submit"]').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
        openModal(document.getElementById('createModal'));
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        var m = document.getElementById('createModal');
        if (m && m.classList.contains('open')) closeModal(m);
    });

    document.querySelectorAll('form[data-ajax]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var button = form.querySelector('button[type="submit"], button:not([type])');
            if (button) button.disabled = true;
            try {
                var response = await fetch(window.location.href, { method: 'POST', body: new FormData(form) });
                var result   = await response.json();
                if (!response.ok || !result.success) {
                    alert(result.message || 'Unable to save the degree program.');
                    return;
                }
                location.reload();
            } catch (error) {
                alert('Unable to reach the server. Please try again.');
            } finally {
                if (button) button.disabled = false;
            }
        });
    });
})();
</script>