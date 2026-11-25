<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/role_helpers.php';

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

function savePartnerUpload(string $field, array $allowedMimes, ?string $existing = null): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Each attachment must be no larger than 10 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException('Attachment type is not supported.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'partners';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare partner attachment storage.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Unable to save the partner attachment.');
    }
    return 'uploads/partners/' . $filename;
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
        $addressRecordId = (int)($_POST['address_record_id'] ?? 0);
        $countryName = '';

        if ($countryId > 0) {
            $stmt = $pdo->prepare("SELECT country_name FROM countries WHERE id = ?");
            $stmt->execute([$countryId]);
            $countryName = (string)($stmt->fetchColumn() ?: '');
        }

        if ($countryId <= 0 || $countryName === '') {
            echo json_encode(['success' => false, 'message' => 'Please select a valid country.']);
            exit;
        }

        // Since we don't have address_record_id in the table yet, we append the address to the notes
        $addressText = '';
        if ($addressRecordId > 0) {
            $addrStmt = $pdo->prepare("SELECT region, province, city, street_address FROM countries_addresses WHERE id = ?");
            $addrStmt->execute([$addressRecordId]);
            $addr = $addrStmt->fetch(PDO::FETCH_ASSOC);
            if ($addr) {
                $addressText = implode(', ', array_filter([$addr['region'], $addr['province'], $addr['city'], $addr['street_address']]));
            }
        }

        $collaborationAreas = $_POST['collaboration_areas'] ?? [];
        $allowedAreas = ['trainings', 'conferences', 'research', 'staff exchange'];
        if (!is_array($collaborationAreas) || array_diff($collaborationAreas, $allowedAreas)) {
            echo json_encode(['success' => false, 'message' => 'Select valid areas of collaboration.']);
            exit;
        }
        
        $institutionName = trim((string)($_POST['institution_name'] ?? ''));
        $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
        
        if ($institutionName === '' || mb_strlen($institutionName) > 255) {
            echo json_encode(['success' => false, 'message' => 'Enter an institution name up to 255 characters.']);
            exit;
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Enter a valid contact email address.']);
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

        $existingPartner = [];
        if ($id > 0) {
            $existingStmt = $pdo->prepare('SELECT profile_photos, brochure_path, moa_path FROM partners WHERE id = ?');
            $existingStmt->execute([$id]);
            $existingPartner = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$existingPartner) {
                echo json_encode(['success' => false, 'message' => 'Partner institution not found.']);
                exit;
            }
        }

        try {
            $photoPaths = json_decode((string)($existingPartner['profile_photos'] ?? '[]'), true);
            $photoPaths = is_array($photoPaths) ? $photoPaths : [];
            $photoMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (isset($_FILES['profile_photos']) && is_array($_FILES['profile_photos']['name'] ?? null)) {
                foreach ($_FILES['profile_photos']['name'] as $index => $unusedName) {
                    if ($_FILES['profile_photos']['error'][$index] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $_FILES['_partner_photo'] = [
                        'name' => $_FILES['profile_photos']['name'][$index],
                        'type' => $_FILES['profile_photos']['type'][$index],
                        'tmp_name' => $_FILES['profile_photos']['tmp_name'][$index],
                        'error' => $_FILES['profile_photos']['error'][$index],
                        'size' => $_FILES['profile_photos']['size'][$index],
                    ];
                    $photoPaths[] = savePartnerUpload('_partner_photo', $photoMimes);
                }
                unset($_FILES['_partner_photo']);
            }

            // Build the final notes string including the address
            $finalNotes = trim($_POST['notes'] ?? '');
            if ($addressText !== '') {
                $finalNotes = "Address: " . $addressText . "\n\n" . $finalNotes;
            }

            $data = [
                'institution_name'     => $institutionName,
                'country_id'           => $countryId,
                'tagline'              => trim($_POST['tagline'] ?? ''),
                'background'           => trim($_POST['background'] ?? ''),
                'offerings'            => trim($_POST['offerings'] ?? ''),
                'contact_first_name'   => trim($_POST['contact_first_name'] ?? ''),
                'contact_middle_name'  => trim($_POST['contact_mi'] ?? ''),
                'contact_last_name'    => trim($_POST['contact_last_name'] ?? ''),
                'contact_email'        => $contactEmail,
                'agreement_type_id'    => $agreementId,
                'expiry_date'          => $_POST['expiry_date'] ?: null,
                'notes'                => $finalNotes,
                'collaboration_areas'  => json_encode(array_values(array_unique($collaborationAreas))),
                'profile_photos'       => json_encode(array_values(array_unique($photoPaths))),
                'brochure_path'        => savePartnerUpload('brochure', [
                    'application/pdf' => 'pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                ], $existingPartner['brochure_path'] ?? null),
                'moa_path'             => savePartnerUpload('moa', [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                ], $existingPartner['moa_path'] ?? null),
                'status'               => $_POST['status'] ?? 'Active'
            ];

            if ($id > 0) {
                $cols = array_keys($data);
                $set  = implode(', ', array_map(static function ($c) { return "$c = ?"; }, $cols));
                $stmt = $pdo->prepare("UPDATE partners SET $set WHERE id = ?");
                $stmt->execute(array_merge(array_values($data), [$id]));
            } else {
                $cols = array_keys($data);
                $stmt = $pdo->prepare(
                    'INSERT INTO partners (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
                );
                $stmt->execute(array_values($data));
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            error_log('partner save: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Database error while saving: ' . $e->getMessage()
            ]);
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

// Sync country names from countries_addresses into `countries`
$addressRows = $pdo->query("
    SELECT DISTINCT country_name
    FROM countries_addresses
    WHERE LOWER(status) = 'active' AND country_name <> ''
    ORDER BY country_name
")->fetchAll(PDO::FETCH_ASSOC);

$findCountry   = $pdo->prepare("SELECT id FROM countries WHERE country_name = ? AND LOWER(status) = 'active' ORDER BY id ASC LIMIT 1");
$insertCountry = $pdo->prepare("INSERT INTO countries (country_name, status) VALUES (?, 'Active')");

$countries = [];
foreach ($addressRows as $addressRow) {
    $addressCountryName = trim((string)$addressRow['country_name']);
    $findCountry->execute([$addressCountryName]);
    $countryId = (int)($findCountry->fetchColumn() ?: 0);
    if ($countryId <= 0) {
        $insertCountry->execute([$addressCountryName]);
        $countryId = (int)$pdo->lastInsertId();
    }
    $countries[] = ['id' => $countryId, 'country_name' => $addressCountryName];
}

$knownCountryNames = array_fill_keys(array_map(
    static function (array $country): string {
        return strtolower(trim((string)$country['country_name']));
    }, $countries
), true);

$storedCountries = $pdo->query("
    SELECT id, country_name
    FROM countries
    WHERE LOWER(status) = 'active'
    ORDER BY country_name
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($storedCountries as $country) {
    $normalizedName = strtolower(trim((string)$country['country_name']));
    if (!isset($knownCountryNames[$normalizedName])) {
        $countries[] = ['id' => (int)$country['id'], 'country_name' => $country['country_name']];
        $knownCountryNames[$normalizedName] = true;
    }
}

$addressOptions = $pdo->query("
    SELECT id, country_name, region, province, city, postal_code, street_address
    FROM countries_addresses
    WHERE LOWER(status) = 'active'
    ORDER BY country_name, region, province, city, street_address
")->fetchAll(PDO::FETCH_ASSOC);

$agreementTypes = $pdo->query("SELECT id, agreement_name FROM agreement_types WHERE status = 'Active' ORDER BY agreement_name")->fetchAll(PDO::FETCH_ASSOC);

// NOTE: We removed the LEFT JOIN to countries_addresses because the column address_record_id does not exist in your partners table yet.
$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.country_name,
        ag.agreement_name
    FROM partners p
    LEFT JOIN countries c ON c.id = p.country_id
    LEFT JOIN agreement_types ag ON ag.id = p.agreement_type_id
    ORDER BY p.institution_name ASC
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

<link rel="stylesheet" href="../asset/css/programs.css">
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
        <a class="create-btn" href="dashboard.php?page=programs" style="background:#0284c7;">Continue to Programs</a>
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
                    $jsonData = htmlspecialchars(json_encode($partner), ENT_QUOTES, 'UTF-8');
                ?>
                <tr data-name="<?= h($searchData) ?>" data-id="<?= (int)$partner['id'] ?>">
                    <td>
                        <div class="program-name">
                            <div class="program-icon" style="background:#eff6ff; color:#2563eb;"><i class="fa fa-handshake"></i></div>
                            <div class="program-info"><strong><?= h($partner['institution_name']) ?></strong></div>
                        </div>
                    </td>
                    <td><?= h($partner['country_name'] ?? 'N/A') ?></td>
                    <td>
                        <div class="contact-cell">
                            <strong><?= h($contactName) ?></strong>
                            <?php if (!empty($partner['contact_email'])): ?>
                                <div style="font-size:0.75rem; color:#64748b;"><?= h($partner['contact_email']) ?></div>
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
                <tr><td colspan="7" style="text-align:center; padding:40px;">No partner institutions found. Click "Add Partner" to register one.</td></tr>
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

        <form id="addForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-globe"></i> Country *</label>
                        <select name="country_id" class="form-input country-select" required>
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
                <div class="form-group">
                    <label class="form-label">Region → Province → City → Full Address *</label>
                    <select name="address_record_id" class="form-input address-select" required>
                        <option value="">Select a saved address</option>
                        <?php foreach ($addressOptions as $address): ?>
                            <?php $addressLabel = implode(', ', array_filter([$address['region'], $address['province'], $address['city'], $address['street_address'], $address['postal_code']])); ?>
                            <option value="<?= (int)$address['id'] ?>" data-country="<?= h($address['country_name']) ?>"><?= h($addressLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Institution Tagline</label>
                        <input type="text" name="tagline" class="form-input" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label class="form-label">What the institution offers</label>
                        <textarea name="offerings" class="form-input"></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Institution Background</label>
                    <textarea name="background" class="form-input"></textarea>
                </div>
                <fieldset class="form-group">
                    <legend>Areas of Collaboration (select all that apply)</legend>
                    <?php foreach (['trainings' => 'Trainings', 'conferences' => 'Conferences', 'research' => 'Research', 'staff exchange' => 'Staff exchange'] as $value => $label): ?>
                        <label><input type="checkbox" name="collaboration_areas[]" value="<?= h($value) ?>"> <?= h($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="form-row">
                    <label class="form-group">Photos <input type="file" name="profile_photos[]" accept="image/jpeg,image/png" multiple style="margin-top:5px;"></label>
                    <label class="form-group">Brochure <input type="file" name="brochure" accept=".pdf,.docx" style="margin-top:5px;"></label>
                    <label class="form-group">MOA / MOU <input type="file" name="moa" accept=".pdf,.doc,.docx" style="margin-top:5px;"></label>
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

        <form id="editForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="edit_id" id="editId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-globe"></i> Country *</label>
                        <select id="editCountry" name="country_id" class="form-input country-select" required>
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
                <div class="form-group">
                    <label class="form-label">Region → Province → City → Full Address *</label>
                    <select name="address_record_id" id="editAddress" class="form-input address-select" required>
                        <option value="">Select a saved address</option>
                        <?php foreach ($addressOptions as $address): ?>
                            <?php $addressLabel = implode(', ', array_filter([$address['region'], $address['province'], $address['city'], $address['street_address'], $address['postal_code']])); ?>
                            <option value="<?= (int)$address['id'] ?>" data-country="<?= h($address['country_name']) ?>"><?= h($addressLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Institution Tagline</label>
                        <input type="text" id="editTagline" name="tagline" class="form-input" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label class="form-label">What the institution offers</label>
                        <textarea id="editOfferings" name="offerings" class="form-input"></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Institution Background</label>
                    <textarea id="editBackground" name="background" class="form-input"></textarea>
                </div>
                <fieldset class="form-group" id="editCollaborationAreas">
                    <legend>Areas of Collaboration</legend>
                    <?php foreach (['trainings' => 'Trainings', 'conferences' => 'Conferences', 'research' => 'Research', 'staff exchange' => 'Staff exchange'] as $value => $label): ?>
                        <label><input type="checkbox" name="collaboration_areas[]" value="<?= h($value) ?>"> <?= h($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="form-row">
                    <label class="form-group">Add photos <input type="file" name="profile_photos[]" accept="image/jpeg,image/png" multiple style="margin-top:5px;"></label>
                    <label class="form-group">Replace brochure <input type="file" name="brochure" accept=".pdf,.docx" style="margin-top:5px;"></label>
                    <label class="form-group">Replace MOA / MOU <input type="file" name="moa" accept=".pdf,.doc,.docx" style="margin-top:5px;"></label>
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
                <div><small style="color:#64748b;">Institution</small><p id="viewInstitution" style="font-weight:600; margin-top:4px;"></p></div>
                <div><small style="color:#64748b;">Country</small><p id="viewCountry" style="font-weight:600; margin-top:4px;"></p></div>
                <div><small style="color:#64748b;">Agreement</small><p id="viewAgreement" style="margin-top:4px;"></p></div>
                <div><small style="color:#64748b;">Status</small><p id="viewStatus" style="margin-top:4px;"></p></div>
                <div><small style="color:#64748b;">Expiry Date</small><p id="viewExpiry" style="margin-top:4px;"></p></div>
            </div>
            <hr style="border:0; border-top:1px solid #e2e8f0; margin: 20px 0;">
            <div><small style="color:#64748b;">Address</small><p id="viewAddress" style="margin-top:4px;"></p></div>
            <div style="margin-top: 15px;"><small style="color:#64748b;">Tagline</small><p id="viewTagline" style="margin-top:4px;"></p></div>
            <div style="margin-top: 15px;"><small style="color:#64748b;">Contact Person</small><p id="viewContactName" style="font-weight:600; margin-top:4px;"></p><p id="viewContactEmail" style="color:#64748b; font-size:0.9rem;"></p></div>
            <div style="margin-top: 15px;"><small style="color:#64748b;">Notes</small><p id="viewNotes" style="color:#334155; line-height:1.6; margin-top:4px;"></p></div>
            <div style="margin-top: 15px;"><small style="color:#64748b;">Background</small><p id="viewBackground" style="margin-top:4px;"></p></div>
            <div style="margin-top: 15px;"><small style="color:#64748b;">What they offer</small><p id="viewOfferings" style="margin-top:4px;"></p></div>
            <div style="margin-top: 15px;"><small style="color:#64748b;">Areas of collaboration</small><p id="viewCollaboration" style="margin-top:4px;"></p></div>
            <div style="margin-top: 20px;" id="viewPartnerFiles"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" data-close="viewModal">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Modal Helpers
    const openModal = (id) => document.getElementById(id).classList.add('open');
    const closeModal = (id) => document.getElementById(id).classList.remove('open');

    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.close));
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) closeModal(this.id);
        });
    });

    function filterAddresses(countrySelect, addressSelect, selectedId = null) {
        const countryName = countrySelect.selectedOptions[0]?.textContent.trim() || '';
        Array.from(addressSelect.options).forEach(option => {
            if (!option.value) return;
            option.hidden = !countryName || option.dataset.country !== countryName;
            if (option.hidden) option.selected = false;
        });
        if (selectedId) {
            const selected = Array.from(addressSelect.options).find(option => option.value === String(selectedId));
            if (selected && !selected.hidden) selected.selected = true;
        }
    }

    document.querySelectorAll('.country-select').forEach(countrySelect => {
        const form = countrySelect.closest('form');
        const addressSelect = form.querySelector('.address-select');
        if (addressSelect) {
            countrySelect.addEventListener('change', () => filterAddresses(countrySelect, addressSelect));
        }
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
            document.getElementById('viewAddress').textContent = [data.region, data.province, data.city, data.street_address, data.postal_code].filter(Boolean).join(', ') || 'See Notes';
            document.getElementById('viewTagline').textContent = data.tagline || 'N/A';
            document.getElementById('viewAgreement').textContent = data.agreement_name || 'N/A';
            document.getElementById('viewStatus').textContent = data.status;
            document.getElementById('viewExpiry').textContent = data.expiry_date || 'N/A';
            document.getElementById('viewContactName').textContent = `${data.contact_first_name} ${data.contact_last_name}`;
            document.getElementById('viewContactEmail').textContent = data.contact_email || 'N/A';
            document.getElementById('viewNotes').textContent = data.notes || 'No notes provided.';
            document.getElementById('viewBackground').textContent = data.background || 'N/A';
            document.getElementById('viewOfferings').textContent = data.offerings || 'N/A';
            let areas = [];
            let photos = [];
            try { areas = JSON.parse(data.collaboration_areas || '[]'); } catch (error) {}
            try { photos = JSON.parse(data.profile_photos || '[]'); } catch (error) {}
            document.getElementById('viewCollaboration').textContent = areas.length ? areas.join(', ') : 'N/A';
            const fileContainer = document.getElementById('viewPartnerFiles');
            fileContainer.replaceChildren();
            const partnerFiles = [
                ['Brochure', data.brochure_path],
                ['MOA / MOU', data.moa_path],
                ...photos.map((path, index) => ['Photo ' + (index + 1), path])
            ];
            partnerFiles.forEach(([label, path]) => {
                if (!path || !/^[A-Za-z0-9_./-]+$/.test(path)) return;
                const link = document.createElement('a');
                link.href = '../' + path.replace(/^\/+/, '');
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = label;
                fileContainer.append(link, document.createElement('br'));
            });
            openModal('viewModal');
        }

        if (editBtn) {
            const data = JSON.parse(editBtn.dataset.partner);
            document.getElementById('editId').value = data.id;
            document.getElementById('editCountry').value = data.country_id;
            filterAddresses(document.getElementById('editCountry'), document.getElementById('editAddress'), data.address_record_id || null);
            document.getElementById('editInstitution').value = data.institution_name;
            document.getElementById('editTagline').value = data.tagline || '';
            document.getElementById('editBackground').value = data.background || '';
            document.getElementById('editOfferings').value = data.offerings || '';
            document.getElementById('editAgreement').value = data.agreement_name || '';
            document.getElementById('editStatus').value = data.status;
            document.getElementById('editContactFirst').value = data.contact_first_name || '';
            document.getElementById('editContactMi').value = data.contact_middle_name || '';
            document.getElementById('editContactLast').value = data.contact_last_name || '';
            document.getElementById('editEmail').value = data.contact_email || '';
            document.getElementById('editExpiry').value = data.expiry_date || '';
            document.getElementById('editNotes').value = data.notes || '';
            let selectedAreas = [];
            try { selectedAreas = JSON.parse(data.collaboration_areas || '[]'); } catch (error) {}
            document.querySelectorAll('#editCollaborationAreas input[name="collaboration_areas[]"]').forEach(input => {
                input.checked = selectedAreas.includes(input.value);
            });
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