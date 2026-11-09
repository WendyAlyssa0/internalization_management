link rel="stylesheet" href="../asset/css/online_forms.css">
<?php
if (!isset($userId)) {
    session_start();
    $userId = $_SESSION['user_id'] ?? 0;
}

require_once __DIR__ . '/../config/db.php';
$pdo = db();

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Fetch downloadable templates
$stmt = $pdo->query("SELECT id, form_name, form_type, file_path FROM forms WHERE status = 'active' ORDER BY form_name");
$downloadableForms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Online Forms (Forms that have a specific form_type)
$stmt = $pdo->query("SELECT id, form_name, form_type FROM forms WHERE status = 'active' AND form_type = 'online'");
$onlineForms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Requirements
$stmt = $pdo->query("
    SELECT pr.id, pr.requirement_name, pr.is_required, pr.requirement_file,
           p.id AS program_id, p.program_name
    FROM program_requirements pr
    JOIN programs p ON p.id = pr.program_id
    WHERE p.status IN ('Active', 'Upcoming')
    ORDER BY p.program_name, pr.is_required DESC
");
$requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($requirements as $r) {
    $grouped[$r['program_id']]['program_name'] = $r['program_name'];
    $grouped[$r['program_id']]['items'][] = $r;
}

// Fetch Submitted Documents
$stmt = $pdo->prepare("SELECT id, title, file_path, status, created_at FROM documents WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$submittedDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$submittedMap = [];
foreach ($submittedDocs as $doc) { $submittedMap[strtolower($doc['title'])] = $doc; }
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

<div class="forms-wrapper">
    <div class="forms-tabs">
        <button class="forms-tab active" onclick="switchTab('online', this)"><i class="ti ti-forms"></i> Online Forms</button>
        <button class="forms-tab" onclick="switchTab('requirements', this)"><i class="ti ti-clipboard-list"></i> Requirements</button>
        <button class="forms-tab" onclick="switchTab('templates', this)"><i class="ti ti-download"></i> Downloadable</button>
        <button class="forms-tab" onclick="switchTab('submitted', this)"><i class="ti ti-file-check"></i> My Submissions</button>
    </div>

    <!-- TAB 1: ONLINE FORMS -->
    <div id="tab-online" class="tab-panel active">
        <?php if (empty($onlineForms)): ?>
            <div class="form-card">
                <div class="form-card-header"><h3><i class="ti ti-forms"></i> Available Online Forms</h3></div>
                <div style="padding: 40px; text-align:center; color:#64748b;">
                    <i class="ti ti-forms" style="font-size:40px; color:#cbd5e1; margin-bottom:10px; display:block;"></i>
                    <p>No online forms available yet. Please check back later.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="form-card">
                <div class="form-card-header"><h3><i class="ti ti-forms"></i> Fill Out Online</h3></div>
                <?php foreach ($onlineForms as $form): ?>
                    <div class="form-row">
                        <div>
                            <div style="font-weight:600; font-size:14px; color:#1e293b; margin-bottom:2px;">
                                <?= e($form['form_name']) ?>
                            </div>
                            <div style="font-size:12px; color:#64748b;">Fill this out online directly on the website.</div>
                        </div>
                        <a href="?page=fill_form&id=<?= (int)$form['id'] ?>" class="btn btn-primary">
                            <i class="ti ti-edit"></i> Fill Out
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 2: REQUIREMENTS -->
    <div id="tab-requirements" class="tab-panel">
        <?php if (empty($grouped)): ?>
            <p style="text-align:center; color:#64748b;">No requirements configured yet.</p>
        <?php else: ?>
            <?php foreach ($grouped as $programId => $program): ?>
                <div class="form-card">
                    <div class="form-card-header"><h3><i class="ti ti-folder"></i> <?= e($program['program_name']) ?></h3></div>
                    <?php foreach ($program['items'] as $req): 
                        $uploaded = isset($submittedMap[strtolower($req['requirement_name'])]);
                    ?>
                        <div class="form-row">
                            <div>
                                <div style="font-weight:600; font-size:14px; color:#1e293b;"><?= e($req['requirement_name']) ?></div>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <?php if ($uploaded): ?>
                                    <span class="badge badge-online"><i class="ti ti-check"></i> Uploaded</span>
                                <?php elseif ($req['is_required']): ?>
                                    <span class="badge badge-required">Required</span>
                                <?php endif; ?>
                                <a href="?page=upload_documents" class="btn btn-outline"><i class="ti ti-upload"></i> Upload</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB 3: TEMPLATES -->
    <div id="tab-templates" class="tab-panel">
        <?php if (empty($downloadableForms)): ?>
            <p style="text-align:center; color:#64748b;">No templates available.</p>
        <?php else: ?>
            <div class="form-card">
                <?php foreach ($downloadableForms as $form): ?>
                    <div class="form-row">
                        <div style="font-weight:600; font-size:14px;"><?= e($form['form_name']) ?></div>
                        <a href="<?= e($form['file_path']) ?>" class="btn btn-outline" download><i class="ti ti-download"></i> Download</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 4: SUBMISSIONS -->
    <div id="tab-submitted" class="tab-panel">
        <?php if (empty($submittedDocs)): ?>
            <p style="text-align:center; color:#64748b;">No files uploaded yet.</p>
        <?php else: ?>
            <div class="form-card">
                <?php foreach ($submittedDocs as $doc): ?>
                    <div class="form-row">
                        <div style="font-weight:600; font-size:14px;"><?= e($doc['title']) ?></div>
                        <a href="../<?= e($doc['file_path']) ?>" class="btn btn-outline" target="_blank"><i class="ti ti-eye"></i> View</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
    <script src="../asset/js/online_forms.js"></script>
