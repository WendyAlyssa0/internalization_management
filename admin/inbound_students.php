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

<section class="content">
    <div class="page-header">
        <h2>Inbound Participants</h2>
        <p>Record visiting students, faculty, and non-teaching staff. These records do not create user accounts.</p>
    </div>
    <?php if ($error !== ''): ?>
        <p role="alert" style="padding:12px;background:#fee2e2;color:#991b1b;border-radius:8px;"><?= inboundEscape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="inbound_students.php" enctype="multipart/form-data" id="inboundParticipantForm" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;padding:20px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:20px;">
        <input type="hidden" name="csrf_token" value="<?= inboundEscape($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="">
        <label>First name <input name="first_name" required maxlength="120"></label>
        <label>Last name <input name="last_name" required maxlength="120"></label>
        <label>Participant type
            <select name="participant_type" required>
                <option value="student">Student</option>
                <option value="faculty">Faculty</option>
                <option value="non-teaching">Non-teaching</option>
            </select>
        </label>
        <label>Home institution <input name="home_institution" required maxlength="255"></label>
        <label>Country
            <select name="country_name" required>
                <option value="">Select country</option>
                <?php foreach ($countries as $country): ?><option><?= inboundEscape($country) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Inbound program
            <select name="program_id">
                <option value="">Not assigned</option>
                <?php foreach ($programs as $program): ?><option value="<?= (int)$program['id'] ?>"><?= inboundEscape($program['program_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Arrival date <input type="date" name="arrival_date"></label>
        <label>Departure date <input type="date" name="departure_date"></label>
        <label>Narrative report <input type="file" name="narrative_report" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></label>
        <label>Evaluation <input type="file" name="evaluation" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></label>
        <label style="grid-column:1/-1;">Notes <textarea name="notes" rows="2"></textarea></label>
        <div style="grid-column:1/-1;display:flex;gap:8px;">
            <button type="submit" id="inboundSubmitButton">Save participant</button>
            <button type="button" id="inboundCancelEdit" hidden onclick="resetInboundForm()">Cancel edit</button>
        </div>
    </form>

    <div class="table-card" style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr><th>Name</th><th>Type</th><th>Institution</th><th>Country</th><th>Program</th><th>Dates</th><th>Reports</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($participants as $participant): ?>
                <tr>
                    <td><?= inboundEscape($participant['first_name'] . ' ' . $participant['last_name']) ?></td>
                    <td><?= inboundEscape(ucwords($participant['participant_type'])) ?></td>
                    <td><?= inboundEscape($participant['home_institution']) ?></td>
                    <td><?= inboundEscape($participant['country_name']) ?></td>
                    <td><?= inboundEscape($participant['program_name'] ?? '—') ?></td>
                    <td><?= inboundEscape($participant['arrival_date'] ?? '—') ?> – <?= inboundEscape($participant['departure_date'] ?? '—') ?></td>
                    <td>
                        <?php foreach (['Narrative report' => $participant['narrative_report_path'], 'Evaluation' => $participant['evaluation_path']] as $label => $path): ?>
                            <?php if ($path): ?><a href="../<?= inboundEscape($path) ?>" target="_blank" rel="noopener"><?= inboundEscape($label) ?></a><br><?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!$participant['narrative_report_path'] && !$participant['evaluation_path']): ?>None uploaded<?php endif; ?>
                    </td>
                    <td>
                        <button type="button" onclick='editInboundParticipant(<?= json_encode($participant, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
                        <form method="post" action="inbound_students.php" onsubmit="return confirm('Remove this inbound participant record?')">
                            <input type="hidden" name="csrf_token" value="<?= inboundEscape($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$participant['id'] ?>">
                            <button type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$participants): ?><tr><td colspan="8">No inbound participants recorded yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
const inboundForm = document.getElementById('inboundParticipantForm');
function resetInboundForm() {
    inboundForm.reset();
    inboundForm.elements.id.value = '';
    document.getElementById('inboundSubmitButton').textContent = 'Save participant';
    document.getElementById('inboundCancelEdit').hidden = true;
}
function editInboundParticipant(participant) {
    Object.keys(participant).forEach(key => {
        if (inboundForm.elements[key]) inboundForm.elements[key].value = participant[key] ?? '';
    });
    inboundForm.elements.action.value = 'save';
    document.getElementById('inboundSubmitButton').textContent = 'Update participant';
    document.getElementById('inboundCancelEdit').hidden = false;
    inboundForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
