<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../public/login.php');
    exit;
}
if (!canManageActivities() && !canManageSystem()) {
    http_response_code(403);
    exit('Access denied.');
}

$pdo = db();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$error = (string)($_SESSION['inbound_error'] ?? '');
unset($_SESSION['inbound_error']);

function inboundEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function inboundUpload(string $field, string $kind, ?string $existing = null): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Each report must be no larger than 10 MB.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The uploaded report could not be verified.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Reports must be PDF, DOC, DOCX, JPG, or PNG files.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'inbound';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare inbound report storage.');
    }

    $filename = $kind . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Unable to save the uploaded report.');
    }

    return 'uploads/inbound/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        $_SESSION['inbound_error'] = 'Your session expired. Refresh the page and try again.';
        header('Location: dashboard.php?page=inbound_students');
        exit;
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        try {
            if ($action === 'delete' && $id > 0) {
                $stmt = $pdo->prepare('DELETE FROM inbound_participants WHERE id = ?');
                $stmt->execute([$id]);
                $_SESSION['flash'] = 'Inbound participant removed.';
                header('Location: dashboard.php?page=inbound_students');
                exit;
            }

            if ($action === 'save') {
                $participant = [
                    'first_name' => trim((string)($_POST['first_name'] ?? '')),
                    'last_name' => trim((string)($_POST['last_name'] ?? '')),
                    'participant_type' => (string)($_POST['participant_type'] ?? ''),
                    'home_institution' => trim((string)($_POST['home_institution'] ?? '')),
                    'country_name' => trim((string)($_POST['country_name'] ?? '')),
                    'program_id' => (int)($_POST['program_id'] ?? 0) ?: null,
                    'arrival_date' => trim((string)($_POST['arrival_date'] ?? '')) ?: null,
                    'departure_date' => trim((string)($_POST['departure_date'] ?? '')) ?: null,
                    'notes' => trim((string)($_POST['notes'] ?? '')),
                ];

                if ($participant['first_name'] === '' || $participant['last_name'] === '' ||
                    $participant['home_institution'] === '' || $participant['country_name'] === '' ||
                    !in_array($participant['participant_type'], ['student', 'faculty', 'non-teaching'], true)) {
                    throw new RuntimeException('Name, participant type, institution, and country are required.');
                }
                if (mb_strlen($participant['first_name']) > 120 || mb_strlen($participant['last_name']) > 120 ||
                    mb_strlen($participant['home_institution']) > 255 || mb_strlen($participant['country_name']) > 120) {
                    throw new RuntimeException('A participant field exceeds its maximum length.');
                }
                if ($participant['program_id'] !== null) {
                    $programCheck = $pdo->prepare("SELECT id FROM programs WHERE id = ? AND mobility_direction = 'inbound' AND status = 'Active'");
                    $programCheck->execute([$participant['program_id']]);
                    if (!$programCheck->fetchColumn()) {
                        throw new RuntimeException('Choose an active inbound program.');
                    }
                }
                if ($participant['arrival_date'] && $participant['departure_date'] &&
                    $participant['departure_date'] < $participant['arrival_date']) {
                    throw new RuntimeException('Departure date cannot be before arrival date.');
                }

                $existing = [];
                if ($id > 0) {
                    $stmt = $pdo->prepare('SELECT narrative_report_path, evaluation_path FROM inbound_participants WHERE id = ?');
                    $stmt->execute([$id]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!$existing) {
                        throw new RuntimeException('Inbound participant not found.');
                    }
                }
                $participant['narrative_report_path'] = inboundUpload('narrative_report', 'narrative', $existing['narrative_report_path'] ?? null);
                $participant['evaluation_path'] = inboundUpload('evaluation', 'evaluation', $existing['evaluation_path'] ?? null);
                $saveValues = [
                    $participant['first_name'],
                    $participant['last_name'],
                    $participant['participant_type'],
                    $participant['home_institution'],
                    $participant['country_name'],
                    $participant['program_id'],
                    $participant['arrival_date'],
                    $participant['departure_date'],
                    $participant['narrative_report_path'],
                    $participant['evaluation_path'],
                    $participant['notes'],
                ];

                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE inbound_participants
                        SET first_name=?, last_name=?, participant_type=?, home_institution=?, country_name=?,
                            program_id=?, arrival_date=?, departure_date=?, narrative_report_path=?, evaluation_path=?, notes=?
                        WHERE id=?
                    ");
                    $stmt->execute([...$saveValues, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO inbound_participants
                            (first_name, last_name, participant_type, home_institution, country_name, program_id,
                             arrival_date, departure_date, narrative_report_path, evaluation_path, notes, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([...$saveValues, (int)$_SESSION['user_id']]);
                }

                $_SESSION['flash'] = $id > 0 ? 'Inbound participant updated.' : 'Inbound participant recorded.';
                header('Location: dashboard.php?page=inbound_students');
                exit;
            }
        } catch (Throwable $exception) {
            error_log('inbound participant save failed: ' . $exception->getMessage());
            $_SESSION['inbound_error'] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Unable to save the inbound participant. Check the database and try again.';
            header('Location: dashboard.php?page=inbound_students');
            exit;
        }
    }
}

$participants = $pdo->query("
    SELECT i.*, p.program_name
    FROM inbound_participants i
    LEFT JOIN programs p ON p.id = i.program_id
    ORDER BY i.created_at DESC, i.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
$programs = $pdo->query("
    SELECT id, program_name
    FROM programs
    WHERE mobility_direction = 'inbound' AND status = 'Active'
    ORDER BY program_name
")->fetchAll(PDO::FETCH_ASSOC);
$countries = $pdo->query("
    SELECT country_name FROM countries
    UNION
    SELECT DISTINCT country_name FROM countries_addresses WHERE country_name <> ''
    ORDER BY country_name
")->fetchAll(PDO::FETCH_COLUMN);
?>

<link rel="stylesheet" href="../asset/css/users.css">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<div class="content">

    <?php if ($error !== ''): ?>
        <div class="error-box" style="margin-bottom:20px;">
            <i class="fa-solid fa-circle-exclamation"></i> <?= inboundEscape($error) ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h2>Inbound Participants</h2>
        <p>Record visiting students, faculty, and non-teaching staff. These records do not create user accounts.</p>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search participants...">
        </div>
        <select class="filter-select" id="typeFilter">
            <option value="">All Types</option>
            <option value="student">Student</option>
            <option value="faculty">Faculty</option>
            <option value="non-teaching">Non-teaching</option>
        </select>
        <select class="filter-select" id="programFilter">
            <option value="">All Programs</option>
            <?php foreach ($programs as $program): ?>
                <option value="<?= inboundEscape(strtolower($program['program_name'])) ?>"><?= inboundEscape($program['program_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="create-btn" id="openModalBtn" type="button">
            <i class="fa fa-plus"></i> Add Participant
        </button>
    </div>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Institution</th>
                    <th>Country</th>
                    <th>Program</th>
                    <th>Dates</th>
                    <th>Reports</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if (!empty($participants)): ?>
                <?php foreach ($participants as $participant): ?>
                    <?php
                        $fullname = trim($participant['first_name'] . ' ' . $participant['last_name']);
                        $ptype    = strtolower($participant['participant_type']);
                        $progName = $participant['program_name'] ?? '';
                    ?>
                    <tr data-name="<?= inboundEscape(strtolower($fullname)) ?>"
                        data-type="<?= inboundEscape($ptype) ?>"
                        data-program="<?= inboundEscape(strtolower($progName)) ?>">
                        <td>
                            <div class="user-name-cell">
                                <div class="user-avatar">
                                    <?= inboundEscape(strtoupper(substr($participant['first_name'], 0, 1) . substr($participant['last_name'], 0, 1))) ?>
                                </div>
                                <span style="font-weight:600; color:#0f172a;"><?= inboundEscape($fullname) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="badge"><?= inboundEscape(ucwords($participant['participant_type'])) ?></span>
                        </td>
                        <td><?= inboundEscape($participant['home_institution']) ?></td>
                        <td><?= inboundEscape($participant['country_name']) ?></td>
                        <td><?= inboundEscape($progName ?: '—') ?></td>
                        <td>
                            <?= inboundEscape($participant['arrival_date'] ?? '—') ?>
                            &nbsp;–&nbsp;
                            <?= inboundEscape($participant['departure_date'] ?? '—') ?>
                        </td>
                        <td>
                            <?php if ($participant['narrative_report_path'] || $participant['evaluation_path']): ?>
                                <?php if ($participant['narrative_report_path']): ?>
                                    <a href="../<?= inboundEscape($participant['narrative_report_path']) ?>" target="_blank" rel="noopener" class="badge" style="text-decoration:none;">
                                        <i class="fa-regular fa-file-lines"></i> Narrative
                                    </a>
                                <?php endif; ?>
                                <?php if ($participant['evaluation_path']): ?>
                                    <a href="../<?= inboundEscape($participant['evaluation_path']) ?>" target="_blank" rel="noopener" class="badge" style="text-decoration:none;">
                                        <i class="fa-regular fa-clipboard"></i> Evaluation
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#94a3b8;">None uploaded</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <button type="button"
                                        class="action-btn edit-btn"
                                        title="Edit participant"
                                        onclick='editInboundParticipant(<?= json_encode($participant, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                    <i class="fa fa-pen"></i>
                                </button>
                                <form method="post" action="inbound_students.php" style="display:inline;"
                                      onsubmit="return confirm('Remove this inbound participant record?')">
                                    <input type="hidden" name="csrf_token" value="<?= inboundEscape($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$participant['id'] ?>">
                                    <button type="submit" class="action-btn delete-btn" title="Remove participant">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            </div>
                            <h3>No inbound participants yet</h3>
                            <p>Click "Add Participant" to record the first visiting participant.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD / EDIT PARTICIPANT MODAL -->
<div id="participantModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="participantModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3 id="participantModalTitle">Add Inbound Participant</h3>
                <p>Inbound is a standalone record — no user account is created for the participant.</p>
            </div>
            <button class="modal-close" id="closeModalBtn" type="button" aria-label="Close modal">
                <i class="fa fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body">
            <form method="post" action="inbound_students.php" enctype="multipart/form-data" id="inboundParticipantForm">
                <input type="hidden" name="csrf_token" value="<?= inboundEscape($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="">

                <div class="form-section-title">Participant Details</div>
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label>First Name <span class="required">*</span></label>
                        <input name="first_name" required maxlength="120">
                    </div>
                    <div class="form-group col-6">
                        <label>Last Name <span class="required">*</span></label>
                        <input name="last_name" required maxlength="120">
                    </div>
                    <div class="form-group col-4">
                        <label>Participant Type <span class="required">*</span></label>
                        <select name="participant_type" required>
                            <option value="student">Student</option>
                            <option value="faculty">Faculty</option>
                            <option value="non-teaching">Non-teaching</option>
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label>Home Institution <span class="required">*</span></label>
                        <input name="home_institution" required maxlength="255">
                    </div>
                    <div class="form-group col-4">
                        <label>Country <span class="required">*</span></label>
                        <select name="country_name" required>
                            <option value="">Select country</option>
                            <?php foreach ($countries as $country): ?>
                                <option><?= inboundEscape($country) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-section-title">Program &amp; Schedule</div>
                <div class="form-grid">
                    <div class="form-group col-4">
                        <label>Inbound Program</label>
                        <select name="program_id">
                            <option value="">Not assigned</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>"><?= inboundEscape($program['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-instructions">Only active inbound programs are listed.</p>
                    </div>
                    <div class="form-group col-4">
                        <label>Arrival Date</label>
                        <input type="date" name="arrival_date">
                    </div>
                    <div class="form-group col-4">
                        <label>Departure Date</label>
                        <input type="date" name="departure_date">
                    </div>
                </div>

                <div class="form-section-title">Reports</div>
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label>Narrative Report</label>
                        <input type="file" name="narrative_report" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <p class="form-instructions">PDF, DOC, DOCX, JPG, or PNG. Max 10 MB. Leave blank to keep existing.</p>
                    </div>
                    <div class="form-group col-6">
                        <label>Evaluation</label>
                        <input type="file" name="evaluation" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <p class="form-instructions">PDF, DOC, DOCX, JPG, or PNG. Max 10 MB. Leave blank to keep existing.</p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group col-12">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Optional notes about this participant"></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" id="cancelModalBtn">Cancel</button>
                    <button type="submit" class="btn-submit" id="inboundSubmitButton">
                        <i class="fa-solid fa-user-plus"></i> Save Participant
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var searchInput   = document.getElementById('searchInput');
    var typeFilter    = document.getElementById('typeFilter');
    var programFilter = document.getElementById('programFilter');

    function filterTable() {
        var search  = searchInput.value.toLowerCase();
        var type    = typeFilter.value.toLowerCase();
        var program = programFilter.value.toLowerCase();
        document.querySelectorAll('#tableBody tr[data-name]').forEach(function (row) {
            var name    = row.dataset.name    || '';
            var rowType = row.dataset.type    || '';
            var rowProg = row.dataset.program || '';
            row.style.display =
                (!search  || name.includes(search)) &&
                (!type    || rowType === type) &&
                (!program || rowProg === program)
                ? '' : 'none';
        });
    }

    if (searchInput)   searchInput.addEventListener('input',  filterTable);
    if (typeFilter)    typeFilter.addEventListener('change',  filterTable);
    if (programFilter) programFilter.addEventListener('change', filterTable);

    var modal         = document.getElementById('participantModal');
    var openBtn       = document.getElementById('openModalBtn');
    var closeBtn      = document.getElementById('closeModalBtn');
    var cancelBtn     = document.getElementById('cancelModalBtn');
    var form          = document.getElementById('inboundParticipantForm');
    var submitBtn     = document.getElementById('inboundSubmitButton');
    var modalTitle    = document.getElementById('participantModalTitle');

    function openModal()  { modal.classList.add('open');    document.body.style.overflow = 'hidden'; }
    function closeModal() { modal.classList.remove('open'); document.body.style.overflow = ''; }

    function resetForm() {
        form.reset();
        form.elements.id.value = '';
        submitBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Save Participant';
        modalTitle.textContent = 'Add Inbound Participant';
    }

    if (openBtn)   openBtn.addEventListener('click', function () { resetForm(); openModal(); });
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });

    window.editInboundParticipant = function (participant) {
        resetForm();
        Object.keys(participant).forEach(function (key) {
            if (form.elements[key]) form.elements[key].value = participant[key] ?? '';
        });
        form.elements.id.value = participant.id;
        submitBtn.innerHTML = '<i class="fa-solid fa-user-check"></i> Update Participant';
        modalTitle.textContent = 'Edit Inbound Participant';
        openModal();
    };
})();
</script>