<?php

require_once __DIR__ . '/../config/db.php';

$pdo = db();


   //SAFE HELPER FUNCTION

if (!function_exists('h')) {

    function h($value): string {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
   //DELETE PROGRAM

if (isset($_GET['delete'])) {

    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("
        DELETE FROM programs
        WHERE id = ?
    ");

    $stmt->execute([$id]);
    header("Location: programs.php");
    exit();
}

   //LOAD PARTNERS
$partnerStmt = $pdo->query("
    SELECT id, institution_name
    FROM partners
    WHERE status = 'Active'
    ORDER BY institution_name
");
$partners = $partnerStmt->fetchAll(PDO::FETCH_ASSOC);

  // LOAD COUNTRIES
$countryStmt = $pdo->query("
    SELECT id, country_name
    FROM countries
    WHERE status = 'Active'
    ORDER BY country_name
");

$countries = $countryStmt->fetchAll(PDO::FETCH_ASSOC);

   //FETCH PROGRAMS
$stmt = $pdo->query("
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
");

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
            <?php foreach ($programs as $p): ?>
                <tr data-name="<?= strtolower(h($p['program_name'])) ?>">

                    <td><?= h($p['program_name']) ?></td>
                    <td><?= h($p['program_type']) ?></td>
                    <td><?= h($p['country_name']) ?></td>
                    <td><?= h($p['institution_name']) ?></td>

                    <td>
                        <span class="status-badge <?= strtolower($p['status']) ?>">
                            <?= h($p['status']) ?>
                        </span>
                    </td>

                    <td>
                        <?= date('M d, Y', strtotime($p['start_date'])) ?>
                        -
                        <?= date('M d, Y', strtotime($p['_end_date'])) ?> 
                    <td>
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
            </tbody>
        </table>
    </div>

</div>


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
                        <select name="program_type" id="edit_program_type" class="form-input" required>
                            <option value="Student Mobility Programs">Student Mobility</option>
                            <option value="Exchange Programs">Exchange</option>
                            <option value="International Internships">Internship</option>
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

                <select name="partner_id" class="form-input" required>
                    <option value="">Select Partner</option>
                    <?php foreach($partners as $partner): ?>
                        <option value="<?= $partner['id'] ?>">
                            <?= h($partner['institution_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="country_id" class="form-input" required>
                    <option value="">Select Country</option>
                    <?php foreach($countries as $country): ?>
                        <option value="<?= $country['id'] ?>">
                            <?= h($country['country_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

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
                <button type="submit" class="btn-confirm">Update Program</button>
            </div>

        </form>
    </div>
</div>
<!-- =========================
     ADD PROGRAM MODAL
========================= -->
<div id="programModal" class="modal-overlay" onclick="closeProgramModalOutside(event)">
    <div class="modal-box">

        <!-- HEADER -->
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

        <!-- FORM -->
        <form id="programForm">
            <div class="modal-body">

                <!-- Program Name -->
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

                <!-- Type + Status -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-tag"></i> Program Type
                        </label>
                        <select name="program_type" class="form-input" required>
                            <option value="">Select type...</option>
                            <option value="Student Mobility Programs">Student Mobility</option>
                            <option value="Exchange Programs">Exchange Programs</option>
                            <option value="International Internships">International Internships</option>
                        </select>
                    </div>
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

                <!-- Partner + Country -->
<div class="form-row">

    <div class="form-group">
        <label class="form-label">
            <i class="fa fa-building"></i> Partner Institution
        </label>

        <select name="partner_id" class="form-input" required>
            <option value="">Select Partner</option>

            <?php foreach($partners as $partner): ?>
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

            <?php foreach($countries as $country): ?>
                <option value="<?= $country['id'] ?>">
                    <?= h($country['country_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

</div>

                <!-- Start + End Date -->
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

                <!-- Description -->
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

            <!-- FOOTER -->
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
   MODAL
========================= */
function openProgramModal() {
    document.getElementById('programModal').classList.add('open');
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

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeProgramModal();
});

function openEditProgramModal(id) {
    document.getElementById('editProgramModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    fetch('get_program.php?id=' + id)
        .then(res => res.json())
        .then(data => {

            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_program_name').value = data.program_name;
            document.getElementById('edit_program_type').value = data.program_type;
            document.getElementById('edit_status').value = data.status;
            document.getElementById('edit_partner_id').value = data.partner_id;
            document.getElementById('edit_country_id').value = data.country_id;
            document.getElementById('edit_start').value = data.start_date;
            document.getElementById('edit_end').value = data._end_date;
            document.getElementById('edit_description').value = data.description;
        })
        .catch(() => {
            alert('Cannot load program data');
        });
}

function closeEditProgramModal() {
    document.getElementById('editProgramModal').classList.remove('open');
    document.body.style.overflow = '';
}

function closeEditProgramOutside(e) {
    if (e.target.id === 'editProgramModal') {
        closeEditProgramModal();
    }
}

/* submit update */
document.getElementById('editProgramForm').addEventListener('submit', function(e) {
    e.preventDefault();

    fetch('update_program.php', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(res => res.json())
    .then(data => {
        showToast(data.success ? 'success' : 'error', data.message);
        if (data.success) {
            closeEditProgramModal();
            setTimeout(() => location.reload(), 1000);
        }
    })
    .catch(() => {
        showToast('error', 'Server error');
    });
});
/* =========================
   FORM SUBMIT
========================= */
document.getElementById('programForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const btn      = this.querySelector('[type="submit"]');
    const original = btn.textContent;
    btn.disabled    = true;
    btn.textContent = 'Saving...';

    fetch('add_program_modal.php', {
        method: 'POST',
        body:   new FormData(this)
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch {
            console.error('Invalid response:', text);
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
    .catch(err => {
        console.error(err);
        showToast('error', 'Something went wrong. Please try again.');
    })
    .finally(() => {
        btn.disabled    = false;
        btn.textContent = original;
    });
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
        <span>${message}</span>
    `;
    document.body.appendChild(toast);

    setTimeout(() => toast.classList.add('toast--show'), 10);
    setTimeout(() => {
        toast.classList.remove('toast--show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
</script>