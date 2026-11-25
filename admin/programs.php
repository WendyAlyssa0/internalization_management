<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* AUTH & ROLE */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit();
}
if (!canManagePrograms()) {
    http_response_code(403);
    exit('Access denied.');
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db();
$departmentOptions = require __DIR__ . '/../config/academic_departments.php';

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================================================
   AJAX HANDLERS (ADD / EDIT / DELETE)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM programs WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Program deleted.']);
        } catch (PDOException $e) {
            error_log('program delete: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Cannot delete program. It may be linked to applications.']);
        }
        exit;
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);

        // Order matters: it must match the column order in the SQL below
        $data = [
            'program_name'          => trim($_POST['program_name'] ?? ''),
            'program_type'          => trim($_POST['program_type'] ?? ''),
            'eligible_department'   => trim($_POST['eligible_department'] ?? ''),
            'eligible_account_type' => trim($_POST['eligible_account_type'] ?? ''),
            'status'                => trim($_POST['status'] ?? 'Active'),
            'country_id'            => (int)($_POST['country_id'] ?? 0),
            'partner_id'            => (int)($_POST['partner_id'] ?? 0),
            'slots'                 => max(0, (int)($_POST['slots'] ?? 0)),   // 0 = unlimited
            'start_date'            => $_POST['start_date'] ?? null,
            'end_date'              => $_POST['end_date'] ?? null,
            'description'           => trim($_POST['description'] ?? '')
        ];

        try {
            if ($id > 0) {
                // Do not allow fewer slots than already-approved applicants
                if ($data['slots'] > 0) {
                    $filledStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE program_id = ? AND LOWER(TRIM(status)) = 'approved'");
                    $filledStmt->execute([$id]);
                    $filled = (int)$filledStmt->fetchColumn();
                    if ($data['slots'] < $filled) {
                        echo json_encode(['success' => false, 'message' => "Slots cannot be lower than the $filled applicant(s) already approved."]);
                        exit;
                    }
                }

                // Update
                $sql = "UPDATE programs SET program_name=?, program_type=?, eligible_department=?, eligible_account_type=?, status=?, country_id=?, partner_id=?, slots=?, start_date=?, end_date=?, description=? WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([...array_values($data), $id]);
                echo json_encode(['success' => true, 'message' => 'Program updated successfully.']);
            } else {
                // Insert
                $sql = "INSERT INTO programs (program_name, program_type, eligible_department, eligible_account_type, status, country_id, partner_id, slots, start_date, end_date, description) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($data));
                echo json_encode(['success' => true, 'message' => 'Program created successfully.']);
            }
        } catch (PDOException $e) {
            error_log('program save: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error while saving.']);
        }
        exit;
    }
}

/* =========================================================
   FETCH DATA
========================================================= */
$programTypes = $pdo->query("SELECT id, category_name FROM program_category WHERE status = 'Active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$partners = $pdo->query("SELECT id, country_id, institution_name FROM partners WHERE status = 'Active' ORDER BY institution_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch active country/address records, then synchronize their names with
// the countries table used by program and partner foreign keys.
$addressRows = $pdo->query("
    SELECT DISTINCT country_name
    FROM countries_addresses
    WHERE LOWER(status) = 'active'
      AND country_name <> ''
    ORDER BY country_name
")->fetchAll(PDO::FETCH_ASSOC);

$countries = [];
foreach ($addressRows as $addressRow) {
    $countryName = trim((string)$addressRow['country_name']);

    $stmt = $pdo->prepare("
        SELECT id
        FROM countries
        WHERE country_name = ?
          AND LOWER(status) = 'active'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$countryName]);
    $countryId = (int)($stmt->fetchColumn() ?: 0);

    if ($countryId <= 0) {
        $stmt = $pdo->prepare("INSERT INTO countries (country_name, status) VALUES (?, 'Active')");
        $stmt->execute([$countryName]);
        $countryId = (int)$pdo->lastInsertId();
    }

    $countries[] = [
        'id' => $countryId,
        'country_name' => $countryName
    ];
}

$countryMap = [];
foreach ($countries as $c) {
    $countryMap[$c['id']] = $c['country_name'];
}

$totalPrograms = (int)$pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn();
$totalPages = max(1, ceil($totalPrograms / $limit));

$stmt = $pdo->prepare("
    SELECT p.*,
           pr.institution_name,
           (SELECT COUNT(*) FROM applications a
             WHERE a.program_id = p.id AND LOWER(TRIM(a.status)) = 'approved') AS filled_slots
    FROM programs p
    LEFT JOIN partners pr ON p.partner_id = pr.id
    ORDER BY p.id DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="../asset/css/programs.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
    /* Modal fix: keep the box inside the screen, scroll only the form body,
       and keep the Cancel / Save buttons always visible and clickable. */
    #addProgramModal.modal-overlay,
    #editProgramModal.modal-overlay {
        padding: 16px;
        box-sizing: border-box;
        overflow-y: auto;
    }
    #addProgramModal .modal-box,
    #editProgramModal .modal-box {
        display: flex;
        flex-direction: column;
        width: 100%;
        max-width: 720px;
        max-height: calc(100vh - 32px);
        margin: auto;
        overflow: hidden;
    }
    #addProgramModal .modal-box > form,
    #editProgramModal .modal-box > form {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
    }
    #addProgramModal .modal-header,
    #editProgramModal .modal-header,
    #addProgramModal .modal-footer,
    #editProgramModal .modal-footer {
        flex-shrink: 0;
    }
    #addProgramModal .modal-body,
    #editProgramModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding-bottom: 8px;
    }
    #addProgramModal .modal-footer,
    #editProgramModal .modal-footer {
        position: relative;
        z-index: 2;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        padding: 16px 24px;
        background: #fff;
        border-top: 1px solid #e2e8f0;
    }
    #addProgramModal .modal-footer button,
    #editProgramModal .modal-footer button {
        cursor: pointer;
        pointer-events: auto;
    }
    @media (max-width: 640px) {
        #addProgramModal .form-row,
        #editProgramModal .form-row {
            grid-template-columns: 1fr !important;
            flex-direction: column;
        }
    }
</style>

<div class="content">
    <div class="page-header">
        <h2>Program Management</h2>
        <p>Manage academic programs and course offerings</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search programs...">
        </div>
        <button type="button" class="create-btn" id="btnAddProgram">
            <i class="fa fa-plus"></i> Add Program
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Program Name</th>
                    <th>Type</th>
                    <th>Eligible Account Type</th>
                    <th>Eligible Department</th>
                    <th>Country</th>
                    <th>Partner</th>
                    <th>Slots</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if ($programs): ?>
                <?php foreach ($programs as $p): ?>
                    <?php
                        $slotsTotal = (int)($p['slots'] ?? 0);
                        $slotsFilled = (int)($p['filled_slots'] ?? 0);
                        $slotsFull = $slotsTotal > 0 && $slotsFilled >= $slotsTotal;
                    ?>
                    <tr data-name="<?= strtolower(h($p['program_name'])) ?>" data-id="<?= (int)$p['id'] ?>">
                        <td>
                            <div class="program-name">
                                <div class="program-icon"><i class="fa fa-graduation-cap"></i></div>
                                <div class="program-info"><strong><?= h($p['program_name']) ?></strong></div>
                            </div>
                        </td>
                        <td><?= h($p['program_type']) ?></td>
                        <td><?= h($p['eligible_account_type'] ?? 'All Applicants') ?></td>
                        <td><?= h($p['eligible_department'] ?? 'All Departments') ?></td>
                        <td><?= h($countryMap[$p['country_id']] ?? 'N/A') ?></td>
                        <td><?= h($p['institution_name'] ?? 'N/A') ?></td>
                        <td>
                            <?php if ($slotsTotal > 0): ?>
                                <span style="font-weight:600; <?= $slotsFull ? 'color:#b91c1c;' : '' ?>">
                                    <?= $slotsFilled ?> / <?= $slotsTotal ?>
                                </span>
                                <?php if ($slotsFull): ?>
                                    <br><small style="color:#b91c1c;">Full</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#64748b;">Unlimited</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower(h($p['status'])) ?>">
                                <?= h($p['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?= date('M d, Y', strtotime($p['start_date'])) ?> - <?= date('M d, Y', strtotime($p['end_date'])) ?>
                        </td>
                        <td>
                            <button type="button" class="action-btn edit-btn" data-program='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>'>
                                <i class="fa fa-pen"></i>
                            </button>
                            <button type="button" class="action-btn delete-btn" data-id="<?= (int)$p['id'] ?>">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                            </div>
                            <h3>No programs yet</h3>
                            <p>Click "Add Program" to create your first one.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span>Showing <?= count($programs) ?> of <?= $totalPrograms ?> programs</span>
            <div class="pages">
                <?php $baseQ = array_merge($_GET, []); unset($baseQ['p'], $baseQ['delete']); ?>
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

<!-- ========================= EDIT PROGRAM MODAL ========================= -->
<div id="editProgramModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon"><i class="fa fa-pen-to-square"></i></div>
                <div><h3>Edit Program</h3><p class="modal-subtitle">Update program details</p></div>
            </div>
            <button type="button" class="modal-close" data-close="editProgramModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="editProgramForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="id" id="edit_id">

            <div class="modal-body">
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" id="edit_program_name" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Eligible Department</label>
                        <select name="eligible_department" id="edit_eligible_department" class="form-input" required>
                            <option value="All Departments">All Departments</option>
                            <?php foreach ($departmentOptions as $department => $label): ?>
                                <option value="<?= h($department) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Eligible Account Type</label>
                        <select name="eligible_account_type" id="edit_eligible_account_type" class="form-input" required>
                            <option value="Student">Student</option>
                            <option value="Faculty">Faculty</option>
                            <option value="Non-Teaching">Non-Teaching</option>
                            <option value ="Both">Both</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Program Type</label>
                        <select name="program_type" id="edit_program_type" class="form-input" required>
                            <option value="">Select Program Type</option>
                            <?php foreach ($programTypes as $type): ?>
                                <option value="<?= h($type['category_name']) ?>"><?= h($type['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Upcoming">Upcoming</option>
                            <option value="Completed">Completed</option>
                            <option value="Suspended">Suspended</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Country</label>
                        <select name="country_id" id="edit_program_country" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= h($country['id']) ?>"><?= h($country['country_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Partner Institution</label>
                        <select name="partner_id" id="edit_program_partner" class="form-input" required>
                            <option value="">Select Country First</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Available Slots</label>
                    <input type="number" name="slots" id="edit_slots" class="form-input" min="0" step="1" value="0" required>
                    <small style="color:#94a3b8; font-size:11px; margin-top:4px; display:block;">
                        Maximum number of participants. Enter 0 for unlimited.
                    </small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" id="edit_start" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" id="edit_end" class="form-input" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" class="form-input" required></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-close="editProgramModal">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Update Program</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================= ADD PROGRAM MODAL ========================= -->
<div id="addProgramModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon"><i class="fa fa-graduation-cap"></i></div>
                <div><h3>Add Program</h3><p class="modal-subtitle">Create a new program.</p></div>
            </div>
            <button type="button" class="modal-close" data-close="addProgramModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="addProgramForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-book"></i> Program Name</label>
                    <input type="text" name="program_name" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-tag"></i> Program Type</label>
                        <select name="program_type" class="form-input" required>
                            <option value="">Select Program Type</option>
                            <?php foreach ($programTypes as $type): ?>
                                <option value="<?= h($type['category_name']) ?>"><?= h($type['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-circle-dot"></i> Status</label>
                        <select name="status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Upcoming">Upcoming</option>
                            <option value="Completed">Completed</option>
                            <option value="Suspended">Suspended</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-users"></i> Eligible Program</label>
                        <select name="eligible_department" class="form-input" required>
                            <option value="All Departments">All Departments</option>
                            <?php foreach ($departmentOptions as $department => $label): ?>
                                <option value="<?= h($department) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-user-check"></i> Eligible Account Type</label>
                        <select name="eligible_account_type" class="form-input" required>
                            <option value="Student">Student</option>
                            <option value="Faculty">Faculty</option>
                            <option value="Non-Teaching">Non-Teaching</option>
                            <option value ="Both">All Applicants</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-globe"></i> Country</label>
                        <select name="country_id" id="add_program_country" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= h($country['id']) ?>"><?= h($country['country_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-building"></i> Partner Institution</label>
                        <select name="partner_id" id="add_program_partner" class="form-input" required>
                            <option value="">Select Country First</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fa fa-chair"></i> Available Slots</label>
                    <input type="number" name="slots" class="form-input" min="0" step="1" value="0" required>
                    <small style="color:#94a3b8; font-size:11px; margin-top:4px; display:block;">
                        Maximum number of participants. Enter 0 for unlimited.
                    </small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-calendar"></i> Start Date</label>
                        <input type="date" name="start_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-calendar-check"></i> End Date</label>
                        <input type="date" name="end_date" class="form-input" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fa fa-align-left"></i> Description</label>
                    <textarea name="description" class="form-input" required></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-close="addProgramModal">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Save Program</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const allPartners = <?= json_encode($partners) ?>;

    // 1. SEARCH
    document.getElementById('searchInput').addEventListener('input', function () {
        const search = this.value.toLowerCase().trim();
        document.querySelectorAll('#tableBody tr').forEach(row => {
            const name = (row.dataset.name || '').toLowerCase();
            row.style.display = name.includes(search) ? '' : 'none';
        });
    });

    // 2. MODAL HELPERS
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', function() { closeModal(this.dataset.close); });
    });
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    });

    // 3. POPULATE PARTNER DROPDOWN
    function populatePartners(countryId, selectId, selectedPartnerId = null) {
        const select = document.getElementById(selectId);
        select.innerHTML = '<option value="">Select Partner</option>';

        if (!countryId) {
            select.innerHTML = '<option value="">Select Country First</option>';
            return;
        }

        const cidStr = String(countryId).trim();
        const filtered = allPartners.filter(p => String(p.country_id).trim() === cidStr);

        if (filtered.length === 0) {
            select.innerHTML = '<option value="">No partners in this country</option>';
            return;
        }

        filtered.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.institution_name;
            if (selectedPartnerId && String(p.id) === String(selectedPartnerId)) opt.selected = true;
            select.appendChild(opt);
        });
    }

    // 4. ADD PROGRAM
    document.getElementById('btnAddProgram').addEventListener('click', function() {
        document.getElementById('addProgramForm').reset();
        document.getElementById('add_program_partner').innerHTML = '<option value="">Select Country First</option>';
        openModal('addProgramModal');
    });

    document.getElementById('add_program_country').addEventListener('change', function() {
        populatePartners(this.value, 'add_program_partner');
    });

    document.getElementById('addProgramForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const start = this.querySelector('[name="start_date"]').value;
        const end = this.querySelector('[name="end_date"]').value;
        if (start && end && end < start) {
            showToast('error', 'End Date cannot be before Start Date.');
            return;
        }
        submitForm(this, 'addProgramModal');
    });

    // 5. EDIT PROGRAM
    document.getElementById('tableBody').addEventListener('click', function(e) {
        const editBtn = e.target.closest('.edit-btn');
        const deleteBtn = e.target.closest('.delete-btn');

        if (editBtn) {
            const data = JSON.parse(editBtn.dataset.program);

            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_program_name').value = data.program_name;
            document.getElementById('edit_program_type').value = data.program_type;
            document.getElementById('edit_eligible_department').value = data.eligible_department || 'All Departments';
            document.getElementById('edit_eligible_account_type').value = data.eligible_account_type || 'Student';
            document.getElementById('edit_status').value = data.status;
            document.getElementById('edit_slots').value = data.slots ?? 0;
            document.getElementById('edit_start').value = data.start_date;
            document.getElementById('edit_end').value = data.end_date;
            document.getElementById('edit_description').value = data.description;

            document.getElementById('edit_program_country').value = data.country_id;
            populatePartners(data.country_id, 'edit_program_partner', data.partner_id);

            openModal('editProgramModal');
        }

        if (deleteBtn) {
            if (!confirm('Delete this program? This cannot be undone.')) return;

            const id = deleteBtn.dataset.id;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            formData.append('csrf_token', '<?= $csrfToken ?>');

            fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        deleteBtn.closest('tr').remove();
                        showToast('success', data.message);
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(() => showToast('error', 'Server error.'));
        }
    });

    document.getElementById('edit_program_country').addEventListener('change', function() {
        populatePartners(this.value, 'edit_program_partner');
    });

    document.getElementById('editProgramForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const start = this.querySelector('[name="start_date"]').value;
        const end = this.querySelector('[name="end_date"]').value;
        if (start && end && end < start) {
            showToast('error', 'End Date cannot be before Start Date.');
            return;
        }
        submitForm(this, 'editProgramModal');
    });

    // 6. SUBMIT FORM HELPER
    function submitForm(form, modalId) {
        const btn = form.querySelector('[type="submit"]');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        fetch('', { method: 'POST', body: new FormData(form) })
            .then(async res => {
                const text = await res.text();
                try { return JSON.parse(text); }
                catch { throw new Error('Invalid JSON response'); }
            })
            .then(data => {
                showToast(data.success ? 'success' : 'error', data.message);
                if (data.success) {
                    closeModal(modalId);
                    setTimeout(() => location.reload(), 1000);
                }
            })
            .catch((err) => {
                console.error("Error saving:", err);
                showToast('error', 'Server error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    }

    // 7. TOAST
    function showToast(type, message) {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.innerHTML = `<i class="fa fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span></span>`;
        toast.querySelector('span').textContent = message;

        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('toast--show'));

        setTimeout(() => {
            toast.classList.remove('toast--show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
});
</script>