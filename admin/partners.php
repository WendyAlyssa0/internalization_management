<?php
require_once __DIR__ . '/../config/db.php';

$pdo = db();

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Build a display-friendly full name from first/MI/last parts.
 * e.g. ("Juan", "D", "Santos") -> "Juan D. Santos"
 */
function formatContactName(?string $first, ?string $mi, ?string $last): string {
    $first = trim((string)$first);
    $mi    = trim((string)$mi);
    $last  = trim((string)$last);

    $parts = [];
    if ($first !== '') $parts[] = $first;
    if ($mi !== '')    $parts[] = strtoupper($mi) . '.';
    if ($last !== '')  $parts[] = $last;

    return $parts ? implode(' ', $parts) : 'N/A';
}

/* =========================
   OVPLIA DATASET
========================= */
$ovpliaData = [
    "Vietnam" => [
        "Vietnam National University (Hanoi)",
        "Vietnam National University (Ho Chi Minh City)",
        "University of Da Nang",
        "Can Tho University"
    ],
    "Malaysia" => [
        "University of Malaya (UM)",
        "Universiti Teknologi Malaysia (UTM)",
        "Universiti Kebangsaan Malaysia (UKM)",
        "Universiti Sains Malaysia (USM)"
    ],
    "Indonesia" => [
        "Universitas Gadjah Mada (UGM)",
        "Universitas Indonesia (UI)",
        "Institut Teknologi Bandung (ITB)",
        "Universitas Brawijaya"
    ],
    "Thailand" => [
        "Chulalongkorn University",
        "Thammasat University",
        "Kasetsart University",
        "KMITL"
    ],
    "Japan" => [
        "University of Tokyo",
        "Kyoto University",
        "Osaka University",
        "Chiba University"
    ],
    "South Korea" => [
        "Seoul National University",
        "Korea University",
        "Yonsei University",
        "Hanyang University"
    ]
];

/* =========================
   SAFE DELETE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int) $_POST['delete_id'];
    $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$id]);
    header("Location: dashboard.php?page=partners");
    exit();
}

$totalPartners = (int)$pdo->query("
    SELECT COUNT(*)
    FROM partners
")->fetchColumn();

$totalPages = max(1, ceil($totalPartners / $limit));

/* =========================
   FETCH PARTNERS
========================= */
$stmt = $pdo->prepare("
SELECT
    p.id,
    p.institution_name,
    c.country_name,
    p.contact_first_name,
    p.contact_middle_name,
    p.contact_last_name,
    p.contact_email,
    a.agreement_name,
    p.expiry_date,
    p.status
FROM partners p
LEFT JOIN countries c
    ON p.country_id = c.id
LEFT JOIN agreement_types a
    ON p.agreement_type_id = a.id
ORDER BY p.id DESC
LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

$agreementTypes = $pdo->query("
    SELECT id, agreement_name
    FROM agreement_types
    WHERE status = 'Active'
    ORDER BY agreement_name
")->fetchAll(PDO::FETCH_ASSOC);

/* Helper: expiry date status class */
function expiryClass(?string $date): string {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    if ($timestamp === false) return '';
    $days = ($timestamp - time()) / 86400;
    if ($days < 0) return 'expiry-expired';
    if ($days <= 30) return 'expiry-soon';
    return '';
}
?>

<link rel="stylesheet" href="../asset/css/partners.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <div class="page-header">
        <h2>Partner Institution Management</h2>
        <p>Manage international partner database, MOA/MOU agreements, collaboration records, and institutional profiles.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search institutions...">
        </div>
        <button class="create-btn" onclick="openAddModal()">
            <i class="fa fa-plus"></i> Add Partner
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Institution</th>
                    <th>Country</th>
                    <th>Contact Person</th>
                    <th>Agreement Type</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!empty($partners)): ?>
                <?php foreach ($partners as $partner):
                    $contactName = formatContactName(
                        $partner['contact_first_name'] ?? '',
                        $partner['contact_middle_name'] ?? '',
                        $partner['contact_last_name'] ?? ''
                    );
                    $searchData = strtolower(
                        $partner['institution_name'] . ' ' .
                        $partner['country_name'] . ' ' .
                        $contactName
                    );
                    $status = strtolower($partner['status'] ?? 'active');
                    $expClass = expiryClass($partner['expiry_date'] ?? null);
                ?>
                <tr data-name="<?= h($searchData) ?>">
                    <td>
                        <div class="institution-cell">
                            <div class="institution-icon">
                                <i class="fa fa-handshake"></i>
                            </div>
                            <div class="institution-info">
                                <strong><?= h($partner['institution_name']) ?></strong>
                            </div>
                        </div>
                    </td>
                    <td><?= h($partner['country_name']) ?></td>
                    <td>
                        <div class="contact-cell">
                            <strong><?= h($contactName) ?></strong>
                            <?php if (!empty($partner['contact_email'])): ?>
                                <small><?= h($partner['contact_email']) ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= h($partner['agreement_name'] ?: 'N/A') ?></td>
                    <td>
                        <span class="expiry-cell <?= $expClass ?>">
                            <?= !empty($partner['expiry_date'])
                                ? date('M d, Y', strtotime($partner['expiry_date']))
                                : 'N/A' ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-badge status-<?= h($status) ?>">
                            <?= ucfirst($status) ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions">
                            <a href="view_partner.php?id=<?= $partner['id'] ?>" class="action-btn view-btn" title="View">
                                <i class="fa fa-eye"></i>
                            </a>
                            <button type="button" class="action-btn edit-btn" title="Edit"
                                    onclick="openEditModal(<?= (int)$partner['id'] ?>)">
                                <i class="fa fa-pen"></i>
                            </button>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Delete this partner?');">
                                <input type="hidden" name="delete_id" value="<?= (int)$partner['id'] ?>">
                                <button type="submit" class="action-btn delete-btn" title="Delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/>
                                </svg>
                            </div>
                            <h3>No partner institutions found</h3>
                            <p>Click "Add Partner" to register your first international partner.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

    <!-- PAGINATION -->
        <div class="pagination">
            <span>
                Showing <?= count($partners) ?> of <?= $totalPartners ?> partners
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

<!-- =====================================================================
     ADD PARTNER MODAL
====================================================================== -->
<div id="addModal" class="modal-overlay" onclick="closeModalOutside(event,'addModal')">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-handshake"></i>
                </div>
                <div>
                    <h3>Add Partner Institution</h3>
                    <p class="modal-subtitle">Register a new international partner.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('addModal')">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <form id="addForm">
            <div class="modal-body">

                <!-- Country + Institution (cascading) -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-globe"></i> Country *
                        </label>
                        <select name="country" id="addCountrySelect" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($ovpliaData as $country => $institutions): ?>
                                <option value="<?= h($country) ?>"><?= h($country) ?></option>
                            <?php endforeach; ?>
                            <option value="__other__">Other (type manually)</option>
                        </select>
                        <input type="text" name="country_manual" id="addCountryManual"
                               class="form-input" placeholder="Enter country name"
                               style="display:none;margin-top:8px">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-building"></i> Institution Name *
                        </label>
                        <select name="institution_name" id="addInstitutionSelect" class="form-input" required>
                            <option value="">Select Country first</option>
                        </select>
                        <input type="text" name="institution_manual" id="addInstitutionManual"
                               class="form-input" placeholder="Enter institution name"
                               style="display:none;margin-top:8px">
                    </div>
                </div>

                <!-- Agreement Type + Status -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-file-contract"></i> Agreement Type
                        </label>
                        <select name="agreement_type" class="form-input">
                            <option value="">Select Agreement Type</option>
                            <?php foreach ($agreementTypes as $type): ?>
                                <option value="<?= h($type['agreement_name']) ?>">
                                    <?= h($type['agreement_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-circle-dot"></i> Status
                        </label>
                        <select name="status" class="form-input" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="expired">Expired</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>

                <!-- Contact Person: First Name / M.I. / Last Name -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-user"></i> Contact Person
                    </label>
                    <div class="form-row" style="grid-template-columns: 1fr 70px 1fr;">
                        <input type="text" name="contact_first_name" class="form-input"
                               placeholder="First name" required maxlength="100">
                        <input type="text" name="contact_mi" class="form-input"
                               placeholder="M.I." maxlength="1" pattern="[A-Za-z]"
                               title="Single letter only" style="text-align:center;">
                        <input type="text" name="contact_last_name" class="form-input"
                               placeholder="Last name" required maxlength="100">
                    </div>
                </div>

                <!-- Contact Email -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-envelope"></i> Contact Email
                    </label>
                    <input type="email" name="contact_email" class="form-input"
                           placeholder="e.g. jsmith@univ.edu">
                </div>

                <!-- Expiry Date -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-calendar-xmark"></i> Expiry Date
                    </label>
                    <input type="date" name="expiry_date" class="form-input">
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-note-sticky"></i> Notes
                    </label>
                    <textarea name="notes" class="form-input"
                              placeholder="Any additional notes about this partner..."></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addModal')">
                    Cancel
                </button>
                <button type="submit" class="btn-confirm">
                    <i class="fa fa-floppy-disk"></i> Save Partner
                </button>
            </div>
        </form>

    </div>
</div>

<!-- =====================================================================
     EDIT PARTNER MODAL
====================================================================== -->
<div id="editModal" class="modal-overlay" onclick="closeModalOutside(event,'editModal')">
    <div class="modal-box">

        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <i class="fa fa-pen-to-square"></i>
                </div>
                <div>
                    <h3>Edit Partner Institution</h3>
                    <p class="modal-subtitle" id="editModalSubtitle">Update partner record details.</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal('editModal')">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <!-- Loading state -->
        <div id="editLoading" class="modal-body" style="display:flex;align-items:center;justify-content:center;min-height:200px;">
            <i class="fa fa-spinner fa-spin" style="font-size:24px;color:#94a3b8"></i>
        </div>

        <form id="editForm" style="display:none;">
            <input type="hidden" name="edit_id" id="editId">

            <div class="modal-body">

                <!-- Country + Institution -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-globe"></i> Country *
                        </label>
                        <input type="text" name="country" id="editCountry" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-building"></i> Institution Name *
                        </label>
                        <input type="text" name="institution_name" id="editInstitution" class="form-input" required>
                    </div>
                </div>

                <!-- Agreement Type + Status -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-file-contract"></i> Agreement Type
                        </label>
                        <select name="agreement_type" id="editAgreement" class="form-input">
                            <option value="">Select Agreement Type</option>
                            <?php foreach ($agreementTypes as $type): ?>
                                <option value="<?= h($type['agreement_name']) ?>">
                                    <?= h($type['agreement_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fa fa-circle-dot"></i> Status
                        </label>
                        <select name="status" id="editStatus" class="form-input" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="expired">Expired</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                </div>

                <!-- Contact Person: First Name / M.I. / Last Name -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-user"></i> Contact Person
                    </label>
                    <div class="form-row" style="grid-template-columns: 1fr 70px 1fr;">
                        <input type="text" name="contact_first_name" id="editContactFirst"
                               class="form-input" placeholder="First name" required maxlength="100">
                        <input type="text" name="contact_mi" id="editContactMi"
                               class="form-input" placeholder="M.I." maxlength="1"
                               pattern="[A-Za-z]" title="Single letter only" style="text-align:center;">
                        <input type="text" name="contact_last_name" id="editContactLast"
                               class="form-input" placeholder="Last name" required maxlength="100">
                    </div>
                </div>

                <!-- Contact Email -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-envelope"></i> Contact Email
                    </label>
                    <input type="email" name="contact_email" id="editEmail"
                           class="form-input" placeholder="e.g. jsmith@univ.edu">
                </div>

                <!-- Expiry Date -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-calendar-xmark"></i> Expiry Date
                    </label>
                    <input type="date" name="expiry_date" id="editExpiry" class="form-input">
                </div>

                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa fa-note-sticky"></i> Notes
                    </label>
                    <textarea name="notes" id="editNotes" class="form-input"
                              placeholder="Any additional notes..."></textarea>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('editModal')">
                    Cancel
                </button>
                <button type="submit" class="btn-confirm">
                    <i class="fa fa-floppy-disk"></i> Update Partner
                </button>
            </div>
        </form>

    </div>
</div>

<script>
const ovpliaData = <?= json_encode($ovpliaData) ?>;

/* ── Search ── */
document.getElementById('searchInput').addEventListener('input', function () {
    const search  = this.value.toLowerCase().trim();
    const rows    = document.querySelectorAll('#tableBody tr[data-name]');
    let   visible = 0;

    rows.forEach(row => {
        const text = (row.dataset.name || '').toLowerCase();
        const show = !search || text.includes(search);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('paginationInfo').innerText =
        `Showing ${visible} partner(s)`;
});

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
    if (e.key === 'Escape') {
        closeModal('addModal');
        closeModal('editModal');
    }
});

/* ── ADD modal ── */
function openAddModal() {
    document.getElementById('addForm').reset();
    const inst = document.getElementById('addInstitutionSelect');
    inst.innerHTML = '<option value="">Select Country first</option>';
    inst.style.display = '';
    document.getElementById('addInstitutionManual').style.display = 'none';
    document.getElementById('addCountryManual').style.display = 'none';
    openModal('addModal');
}

/* Cascading country -> institution */
document.getElementById('addCountrySelect').addEventListener('change', function () {
    const country    = this.value;
    const instSelect = document.getElementById('addInstitutionSelect');
    const instManual = document.getElementById('addInstitutionManual');
    const ctryManual = document.getElementById('addCountryManual');

    ctryManual.style.display = country === '__other__' ? '' : 'none';
    ctryManual.required      = country === '__other__';

    instSelect.innerHTML = '<option value="">Select Institution</option>';
    instManual.style.display = 'none';
    instManual.required      = false;

    if (!country || country === '__other__') {
        instSelect.innerHTML = '<option value="">Enter manually below</option>';
        instManual.style.display = '';
        instManual.required      = true;
        instSelect.style.display = 'none';
        return;
    }

    instSelect.style.display = '';
    if (ovpliaData[country]) {
        ovpliaData[country].forEach(inst => {
            const opt = document.createElement('option');
            opt.value = inst;
            opt.textContent = inst;
            instSelect.appendChild(opt);
        });
    }

    const other = document.createElement('option');
    other.value = '__other__';
    other.textContent = 'Other (type manually)';
    instSelect.appendChild(other);
});

document.getElementById('addInstitutionSelect').addEventListener('change', function () {
    const manual = document.getElementById('addInstitutionManual');
    const isOther = this.value === '__other__';
    manual.style.display = isOther ? '' : 'none';
    manual.required      = isOther;
});

/* ADD form submit */
document.getElementById('addForm').addEventListener('submit', function (e) {
    e.preventDefault();
    submitForm(this, '/Internalization_management/admin/add_partner.php', 'addModal');
});

/* ── EDIT modal ── */
function openEditModal(id) {
    document.getElementById('editLoading').style.display = 'flex';
    document.getElementById('editForm').style.display    = 'none';
    document.getElementById('editModalSubtitle').textContent = 'Loading partner data...';
    openModal('editModal');

    fetch(`/Internalization_management/admin/get_partner.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data || data.error) {
                showToast('error', (data && data.error) || 'Failed to load partner data.');
                closeModal('editModal');
                return;
            }

            document.getElementById('editId').value             = data.id;
            document.getElementById('editInstitution').value    = data.institution_name  || '';
            document.getElementById('editCountry').value      = data.country           || '';
            document.getElementById('editContactFirst').value = data.contact_first_name || '';
            document.getElementById('editContactMi').value     = data.contact_mi        || '';
            document.getElementById('editContactLast').value  = data.contact_last_name || '';
            document.getElementById('editEmail').value         = data.contact_email     || '';
            document.getElementById('editExpiry').value       = data.expiry_date       || '';
            document.getElementById('editNotes').value         = data.notes             || '';

            setSelectValue('editAgreement', data.agreement_type || '');
            setSelectValue('editStatus',    (data.status || 'active').toLowerCase());

            document.getElementById('editModalSubtitle').textContent =
                `Editing: ${data.institution_name}`;

            document.getElementById('editLoading').style.display = 'none';
            document.getElementById('editForm').style.display    = '';
        })
        .catch(() => {
            showToast('error', 'Could not reach the server.');
            closeModal('editModal');
        });
}

function setSelectValue(id, value) {
    const el = document.getElementById(id);
    for (let i = 0; i < el.options.length; i++) {
        if (el.options[i].value.toLowerCase() === value.toLowerCase()) {
            el.selectedIndex = i;
            return;
        }
    }
    el.selectedIndex = 0;
}

/* EDIT form submit */
document.getElementById('editForm').addEventListener('submit', function (e) {
    e.preventDefault();
    submitForm(this, '/Internalization_management/admin/update_partner.php', 'editModal');
});

/* ── Shared submit handler ── */
function submitForm(form, endpoint, modalId) {
    const btn      = form.querySelector('[type="submit"]');
    const original = btn.innerHTML;
    btn.disabled   = true;
    btn.innerHTML  = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    fetch(endpoint, { method: 'POST', body: new FormData(form) })
        .then(async res => {
            const text = await res.text();
            try { return JSON.parse(text); }
            catch { throw new Error('Server did not return JSON'); }
        })
        .then(data => {
            showToast(data.success ? 'success' : 'error', data.message);
            if (data.success) {
                closeModal(modalId);
                setTimeout(() => location.reload(), 1200);
            }
        })
        .catch(err => {
            console.error(err);
            showToast('error', 'Something went wrong. Please try again.');
        })
        .finally(() => {
            btn.disabled  = false;
            btn.innerHTML = original;
        });
}

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