<?php

require_once __DIR__ . '/../config/db.php';

$pdo = db();

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

/* SAFE HELPER FUNCTION */
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/* DELETE PROGRAM */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: programs.php");
    exit();
}

/* LOAD PROGRAM CATEGORIES */
$typeStmt = $pdo->query("
    SELECT id, category_name
    FROM program_category
    WHERE status = 'Active'
    ORDER BY category_name
");
$programTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

/* LOAD PARTNERS */
$partnerStmt = $pdo->query("
    SELECT id, institution_name
    FROM partners
    WHERE status = 'Active'
    ORDER BY institution_name
");
$partners = $partnerStmt->fetchAll(PDO::FETCH_ASSOC);

/* LOAD COUNTRIES */
$countryStmt = $pdo->query("
    SELECT id, country_name
    FROM countries_addresses
    WHERE status = 'active'
    ORDER BY country_name
");
$countries = $countryStmt->fetchAll(PDO::FETCH_ASSOC);

$totalPrograms = (int)$pdo->query("
    SELECT COUNT(*) FROM programs
")->fetchColumn();

$totalPages = max(1, ceil($totalPrograms / $limit));

/* FETCH PROGRAMS */
$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.country_name,
        pr.institution_name
    FROM programs p
    LEFT JOIN countries c
        ON p.country_id = c.id
    LEFT JOIN partners pr
        ON p.partner_id = pr.id
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

<div class="content">

    <div class="page-header">
        <h2>Program Management</h2>
        <p>Manage academic programs and course offerings</p>
    </div>

    <!-- TOOLBAR -->
    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search programs...">
        </div>
        <button class="create-btn" onclick="openProgramModal()">
            <i class="fa fa-plus"></i> Add Program
        </button>
    </div>

    <!-- TABLE -->
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Program Name</th>
                    <th>Type</th>
                    <th>Country</th>
                    <th>Partner</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if ($programs): ?>
                <?php foreach ($programs as $p): ?>
                    <tr data-name="<?= strtolower(h($p['program_name'])) ?>">
                        <td>
                            <div class="program-name">
                                <div class="program-icon">
                                    <i class="fa fa-graduation-cap"></i>
                                </div>
                                <div class="program-info">
                                    <strong><?= h($p['program_name']) ?></strong>
                                </div>
                            </div>
                        </td>
                        <td><?= h($p['program_type']) ?></td>
                        <td><?= h($p['country_name']) ?></td>
                        <td><?= h($p['institution_name']) ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower(h($p['status'])) ?>">
                                <?= h($p['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?= date('M d, Y', strtotime($p['start_date'])) ?>
                            -
                            <?= date('M d, Y', strtotime($p['end_date'])) ?>
                        </td>
                        <td>
                            <button type="button"
                                    class="action-btn edit-btn"
                                    onclick="openEditProgramModal(<?= (int)$p['id'] ?>)">
                                <i class="fa fa-pen"></i>
                            </button>
                            <a href="programs.php?delete=<?= $p['id'] ?>"
                               onclick="return confirm('Delete this program?')"
                               class="action-btn delete-btn">
                                <i class="fa fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">
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

    <!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($programs) ?> of <?= $totalPrograms ?> programs
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

<!-- =========================
     EDIT PROGRAM MODAL
========================= -->
<div id="editProgramModal" class="modal-overlay" onclick="closeEditProgramOutside(event)">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-pen-to-square"></i>
                </div>
                <div>
                    <h3>Edit Program</h3>
                    <p class="modal-subtitle">Update program details</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeEditProgramModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <form id="editProgramForm">
            <input type="hidden" name="id" id="edit_id">

            <div class="modal-body">

                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" id="edit_program_name" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Program Type</label>
                            <select name="program_type"
                                    id="edit_program_type"
                                    class="form-input"
                                    required>

                                <option value="">Select Program Type</option>

                                <?php foreach ($programTypes as $type): ?>
                                    <option value="<?= h($type['category_name']) ?>">
                                        <?= h($type['category_name']) ?>
                                    </option>
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
                        <label>Partner Institution</label>

                        <select name="partner_id"
                                id="programPartner"
                                class="form-input"
                                required>

                            <option value="">Select Country First</option>

                        </select>
                    </div>

                    <div class="form-group">
                        <label>Country</label>

                        <select name="country_id"
                                id="programCountry"
                                class="form-input"
                                required>

                            <option value="">Select Country</option>

                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['id'] ?>">
                                    <?= h($country['country_name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>
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
                <button type="button" class="btn-secondary" onclick="closeEditProgramModal()">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Update Program</button>
            </div>

        </form>
    </div>
</div>

<!-- =========================
     ADD PROGRAM MODAL
========================= -->
<div id="programModal" class="modal-overlay" onclick="closeProgramModalOutside(event)">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-graduation-cap"></i>
                </div>
                <div>
                    <h3>Add Program</h3>
                    <p class="modal-subtitle">Fill in the details below to create a new program.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeProgramModal()">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <form id="programForm">
            <div class="modal-body">

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-book"></i> Program Name
                    </label>
                    <input type="text"
                           name="program_name"
                           class="form-input"
                           placeholder="e.g. International Student Exchange"
                           required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-tag"></i> Program Type
                        </label>
                            <select name="program_type" class="form-input" required>
                                <option value="">Select Program Type</option>

                                <?php foreach ($programTypes as $type): ?>
                                    <option value="<?= h($type['category_name']) ?>">
                                        <?= h($type['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-circle-dot"></i> Status
                        </label>
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
                        <label class="form-label">
                            <i class="fa fa-building"></i> Partner Institution
                        </label>
                        <select name="partner_id" class="form-input" required>
                            <option value="">Select Partner</option>
                            <?php foreach ($partners as $partner): ?>
                                <option value="<?= $partner['id'] ?>">
                                    <?= h($partner['institution_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-globe"></i> Country
                        </label>
                        <select name="country_id" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= $country['id'] ?>">
                                    <?= h($country['country_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-calendar"></i> Start Date
                        </label>
                        <input type="date" name="start_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-calendar-check"></i> End Date
                        </label>
                        <input type="date" name="end_date" class="form-input" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-align-left"></i> Description
                    </label>
                    <textarea name="description"
                              class="form-input"
                              placeholder="Brief description of the program..."
                              required></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeProgramModal()">
                    Cancel
                </button>
                <button type="submit" class="btn-confirm">
                    <i class="fa fa-floppy-disk"></i> Save Program
                </button>
            </div>

        </form>

    </div>
</div>

<script>
/* =========================
   SEARCH
========================= */
document.getElementById('searchInput').addEventListener('input', function () {
    const search = this.value.toLowerCase().trim();
    document.querySelectorAll('#tableBody tr').forEach(row => {
        const name = (row.dataset.name || '').toLowerCase();
        row.style.display = name.includes(search) ? '' : 'none';
    });
});

/* =========================
   ADD PROGRAM MODAL
========================= */
function openProgramModal() {
    const modal = document.getElementById('programModal');
    document.body.appendChild(modal);          // NEW — escape any ancestor transform/filter
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeProgramModal() {
    document.getElementById('programModal').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('programForm').reset();
}

function closeProgramModalOutside(e) {
    if (e.target.id === 'programModal') closeProgramModal();
}

/* =========================
   EDIT PROGRAM MODAL
========================= */
function openEditProgramModal(id) {
    const modal = document.getElementById('editProgramModal');
    document.body.appendChild(modal);          // NEW
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('get_program.php?id=' + id)
        .then(res => res.json())
        .then(data => {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_program_name').value = data.program_name;
            document.getElementById('edit_program_type').value = data.program_type;
            document.getElementById('edit_status').value = data.status;

            // Select the country first
            document.getElementById('edit_country_id').value = data.country_id;

            // Load partners for that country
            loadPartners(data.country_id, data.partner_id);

            document.getElementById('edit_start').value = data.start_date;
            document.getElementById('edit_end').value = data.end_date;
            document.getElementById('edit_description').value = data.description;
        })
        .catch(() => {
            showToast('error', 'Cannot load program data');
            closeEditProgramModal();
        });
}

function closeEditProgramModal() {
    document.getElementById('editProgramModal').classList.remove('open');
    document.body.style.overflow = '';
}

function closeEditProgramOutside(e) {
    if (e.target.id === 'editProgramModal') closeEditProgramModal();
}

function loadPartners(countryId, selectedPartner = '') {

    fetch('get_partners_by_country.php?country_id=' + countryId)
        .then(res => res.json())
        .then(partners => {

            const select = document.getElementById('edit_partner_id');

            select.innerHTML = '<option value="">Select Partner</option>';

            partners.forEach(partner => {

                const option = document.createElement('option');
                option.value = partner.id;
                option.textContent = partner.institution_name;

                if (partner.id == selectedPartner) {
                    option.selected = true;
                }

                select.appendChild(option);
            });

        });
}
/* =========================
   EDIT FORM SUBMIT
========================= */
document.getElementById('editProgramForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const btn      = this.querySelector('[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled    = true;
    btn.textContent  = 'Updating...';

    fetch('update_program.php', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch {
            throw new Error('Server did not return JSON');
        }
    })
    .then(data => {
        showToast(data.success ? 'success' : 'error', data.message);
        if (data.success) {
            closeEditProgramModal();
            setTimeout(() => location.reload(), 1000);
        }
    })
    .catch(() => {
        showToast('error', 'Server error');
    })
    .finally(() => {
        btn.disabled    = false;
        btn.innerHTML   = original;
    });
});

document.getElementById('edit_country_id').addEventListener('change', function () {
    loadPartners(this.value);
});

/* =========================
   ADD FORM SUBMIT
========================= */
document.getElementById('programForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const btn      = this.querySelector('[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled    = true;
    btn.textContent  = 'Saving...';

    fetch('add_program_modal.php', {
        method: 'POST',
        body:   new FormData(this)
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch {
            throw new Error('Server did not return JSON');
        }
    })
    .then(data => {
        showToast(data.success ? 'success' : 'error', data.message);
        if (data.success) {
            closeProgramModal();
            setTimeout(() => location.reload(), 1200);
        }
    })
    .catch(() => {
        showToast('error', 'Something went wrong. Please try again.');
    })
    .finally(() => {
        btn.disabled    = false;
        btn.innerHTML   = original;
    });
});

document.querySelector('[name="country_id"]').addEventListener('change', function () {
    loadAddPartners(this.value);
});

/* =========================
   ESC KEY HANDLER
========================= */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeProgramModal();
        closeEditProgramModal();
    }
});

/* =========================
   TOAST
========================= */
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