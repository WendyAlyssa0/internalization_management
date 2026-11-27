<?php

/* ---------- Safety fallbacks (only used if your project doesn't define them) ---------- */
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('checkCsrf')) {
    function checkCsrf(): bool {
        $token = $_POST['csrf_token'] ?? '';
        $sess  = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $sess !== '' && hash_equals($sess, $token);
    }
}

$user_id = (int)($userId ?? ($_SESSION['user_id'] ?? 0));
$page    = $page ?? 'documents';
$message = '';
$msgType = '';

$csrfToken = $_SESSION['csrf_token'] ?? '';

$allowedMimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];
const MAX_FILE_SIZE = 5 * 1024 * 1024;

/*
 * Uploads are allowed for ANY application status except these.
 * Add or remove values to match what your applications table stores.
 */
$blockedStatuses = ['rejected', 'declined', 'cancelled', 'canceled', 'withdrawn'];

/* ---------- Load the applicant's application ---------- */
$stmt = $pdo->prepare("
    SELECT program_id, status
    FROM applications
    WHERE applicant_id = ? AND program_id IS NOT NULL
    ORDER BY
        CASE WHEN LOWER(TRIM(status)) = 'approved' THEN 0 ELSE 1 END,
        COALESCE(reviewed_at, updated_at, created_at) DESC
    LIMIT 1
");
$stmt->execute([$user_id]);
$appRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$userProgramId       = (int)($appRow['program_id'] ?? 0);
$applicationStatus   = strtolower(trim((string)($appRow['status'] ?? '')));
$applicationApproved = $appRow && $applicationStatus === 'approved';
$canUpload           = $appRow && !in_array($applicationStatus, $blockedStatuses, true);

/* ---------- Handle Upload ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // File larger than post_max_size: PHP drops $_POST and $_FILES entirely
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $message = 'File exceeds the server upload limit.';
        $msgType = 'error';

    } elseif (isset($_FILES['document'])) {

        if (!checkCsrf()) {
            $message = 'Your session expired. Please refresh and try again.';
            $msgType = 'error';
        } elseif (!$canUpload) {
            $message = 'Your application must be approved before you can upload documents.';
            $msgType = 'error';
        } else {
            $req_id = (int)($_POST['requirement_id'] ?? 0);
            $file   = $_FILES['document'];

            // The requirement must belong to the applicant's own program
            $check = $pdo->prepare("SELECT id FROM program_requirements WHERE id = ? AND program_id = ?");
            $check->execute([$req_id, $userProgramId]);
            $validReq = (bool)$check->fetchColumn();

            if (!$validReq) {
                $message = 'Invalid requirement selected.';
                $msgType = 'error';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $message = match ($file['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the upload limit.',
                    UPLOAD_ERR_NO_FILE => 'Please select a file.',
                    default => 'Upload failed. Please try again.',
                };
                $msgType = 'error';
            } elseif ($file['size'] <= 0 || $file['size'] > MAX_FILE_SIZE) {
                $message = 'File must be between 1 byte and 5 MB.';
                $msgType = 'error';
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $message = 'Invalid upload.';
                $msgType = 'error';
            } else {
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);

                if (!isset($allowedMimes[$mimeType])) {
                    $message = 'Only PDF, JPG, and PNG are allowed.';
                    $msgType = 'error';
                } else {
                    $uploadDir = __DIR__ . '/../uploads/user_docs/';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        error_log('Cannot create upload dir: ' . $uploadDir);
                        $message = 'Upload location unavailable.';
                        $msgType = 'error';
                    } elseif (!is_writable($uploadDir)) {
                        error_log('Upload dir not writable: ' . $uploadDir);
                        $message = 'Upload location is not writable. Please contact the administrator.';
                        $msgType = 'error';
                    } else {
                        $filename     = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mimeType];
                        $destination  = $uploadDir . $filename;
                        $relativePath = 'uploads/user_docs/' . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                            $message = 'Could not save the file. Please try again.';
                            $msgType = 'error';
                        } else {
                            try {
                                $existing = $pdo->prepare("SELECT id, file_path FROM user_documents WHERE user_id = ? AND requirement_id = ? LIMIT 1");
                                $existing->execute([$user_id, $req_id]);
                                $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

                                if ($existingRow) {
                                    $stmt = $pdo->prepare("UPDATE user_documents SET file_path = ?, uploaded_at = NOW() WHERE id = ?");
                                    $stmt->execute([$relativePath, $existingRow['id']]);
                                } else {
                                    $stmt = $pdo->prepare("INSERT INTO user_documents (user_id, requirement_id, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
                                    $stmt->execute([$user_id, $req_id, $relativePath]);
                                }

                                // Remove the old file after the DB change succeeded
                                $oldPath = $existingRow['file_path'] ?? null;
                                if (is_string($oldPath) && str_starts_with($oldPath, 'uploads/user_docs/') && basename($oldPath) !== $filename) {
                                    $oldFile = __DIR__ . '/../' . $oldPath;
                                    if (is_file($oldFile) && !unlink($oldFile)) {
                                        error_log('Could not delete old doc: ' . $oldFile);
                                    }
                                }

                                $requiredCountStmt = $pdo->prepare("
                                    SELECT COUNT(*)
                                    FROM program_requirements
                                    WHERE program_id = ? AND is_required = 1
                                ");
                                $requiredCountStmt->execute([$userProgramId]);
                                $requiredCount = (int)$requiredCountStmt->fetchColumn();

                                $submittedCountStmt = $pdo->prepare("
                                    SELECT COUNT(DISTINCT ud.requirement_id)
                                    FROM user_documents ud
                                    INNER JOIN program_requirements pr ON pr.id = ud.requirement_id
                                    WHERE ud.user_id = ? AND pr.program_id = ? AND pr.is_required = 1
                                ");
                                $submittedCountStmt->execute([$user_id, $userProgramId]);
                                $submittedCount = (int)$submittedCountStmt->fetchColumn();

                                $documentsStatus = $requiredCount > 0 && $submittedCount >= $requiredCount ? 'complete' : 'incomplete';
                                $statusStmt = $pdo->prepare("
                                    UPDATE applications
                                    SET documents_status = ?
                                    WHERE applicant_id = ? AND program_id = ?
                                ");
                                $statusStmt->execute([$documentsStatus, $user_id, $userProgramId]);

                                $message = 'Document uploaded successfully!';
                                $msgType = 'success';
                            } catch (PDOException $e) {
                                @unlink($destination);
                                error_log('user_documents upload error: ' . $e->getMessage());
                                $message = 'Database error. Please try again.';
                                $msgType = 'error';
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ---------- Fetch requirements ---------- */
$requirements           = [];
$programHasRequirements = false;

if ($userProgramId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            pr.id,
            pr.is_required,
            pr.requirement_file       AS template_file,
            r.requirement_name,
            r.description,
            ud.file_path              AS uploaded_file,
            ud.uploaded_at
        FROM program_requirements pr
        INNER JOIN requirements r   ON pr.requirement_id = r.id
        LEFT JOIN user_documents ud ON ud.requirement_id = pr.id AND ud.user_id = ?
        WHERE pr.program_id = ?
        ORDER BY r.requirement_name ASC
    ");
    $stmt->execute([$user_id, $userProgramId]);
    $requirements           = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $programHasRequirements = count($requirements) > 0;
}

/* Managed forms (downloadable and fill-online) */
$formsStmt = $pdo->query("
    SELECT id, form_name, form_type, file_path, form_schema
    FROM forms
    WHERE status = 'active'
      AND ((form_type = 'downloadable' AND file_path LIKE 'uploads/forms/%')
       OR (form_type = 'online' AND form_schema IS NOT NULL))
    ORDER BY form_name
");
$availableForms = $formsStmt->fetchAll(PDO::FETCH_ASSOC);

/* Handle online form submissions */
$onlineFormId = (int)($_GET['fill_form'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int)($_POST['online_form_id'] ?? 0) > 0) {
    if (!checkCsrf()) {
        $message = 'Your session expired. Please refresh and try again.';
        $msgType = 'error';
    } else {
        $onlineFormId = (int)$_POST['online_form_id'];
        $formStmt = $pdo->prepare("SELECT id, form_name, form_schema FROM forms WHERE id = ? AND form_type = 'online' AND status = 'active'");
        $formStmt->execute([$onlineFormId]);
        $onlineForm = $formStmt->fetch(PDO::FETCH_ASSOC);

        if (!$onlineForm) {
            $message = 'The requested online form is unavailable.';
            $msgType = 'error';
        } else {
            $schema = json_decode((string)$onlineForm['form_schema'], true);
            $schema = is_array($schema) ? $schema : [];
            $answers = [];
            $validationError = null;

            foreach ($schema as $field) {
                $name = trim((string)($field['name'] ?? ''));
                if ($name === '') continue;
                $value = trim((string)($_POST['form_' . $name] ?? ''));
                if (!empty($field['required']) && $value === '') {
                    $validationError = 'Please complete: ' . ($field['label'] ?: $name);
                    break;
                }
                $answers[$name] = $value;
            }

            if ($validationError) {
                $message = $validationError;
                $msgType = 'error';
            } else {
                try {
                    $existingSubmission = $pdo->prepare("SELECT id FROM form_submissions WHERE form_id = ? AND user_id = ? LIMIT 1");
                    $existingSubmission->execute([$onlineFormId, $user_id]);
                    $submissionId = $existingSubmission->fetchColumn();

                    if ($submissionId !== false) {
                        $saveSubmission = $pdo->prepare("UPDATE form_submissions SET answers = ?, status = 'submitted', created_at = NOW() WHERE id = ?");
                        $saveSubmission->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), (int)$submissionId]);
                    } else {
                        $saveSubmission = $pdo->prepare("INSERT INTO form_submissions (form_id, user_id, answers, status, created_at) VALUES (?, ?, ?, 'submitted', NOW())");
                        $saveSubmission->execute([$onlineFormId, $user_id, json_encode($answers, JSON_UNESCAPED_UNICODE)]);
                    }
                    $message = 'Online form submitted successfully.';
                    $msgType = 'success';
                } catch (PDOException $e) {
                    error_log('Online form submission error: ' . $e->getMessage());
                    $message = 'The online form could not be submitted.';
                    $msgType = 'error';
                }
            }
        }
    }
}

/* Progress: count only required docs */
$requiredReqs = array_filter($requirements, static fn(array $r): bool => !empty($r['is_required']));
$totalReqs    = count($requiredReqs);
$uploadedReqs = 0;
foreach ($requiredReqs as $r) {
    if (!empty($r['uploaded_file'])) $uploadedReqs++;
}
$progressPercent = $totalReqs > 0 ? round(($uploadedReqs / $totalReqs) * 100) : 0;
?>
<style>
    .requirements-summary { background: var(--bg-main); border: 1px solid var(--border-light); border-radius: var(--radius); padding: 32px; margin-bottom: 40px; box-shadow: var(--shadow-sm); }
    .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .progress-title { font-family: var(--font-heading); font-size: 1.15rem; font-weight: 800; color: var(--psu-navy); }
    .progress-text { font-family: var(--font-heading); font-size: 0.95rem; font-weight: 700; color: var(--psu-navy); background: var(--white); padding: 8px 16px; border-radius: var(--radius-sm); box-shadow: var(--shadow-sm); border: 1px solid var(--border-light); }
    .progress-bar-bg { width: 100%; height: 12px; background: var(--border-light); border-radius: 999px; overflow: hidden; margin-bottom: 28px; }
    .progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--psu-gold) 0%, var(--psu-gold-light) 100%); border-radius: 999px; transition: width 0.8s cubic-bezier(0.4,0,0.2,1); box-shadow: 0 0 10px rgba(228,177,52,0.4); }
    .req-pill-grid { display: flex; flex-wrap: wrap; gap: 12px; }
    .req-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: var(--radius-full); font-size: 0.8rem; font-weight: 600; background: var(--white); border: 1px solid var(--border-light); color: var(--text-muted); transition: var(--transition); }
    .req-pill.completed { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
    .req-pill i { color: var(--text-light); }
    .req-pill.completed i { color: #047857; }
    .managed-forms { margin-bottom: 40px; }
    .managed-forms h3 { font-family: var(--font-heading); font-size: 1.25rem; color: var(--psu-navy); margin-bottom: 16px; }
    .form-download-list { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .form-download-item { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 20px; background: var(--white); border: 1px solid var(--border-light); border-radius: var(--radius-sm); transition: var(--transition); }
    .form-download-item:hover { border-color: var(--psu-gold); box-shadow: var(--shadow-sm); }
    .form-download-item strong { font-size: 0.9rem; color: var(--psu-navy); }
    .form-download-item a { font-size: 0.8rem; font-weight: 700; color: var(--psu-gold); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .form-download-item a:hover { text-decoration: underline; }
    .checklist-grid { display: flex; flex-direction: column; gap: 16px; }
    .checklist-item { display: flex; align-items: flex-start; gap: 20px; padding: 24px; background: var(--white); border: 1px solid var(--border-light); border-radius: var(--radius); transition: var(--transition); position: relative; overflow: hidden; }
    .checklist-item:hover { border-color: var(--psu-gold); box-shadow: var(--shadow-sm); }
    .checklist-item.completed { border-color: #a7f3d0; background: #fcfdfd; }
    .checklist-item::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--border-light); transition: var(--transition); }
    .checklist-item.completed::before { background: #047857; }
    .checklist-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--bg-main); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.25rem; color: var(--text-light); transition: var(--transition); }
    .checklist-item.completed .checklist-icon { background: #ecfdf5; color: #047857; }
    .checklist-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
    .checklist-info strong { font-family: var(--font-heading); font-size: 1.05rem; color: var(--psu-navy); font-weight: 700; }
    .checklist-info small { font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; }
    .checklist-info small.uploaded-date { font-size: 0.75rem; color: #047857; font-weight: 600; margin-top: 4px; }
    .view-file { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; font-weight: 700; color: var(--psu-navy); text-decoration: none; margin-top: 8px; }
    .view-file:hover { text-decoration: underline; }
    .checklist-action { flex-shrink: 0; margin-left: auto; display: flex; align-items: center; }
    .upload-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: var(--psu-navy); color: var(--white); border-radius: var(--radius-sm); font-family: var(--font-heading); font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: var(--transition); border: 1px solid var(--psu-navy); }
    .upload-btn:hover { background: var(--psu-blue); border-color: var(--psu-blue); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(11,26,48,0.15); }
    .checklist-item.completed .upload-btn { background: var(--white); color: var(--psu-navy); border-color: var(--border-light); }
    .checklist-item.completed .upload-btn:hover { background: var(--bg-main); border-color: var(--psu-navy); }
    .upload-btn input[type="file"] { display: none; }
    .empty-state { text-align: center; padding: 60px 24px; background: var(--bg-main); border-radius: var(--radius-lg); border: 2px dashed var(--border-light); }
    .empty-icon { width: 72px; height: 72px; background: var(--white); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: var(--shadow-sm); color: var(--psu-navy); font-size: 1.75rem; }
    @media (max-width: 640px) {
        .checklist-item { flex-direction: column; gap: 16px; }
        .checklist-action { width: 100%; margin-left: 0; }
        .upload-btn { width: 100%; justify-content: center; }
    }
</style>

<div class="content-card">
    <div class="page-header">
        <div>
            <h2><?= $page === 'forms_requirements' ? 'Forms & Requirements' : 'Upload Documents' ?></h2>
            <p>Manage and submit the required documents for your application.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="program-alert <?= h($msgType) ?>" style="margin-bottom:24px;" role="<?= $msgType === 'success' ? 'status' : 'alert' ?>">
            <i class="fa-solid <?= $msgType === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <section class="managed-forms" id="forms">
        <h3>Application Forms</h3>
        <?php if ($availableForms): ?>
            <ul class="form-download-list">
                <?php foreach ($availableForms as $form): ?>
                    <li class="form-download-item">
                        <strong><?= h($form['form_name']) ?></strong>
                        <?php if (($form['form_type'] ?? 'downloadable') === 'online'): ?>
                            <a href="?page=<?= h($page) ?>&fill_form=<?= (int)$form['id'] ?>">
                                <i class="fa-solid fa-pen-to-square"></i> Fill Online
                            </a>
                        <?php else: ?>
                            <a href="dashboard.php?page=<?= h($page) ?>&download_type=managed_form&id=<?= (int)$form['id'] ?>">
                                <i class="fa-solid fa-download"></i> Download
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 0.9rem;">No application forms are available right now.</p>
        <?php endif; ?>
    </section>

    <?php if ($onlineFormId > 0): ?>
        <?php
        $onlineStmt = $pdo->prepare("SELECT id, form_name, form_schema FROM forms WHERE id = ? AND form_type = 'online' AND status = 'active'");
        $onlineStmt->execute([$onlineFormId]);
        $selectedOnlineForm = $onlineStmt->fetch(PDO::FETCH_ASSOC);
        $existingAnswers = [];
        if ($selectedOnlineForm) {
            $submissionStmt = $pdo->prepare("SELECT answers FROM form_submissions WHERE form_id = ? AND user_id = ? LIMIT 1");
            $submissionStmt->execute([$onlineFormId, $user_id]);
            $answerJson = $submissionStmt->fetchColumn();
            $decodedAnswers = is_string($answerJson) ? json_decode($answerJson, true) : null;
            $existingAnswers = is_array($decodedAnswers) ? $decodedAnswers : [];
        }
        ?>
        <?php if ($selectedOnlineForm): ?>
            <section class="managed-forms">
                <h3>Fill Online: <?= h($selectedOnlineForm['form_name']) ?></h3>
                <form method="POST" style="display:grid;gap:16px;max-width:720px;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="online_form_id" value="<?= (int)$selectedOnlineForm['id'] ?>">
                    <?php foreach ((json_decode((string)$selectedOnlineForm['form_schema'], true) ?: []) as $field):
                        $name = trim((string)($field['name'] ?? ''));
                        if ($name === '') continue;
                        $label = h($field['label'] ?? ucwords(str_replace('_', ' ', $name)));
                        $required = !empty($field['required']) ? 'required' : '';
                        $value = h($existingAnswers[$name] ?? '');
                    ?>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-weight:700;color:var(--psu-navy);"><?= $label ?></label>
                            <?php if (($field['type'] ?? 'text') === 'textarea'): ?>
                                <textarea name="form_<?= h($name) ?>" <?= $required ?> style="min-height:100px;padding:10px;border:1px solid var(--border-light);border-radius:8px;"><?= $value ?></textarea>
                            <?php elseif (($field['type'] ?? 'text') === 'select'): ?>
                                <select name="form_<?= h($name) ?>" <?= $required ?> style="padding:10px;border:1px solid var(--border-light);border-radius:8px;">
                                    <option value="">Select...</option>
                                    <?php foreach ((array)($field['options'] ?? []) as $option): ?>
                                        <option value="<?= h($option) ?>" <?= ($existingAnswers[$name] ?? '') === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?= h(in_array($field['type'] ?? 'text', ['text','email','date'], true) ? $field['type'] : 'text') ?>"
                                       name="form_<?= h($name) ?>" value="<?= $value ?>" <?= $required ?>
                                       style="padding:10px;border:1px solid var(--border-light);border-radius:8px;">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="upload-btn" style="width:max-content;">Submit Form</button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($userProgramId <= 0): ?>
        <!-- No application yet -->
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-folder-open"></i></div>
            <h4 style="font-size:1.25rem; font-family:var(--font-heading); color:var(--psu-navy); margin-bottom:8px;">No application on file</h4>
            <p style="color:var(--text-muted); font-size:0.95rem; max-width:420px; margin:0 auto 24px;">
                Submit an application to a program first. Once it is approved, your program-specific requirements will appear here.
            </p>
            <a href="?page=available_programs" style="display:inline-flex; padding:12px 24px; background:var(--psu-gold); color:var(--psu-navy); border-radius:var(--radius-sm); font-family:var(--font-heading); font-weight:700; text-decoration:none;">
                <i class="fa-solid fa-globe" style="margin-right:8px;"></i> Browse Programs
            </a>
        </div>
    <?php elseif (!$programHasRequirements): ?>
        <!-- Application exists, but program has no requirements configured -->
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-list-check"></i></div>
            <h4 style="font-size:1.25rem; font-family:var(--font-heading); color:var(--psu-navy); margin-bottom:8px;">No requirements configured</h4>
            <p style="color:var(--text-muted); font-size:0.95rem; max-width:460px; margin:0 auto 24px;">
                Your program has no requirements attached yet. Please check back later or contact the program coordinator.
            </p>
        </div>
    <?php else: ?>
        <?php if (!$canUpload): ?>
            <div class="program-alert warning" style="margin-bottom:24px;">
                <i class="fa-solid fa-clock"></i>
                Your application status is <strong><?= h($applicationStatus ?: 'unknown') ?></strong>. Uploading is not available for this status yet.
            </div>
        <?php endif; ?>

        <div class="requirements-summary">
            <div class="progress-header">
                <div class="progress-title">Document Checklist Progress</div>
                <div class="progress-text"><?= $uploadedReqs ?> of <?= $totalReqs ?> Completed</div>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width: <?= $progressPercent ?>%;"></div>
            </div>
            <div class="req-pill-grid">
                <?php foreach ($requirements as $req):
                    $isUploaded = !empty($req['uploaded_file']);
                ?>
                    <div class="req-pill <?= $isUploaded ? 'completed' : '' ?>">
                        <i class="fa-solid <?= $isUploaded ? 'fa-check-circle' : 'fa-circle-notch' ?>"></i>
                        <?= h($req['requirement_name']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <h3 id="required-documents" style="font-family:var(--font-heading); font-size:1.25rem; color:var(--psu-navy); margin-bottom:8px;">Required Documents for Your Program</h3>
        <p style="color:var(--text-muted); font-size:0.95rem; margin-bottom:24px;">
            Please upload clear copies of the following documents (PDF, JPG, or PNG, max 5 MB each).
        </p>

        <div class="checklist-grid">
            <?php foreach ($requirements as $req):
                $isUploaded = !empty($req['uploaded_file']);
            ?>
                <div class="checklist-item <?= $isUploaded ? 'completed' : '' ?>">
                    <div class="checklist-icon">
                        <i class="fa-solid <?= $isUploaded ? 'fa-check' : 'fa-file-arrow-up' ?>"></i>
                    </div>
                    <div class="checklist-info">
                        <strong><?= h($req['requirement_name']) ?></strong>
                        <small><?= !empty($req['is_required']) ? 'Required Document' : 'Optional Document' ?></small>
                        <small><?= h($req['description'] ?? 'No description provided.') ?></small>

                        <?php if (!empty($req['template_file'])): ?>
                            <a href="dashboard.php?page=<?= h($page) ?>&download_type=template&id=<?= (int)$req['id'] ?>" class="view-file">
                                <i class="fa-solid fa-download"></i> Download template
                            </a>
                        <?php endif; ?>

                        <?php if ($isUploaded): ?>
                            <a href="dashboard.php?page=<?= h($page) ?>&download_type=user_doc&req=<?= (int)$req['id'] ?>" class="view-file" target="_blank" rel="noopener">
                                <i class="fa-solid fa-eye"></i> View uploaded file
                            </a>
                            <?php if (!empty($req['uploaded_at'])): ?>
                                <small class="uploaded-date">Uploaded <?= h(date('M d, Y g:i A', strtotime($req['uploaded_at']))) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="checklist-action">
                        <?php if ($canUpload): ?>
                            <form method="POST" enctype="multipart/form-data" class="upload-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="requirement_id" value="<?= (int)$req['id'] ?>">
                                <label class="upload-btn">
                                    <i class="fa-solid fa-upload upload-icon"></i>
                                    <span class="upload-label"><?= $isUploaded ? 'Replace' : 'Upload' ?></span>
                                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" onchange="submitUpload(this)" required>
                                </label>
                            </form>
                        <?php else: ?>
                            <span class="badge-success" style="background:#fffbeb; color:#92400e; border-color:#fde68a;">
                                <i class="fa-solid fa-clock" style="margin-right:6px;"></i> Awaiting Approval
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function submitUpload(input) {
    const form = input.closest('form');
    if (!input.files.length) return;

    if (input.files[0].size > 5 * 1024 * 1024) {
        alert('File is larger than 5 MB. Please choose a smaller file.');
        input.value = '';
        return;
    }

    // IMPORTANT: do NOT overwrite btn.innerHTML. The <input type="file"> lives
    // inside the label, so replacing the label's HTML deletes the chosen file
    // and the form is submitted empty. Only change the icon and the text span.
    const btn   = form.querySelector('.upload-btn');
    const icon  = btn.querySelector('.upload-icon');
    const label = btn.querySelector('.upload-label');
    if (icon)  icon.className = 'fa-solid fa-spinner fa-spin upload-icon';
    if (label) label.textContent = 'Uploading…';
    btn.style.pointerEvents = 'none';

    form.submit();
}
</script>