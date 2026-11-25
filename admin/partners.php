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
if (!canManagePartners()) {
    http_response_code(403);
    exit('Access denied.');
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db();

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function formatContactName(?string $first, ?string $mi, ?string $last): string {
    $first = trim((string)$first); $mi = trim((string)$mi); $last = trim((string)$last);
    $parts = [];
    if ($first !== '') $parts[] = $first;
    if ($mi !== '')    $parts[] = strtoupper($mi) . '.';
    if ($last !== '')  $parts[] = $last;
    return $parts ? implode(' ', $parts) : 'N/A';
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
            $pdo->prepare("DELETE FROM partners WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('partner delete: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Cannot delete partner. They may be linked to existing programs.']);
        }
        exit;
    }

    if ($action === 'save') {
        $id = (int)($_POST['edit_id'] ?? 0);
        $countryId = (int)($_POST['country_id'] ?? 0);
        $countryName = '';

        if ($countryId > 0) {
            $stmt = $pdo->prepare("SELECT country_name FROM countries WHERE id = ?");
            $stmt->execute([$countryId]);
            $countryName = (string)($stmt->fetchColumn() ?: '');

            // Accept an address-table ID as a fallback for older forms.
            if ($countryName === '') {
                $stmt = $pdo->prepare("SELECT country_name FROM countries_addresses WHERE id = ?");
                $stmt->execute([$countryId]);
                $countryName = (string)($stmt->fetchColumn() ?: '');
            }

            if ($countryName !== '') {
                $stmt = $pdo->prepare("SELECT id FROM countries WHERE country_name = ? AND LOWER(status) = 'active' ORDER BY id ASC LIMIT 1");
                $stmt->execute([$countryName]);
                $countryId = (int)($stmt->fetchColumn() ?: 0);

                if ($countryId <= 0) {
                    $stmt = $pdo->prepare("INSERT INTO countries (country_name, status) VALUES (?, 'Active')");
                    $stmt->execute([$countryName]);
                    $countryId = (int)$pdo->lastInsertId();
                }
            }
        }

        if ($countryId <= 0 || $countryName === '') {
            echo json_encode(['success' => false, 'message' => 'Please select a valid country.']);
            exit;
        }
        
        // Lookup agreement type ID based on the string name from the form
        $agreementName = trim($_POST['agreement_type'] ?? '');
        $agreementId = null;
        if ($agreementName !== '') {
            $stmt = $pdo->prepare("SELECT id FROM agreement_types WHERE agreement_name = ? LIMIT 1");
            $stmt->execute([$agreementName]);
            $agreementId = $stmt->fetchColumn() ?: null;
        }

        $data = [
            'institution_name'     => trim($_POST['institution_name'] ?? ''),
            'contact_first_name'   => trim($_POST['contact_first_name'] ?? ''),
            'contact_middle_name'  => trim($_POST['contact_mi'] ?? ''),
            'contact_last_name'    => trim($_POST['contact_last_name'] ?? ''),
            'contact_email'        => trim($_POST['contact_email'] ?? ''),
            'agreement_type_id'    => $agreementId,
            'expiry_date'          => $_POST['expiry_date'] ?: null,
            'notes'                => trim($_POST['notes'] ?? ''),
            'status'               => $_POST['status'] ?? 'Active'
        ];

        try {
            if ($id > 0) {
                // Update
                $sql = "UPDATE partners SET institution_name=?, country_id=?, contact_first_name=?, contact_middle_name=?, contact_last_name=?, contact_email=?, agreement_type_id=?, expiry_date=?, notes=?, status=? WHERE id=?";
                $params = [
                    $data['institution_name'],
                    $countryId,
                    $data['contact_first_name'],
                    $data['contact_middle_name'],
                    $data['contact_last_name'],
                    $data['contact_email'],
                    $data['agreement_type_id'],
                    $data['expiry_date'],
                    $data['notes'],
                    $data['status'],
                    $id
                ];
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                // Insert
                $sql = "INSERT INTO partners (institution_name, country_id, contact_first_name, contact_middle_name, contact_last_name, contact_email, agreement_type_id, expiry_date, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $data['institution_name'],
                    $countryId,
                    $data['contact_first_name'],
                    $data['contact_middle_name'],
                    $data['contact_last_name'],
                    $data['contact_email'],
                    $data['agreement_type_id'],
                    $data['expiry_date'],
                    $data['notes'],
                    $data['status']
                ]);
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('partner save: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error while saving.']);
        }
        exit;
    }
}

/* =========================================================
   FETCH DATA
========================================================= */
$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;

$totalPartners = (int)$pdo->query("SELECT COUNT(*) FROM partners")->fetchColumn();
$totalPages = max(1, ceil($totalPartners / $limit));

// Country addresses are the source of country dropdown options.
// Synchronize their names into the countries table used by partner foreign keys.
$addressRows = $pdo->query("
    SELECT DISTINCT country_name
    FROM countries_addresses
    WHERE LOWER(status) = 'active'
      AND country_name <> ''
    ORDER BY country_name
")->fetchAll(PDO::FETCH_ASSOC);

$countries = [];
foreach ($addressRows as $addressRow) {
    $addressCountryName = trim((string)$addressRow['country_name']);
    $stmt = $pdo->prepare("SELECT id FROM countries WHERE country_name = ? AND LOWER(status) = 'active' ORDER BY id ASC LIMIT 1");
    $stmt->execute([$addressCountryName]);
    $countryId = (int)($stmt->fetchColumn() ?: 0);
    if ($countryId <= 0) {
        $stmt = $pdo->prepare("INSERT INTO countries (country_name, status) VALUES (?, 'Active')");
        $stmt->execute([$addressCountryName]);
        $countryId = (int)$pdo->lastInsertId();
    }

    $countries[] = ['id' => $countryId, 'country_name' => $addressCountryName];
}

$agreementTypes = $pdo->query("SELECT id, agreement_name FROM agreement_types WHERE status = 'Active' ORDER BY agreement_name")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT p.id, p.institution_name, p.country_id, c.country_name,
           p.contact_first_name, p.contact_middle_name, p.contact_last_name, p.contact_email,
           p.agreement_type_id, a.agreement_name, p.expiry_date, p.status, p.notes
    FROM partners p
    LEFT JOIN countries c ON p.country_id = c.id
    LEFT JOIN agreement_types a ON p.agreement_type_id = a.id
    ORDER BY p.id DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        <button type="button" class="create-btn" id="btnAddPartner">
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
                    $contactName = formatContactName($partner['contact_first_name'], $partner['contact_middle_name'], $partner['contact_last_name']);
                    $searchData = strtolower($partner['institution_name'] . ' ' . $partner['country_name'] . ' ' . $contactName);
                    $status = strtolower($partner['status'] ?? 'Active');
                    $expClass = expiryClass($partner['expiry_date'] ?? null);
                    // json_encode handles the JS injection safety better than htmlspecialchars here
                    $jsonData = htmlspecialchars(json_encode($partner), ENT_QUOTES, 'UTF-8');
                ?>
                <tr data-name="<?= h($searchData) ?>" data-id="<?= (int)$partner['id'] ?>">
                    <td>
                        <div class="institution-cell">
                            <div class="institution-icon"><i class="fa fa-handshake"></i></div>
                            <div class="institution-info"><strong><?= h($partner['institution_name']) ?></strong></div>
                        </div>
                    </td>
                    <td><?= h($partner['country_name'] ?? 'N/A') ?></td>
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
                            <?= !empty($partner['expiry_date']) ? date('M d, Y', strtotime($partner['expiry_date'])) : 'N/A' ?>
                        </span>
                    </td>
                    <td><span class="status-badge status-<?= h($status) ?>"><?= ucfirst($status) ?></span></td>
                    <td>
                        <div class="actions">
                            <button type="button" class="action-btn view-btn" title="View" data-partner='<?= $jsonData ?>'><i class="fa fa-eye"></i></button>
                            <button type="button" class="action-btn edit-btn" title="Edit" data-partner='<?= $jsonData ?>'><i class="fa fa-pen"></i></button>
                            <button type="button" class="action-btn delete-btn" title="Delete" data-id="<?= (int)$partner['id'] ?>"><i class="fa fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="empty-state"><h3>No partner institutions found</h3><p>Click "Add Partner" to register one.</p></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span>Showing <?= count($partners) ?> of <?= $totalPartners ?> partners</span>
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

<!-- ===================== ADD MODAL ===================== -->
<div id="addModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon"><i class="fa fa-handshake"></i></div>
                <div><h3>Add Partner Institution</h3><p class="modal-subtitle">Register a new partner.</p></div>
            </div>
            <button class="modal-close" data-close="addModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="addForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-globe"></i> Country *</label>
                        <select name="country_id" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= h($c['country_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-building"></i> Institution Name *</label>
                        <input type="text" name="institution_name" class="form-input" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-file-contract"></i> Agreement Type</label>
                        <select name="agreement_type" class="form-input">
                            <option value="">Select Type</option>
                            <?php foreach ($agreementTypes as $t): ?>
                                <option value="<?= h($t['agreement_name']) ?>"><?= h($t['agreement_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-circle-dot"></i> Status</label>
                        <select name="status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-user"></i> Contact Person</label>
                    <div class="form-row" style="grid-template-columns: 1fr 70px 1fr;">
                        <input type="text" name="contact_first_name" class="form-input" placeholder="First name" required>
                        <input type="text" name="contact_mi" class="form-input" placeholder="M.I." maxlength="1" style="text-align:center;">
                        <input type="text" name="contact_last_name" class="form-input" placeholder="Last name" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-envelope"></i> Contact Email</label>
                    <input type="email" name="contact_email" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-calendar-xmark"></i> Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-note-sticky"></i> Notes</label>
                    <textarea name="notes" class="form-input"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-close="addModal">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Save Partner</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== EDIT MODAL ===================== -->
<div id="editModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon"><i class="fa fa-pen-to-square"></i></div>
                <div><h3>Edit Partner</h3><p class="modal-subtitle">Update record.</p></div>
            </div>
            <button class="modal-close" data-close="editModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="editForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="edit_id" id="editId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-globe"></i> Country *</label>
                        <select id="editCountry" name="country_id" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= h($c['country_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-building"></i> Institution Name *</label>
                        <input type="text" id="editInstitution" name="institution_name" class="form-input" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-file-contract"></i> Agreement Type</label>
                        <select name="agreement_type" id="editAgreement" class="form-input">
                            <option value="">Select Type</option>
                            <?php foreach ($agreementTypes as $t): ?>
                                <option value="<?= h($t['agreement_name']) ?>"><?= h($t['agreement_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-circle-dot"></i> Status</label>
                        <select name="status" id="editStatus" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-user"></i> Contact Person</label>
                    <div class="form-row" style="grid-template-columns: 1fr 70px 1fr;">
                        <input type="text" name="contact_first_name" id="editContactFirst" class="form-input" required>
                        <input type="text" name="contact_mi" id="editContactMi" class="form-input" maxlength="1" style="text-align:center;">
                        <input type="text" name="contact_last_name" id="editContactLast" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-envelope"></i> Contact Email</label>
                    <input type="email" name="contact_email" id="editEmail" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-calendar-xmark"></i> Expiry Date</label>
                    <input type="date" name="expiry_date" id="editExpiry" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-note-sticky"></i> Notes</label>
                    <textarea name="notes" id="editNotes" class="form-input"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-close="editModal">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Update Partner</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== VIEW MODAL ===================== -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 600px;">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon" style="background: #e0f2fe; color: #0284c7;"><i class="fa fa-eye"></i></div>
                <div><h3>Partner Details</h3></div>
            </div>
            <button class="modal-close" data-close="viewModal"><i class="fa fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding: 24px;">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div><small>Institution</small><p id="viewInstitution" style="font-weight:600;"></p></div>
                <div><small>Country</small><p id="viewCountry" style="font-weight:600;"></p></div>
                <div><small>Agreement</small><p id="viewAgreement"></p></div>
                <div><small>Status</small><p id="viewStatus"></p></div>
                <div><small>Expiry Date</small><p id="viewExpiry"></p></div>
            </div>
            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 20px 0;">
            <div>
                <small>Contact Person</small>
                <p id="viewContactName" style="font-weight:600; margin-top:4px;"></p>
                <p id="viewContactEmail" style="color:#64748b; font-size:0.9rem;"></p>
            </div>
            <div style="margin-top: 20px;">
                <small>Notes</small>
                <p id="viewNotes" style="color:#334155; line-height:1.6;"></p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" data-close="viewModal">Close</button>
        </div>
    </div>
</div>

<!-- INLINE JS (Replaces partners.js to guarantee functionality) -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Modal Helpers
    const openModal = (id) => document.getElementById(id).classList.add('open');
    const closeModal = (id) => document.getElementById(id).classList.remove('open');

    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.close));
    });

    // Add Partner
    document.getElementById('btnAddPartner').addEventListener('click', () => {
        document.getElementById('addForm').reset();
        openModal('addModal');
    });

    // Handle Form Submissions (AJAX)
    const handleFormSubmit = (e) => {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const btn = form.querySelector('[type="submit"]');
        const originalText = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error saving partner.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            })
            .catch(() => {
                alert('Server error.');
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
    };

    document.getElementById('addForm').addEventListener('submit', handleFormSubmit);
    document.getElementById('editForm').addEventListener('submit', handleFormSubmit);

    // Table Actions (View, Edit, Delete)
    document.getElementById('tableBody').addEventListener('click', (e) => {
        const viewBtn = e.target.closest('.view-btn');
        const editBtn = e.target.closest('.edit-btn');
        const deleteBtn = e.target.closest('.delete-btn');

        if (viewBtn) {
            const data = JSON.parse(viewBtn.dataset.partner);
            document.getElementById('viewInstitution').textContent = data.institution_name;
            document.getElementById('viewCountry').textContent = data.country_name || 'N/A';
            document.getElementById('viewAgreement').textContent = data.agreement_name || 'N/A';
            document.getElementById('viewStatus').textContent = data.status;
            document.getElementById('viewExpiry').textContent = data.expiry_date || 'N/A';
            document.getElementById('viewContactName').textContent = `${data.contact_first_name} ${data.contact_last_name}`;
            document.getElementById('viewContactEmail').textContent = data.contact_email || 'N/A';
            document.getElementById('viewNotes').textContent = data.notes || 'No notes provided.';
            openModal('viewModal');
        }

        if (editBtn) {
            const data = JSON.parse(editBtn.dataset.partner);
            document.getElementById('editId').value = data.id;
            document.getElementById('editCountry').value = data.country_id;
            document.getElementById('editInstitution').value = data.institution_name;
            document.getElementById('editAgreement').value = data.agreement_name || '';
            document.getElementById('editStatus').value = data.status;
            document.getElementById('editContactFirst').value = data.contact_first_name || '';
            document.getElementById('editContactMi').value = data.contact_middle_name || '';
            document.getElementById('editContactLast').value = data.contact_last_name || '';
            document.getElementById('editEmail').value = data.contact_email || '';
            document.getElementById('editExpiry').value = data.expiry_date || '';
            document.getElementById('editNotes').value = data.notes || '';
            openModal('editModal');
        }

        if (deleteBtn) {
            if (!confirm('Delete this partner? This cannot be undone.')) return;
            
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
                    } else {
                        alert(data.message);
                    }
                });
        }
    });

    // Search Filter
    document.getElementById('searchInput').addEventListener('input', function() {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(row => {
            row.style.display = row.dataset.name.includes(q) ? '' : 'none';
        });
    });
});
</script>