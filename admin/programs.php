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
if (!canManagePrograms()) {
    http_response_code(403);
    exit('Access denied.');
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('programJsonList')) {
    function programJsonList(?string $value): array {
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
if (!function_exists('programDate')) {
    function programDate(?string $value): string {
        $ts = $value ? strtotime($value) : false;
        return $ts ? date('M d, Y', $ts) : 'TBD';
    }
}
if (!function_exists('placeholders')) {
    function placeholders(array $items): string {
        return implode(',', array_fill(0, count($items), '?'));
    }
}
if (!function_exists('cleanIds')) {
    function cleanIds($input): array {
        if (!is_array($input)) return [];
        $ids = array_filter(array_map('intval', $input), static function ($v) { return $v > 0; });
        return array_values(array_unique($ids));
    }
}
if (!function_exists('cleanStrings')) {
    function cleanStrings($input): array {
        if (!is_array($input)) return [];
        $vals = array_filter(array_map('trim', array_map('strval', $input)), 'strlen');
        return array_values(array_unique($vals));
    }
}

$pdo = db();

/* ------------------------------------------------------------------
   S7: one-time seed for OUS and SAS.
------------------------------------------------------------------ */
try {
    $ousCampus = $pdo->prepare("SELECT id FROM campuses WHERE LOWER(campus_name) IN ('ous','open university system') LIMIT 1");
    $ousCampus->execute();
    if ($ousId = (int)$ousCampus->fetchColumn()) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM degree_programs WHERE campus_id = ? AND LOWER(program_name) LIKE '%open university%'");
        $chk->execute([$ousId]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO degree_programs (program_name, campus_id, status) VALUES ('Open University System', ?, 'Active')")->execute([$ousId]);
        }
    }

    $sasCampus = $pdo->prepare("SELECT id FROM campuses WHERE LOWER(campus_name) = 'sas' LIMIT 1");
    $sasCampus->execute();
    if ($sasId = (int)$sasCampus->fetchColumn()) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM degree_programs WHERE campus_id = ? AND LOWER(program_name) = 'sas'");
        $chk->execute([$sasId]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO degree_programs (program_name, campus_id, status) VALUES ('SAS', ?, 'Active')")->execute([$sasId]);
        }
    }
} catch (Throwable $e) {
    error_log('seed OUS/SAS: ' . $e->getMessage());
}

/* Dropdown data */
$programTypes = $pdo->query("SELECT id, category_name FROM program_category WHERE LOWER(status) = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$campusOptions = $pdo->query("SELECT id, campus_name FROM campuses WHERE LOWER(status) = 'active' ORDER BY campus_name")->fetchAll(PDO::FETCH_ASSOC);
$degreeProgramOptions = $pdo->query("
    SELECT dp.id, dp.program_name, dp.campus_id, c.campus_name
    FROM degree_programs dp
    INNER JOIN campuses c ON c.id = dp.campus_id
    WHERE LOWER(dp.status) = 'active' AND LOWER(c.status) = 'active'
    ORDER BY c.campus_name, dp.program_name
")->fetchAll(PDO::FETCH_ASSOC);

$campusNamesById = [];
foreach ($campusOptions as $campusOption) {
    $campusNamesById[(int)$campusOption['id']] = $campusOption['campus_name'];
}
$degreeNamesById = [];
foreach ($degreeProgramOptions as $degreeOption) {
    $degreeNamesById[(int)$degreeOption['id']] = $degreeOption['program_name'];
}

$limit  = 10;
$page   = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $limit;
$search = trim((string)($_GET['q'] ?? ''));

/* =========================================================
   AJAX HANDLERS (SAVE / DELETE / DUPLICATE)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    $fail = static function (string $message) {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    };

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $fail('Invalid CSRF token. Please refresh the page and try again.');
    }

    $action = $_POST['action'];

    /* ---------- DELETE ---------- */
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

    /* ---------- S10: DUPLICATE AS NEW BATCH ---------- */
    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $fail('Invalid program.');
        }

        $src = $pdo->prepare("SELECT * FROM programs WHERE id = ?");
        $src->execute([$id]);
        $row = $src->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $fail('Program not found.');
        }

        /* 1. Verify the parent records still exist */
        $chkPartner = $pdo->prepare("SELECT COUNT(*) FROM partners WHERE id = ?");
        $chkPartner->execute([(int)$row['partner_id']]);
        if ((int)$chkPartner->fetchColumn() === 0) {
            $fail('Cannot duplicate: the original program’s partner institution no longer exists.');
        }

        $chkCountry = $pdo->prepare("SELECT COUNT(*) FROM countries WHERE id = ?");
        $chkCountry->execute([(int)$row['country_id']]);
        if ((int)$chkCountry->fetchColumn() === 0) {
            $fail('Cannot duplicate: the original program’s country no longer exists.');
        }

        /* 2. Derive "Base Title Batch N" */
        $baseTitle = preg_replace('/\s*Batch\s+\d+\s*$/i', '', $row['program_name']);
        $batchStmt = $pdo->prepare("SELECT program_name FROM programs WHERE program_name LIKE ?");
        $batchStmt->execute([addcslashes($baseTitle, '%_\\') . ' Batch %']);
        $maxBatch = 0;
        foreach ($batchStmt->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
            if (preg_match('/Batch\s+(\d+)\s*$/i', $existingName, $m)) {
                $maxBatch = max($maxBatch, (int)$m[1]);
            }
        }
        $newTitle = $baseTitle . ' Batch ' . ($maxBatch + 1);

        /* Fallback in case the title is UNIQUE and still collides */
        $check = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_name = ?");
        $check->execute([$newTitle]);
        if ((int)$check->fetchColumn() > 0) {
            $newTitle .= ' (' . date('YmdHis') . ')';
        }

        /* 3. Reset per-batch values (Uses placeholder dates to prevent NOT NULL DB errors) */
        $row['program_name']           = $newTitle;
        $row['status']                 = 'Upcoming';
        $row['start_date']             = date('Y-m-d');
        $row['end_date']               = date('Y-m-d');
        $row['application_start_date'] = date('Y-m-d');
        $row['application_end_date']   = date('Y-m-d');

        /* 4. Strip auto-managed columns */
        unset(
            $row['id'],
            $row['created_at'],
            $row['updated_at']
        );

        try {
            $cols = array_keys($row);
            $stmt = $pdo->prepare(
                'INSERT INTO programs (' . implode(', ', $cols) . ') VALUES (' . placeholders($cols) . ')'
            );
            $stmt->execute(array_values($row));

            echo json_encode([
                'success' => true,
                'message' => 'Created "' . $newTitle . '". All its dates are set to today as placeholders. Edit it to set the real program and application dates.',
                'new_id'  => (int)$pdo->lastInsertId(),
            ]);
        } catch (PDOException $e) {
            error_log('program duplicate: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error while duplicating. Check error logs.']);
        }
        exit;
    }

    /* ---------- SAVE (add / edit) ---------- */
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);

        $programTypeNames  = cleanStrings($_POST['program_types'] ?? []);
        $participantTypes  = cleanStrings($_POST['eligible_participant_types'] ?? []);
        $eligibleCampusIds = cleanIds($_POST['eligible_campuses'] ?? []);
        $eligibleDegreeIds = cleanIds($_POST['eligible_degree_programs'] ?? []);
        $otherType         = trim((string)($_POST['other_program_type'] ?? ''));

        /* S5: "Others" replaced by the custom type typed by the admin */
        $usesOthers = in_array('Others', $programTypeNames, true);
        if ($usesOthers) {
            if ($otherType === '') {
                $fail('Enter the custom program type for Others.');
            }
            $programTypeNames = array_values(array_diff($programTypeNames, ['Others']));
            $programTypeNames[] = $otherType;
            $programTypeNames = array_values(array_unique($programTypeNames));
        }

        /* Validate program types */
        $validTypeNames = array_column($programTypes, 'category_name');
        if ($usesOthers) {
            $validTypeNames[] = $otherType;
        }
        $invalidTypes = array_diff($programTypeNames, $validTypeNames);

        /* Validate participant types (S4) */
        $validParticipantTypes = ['student', 'faculty', 'non-teaching'];
        $invalidParticipants   = array_diff($participantTypes, $validParticipantTypes);

        if (!$programTypeNames || $invalidTypes) {
            $fail('Select at least one valid program type.');
        }
        if (!$participantTypes || $invalidParticipants) {
            $fail('Select at least one eligible participant type.');
        }
        if (!$eligibleCampusIds) {
            $fail('Select at least one eligible campus.');
        }
        if (!$eligibleDegreeIds) {
            $fail('Select at least one eligible degree program.');
        }

        /* Validate campuses exist and are active */
        $st = $pdo->prepare("SELECT COUNT(*) FROM campuses WHERE LOWER(status) = 'active' AND id IN (" . placeholders($eligibleCampusIds) . ")");
        $st->execute($eligibleCampusIds);
        if ((int)$st->fetchColumn() !== count($eligibleCampusIds)) {
            $fail('One or more selected campuses are invalid.');
        }

        /* Validate degree programs (S7: per campus) */
        $st = $pdo->prepare(
            "SELECT program_name FROM degree_programs
             WHERE LOWER(status) = 'active'
               AND id IN (" . placeholders($eligibleDegreeIds) . ")
               AND campus_id IN (" . placeholders($eligibleCampusIds) . ")
             ORDER BY program_name"
        );
        $st->execute(array_merge($eligibleDegreeIds, $eligibleCampusIds));
        $degreeNames = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($degreeNames) !== count($eligibleDegreeIds)) {
            $fail('One or more degree programs do not belong to the selected campuses.');
        }

        /* Legacy columns kept for older pages */
        $legacyType         = implode(', ', $programTypeNames);
        $legacyParticipants = count($participantTypes) === 1 ? ucfirst($participantTypes[0]) : 'Both';
        $legacyDegrees      = $degreeNames ? implode(', ', $degreeNames) : 'All Departments';

        /* Dates */
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate   = trim((string)($_POST['end_date'] ?? ''));
        $dateRe    = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($dateRe, $startDate) || !preg_match($dateRe, $endDate)) {
            $fail('Enter a valid program start and end date.');
        }
        if ($endDate < $startDate) {
            $fail('Program end date cannot be before its start date.');
        }

        /* S9: the application window is locked once the first application is received.
           Until then it stays editable, so a duplicated batch (placeholder dates) can be fixed. */
        $applicationStart = trim((string)($_POST['application_start_date'] ?? '')) ?: null;
        $applicationEnd   = trim((string)($_POST['application_end_date'] ?? '')) ?: null;
        if ($id > 0) {
            $windowStmt = $pdo->prepare('SELECT application_start_date, application_end_date FROM programs WHERE id = ?');
            $windowStmt->execute([$id]);
            $applicationWindow = $windowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$applicationWindow) {
                $fail('Program not found.');
            }
            $appCountStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE program_id = ?');
            $appCountStmt->execute([$id]);
            if ((int)$appCountStmt->fetchColumn() > 0 || $applicationStart === null || $applicationEnd === null) {
                $applicationStart = $applicationWindow['application_start_date'];
                $applicationEnd   = $applicationWindow['application_end_date'];
            }
        }
        if ($applicationStart === null || $applicationEnd === null ||
            !preg_match($dateRe, $applicationStart) || !preg_match($dateRe, $applicationEnd) ||
            $applicationEnd < $applicationStart) {
            $fail('Enter a valid application start and end date.');
        }

        /* Other fields */
        $programName = trim((string)($_POST['program_name'] ?? ''));
        if ($programName === '') {
            $fail('Program name is required.');
        }
        $status = trim((string)($_POST['status'] ?? 'Active'));
        if (!in_array($status, ['Active', 'Upcoming', 'Completed', 'Suspended'], true)) {
            $fail('Invalid status.');
        }
        $direction = trim((string)($_POST['mobility_direction'] ?? 'outbound'));
        if (!in_array($direction, ['inbound', 'outbound'], true)) {
            $fail('Choose whether this program is inbound or outbound.');
        }
        $countryId = (int)($_POST['country_id'] ?? 0);
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        if ($countryId <= 0 || $partnerId <= 0) {
            $fail('Select a country and a partner institution.');
        }
        $pc = $pdo->prepare('SELECT country_id FROM partners WHERE id = ?');
        $pc->execute([$partnerId]);
        $partnerCountry = $pc->fetchColumn();
        if ($partnerCountry === false || (int)$partnerCountry !== $countryId) {
            $fail('The selected partner institution is not in the selected country.');
        }

        /* S8: ceiling is optional; must be confirmed by the partner */
        $slots            = max(0, (int)($_POST['slots'] ?? 0));
        $ceilingConfirmed = !empty($_POST['slots_confirmed']);
        if ($slots > 0 && !$ceilingConfirmed) {
            $fail('Only enter a capacity ceiling when the partner has confirmed it.');
        }

        $data = [
            'program_name'               => $programName,
            'program_type'               => $legacyType,
            'program_types'              => json_encode($programTypeNames, JSON_UNESCAPED_UNICODE),
            'nature_of_partnership'      => trim((string)($_POST['nature_of_partnership'] ?? '')),
            'eligible_department'        => $legacyDegrees,
            'eligible_degree_programs'   => json_encode($eligibleDegreeIds),
            'eligible_campuses'          => json_encode($eligibleCampusIds),
            'eligible_account_type'      => $legacyParticipants,
            'eligible_participant_types' => json_encode($participantTypes),
            'status'                     => $status,
            'mobility_direction'         => $direction,
            'country_id'                 => $countryId,
            'partner_id'                 => $partnerId,
            'slots'                      => $slots,
            'start_date'                 => $startDate,
            'end_date'                   => $endDate,
            'application_start_date'     => $applicationStart,
            'application_end_date'       => $applicationEnd,
            'description'                => trim((string)($_POST['description'] ?? '')),
        ];

        try {
            $warning = null;

            /* S5: persist the custom type only once everything else has validated */
            if ($usesOthers && $otherType !== '') {
                $chk = $pdo->prepare("SELECT id FROM program_category WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
                $chk->execute([$otherType]);
                if (!$chk->fetchColumn()) {
                    $pdo->prepare("INSERT INTO program_category (category_name, status) VALUES (?, 'Active')")
                        ->execute([$otherType]);
                }
            }

            if ($id > 0) {
                /* S8: don't block when the ceiling drops below approved count — just warn */
                if ($slots > 0) {
                    $filledStmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE program_id = ? AND LOWER(TRIM(status)) = 'approved'");
                    $filledStmt->execute([$id]);
                    $filled = (int)$filledStmt->fetchColumn();
                    if ($slots < $filled) {
                        $warning = "Note: $filled applicant(s) are already approved, above the new ceiling of $slots.";
                    }
                }

                $cols = array_keys($data);
                $set  = implode(', ', array_map(static function ($c) { return "$c = ?"; }, $cols));
                $stmt = $pdo->prepare("UPDATE programs SET $set WHERE id = ?");
                $stmt->execute(array_merge(array_values($data), [$id]));

                echo json_encode([
                    'success' => true,
                    'message' => 'Program updated successfully.',
                    'warning' => $warning,
                ]);
            } else {
                $cols = array_keys($data);
                $stmt = $pdo->prepare(
                    'INSERT INTO programs (' . implode(', ', $cols) . ') VALUES (' . placeholders($cols) . ')'
                );
                $stmt->execute(array_values($data));
                echo json_encode(['success' => true, 'message' => 'Program created successfully.']);
            }
        } catch (PDOException $e) {
            error_log('program save: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error while saving. Check the PHP error log.']);
        }
        exit;
    }

    $fail('Unknown action.');
}

/* =========================================================
   FETCH DATA
========================================================= */
$partners = $pdo->query("SELECT id, country_id, institution_name, status FROM partners ORDER BY institution_name")->fetchAll(PDO::FETCH_ASSOC);

/* Countries that have at least one partner. Read-only: nothing is written on page load. */
$countries = $pdo->query("
    SELECT DISTINCT c.id, c.country_name
    FROM countries c
    INNER JOIN partners pr ON pr.country_id = c.id
    ORDER BY c.country_name
")->fetchAll(PDO::FETCH_ASSOC);

/* Names for the table come from every country so older programs never show N/A */
$countryMap = [];
foreach ($pdo->query("SELECT id, country_name FROM countries")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $countryMap[(int)$c['id']] = $c['country_name'];
}

/* "Save & Add Program" on the Partners page links here with ?partner_id= */
$prefill = null;
$prefillPartnerId = (int)($_GET['partner_id'] ?? 0);
foreach ($partners as $pp) {
    if ($prefillPartnerId > 0 && (int)$pp['id'] === $prefillPartnerId && strtolower((string)$pp['status']) === 'active') {
        $prefill = ['partner_id' => (int)$pp['id'], 'country_id' => (int)$pp['country_id']];
    }
}

/* Server-side search across name and partner */
$where  = '';
$params = [];
if ($search !== '') {
    $where = "WHERE (p.program_name LIKE :q1 OR pr.institution_name LIKE :q2)";
    $like  = '%' . addcslashes($search, '%_\\') . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM programs p LEFT JOIN partners pr ON p.partner_id = pr.id $where");
$countStmt->execute($params);
$totalPrograms = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalPrograms / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/* S9: expose applications_closed so the UI can flag it */
$stmt = $pdo->prepare("
    SELECT p.*,
           pr.institution_name,
           (SELECT COUNT(*) FROM applications a
             WHERE a.program_id = p.id AND LOWER(TRIM(a.status)) = 'approved') AS filled_slots,
           (SELECT COUNT(*) FROM applications a2 WHERE a2.program_id = p.id) AS application_count,
           CASE
             WHEN p.application_end_date IS NULL THEN 0
             WHEN p.application_end_date < CURDATE() THEN 1
             ELSE 0
           END AS applications_closed
    FROM programs p
    LEFT JOIN partners pr ON p.partner_id = pr.id
    $where
    ORDER BY p.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
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
        <p>Manage international programs, eligibility, and application windows</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" value="<?= h($search) ?>"
                   placeholder="Search by name / program"
                   aria-label="Search programs by program name or partner institution">
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
                    <th>Program Type</th>
                    <th>Eligible Participants</th>
                    <th>Eligible Campus / Degree</th>
                    <th>Direction</th>
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
                        $slotsTotal  = (int)($p['slots'] ?? 0);
                        $slotsFilled = (int)($p['filled_slots'] ?? 0);
                        $slotsFull   = $slotsTotal > 0 && $slotsFilled >= $slotsTotal;
                        $appClosed   = (int)($p['applications_closed'] ?? 0) === 1;

                        $participantLabels = programJsonList($p['eligible_participant_types'] ?? null);
                        if (!$participantLabels) {
                            $participantLabels = ($p['eligible_account_type'] ?? '') === 'Both'
                                ? ['student', 'faculty']
                                : [strtolower((string)($p['eligible_account_type'] ?? 'student'))];
                        }

                        $campusIds = array_map('intval', programJsonList($p['eligible_campuses'] ?? null));
                        $degreeIds = array_map('intval', programJsonList($p['eligible_degree_programs'] ?? null));
                        $campusLabels = [];
                        foreach ($campusIds as $cid) {
                            if (isset($campusNamesById[$cid])) $campusLabels[] = $campusNamesById[$cid];
                        }
                        $degreeLabels = [];
                        foreach ($degreeIds as $did) {
                            if (isset($degreeNamesById[$did])) $degreeLabels[] = $degreeNamesById[$did];
                        }
                        $eligibilityText = implode(', ', $campusLabels);
                        if ($degreeLabels) {
                            $eligibilityText .= ($eligibilityText !== '' ? ' · ' : '') . implode(', ', $degreeLabels);
                        }
                        if ($eligibilityText === '') $eligibilityText = 'Not set';
                    ?>
                    <tr data-id="<?= (int)$p['id'] ?>">
                        <td>
                            <div class="program-name">
                                <div class="program-icon"><i class="fa fa-graduation-cap"></i></div>
                                <div class="program-info"><strong><?= h($p['program_name']) ?></strong></div>
                            </div>
                        </td>
                        <td><?= h(implode(', ', programJsonList($p['program_types'] ?? null)) ?: ($p['program_type'] ?? '')) ?></td>
                        <td><?= h(implode(', ', array_map('ucwords', $participantLabels))) ?></td>
                        <td><?= h($eligibilityText) ?></td>
                        <td><?= h(ucfirst($p['mobility_direction'] ?? 'outbound')) ?></td>
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
                                <span style="color:#64748b;">Not specified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower(h($p['status'])) ?>">
                                <?= h($p['status']) ?>
                            </span>
                            <?php if ($appClosed): ?>
                                <br><span class="badge--closed">Applications closed</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h(programDate($p['start_date'] ?? null)) ?> - <?= h(programDate($p['end_date'] ?? null)) ?></td>
                        <td>
                            <button type="button" class="action-btn edit-btn" aria-label="Edit program"
                                    data-program='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>'>
                                <i class="fa fa-pen"></i>
                            </button>
                            <button type="button" class="action-btn duplicate-btn" aria-label="Duplicate as new batch"
                                    data-id="<?= (int)$p['id'] ?>">
                                <i class="fa fa-clone"></i>
                            </button>
                            <button type="button" class="action-btn delete-btn" aria-label="Delete program" data-id="<?= (int)$p['id'] ?>">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                                    <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                                </svg>
                            </div>
                            <?php if ($search !== ''): ?>
                                <h3>No matching programs</h3>
                                <p>Try a different program name or partner institution.</p>
                            <?php else: ?>
                                <h3>No programs yet</h3>
                                <p>Click "Add Program" to create your first one.</p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span>Showing <?= count($programs) ?> of <?= $totalPrograms ?> programs</span>
            <div class="pages">
                <?php $baseQ = $_GET; unset($baseQ['p'], $baseQ['delete'], $baseQ['partner_id']); ?>
                <?php if ($page > 1): ?>
                    <a class="page-arrow" aria-label="Previous page" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page - 1]))) ?>"><i class="fa fa-angle-left"></i></a>
                <?php else: ?>
                    <button class="page-arrow" disabled aria-label="Previous page"><i class="fa fa-angle-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $i]))) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" aria-label="Next page" href="?<?= h(http_build_query(array_merge($baseQ, ['p' => $page + 1]))) ?>"><i class="fa fa-angle-right"></i></a>
                <?php else: ?>
                    <button class="page-arrow" disabled aria-label="Next page"><i class="fa fa-angle-right"></i></button>
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
            <button type="button" class="modal-close" aria-label="Close" data-close="editProgramModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="editProgramForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="id" id="edit_id">

            <div class="modal-body">
                <div class="form-group">
                    <label for="edit_program_name">Program Name</label>
                    <input type="text" name="program_name" id="edit_program_name" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_eligible_campuses">Eligible Campuses</label>
                        <select name="eligible_campuses[]" id="edit_eligible_campuses" class="form-input" multiple required>
                            <?php foreach ($campusOptions as $campus): ?>
                                <option value="<?= (int)$campus['id'] ?>"><?= h($campus['campus_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Hold Ctrl (or Cmd) to select more than one.</small>
                    </div>
                    <div class="form-group">
                        <label for="edit_eligible_degree_programs">Eligible Degree Programs</label>
                        <select name="eligible_degree_programs[]" id="edit_eligible_degree_programs" class="form-input" multiple required>
                            <?php foreach ($degreeProgramOptions as $degree): ?>
                                <option value="<?= (int)$degree['id'] ?>" data-campus="<?= (int)$degree['campus_id'] ?>"><?= h($degree['program_name'] . ' — ' . $degree['campus_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Shows degree programs of the selected campuses. <a href="degree_programs.php">Manage degree programs</a></small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_program_types">Program Types</label>
                        <select name="program_types[]" id="edit_program_types" class="form-input" multiple required>
                            <?php foreach ($programTypes as $type): ?>
                                <option value="<?= h($type['category_name']) ?>"><?= h($type['category_name']) ?></option>
                            <?php endforeach; ?>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_other_program_type">Other program type (when Others is selected)</label>
                        <input type="text" name="other_program_type" id="edit_other_program_type" class="form-input" maxlength="100">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Eligible Participants</label>
                        <fieldset id="edit_participant_types">
                            <label><input type="checkbox" name="eligible_participant_types[]" value="student"> Student</label>
                            <label><input type="checkbox" name="eligible_participant_types[]" value="faculty"> Faculty</label>
                            <label><input type="checkbox" name="eligible_participant_types[]" value="non-teaching"> Non-teaching</label>
                        </fieldset>
                    </div>
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select name="status" id="edit_status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Upcoming">Upcoming</option>
                            <option value="Completed">Completed</option>
                            <option value="Suspended">Suspended</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_mobility_direction">Mobility Direction</label>
                    <select name="mobility_direction" id="edit_mobility_direction" class="form-input" required>
                        <option value="outbound">Outbound</option>
                        <option value="inbound">Inbound</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_program_country">Country</label>
                        <select name="country_id" id="edit_program_country" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= (int)$country['id'] ?>"><?= h($country['country_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_program_partner">Partner Institution</label>
                        <select name="partner_id" id="edit_program_partner" class="form-input" required>
                            <option value="">Select Country First</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_slots">Capacity Ceiling (optional)</label>
                    <input type="number" name="slots" id="edit_slots" class="form-input" min="0" step="1" value="0" required>
                    <small class="form-hint">Leave at 0 if the partner has not confirmed a ceiling. Lowering it below approved applicants only shows a warning.</small>
                    <label class="slots-confirm">
                        <input type="checkbox" name="slots_confirmed" id="edit_slots_confirmed" value="1">
                        Partner has confirmed this ceiling
                    </label>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_start">Program Start Date</label>
                        <input type="date" name="start_date" id="edit_start" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_end">Program End Date</label>
                        <input type="date" name="end_date" id="edit_end" class="form-input" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_application_start_date">Application opens</label>
                        <input type="date" name="application_start_date" id="edit_application_start_date" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="edit_application_end_date">Application closes</label>
                        <input type="date" name="application_end_date" id="edit_application_end_date" class="form-input">
                    </div>
                </div>
                <small class="form-hint" id="edit_app_hint" style="margin-bottom:18px;"></small>

                <div class="form-group">
                    <label for="edit_nature_of_partnership">Nature of Partnership</label>
                    <input type="text" name="nature_of_partnership" id="edit_nature_of_partnership" class="form-input" maxlength="255">
                </div>

                <div class="form-group">
                    <label for="edit_description">Description</label>
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
            <button type="button" class="modal-close" aria-label="Close" data-close="addProgramModal"><i class="fa fa-times"></i></button>
        </div>

        <form id="addProgramForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="add_program_name"><i class="fa fa-book"></i> Program Name</label>
                    <input type="text" name="program_name" id="add_program_name" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_program_types"><i class="fa fa-tag"></i> Program Types</label>
                        <select name="program_types[]" id="add_program_types" class="form-input" multiple required>
                            <?php foreach ($programTypes as $type): ?>
                                <option value="<?= h($type['category_name']) ?>"><?= h($type['category_name']) ?></option>
                            <?php endforeach; ?>
                            <option value="Others">Others</option>
                        </select>
                        <small class="form-hint">Hold Ctrl (or Cmd) to select more than one.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_other_program_type">Other program type (when Others is selected)</label>
                        <input type="text" name="other_program_type" id="add_other_program_type" class="form-input" maxlength="100">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_eligible_campuses">Eligible Campuses</label>
                        <select name="eligible_campuses[]" id="add_eligible_campuses" class="form-input" multiple required>
                            <?php foreach ($campusOptions as $campus): ?>
                                <option value="<?= (int)$campus['id'] ?>"><?= h($campus['campus_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_eligible_degree_programs">Eligible Degree Programs</label>
                        <select name="eligible_degree_programs[]" id="add_eligible_degree_programs" class="form-input" multiple required>
                            <?php foreach ($degreeProgramOptions as $degree): ?>
                                <option value="<?= (int)$degree['id'] ?>" data-campus="<?= (int)$degree['campus_id'] ?>"><?= h($degree['program_name'] . ' — ' . $degree['campus_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Shows degree programs of the selected campuses.</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Eligible Participants</label>
                        <fieldset>
                            <label><input type="checkbox" name="eligible_participant_types[]" value="student"> Student</label>
                            <label><input type="checkbox" name="eligible_participant_types[]" value="faculty"> Faculty</label>
                            <label><input type="checkbox" name="eligible_participant_types[]" value="non-teaching"> Non-teaching</label>
                        </fieldset>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_status"><i class="fa fa-circle-dot"></i> Status</label>
                        <select name="status" id="add_status" class="form-input" required>
                            <option value="Active">Active</option>
                            <option value="Upcoming">Upcoming</option>
                            <option value="Completed">Completed</option>
                            <option value="Suspended">Suspended</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="add_mobility_direction">Mobility Direction</label>
                    <select name="mobility_direction" id="add_mobility_direction" class="form-input" required>
                        <option value="outbound">Outbound</option>
                        <option value="inbound">Inbound</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_program_country"><i class="fa fa-globe"></i> Country</label>
                        <select name="country_id" id="add_program_country" class="form-input" required>
                            <option value="">Select Country</option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?= (int)$country['id'] ?>"><?= h($country['country_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_program_partner"><i class="fa fa-building"></i> Partner Institution</label>
                        <select name="partner_id" id="add_program_partner" class="form-input" required>
                            <option value="">Select Country First</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="add_slots"><i class="fa fa-chair"></i> Capacity Ceiling (optional)</label>
                    <input type="number" name="slots" id="add_slots" class="form-input" min="0" step="1" value="0" required>
                    <small class="form-hint">Leave at 0 if the partner has not confirmed a ceiling.</small>
                    <label class="slots-confirm">
                        <input type="checkbox" name="slots_confirmed" id="add_slots_confirmed" value="1">
                        Partner has confirmed this ceiling
                    </label>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_start"><i class="fa fa-calendar"></i> Program Start Date</label>
                        <input type="date" name="start_date" id="add_start" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_end"><i class="fa fa-calendar-check"></i> Program End Date</label>
                        <input type="date" name="end_date" id="add_end" class="form-input" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="add_application_start_date">Application opens</label>
                        <input type="date" name="application_start_date" id="add_application_start_date" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="add_application_end_date">Application closes</label>
                        <input type="date" name="application_end_date" id="add_application_end_date" class="form-input" required>
                    </div>
                </div>
                <small class="form-hint" style="margin-bottom:18px;">The application closes automatically on its end date. The window locks once the first application is received.</small>

                <div class="form-group">
                    <label class="form-label" for="add_nature_of_partnership">Nature of Partnership</label>
                    <input type="text" name="nature_of_partnership" id="add_nature_of_partnership" class="form-input" maxlength="255">
                </div>

                <div class="form-group">
                    <label class="form-label" for="add_description"><i class="fa fa-align-left"></i> Description</label>
                    <textarea name="description" id="add_description" class="form-input" required></textarea>
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
    const allPartners = <?= json_encode($partners, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken   = <?= json_encode($csrfToken) ?>;

    /* 1. SEARCH (server-side, debounced) */
    const searchInput = document.getElementById('searchInput');
    let searchTimer = null;
    function runSearch() {
        const url = new URL(window.location.href);
        const q = searchInput.value.trim();
        if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
        url.searchParams.delete('p');
        window.location.href = url.toString();
    }
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 500);
    });
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); runSearch(); }
    });
    if (searchInput.value) {
        searchInput.focus();
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    }

    /* 2. MODAL HELPERS */
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', function () { closeModal(this.dataset.close); });
    });
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function (e) {
            if (e.target === this) closeModal(this.id);
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => closeModal(m.id));
    });

    /* 3. PARTNER DROPDOWN (filtered by country) */
    function populatePartners(countryId, selectId, selectedPartnerId = null) {
        const select = document.getElementById(selectId);
        if (!countryId) {
            select.innerHTML = '<option value="">Select Country First</option>';
            return;
        }
        const cid = String(countryId).trim();
        const filtered = allPartners.filter(p => String(p.country_id).trim() === cid &&
            (String(p.status || '').toLowerCase() === 'active' ||
             (selectedPartnerId && String(p.id) === String(selectedPartnerId))));
        if (filtered.length === 0) {
            select.innerHTML = '<option value="">No partners in this country</option>';
            return;
        }
        select.innerHTML = '<option value="">Select Partner</option>';
        filtered.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.institution_name;
            if (selectedPartnerId && String(p.id) === String(selectedPartnerId)) opt.selected = true;
            select.appendChild(opt);
        });
    }

    function parseList(value, fallback) {
        try {
            const parsed = JSON.parse(value || '[]');
            if (Array.isArray(parsed)) return parsed;
        } catch (error) { /* legacy comma-separated value */ }
        return fallback ? String(fallback).split(',').map(s => s.trim()).filter(Boolean) : [];
    }

    function syncDegreeOptions(campusSelect, degreeSelect, selectedIds = []) {
        const selectedCampuses = new Set(Array.from(campusSelect.selectedOptions, o => o.value));
        Array.from(degreeSelect.options).forEach(option => {
            const visible = selectedCampuses.has(option.dataset.campus);
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible) option.selected = false;
        });
        selectedIds.forEach(id => {
            const option = Array.from(degreeSelect.options).find(o => o.value === String(id));
            if (option && !option.hidden) option.selected = true;
        });
    }

    function selectValues(select, values) {
        const chosen = new Set(values.map(String));
        Array.from(select.options).forEach(o => { o.selected = chosen.has(o.value); });
    }

    const addCampuses  = document.getElementById('add_eligible_campuses');
    const addDegrees   = document.getElementById('add_eligible_degree_programs');
    const editCampuses = document.getElementById('edit_eligible_campuses');
    const editDegrees  = document.getElementById('edit_eligible_degree_programs');
    addCampuses.addEventListener('change',  () => syncDegreeOptions(addCampuses, addDegrees));
    editCampuses.addEventListener('change', () => syncDegreeOptions(editCampuses, editDegrees));

    /* 4. ADD PROGRAM */
    document.getElementById('btnAddProgram').addEventListener('click', function () {
        document.getElementById('addProgramForm').reset();
        document.getElementById('add_program_partner').innerHTML = '<option value="">Select Country First</option>';
        syncDegreeOptions(addCampuses, addDegrees);
        openModal('addProgramModal');
    });
    document.getElementById('add_program_country').addEventListener('change', function () {
        populatePartners(this.value, 'add_program_partner');
    });
    document.getElementById('addProgramForm').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!validateDates(this)) return;
        submitForm(this, 'addProgramModal');
    });

    /* Arrived from "Save & Add Program" on the Partners page */
    const prefill = <?= json_encode($prefill) ?>;
    if (prefill) {
        document.getElementById('addProgramForm').reset();
        syncDegreeOptions(addCampuses, addDegrees);
        document.getElementById('add_program_country').value = prefill.country_id;
        populatePartners(prefill.country_id, 'add_program_partner', prefill.partner_id);
        openModal('addProgramModal');
        const clean = new URL(window.location.href);
        clean.searchParams.delete('partner_id');
        history.replaceState(null, '', clean.toString());
    }

    /* 5. EDIT / DUPLICATE / DELETE */
    document.getElementById('tableBody').addEventListener('click', function (e) {
        const editBtn      = e.target.closest('.edit-btn');
        const deleteBtn    = e.target.closest('.delete-btn');
        const duplicateBtn = e.target.closest('.duplicate-btn');

        if (editBtn) {
            const data = JSON.parse(editBtn.dataset.program);

            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_program_name').value = data.program_name || '';

            const typeSelect = document.getElementById('edit_program_types');
            const types = parseList(data.program_types, data.program_type || '');
            const knownTypes = new Set(Array.from(typeSelect.options, o => o.value));
            const customType = types.find(t => !knownTypes.has(t)) || '';
            const selectedTypes = types.filter(t => knownTypes.has(t));
            if (customType) selectedTypes.push('Others');
            selectValues(typeSelect, selectedTypes);
            document.getElementById('edit_other_program_type').value = customType;

            const participantValues = new Set(parseList(
                data.eligible_participant_types,
                data.eligible_account_type === 'Both' ? 'student,faculty' : String(data.eligible_account_type || 'student').toLowerCase()
            ));
            document.querySelectorAll('#edit_participant_types input[name="eligible_participant_types[]"]').forEach(input => {
                input.checked = participantValues.has(input.value);
            });

            selectValues(editCampuses, parseList(data.eligible_campuses, ''));
            syncDegreeOptions(editCampuses, editDegrees, parseList(data.eligible_degree_programs, ''));

            document.getElementById('edit_status').value = data.status;
            document.getElementById('edit_mobility_direction').value = data.mobility_direction || 'outbound';
            document.getElementById('edit_slots').value = data.slots ?? 0;
            document.getElementById('edit_slots_confirmed').checked = (parseInt(data.slots ?? 0, 10) > 0);
            document.getElementById('edit_start').value = data.start_date || '';
            document.getElementById('edit_end').value = data.end_date || '';
            const appLocked = parseInt(data.application_count ?? 0, 10) > 0;
            ['start', 'end'].forEach(k => {
                const el = document.getElementById('edit_application_' + k + '_date');
                el.value = data['application_' + k + '_date'] || '';
                el.disabled = appLocked;
                el.required = !appLocked;
            });
            document.getElementById('edit_app_hint').textContent = appLocked
                ? 'Application dates are locked because applications have already been received. Program dates stay editable.'
                : 'No applications yet, so the application window can still be changed. It locks once the first application is received.';
            document.getElementById('edit_nature_of_partnership').value = data.nature_of_partnership || '';
            document.getElementById('edit_description').value = data.description || '';

            document.getElementById('edit_program_country').value = data.country_id;
            populatePartners(data.country_id, 'edit_program_partner', data.partner_id);

            openModal('editProgramModal');
        }

        if (duplicateBtn) {
            if (!confirm('Create a new batch from this program? Its dates will be reset to today; set the real dates afterwards.')) return;

            const formData = new FormData();
            formData.append('action', 'duplicate');
            formData.append('id', duplicateBtn.dataset.id);
            formData.append('csrf_token', csrfToken);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    showToast(data.success ? 'success' : 'error', data.message);
                    if (data.success) setTimeout(() => location.reload(), 900);
                })
                .catch(() => showToast('error', 'Server error.'));
        }

        if (deleteBtn) {
            if (!confirm('Delete this program? This cannot be undone.')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', deleteBtn.dataset.id);
            formData.append('csrf_token', csrfToken);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    showToast(data.success ? 'success' : 'error', data.message);
                    if (data.success) deleteBtn.closest('tr').remove();
                })
                .catch(() => showToast('error', 'Server error.'));
        }
    });

    document.getElementById('edit_program_country').addEventListener('change', function () {
        populatePartners(this.value, 'edit_program_partner');
    });
    document.getElementById('editProgramForm').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!validateDates(this)) return;
        submitForm(this, 'editProgramModal');
    });

    /* 6. VALIDATION + SUBMIT */
    function validateDates(form) {
        const start = form.querySelector('[name="start_date"]').value;
        const end   = form.querySelector('[name="end_date"]').value;
        if (start && end && end < start) {
            showToast('error', 'Program end date cannot be before the start date.');
            return false;
        }
        const aStart = form.querySelector('[name="application_start_date"]');
        const aEnd   = form.querySelector('[name="application_end_date"]');
        if (aStart && aEnd && aStart.value && aEnd.value && aEnd.value < aStart.value) {
            showToast('error', 'Application close date cannot be before the open date.');
            return false;
        }
        return true;
    }

    function submitForm(form, modalId) {
        const btn = form.querySelector('[type="submit"]');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        fetch(window.location.href, { method: 'POST', body: new FormData(form) })
            .then(async res => {
                const text = await res.text();
                try { return JSON.parse(text); }
                catch (err) {
                    console.error('Non-JSON response from server:', text);
                    throw new Error('Invalid JSON response');
                }
            })
            .then(data => {
                showToast(data.success ? 'success' : 'error', data.message);
                if (data.warning) {
                    setTimeout(() => showToast('error', data.warning), 500);
                }
                if (data.success) {
                    closeModal(modalId);
                    setTimeout(() => location.reload(), 900);
                }
            })
            .catch(err => {
                console.error('Error saving:', err);
                showToast('error', 'Server error. Open the browser console to see the response.');
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    }

    /* 7. TOAST */
    function showToast(type, message) {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = 'toast toast--' + type;
        toast.setAttribute('role', 'status');
        toast.innerHTML = '<i class="fa fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i><span></span>';
        toast.querySelector('span').textContent = message;

        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('toast--show'));
        setTimeout(() => {
            toast.classList.remove('toast--show');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
});
</script>