link rel="stylesheet" href="../asset/css/fill_form.css">
<?php
if (!isset($userId)) {
    session_start();
    $userId = $_SESSION['user_id'] ?? 0;
}

require_once __DIR__ . '/../config/db.php';
$pdo = db();

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$formId = (int)($_GET['id'] ?? 0);

/* Fetch form */
$stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ? AND status = 'active'");
$stmt->execute([$formId]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <div style="max-width:640px;margin:60px auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:48px;text-align:center;">
        <div style="width:64px;height:64px;background:#fee2e2;color:#dc2626;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px;">
            <i class="ti ti-alert-circle"></i>
        </div>
        <h2 style="margin:0 0 8px;color:#0f172a;font-size:20px;">Form Not Found</h2>
        <p style="color:#64748b;margin:0 0 24px;">This form is either inactive or no longer exists.</p>
        <a href="?page=online_forms" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#1d4ed8;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">
            ← Back to Forms
        </a>
    </div>
    <?php
    return;
}

/* Decode schema */
$schema = json_decode($form['form_schema'] ?? '[]', true);
if (!is_array($schema)) { $schema = []; }

/* Check if user already submitted this form */
$alreadyStmt = $pdo->prepare("SELECT id, answers, created_at FROM form_submissions WHERE form_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
$alreadyStmt->execute([$formId, $userId]);
$existingSubmission = $alreadyStmt->fetch(PDO::FETCH_ASSOC);

/* Handle submission */
$success = false;
$error = '';
$submittedAnswers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingSubmission) {
    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $answers = $_POST['answers'] ?? [];
        
        // Validate required fields
        foreach ($schema as $field) {
            $fieldName = $field['name'] ?? '';
            if (!empty($field['required']) && empty($answers[$fieldName])) {
                $error = "Please fill out the required field: " . ($field['label'] ?? $fieldName);
                break;
            }
        }

        if (!$error) {
            try {
                $jsonAnswers = json_encode($answers);
                $insert = $pdo->prepare("INSERT INTO form_submissions (form_id, user_id, answers, status, created_at) VALUES (?, ?, ?, 'submitted', NOW())");
                $insert->execute([$formId, $userId, $jsonAnswers]);
                $success = true;
                $submittedAnswers = $answers;
                
                // Re-fetch so the "already submitted" block shows on the same page
                $alreadyStmt->execute([$formId, $userId]);
                $existingSubmission = $alreadyStmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $ex) {
                error_log('Form submission error: ' . $ex->getMessage());
                $error = 'Unable to save your submission. Please try again.';
            }
        }
    }
}

/* Decode existing answers for display */
$existingAnswers = [];
if ($existingSubmission && !empty($existingSubmission['answers'])) {
    $existingAnswers = json_decode($existingSubmission['answers'], true) ?: [];
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

<div class="form-wrapper">
    <div class="form-card">

        <!-- HEADER -->
        <div class="form-header">
            <div class="form-header-icon">
                <i class="ti ti-forms"></i>
            </div>
            <div class="form-header-text">
                <h2><?= e($form['form_name']) ?></h2>
                <p>
                    <?php if ($existingSubmission): ?>
                        Submitted on <?= date('M d, Y · g:i A', strtotime($existingSubmission['created_at'])) ?>
                    <?php else: ?>
                        Please fill out the form below accurately.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- BODY -->
        <div class="form-body">

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="ti ti-circle-check"></i>
                    <div>
                        <strong>Form submitted successfully!</strong><br>
                        Your answers have been saved. You may review them below.
                    </div>
                </div>
            <?php elseif ($existingSubmission): ?>
                <div class="alert alert-info">
                    <i class="ti ti-info-circle"></i>
                    <div>
                        <strong>You've already submitted this form.</strong><br>
                        Below are the answers you provided. Contact the admin if you need to update them.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="ti ti-alert-circle"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (empty($schema)): ?>
                <div class="alert alert-info">
                    <i class="ti ti-info-circle"></i>
                    <div>This form has no fields configured yet.</div>
                </div>
            <?php elseif ($existingSubmission): ?>

                <!-- READ-ONLY ANSWERS -->
                <?php foreach ($schema as $field):
                    $name  = $field['name'] ?? '';
                    $label = $field['label'] ?? 'Untitled Field';
                    $value = $existingAnswers[$name] ?? '';
                ?>
                    <div class="form-group">
                        <label><?= e($label) ?></label>
                        <div class="answer-readonly">
                            <?= $value !== '' ? e($value) : '<span style="color:#94a3b8;">Not answered</span>' ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>

                <!-- FILLABLE FORM -->
                <form method="POST" id="fillForm">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                    <?php foreach ($schema as $field):
                        $name        = e($field['name'] ?? '');
                        $label       = e($field['label'] ?? 'Untitled Field');
                        $type        = $field['type'] ?? 'text';
                        $required    = !empty($field['required']);
                        $placeholder = e($field['placeholder'] ?? '');
                        $options     = $field['options'] ?? [];
                    ?>
                        <div class="form-group">
                            <?php if ($type === 'checkbox'): ?>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="answers[<?= $name ?>]" id="field_<?= $name ?>" value="Yes" <?= $required ? 'required' : '' ?>>
                                    <label for="field_<?= $name ?>">
                                        <?= $label ?><?= $required ? '<span class="req-star">*</span>' : '' ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <label for="field_<?= $name ?>">
                                    <?= $label ?><?= $required ? '<span class="req-star">*</span>' : '' ?>
                                </label>

                                <?php if ($type === 'textarea'): ?>
                                    <textarea name="answers[<?= $name ?>]" id="field_<?= $name ?>" rows="4" placeholder="<?= $placeholder ?>" <?= $required ? 'required' : '' ?>></textarea>

                                <?php elseif ($type === 'select'): ?>
                                    <select name="answers[<?= $name ?>]" id="field_<?= $name ?>" <?= $required ? 'required' : '' ?>>
                                        <option value="">Select an option...</option>
                                        <?php foreach ($options as $opt): ?>
                                            <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php else: ?>
                                    <input type="<?= e($type) ?>" name="answers[<?= $name ?>]" id="field_<?= $name ?>" placeholder="<?= $placeholder ?>" <?= $required ? 'required' : '' ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-footer">
                        <a href="?page=online_forms" class="btn-back">
                            <i class="ti ti-arrow-left"></i> Back to Forms
                        </a>
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="ti ti-send"></i> Submit Form
                        </button>
                    </div>
                </form>

            <?php endif; ?>

            <?php if ($existingSubmission): ?>
                <div class="form-footer">
                    <a href="?page=online_forms" class="btn-back">
                        <i class="ti ti-arrow-left"></i> Back to Forms
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
    <script src="../asset/js/fill_form.js"></script>
