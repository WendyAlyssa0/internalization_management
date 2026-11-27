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

/** Full world country list (S11). Existing DB spellings are merged in below. */
const WORLD_COUNTRIES = "Afghanistan|Albania|Algeria|Andorra|Angola|Antigua and Barbuda|Argentina|Armenia|Australia|Austria|Azerbaijan|Bahamas|Bahrain|Bangladesh|Barbados|Belarus|Belgium|Belize|Benin|Bhutan|Bolivia|Bosnia and Herzegovina|Botswana|Brazil|Brunei|Bulgaria|Burkina Faso|Burundi|Cabo Verde|Cambodia|Cameroon|Canada|Central African Republic|Chad|Chile|China|Colombia|Comoros|Congo|Costa Rica|Côte d'Ivoire|Croatia|Cuba|Cyprus|Czech Republic|Democratic Republic of the Congo|Denmark|Djibouti|Dominica|Dominican Republic|Ecuador|Egypt|El Salvador|Equatorial Guinea|Eritrea|Estonia|Eswatini|Ethiopia|Fiji|Finland|France|Gabon|Gambia|Georgia|Germany|Ghana|Greece|Grenada|Guatemala|Guinea|Guinea-Bissau|Guyana|Haiti|Honduras|Hong Kong|Hungary|Iceland|India|Indonesia|Iran|Iraq|Ireland|Israel|Italy|Jamaica|Japan|Jordan|Kazakhstan|Kenya|Kiribati|Kosovo|Kuwait|Kyrgyzstan|Laos|Latvia|Lebanon|Lesotho|Liberia|Libya|Liechtenstein|Lithuania|Luxembourg|Macau|Madagascar|Malawi|Malaysia|Maldives|Mali|Malta|Marshall Islands|Mauritania|Mauritius|Mexico|Micronesia|Moldova|Monaco|Mongolia|Montenegro|Morocco|Mozambique|Myanmar|Namibia|Nauru|Nepal|Netherlands|New Zealand|Nicaragua|Niger|Nigeria|North Korea|North Macedonia|Norway|Oman|Pakistan|Palau|Palestine|Panama|Papua New Guinea|Paraguay|Peru|Philippines|Poland|Portugal|Qatar|Romania|Russia|Rwanda|Saint Kitts and Nevis|Saint Lucia|Saint Vincent and the Grenadines|Samoa|San Marino|Sao Tome and Principe|Saudi Arabia|Senegal|Serbia|Seychelles|Sierra Leone|Singapore|Slovakia|Slovenia|Solomon Islands|Somalia|South Africa|South Korea|South Sudan|Spain|Sri Lanka|Sudan|Suriname|Sweden|Switzerland|Syria|Taiwan|Tajikistan|Tanzania|Thailand|Timor-Leste|Togo|Tonga|Trinidad and Tobago|Tunisia|Turkey|Turkmenistan|Tuvalu|Uganda|Ukraine|United Arab Emirates|United Kingdom|United States|Uruguay|Uzbekistan|Vanuatu|Vatican City|Venezuela|Vietnam|Yemen|Zambia|Zimbabwe";

/** Newly stored files in this request, so they can be removed if the save fails. */
$GLOBALS['newPartnerUploads'] = [];

function partnerUploadRoot(): string {
    return dirname(__DIR__);
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

    $directory = partnerUploadRoot() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'partners';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare partner attachment storage.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Unable to save the partner attachment.');
    }
    $GLOBALS['newPartnerUploads'][] = $target;
    return 'uploads/partners/' . $filename;
}

function deletePartnerFile(string $relative): void {
    if (!preg_match('#^uploads/partners/[a-f0-9]{32}\.[a-z]{3,4}$#', $relative)) return;
    $full = partnerUploadRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($full)) @unlink($full);
}

/* =========================================================
   AJAX HANDLERS (SAVE / DEACTIVATE)
   Runs before any HTML so the response is always clean JSON.
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $action = $_POST['action'];

    /* Soft delete: partner records are kept for accreditation / linked programs. */
    if ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("UPDATE partners SET status = 'Inactive' WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('partner deactivate: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to deactivate partner.']);
        }
        exit;
    }

    if ($action === 'save') {
        $id = (int)($_POST['edit_id'] ?? 0);
        $addressRecordId = (int)($_POST['address_record_id'] ?? 0);

        /* ---- Country (searchable text; resolved to a countries row on save) ---- */
        $typedCountry = trim((string)($_POST['country_name'] ?? ''));
        $known = [];
        foreach (explode('|', WORLD_COUNTRIES) as $n) { $known[mb_strtolower($n)] = $n; }
        foreach ($pdo->query("SELECT country_name FROM countries")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $n = trim((string)$n);
            if ($n !== '') $known[mb_strtolower($n)] = $n;
        }
        foreach ($pdo->query("SELECT DISTINCT country_name FROM countries_addresses")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $n = trim((string)$n);
            if ($n !== '') $known[mb_strtolower($n)] = $n;
        }
        $countryName = $known[mb_strtolower($typedCountry)] ?? '';
        if ($countryName === '') {
            echo json_encode(['success' => false, 'message' => 'Please pick a country from the list.']);
            exit;
        }

        /* ---- Address must belong to the chosen country ---- */
        $addrCount = $pdo->prepare("SELECT COUNT(*) FROM countries_addresses WHERE LOWER(status) = 'active' AND LOWER(country_name) = LOWER(?)");
        $addrCount->execute([$countryName]);
        $countryHasAddresses = (int)$addrCount->fetchColumn() > 0;

        if ($addressRecordId > 0) {
            $chk = $pdo->prepare("SELECT country_name FROM countries_addresses WHERE id = ?");
            $chk->execute([$addressRecordId]);
            $addrCountry = $chk->fetchColumn();
            if ($addrCountry === false || mb_strtolower(trim((string)$addrCountry)) !== mb_strtolower($countryName)) {
                echo json_encode(['success' => false, 'message' => 'The selected address does not belong to that country.']);
                exit;
            }
        } elseif ($countryHasAddresses) {
            echo json_encode(['success' => false, 'message' => 'Select the region, city and full address for this institution.']);
            exit;
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

        $status = (string)($_POST['status'] ?? 'Active');
        if (!in_array($status, ['Active', 'Inactive'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status.']);
            exit;
        }

        $expiry = trim((string)($_POST['expiry_date'] ?? ''));
        if ($expiry !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $expiry);
            if (!$d || $d->format('Y-m-d') !== $expiry) {
                echo json_encode(['success' => false, 'message' => 'Enter a valid expiry date.']);
                exit;
            }
        }

        // Agreement type lookup
        $agreementName = trim((string)($_POST['agreement_type'] ?? ''));
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

        $removedPhotos = [];
        try {
            $pdo->beginTransaction();

            /* find or create the countries row (write happens on save only) */
            $cs = $pdo->prepare("SELECT id FROM countries WHERE LOWER(country_name) = LOWER(?) ORDER BY (LOWER(status) = 'active') DESC, id ASC LIMIT 1");
            $cs->execute([$countryName]);
            $countryId = (int)($cs->fetchColumn() ?: 0);
            if ($countryId <= 0) {
                $pdo->prepare("INSERT INTO countries (country_name, status) VALUES (?, 'Active')")->execute([$countryName]);
                $countryId = (int)$pdo->lastInsertId();
            }

            /* photos: keep existing, drop ticked ones, add new (max 10) */
            $photoPaths = json_decode((string)($existingPartner['profile_photos'] ?? '[]'), true);
            $photoPaths = is_array($photoPaths) ? $photoPaths : [];
            $toRemove = $_POST['remove_photos'] ?? [];
            if (is_array($toRemove) && $toRemove) {
                $removedPhotos = array_values(array_intersect($photoPaths, $toRemove));
                $photoPaths = array_values(array_diff($photoPaths, $removedPhotos));
            }
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
            $photoPaths = array_values(array_unique($photoPaths));
            if (count($photoPaths) > 10) {
                throw new RuntimeException('A partner can have at most 10 photos.');
            }

            $data = [
                'institution_name'     => $institutionName,
                'country_id'           => $countryId,
                'address_record_id'    => $addressRecordId > 0 ? $addressRecordId : null,
                'tagline'              => trim((string)($_POST['tagline'] ?? '')),
                'background'           => trim((string)($_POST['background'] ?? '')),
                'offerings'            => trim((string)($_POST['offerings'] ?? '')),
                'contact_first_name'   => trim((string)($_POST['contact_first_name'] ?? '')),
                'contact_middle_name'  => trim((string)($_POST['contact_mi'] ?? '')),
                'contact_last_name'    => trim((string)($_POST['contact_last_name'] ?? '')),
                'contact_email'        => $contactEmail,
                'agreement_type_id'    => $agreementId,
                'expiry_date'          => $expiry !== '' ? $expiry : null,
                'notes'                => trim((string)($_POST['notes'] ?? '')),
                'collaboration_areas'  => json_encode(array_values(array_unique($collaborationAreas))),
                'profile_photos'       => json_encode($photoPaths),
                'brochure_path'        => savePartnerUpload('brochure', [
                    'application/pdf' => 'pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                ], $existingPartner['brochure_path'] ?? null),
                'moa_path'             => savePartnerUpload('moa', [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                ], $existingPartner['moa_path'] ?? null),
                'status'               => $status,
            ];

            $cols = array_keys($data);
            if ($id > 0) {
                $set  = implode(', ', array_map(static function ($c) { return "$c = ?"; }, $cols));
                $stmt = $pdo->prepare("UPDATE partners SET $set WHERE id = ?");
                $stmt->execute(array_merge(array_values($data), [$id]));
                $savedId = $id;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO partners (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
                );
                $stmt->execute(array_values($data));
                $savedId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();

            foreach ($removedPhotos as $p) { deletePartnerFile((string)$p); }
            echo json_encode(['success' => true, 'id' => $savedId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($GLOBALS['newPartnerUploads'] as $f) { if (is_file($f)) @unlink($f); }
            error_log('partner save: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Database error while saving. Please try again.'
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

/* server-side search (works across all pages) */
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$searchParams = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where = "WHERE (p.institution_name LIKE :q1
              OR c.country_name LIKE :q2
              OR CONCAT_WS(' ', p.contact_first_name, p.contact_middle_name, p.contact_last_name) LIKE :q3)";
    $searchParams = [':q1' => $like, ':q2' => $like, ':q3' => $like];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM partners p LEFT JOIN countries c ON c.id = p.country_id $where");
$countStmt->execute($searchParams);
$totalPartners = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalPartners / $limit));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

/* country choices for the searchable field: world list + anything already in the DB (read-only) */
$countryChoices = [];
foreach (explode('|', WORLD_COUNTRIES) as $n) { $countryChoices[mb_strtolower($n)] = $n; }
foreach ($pdo->query("SELECT country_name FROM countries WHERE LOWER(status) = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
    $n = trim((string)$n);
    if ($n !== '') $countryChoices[mb_strtolower($n)] = $n;
}
foreach ($pdo->query("SELECT DISTINCT country_name FROM countries_addresses WHERE LOWER(status) = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
    $n = trim((string)$n);
    if ($n !== '') $countryChoices[mb_strtolower($n)] = $n;
}
natcasesort($countryChoices);

$addressOptions = $pdo->query("
    SELECT id, country_name, region, province, city, postal_code, street_address
    FROM countries_addresses
    WHERE LOWER(status) = 'active'
    ORDER BY country_name, region, province, city, street_address
")->fetchAll(PDO::FETCH_ASSOC);

$agreementTypes = $pdo->query("SELECT id, agreement_name FROM agreement_types WHERE status = 'Active' ORDER BY agreement_name")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.country_name,
        ag.agreement_name,
        a.region, a.province, a.city, a.street_address, a.postal_code
    FROM partners p
    LEFT JOIN countries c ON c.id = p.country_id
    LEFT JOIN agreement_types ag ON ag.id = p.agreement_type_id
    LEFT JOIN countries_addresses a ON a.id = p.address_record_id
    $where
    ORDER BY p.institution_name ASC
    LIMIT :limit OFFSET :offset
");
foreach ($searchParams as $k => $v) { $stmt->bindValue($k, $v); }
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

/* Shared form body for Add and Edit so the two cannot drift apart. */
$renderFields = function (string $p, bool $isEdit) use ($agreementTypes): void { ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-globe"></i> Country *</label>
                        <input type="text" name="country_name" id="<?= $p ?>Country" class="form-input country-input"
                               list="countryList" autocomplete="off" placeholder="Type to search countries" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-building"></i> Institution Name *</label>
                        <input type="text" name="institution_name" id="<?= $p ?>Institution" class="form-input" maxlength="255" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Region</label>
                        <select class="form-input addr-region" disabled><option value="">Select country first</option></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Province</label>
                        <select class="form-input addr-province" disabled><option value=""></option></select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <select class="form-input addr-city" disabled><option value=""></option></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Address *</label>
                        <select name="address_record_id" id="<?= $p ?>Address" class="form-input addr-street" disabled><option value=""></option></select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-file-contract"></i> Agreement Type</label>
                        <select name="agreement_type" id="<?= $p ?>Agreement" class="form-input">
                            <option value="">Select Type</option>
                            <?php foreach ($agreementTypes as $t): ?>
                                <option value="<?= h($t['agreement_name']) ?>"><?= h($t['agreement_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa fa-circle-dot"></i> Status</label>
                        <select name="status" id="<?= $p ?>Status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-user"></i> Contact Person</label>
                    <div class="form-row" style="grid-template-columns: 1fr 70px 1fr;">
                        <input type="text" name="contact_first_name" id="<?= $p ?>ContactFirst" class="form-input" placeholder="First name" required>
                        <input type="text" name="contact_mi" id="<?= $p ?>ContactMi" class="form-input" placeholder="M.I." maxlength="1" style="text-align:center;">
                        <input type="text" name="contact_last_name" id="<?= $p ?>ContactLast" class="form-input" placeholder="Last name" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-envelope"></i> Contact Email</label>
                    <input type="email" name="contact_email" id="<?= $p ?>Email" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-calendar-xmark"></i> Expiry Date</label>
                    <input type="date" name="expiry_date" id="<?= $p ?>Expiry" class="form-input">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Institution Tagline</label>
                        <input type="text" name="tagline" id="<?= $p ?>Tagline" class="form-input" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label class="form-label">What the institution offers</label>
                        <textarea name="offerings" id="<?= $p ?>Offerings" class="form-input"></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Institution Background</label>
                    <textarea name="background" id="<?= $p ?>Background" class="form-input"></textarea>
                </div>
                <fieldset class="form-group" id="<?= $p ?>CollaborationAreas">
                    <legend>Areas of Collaboration (select all that apply)</legend>
                    <?php foreach (['trainings' => 'Trainings', 'conferences' => 'Conferences', 'research' => 'Research', 'staff exchange' => 'Staff exchange'] as $value => $label): ?>
                        <label><input type="checkbox" name="collaboration_areas[]" value="<?= h($value) ?>"> <?= h($label) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <?php if ($isEdit): ?>
                    <div class="form-group" id="editExistingPhotos"></div>
                <?php endif; ?>
                <div class="form-row">
                    <label class="form-group"><?= $isEdit ? 'Add photos' : 'Photos' ?> (max 10) <input type="file" name="profile_photos[]" accept="image/jpeg,image/png" multiple style="margin-top:5px;"></label>
                    <label class="form-group"><?= $isEdit ? 'Replace brochure' : 'Brochure' ?> <input type="file" name="brochure" accept=".pdf,.docx" style="margin-top:5px;"></label>
                    <label class="form-group"><?= $isEdit ? 'Replace MOA / MOU' : 'MOA / MOU' ?> <input type="file" name="moa" accept=".pdf,.doc,.docx" style="margin-top:5px;"></label>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fa fa-note-sticky"></i> Notes</label>
                    <textarea name="notes" id="<?= $p ?>Notes" class="form-input"></textarea>
                </div>
<?php };
?>

<link rel="stylesheet" href="../asset/css/programs.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<datalist id="countryList">
    <?php foreach ($countryChoices as $name): ?><option value="<?= h($name) ?>"></option><?php endforeach; ?>
</datalist>

<div class="content">
    <div class="page-header">
        <h2>Partner Institution Management</h2>
        <p>Manage international partner database, MOA/MOU agreements, collaboration records, and institutional profiles.</p>
    </div>

    <div class="toolbar">
        <form class="search-wrap" method="get" action="">
            <?php foreach ($_GET as $k => $v): if (in_array($k, ['p', 'q'], true) || !is_scalar($v)) continue; ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
            <?php endforeach; ?>
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" name="q" value="<?= h($q) ?>" placeholder="Search by institution / country / contact person">
        </form>
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
                    $status = strtolower($partner['status'] ?? 'Active');
                    $expClass = expiryClass($partner['expiry_date'] ?? null);
                    $jsonData = htmlspecialchars(json_encode($partner), ENT_QUOTES, 'UTF-8');
                ?>
                <tr data-id="<?= (int)$partner['id'] ?>">
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
                    <td><span class="status-badge status-<?= h($status) ?>"><?= h(ucfirst($status)) ?></span></td>
                    <td>
                        <div class="actions">
                            <button type="button" class="action-btn view-btn" title="View" data-partner='<?= $jsonData ?>'><i class="fa fa-eye"></i></button>
                            <button type="button" class="action-btn edit-btn" title="Edit" data-partner='<?= $jsonData ?>'><i class="fa fa-pen"></i></button>
                            <?php if ($status !== 'inactive'): ?>
                            <button type="button" class="action-btn deactivate-btn" title="Deactivate" data-id="<?= (int)$partner['id'] ?>"><i class="fa fa-ban"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center; padding:40px;">
                    <?= $q !== '' ? 'No partner institutions match your search.' : 'No partner institutions found. Click "Add Partner" to register one.' ?>
                </td></tr>
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
                <div><h3>Add Partner Institution</h3><p class="modal-subtitle">Register a new partner, then add its program.</p></div>
            </div>
            <button class="modal-close" data-close="addModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="addForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <div class="modal-body">
                <?php $renderFields('add', false); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-close="addModal">Cancel</button>
                <button type="submit" class="btn-confirm"><i class="fa fa-floppy-disk"></i> Save Partner</button>
                <button type="submit" class="btn-confirm" data-next="programs" style="background:#0284c7;"><i class="fa fa-arrow-right"></i> Save &amp; Add Program</button>
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
                <?php $renderFields('edit', true); ?>
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
            <div style="margin-top: 15px;"><small style="color:#64748b;">Notes</small><p id="viewNotes" style="color:#334155; line-height:1.6; margin-top:4px; white-space:pre-line;"></p></div>
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
    const CSRF = <?= json_encode($csrfToken) ?>;
    const ADDRESSES = <?= json_encode($addressOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const norm = s => (s || '').trim().toLowerCase();
    const uniq = a => [...new Set(a.filter(Boolean))].sort((x, y) => x.localeCompare(y));
    const SAFE_PATH = /^[A-Za-z0-9_./-]+$/;

    // Modal helpers
    const openModal = (id) => document.getElementById(id).classList.add('open');
    const closeModal = (id) => document.getElementById(id).classList.remove('open');
    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) { if (e.target === this) closeModal(this.id); });
    });

    /* Region -> Province -> City -> Full address cascade, driven by the typed country.
       A level with no values for that country is skipped. */
    function initCascade(form) {
        const country  = form.querySelector('.country-input');
        const region   = form.querySelector('.addr-region');
        const province = form.querySelector('.addr-province');
        const city     = form.querySelector('.addr-city');
        const street   = form.querySelector('.addr-street');

        function setOptions(select, values, placeholder, keep) {
            select.replaceChildren(new Option(placeholder, ''));
            values.forEach(v => select.add(new Option(v, v)));
            select.disabled = values.length === 0;
            select.value = values.includes(keep) ? keep : '';
        }

        function update(preset) {
            const p = preset || {};
            const pick = (key, sel) => (p[key] !== undefined ? p[key] : sel.value);
            const all = ADDRESSES.filter(a => norm(a.country_name) === norm(country.value));

            const regions = uniq(all.map(r => r.region));
            setOptions(region, regions, regions.length ? 'Select region' : 'N/A', pick('region', region));
            let rows = regions.length ? all.filter(r => r.region === region.value) : all;

            const provinces = uniq(rows.map(r => r.province));
            setOptions(province, provinces, provinces.length ? 'Select province' : 'N/A', pick('province', province));
            rows = provinces.length ? rows.filter(r => r.province === province.value) : rows;

            const cities = uniq(rows.map(r => r.city));
            setOptions(city, cities, cities.length ? 'Select city' : 'N/A', pick('city', city));
            rows = cities.length ? rows.filter(r => r.city === city.value) : rows;

            const keepId = p.id !== undefined ? String(p.id) : street.value;
            street.replaceChildren(new Option(all.length ? 'Select full address' : 'No saved addresses for this country', ''));
            rows.forEach(r => street.add(new Option(
                [r.street_address, r.postal_code].filter(Boolean).join(', ') || '(no street recorded)', r.id)));
            street.disabled = rows.length === 0;
            street.required = all.length > 0;
            if ([...street.options].some(o => o.value === keepId)) street.value = keepId;
        }

        country.addEventListener('input', () => update({ region: '', province: '', city: '', id: '' }));
        [region, province, city].forEach(s => s.addEventListener('change', () => update()));
        form.updateAddress = update;
        update();
    }
    document.querySelectorAll('#addForm, #editForm').forEach(initCascade);

    document.getElementById('btnAddPartner').addEventListener('click', () => {
        const f = document.getElementById('addForm');
        f.reset();
        f.updateAddress({ region: '', province: '', city: '', id: '' });
        openModal('addModal');
    });

    // AJAX submit (Save / Update / Save & Add Program)
    const handleFormSubmit = (e) => {
        e.preventDefault();
        const form = e.target;
        const nextPage = e.submitter && e.submitter.dataset.next;
        const formData = new FormData(form);
        const buttons = form.querySelectorAll('[type="submit"]');
        const saved = Array.from(buttons, b => b.innerHTML);
        buttons.forEach(b => b.disabled = true);
        if (e.submitter) e.submitter.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
        const restore = () => buttons.forEach((b, i) => { b.disabled = false; b.innerHTML = saved[i]; });

        fetch('', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (nextPage === 'programs') {
                        window.location.href = 'dashboard.php?page=programs&partner_id=' + encodeURIComponent(data.id);
                    } else {
                        location.reload();
                    }
                } else {
                    alert(data.message || 'Error saving partner.');
                    restore();
                }
            })
            .catch(() => { alert('Server error. The response was not valid JSON — check the server log.'); restore(); });
    };
    document.getElementById('addForm').addEventListener('submit', handleFormSubmit);
    document.getElementById('editForm').addEventListener('submit', handleFormSubmit);

    // Table actions
    document.getElementById('tableBody').addEventListener('click', (e) => {
        const viewBtn = e.target.closest('.view-btn');
        const editBtn = e.target.closest('.edit-btn');
        const deactivateBtn = e.target.closest('.deactivate-btn');

        if (viewBtn) {
            const data = JSON.parse(viewBtn.dataset.partner);
            const set = (id, v) => { document.getElementById(id).textContent = v; };
            set('viewInstitution', data.institution_name);
            set('viewCountry', data.country_name || 'N/A');
            set('viewAddress', [data.region, data.province, data.city, data.street_address, data.postal_code].filter(Boolean).join(', ')
                || (/^Address:/.test(data.notes || '') ? 'See Notes' : 'N/A'));
            set('viewTagline', data.tagline || 'N/A');
            set('viewAgreement', data.agreement_name || 'N/A');
            set('viewStatus', data.status || 'N/A');
            set('viewExpiry', data.expiry_date || 'N/A');
            set('viewContactName', [data.contact_first_name,
                data.contact_middle_name ? data.contact_middle_name.toUpperCase() + '.' : '',
                data.contact_last_name].filter(Boolean).join(' ') || 'N/A');
            set('viewContactEmail', data.contact_email || 'N/A');
            set('viewNotes', data.notes || 'No notes provided.');
            set('viewBackground', data.background || 'N/A');
            set('viewOfferings', data.offerings || 'N/A');
            let areas = [], photos = [];
            try { areas = JSON.parse(data.collaboration_areas || '[]'); } catch (error) {}
            try { photos = JSON.parse(data.profile_photos || '[]'); } catch (error) {}
            set('viewCollaboration', areas.length ? areas.join(', ') : 'N/A');
            const fileContainer = document.getElementById('viewPartnerFiles');
            fileContainer.replaceChildren();
            [['Brochure', data.brochure_path], ['MOA / MOU', data.moa_path],
             ...photos.map((path, i) => ['Photo ' + (i + 1), path])].forEach(([label, path]) => {
                if (!path || !SAFE_PATH.test(path)) return;
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
            const form = document.getElementById('editForm');
            const val = (id, v) => { document.getElementById(id).value = v || ''; };
            val('editId', data.id);
            val('editCountry', data.country_name);
            const row = ADDRESSES.find(a => String(a.id) === String(data.address_record_id));
            form.updateAddress(row
                ? { region: row.region || '', province: row.province || '', city: row.city || '', id: row.id }
                : { region: '', province: '', city: '', id: '' });
            val('editInstitution', data.institution_name);
            val('editTagline', data.tagline);
            val('editBackground', data.background);
            val('editOfferings', data.offerings);
            val('editAgreement', data.agreement_name);
            val('editStatus', data.status || 'Active');
            val('editContactFirst', data.contact_first_name);
            val('editContactMi', data.contact_middle_name);
            val('editContactLast', data.contact_last_name);
            val('editEmail', data.contact_email);
            val('editExpiry', data.expiry_date);
            val('editNotes', data.notes);
            let selectedAreas = [], photos = [];
            try { selectedAreas = JSON.parse(data.collaboration_areas || '[]'); } catch (error) {}
            try { photos = JSON.parse(data.profile_photos || '[]'); } catch (error) {}
            document.querySelectorAll('#editCollaborationAreas input[name="collaboration_areas[]"]').forEach(input => {
                input.checked = selectedAreas.includes(input.value);
            });

            // existing photos with a "remove" tick box
            const box = document.getElementById('editExistingPhotos');
            box.replaceChildren();
            const valid = photos.filter(p => SAFE_PATH.test(p));
            if (valid.length) {
                const title = document.createElement('small');
                title.textContent = 'Current photos (tick to remove)';
                box.append(title, document.createElement('br'));
                valid.forEach((path, i) => {
                    const label = document.createElement('label');
                    label.style.marginRight = '12px';
                    const cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.name = 'remove_photos[]'; cb.value = path;
                    const a = document.createElement('a');
                    a.href = '../' + path.replace(/^\/+/, ''); a.target = '_blank'; a.rel = 'noopener';
                    a.textContent = ' Photo ' + (i + 1);
                    label.append(cb, a);
                    box.append(label);
                });
            }
            openModal('editModal');
        }

        if (deactivateBtn) {
            if (!confirm('Deactivate this partner? The record and its history are kept; you can reactivate it by editing it.')) return;
            const formData = new FormData();
            formData.append('action', 'deactivate');
            formData.append('id', deactivateBtn.dataset.id);
            formData.append('csrf_token', CSRF);
            fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => { data.success ? location.reload() : alert(data.message || 'Unable to deactivate partner.'); })
                .catch(() => alert('Server error.'));
        }
    });
});
</script>