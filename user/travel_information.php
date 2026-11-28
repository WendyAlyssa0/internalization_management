<?php
/**
 * user/travel_information.php  —  "Mobility & Travel" (participant view)
 *
 * Loaded by user/dashboard.php (?page=travel_information), which supplies
 * $pdo, $userId, $user, h() and checkCsrf().
 *
 *  1. Approved mobility information + current mobility status
 *  2. Travel information (flight itinerary) and passport details / documents
 *  3. Progress through the mobility process
 *  4. A "request" form that notifies staff when something is missing
 */
if (!isset($pdo, $userId) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=travel_information');
    exit;
}

/* ---------- helpers ---------- */
$hasCol = static function (PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = $table . '.' . $col;
    if (!isset($cache[$key])) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $st->execute([$col]);
            $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
};
$fmtDate = static fn($d, string $f = 'M j, Y') => ($d && $d !== '0000-00-00') ? date($f, strtotime((string)$d)) : null;
$fmtTime = static fn($t) => $t ? date('g:i A', strtotime((string)$t)) : null;

/* ---------- load applications ---------- */
$flash = null;
try {
    $st = $pdo->prepare("
        SELECT a.id, a.status, a.documents_status, a.mobility_type, a.semester, a.school_year,
               a.approval_date, a.created_at, a.reviewed_at,
               p.program_name, p.start_date, p.end_date,
               partner.institution_name AS partner_institution,
               c.country_name
        FROM applications a
        LEFT JOIN programs p        ON p.id = a.program_id
        LEFT JOIN partners partner  ON partner.id = p.partner_id
        LEFT JOIN countries c       ON c.id = p.country_id
        WHERE a.applicant_id = ?
        ORDER BY a.created_at DESC
    ");
    $st->execute([$userId]);
    $apps = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('travel_information (apps): ' . $e->getMessage());
    echo '<section class="content-card"><p>Unable to load your mobility information right now. Please try again later.</p></section>';
    return;
}

$liveStatuses = ['approved', 'traveling', 'completed'];
$app = null;
$wantedId = (int)($_GET['id'] ?? 0);
foreach ($apps as $a) {                       // explicit ?id= (must be the user's own)
    if ($wantedId && (int)$a['id'] === $wantedId) { $app = $a; break; }
}
if (!$app) {                                  // else newest approved/active one
    foreach ($apps as $a) {
        if (in_array(strtolower((string)$a['status']), $liveStatuses, true)) { $app = $a; break; }
    }
}
if (!$app && $apps) { $app = $apps[0]; }      // else newest application (shows progress only)

/* ---------- travel record, passport, documents ---------- */
$travel = null;
$docs   = [];
if ($app) {
    $appStatus = strtolower((string)$app['status']);

    try {
        if ($hasCol($pdo, 'travel_information', 'application_id')) {
            $st = $pdo->prepare("SELECT * FROM travel_information WHERE user_id = ?
                                 AND (application_id = ? OR application_id IS NULL)
                                 ORDER BY (application_id IS NULL), departure_date DESC, id DESC LIMIT 1");
            $st->execute([$userId, (int)$app['id']]);
        } else {
            $st = $pdo->prepare("SELECT * FROM travel_information WHERE user_id = ?
                                 ORDER BY departure_date DESC, id DESC LIMIT 1");
            $st->execute([$userId]);
        }
        $travel = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('travel_information (travel): ' . $e->getMessage());
    }

    try {
        $st = $pdo->prepare("
            SELECT ud.requirement_id, ud.status, ud.uploaded_at, r.requirement_name
            FROM user_documents ud
            INNER JOIN requirements r ON r.id = ud.requirement_id
            WHERE ud.user_id = ?
              AND r.requirement_name REGEXP 'passport|visa|travel|flight|ticket|itinerary'
            ORDER BY ud.uploaded_at DESC
        ");
        $st->execute([$userId]);
        $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('travel_information (docs): ' . $e->getMessage());
    }
}

/* ---------- participant request -> notify staff ---------- */
$requestTypes = [
    'travel'   => 'Travel / flight information',
    'passport' => 'Passport details',
    'document' => 'Travel or passport documents',
];
if ($app && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mt_request'])) {
    $type = (string)($_POST['request_type'] ?? '');
    $note = trim((string)($_POST['request_note'] ?? ''));
    if (!checkCsrf()) {
        $flash = ['error', 'Your session expired. Please reload the page and try again.'];
    } elseif (!isset($requestTypes[$type])) {
        $flash = ['error', 'Please choose what you are requesting.'];
    } elseif (mb_strlen($note, 'UTF-8') > 300) {
        $flash = ['error', 'Please keep your note under 300 characters.'];
    } else {
        try {
            $tag = '[user #' . $userId . ' / app #' . (int)$app['id'] . ' / ' . $type . ']';
            $dup = $pdo->prepare("SELECT 1 FROM notifications
                                  WHERE title = 'Travel Information Request' AND message LIKE ?
                                    AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1");
            $dup->execute(['%' . $tag . '%']);
            if ($dup->fetchColumn()) {
                $flash = ['info', 'You already sent this request in the last 24 hours. Staff have been notified.'];
            } else {
                $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $msg  = $name . ' requested: ' . $requestTypes[$type] . ' for ' . ($app['program_name'] ?? 'a program') . '.'
                      . ($note !== '' ? ' Note: ' . $note : '') . ' ' . $tag;
                $staff = $pdo->query("SELECT id FROM users WHERE user_role IN ('admin','super admin') AND status = 'active'")
                             ->fetchAll(PDO::FETCH_COLUMN);
                $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message)
                                      VALUES (?, 'system', 'Travel Information Request', ?)");
                foreach ($staff as $sid) { $ins->execute([(int)$sid, $msg]); }
                $flash = ['success', 'Request sent. The Internationalization Office will follow up.'];
            }
        } catch (Throwable $e) {
            error_log('travel_information (request): ' . $e->getMessage());
            $flash = ['error', 'We could not send your request. Please try again.'];
        }
    }
}

/* ---------- status + progress ---------- */
$stages = [];
$statusLabel = 'No application yet';
$statusTone  = 'pending';
$isRejected = $isApproved = false;

if ($app) {
    $appStatus    = strtolower((string)$app['status']);
    $isRejected   = $appStatus === 'rejected';
    $isApproved   = in_array($appStatus, $liveStatuses, true);
    $travelStatus = strtolower((string)($travel['travel_status'] ?? ''));
    $isCompleted  = $appStatus === 'completed' || $travelStatus === 'completed';
    $isTraveling  = $isCompleted || $appStatus === 'traveling' || $travelStatus === 'traveling';
    $docsComplete = strtolower((string)$app['documents_status']) === 'complete';

    $stages = [
        ['Submitted',          'fa-paper-plane',        true],
        ['Under review',       'fa-magnifying-glass',   $isApproved || $isRejected || $appStatus === 'under_review'],
        ['Approved',           'fa-circle-check',       $isApproved],
        ['Documents verified', 'fa-file-circle-check',  $isApproved && $docsComplete],
        ['Travel details',     'fa-plane-departure',    $isApproved && (bool)$travel],
        ['Traveling',          'fa-plane',              $isApproved && $isTraveling],
        ['Completed',          'fa-flag-checkered',     $isApproved && $isCompleted],
    ];

    if ($isRejected)            { $statusLabel = 'Application not approved'; $statusTone = 'rejected'; }
    elseif ($isCompleted)       { $statusLabel = 'Mobility completed';       $statusTone = 'approved'; }
    elseif ($isTraveling)       { $statusLabel = 'Traveling';                $statusTone = 'approved'; }
    elseif ($isApproved)        { $statusLabel = 'Approved';                 $statusTone = 'approved'; }
    elseif ($appStatus === 'under_review') { $statusLabel = 'Under review';  $statusTone = 'pending'; }
    else                        { $statusLabel = 'Submitted';                $statusTone = 'pending'; }
}
$firstOpen = null;
foreach ($stages as $i => $s) { if (!$s[2]) { $firstOpen = $i; break; } }
$doneCount = count(array_filter($stages, static fn($s) => $s[2]));
$pct = $stages ? (int)round($doneCount / count($stages) * 100) : 0;

/* passport (only meaningful once approved) */
$passportNo  = $isApproved ? trim((string)($travel['passport_number'] ?? '')) : '';
$passportExp = $isApproved ? ($travel['passport_expiry_date'] ?? null) : null;
$passportMasked = $passportNo !== '' ? str_repeat('•', max(0, mb_strlen($passportNo) - 4)) . mb_substr($passportNo, -4) : '';
$expSoon = $passportExp && strtotime((string)$passportExp) < strtotime('+6 months', strtotime((string)($app['end_date'] ?? 'now')));

$docTone = static fn(string $s): string => match (strtolower($s)) { 'approved' => 'approved', 'rejected' => 'rejected', default => 'pending' };
?>

<style>
    .mt-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:24px; margin-top:28px; }
    .mt-card { background:var(--white); border:1px solid var(--border-light); border-radius:var(--radius-lg); padding:24px 28px; box-shadow:var(--shadow-sm); }
    .mt-card h3 { font-size:1.05rem; font-weight:800; margin-bottom:16px; display:flex; align-items:center; gap:10px; }
    .mt-card h3 i { color:var(--psu-gold); }
    .mt-dl { display:grid; grid-template-columns: 150px 1fr; gap:10px 16px; margin:0; }
    .mt-dl dt { color:var(--text-muted); font-size:.85rem; font-weight:600; }
    .mt-dl dd { margin:0; font-weight:600; color:var(--text-main); word-break:break-word; }
    .mt-badge { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:var(--radius-full, 999px); font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .mt-badge.approved { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
    .mt-badge.pending  { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
    .mt-badge.rejected { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .mt-muted { color:var(--text-muted); font-size:.92rem; }
    .mt-alert { padding:12px 16px; border-radius:var(--radius-sm); margin-top:20px; font-weight:600; font-size:.92rem; border:1px solid; }
    .mt-alert.success { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .mt-alert.error, .mt-alert.rejected { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
    .mt-alert.info, .mt-alert.warn { background:#fffbeb; color:#92400e; border-color:#fde68a; }

    /* progress */
    .mt-progress-top { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:8px; }
    .mt-bar { height:8px; background:var(--border-light); border-radius:99px; overflow:hidden; margin-bottom:28px; }
    .mt-bar > span { display:block; height:100%; background:var(--psu-gold); border-radius:99px; transition:width .4s; }
    .mt-steps { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(7, 1fr); gap:8px; }
    .mt-step { text-align:center; position:relative; font-size:.8rem; font-weight:600; color:var(--text-light); }
    .mt-step .dot { width:40px; height:40px; margin:0 auto 8px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                    background:var(--bg-main); border:2px solid var(--border-light); font-size:.95rem; }
    .mt-step.done { color:var(--psu-navy); }
    .mt-step.done .dot { background:#ecfdf5; border-color:#047857; color:#047857; }
    .mt-step.current { color:var(--psu-navy); }
    .mt-step.current .dot { background:#fffbeb; border-color:var(--psu-gold); color:#92400e; box-shadow:0 0 0 4px rgba(228,177,52,.18); }
    .mt-step.failed .dot { background:#fef2f2; border-color:#b91c1c; color:#b91c1c; }

    /* docs table */
    .mt-table-wrap { overflow-x:auto; }
    .mt-table { width:100%; border-collapse:collapse; font-size:.9rem; }
    .mt-table th, .mt-table td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--border-light); white-space:nowrap; }
    .mt-table th { color:var(--text-muted); font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; }
    .mt-link { font-weight:700; color:var(--psu-blue); }
    .mt-link:hover { color:var(--psu-gold); }

    .mt-form label { display:block; font-size:.85rem; font-weight:700; margin:0 0 6px; color:var(--psu-navy); }
    .mt-form select, .mt-form textarea { width:100%; padding:10px 12px; border:1px solid var(--border-light); border-radius:var(--radius-sm); font:inherit; background:var(--white); }
    .mt-form textarea { resize:vertical; min-height:70px; margin-bottom:14px; }
    .mt-form select { margin-bottom:14px; }
    .mt-btn { display:inline-flex; align-items:center; gap:8px; padding:11px 22px; border-radius:var(--radius-sm); background:var(--psu-gold); color:var(--psu-navy); font-family:var(--font-heading); font-weight:700; }
    .mt-btn:hover { filter:brightness(.96); }
    .mt-reveal { margin-left:10px; font-size:.8rem; font-weight:700; color:var(--psu-blue); }
    .mt-picker { margin-top:16px; }
    .mt-picker select { padding:8px 12px; border:1px solid var(--border-light); border-radius:var(--radius-sm); font:inherit; }

    @media (max-width: 768px) {
        .mt-steps { grid-template-columns:1fr; gap:14px; }
        .mt-step { display:flex; align-items:center; gap:14px; text-align:left; }
        .mt-step .dot { margin:0; flex:0 0 40px; }
        .mt-dl { grid-template-columns:1fr; gap:2px 0; }
        .mt-dl dd { margin-bottom:10px; }
        .mt-card { padding:20px; }
    }
</style>

<section class="content-card">
    <div class="page-header">
        <div>
            <h2>Mobility &amp; Travel</h2>
            <p>Your approved mobility details, travel information, and progress through the mobility process.</p>
        </div>
        <?php if ($app): ?>
            <span class="mt-badge <?= h($statusTone) ?>">
                <i class="fa-solid <?= $statusTone === 'approved' ? 'fa-circle-check' : ($statusTone === 'rejected' ? 'fa-circle-xmark' : 'fa-clock') ?>"></i>
                <?= h($statusLabel) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="mt-alert <?= h($flash[0]) ?>" role="status"><?= h($flash[1]) ?></div>
    <?php endif; ?>

    <?php if (!$app): ?>
        <div class="empty-state" style="text-align:center;padding:60px 24px;background:var(--bg-main);border-radius:var(--radius-lg);border:2px dashed var(--border-light);margin-top:24px;">
            <h4 style="font-size:1.25rem;color:var(--psu-navy);margin-bottom:8px;">No mobility record yet</h4>
            <p class="mt-muted" style="max-width:420px;margin:0 auto 20px;">Once you apply to a program and it is approved, your mobility and travel information will appear here.</p>
            <a class="mt-btn" href="dashboard.php?page=available_programs"><i class="fa-solid fa-globe"></i> Browse Programs</a>
        </div>
    <?php else: ?>

        <?php if (count($apps) > 1): ?>
            <form class="mt-picker" method="get" action="dashboard.php">
                <input type="hidden" name="page" value="travel_information">
                <label for="mtApp" class="mt-muted">Application:</label>
                <select id="mtApp" name="id" onchange="this.form.submit()">
                    <?php foreach ($apps as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === (int)$app['id'] ? 'selected' : '' ?>>
                            <?= h($a['program_name'] ?? 'Program') ?> — <?= h(ucwords(str_replace('_', ' ', (string)$a['status'] ?: 'unknown'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="mt-btn" type="submit">View</button></noscript>
            </form>
        <?php endif; ?>

        <?php if ($isRejected): ?>
            <div class="mt-alert rejected">This application was not approved, so there is no mobility or travel information to show.
                <a class="mt-link" href="?page=my_application_detail&id=<?= (int)$app['id'] ?>">See application details</a>.</div>
        <?php endif; ?>

        <!-- Progress -->
        <div class="mt-card" style="margin-top:28px;" id="mobility-progress">
            <div class="mt-progress-top">
                <h3 style="margin:0;"><i class="fa-solid fa-route"></i> Mobility progress</h3>
                <span class="mt-muted"><?= (int)$doneCount ?> of <?= count($stages) ?> steps &middot; <?= (int)$pct ?>%</span>
            </div>
            <div class="mt-bar" role="progressbar" aria-valuenow="<?= (int)$pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= (int)$pct ?>%"></span></div>
            <ol class="mt-steps">
                <?php foreach ($stages as $i => [$label, $icon, $done]):
                    $cls = $done ? 'done' : (($i === $firstOpen && !$isRejected) ? 'current' : '');
                    if ($isRejected && $i === 2) { $cls = 'failed'; $icon = 'fa-circle-xmark'; $label = 'Not approved'; }
                ?>
                    <li class="mt-step <?= $cls ?>"<?= $cls === 'current' ? ' aria-current="step"' : '' ?>>
                        <div class="dot"><i class="fa-solid <?= h($icon) ?>"></i></div>
                        <span><?= h($label) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>

        <?php if ($isApproved): ?>
        <div class="mt-grid">
            <!-- Approved mobility information -->
            <div class="mt-card" id="mobility-info">
                <h3><i class="fa-solid fa-earth-asia"></i> Approved mobility information</h3>
                <dl class="mt-dl">
                    <dt>Program</dt><dd><?= h($app['program_name'] ?? '—') ?></dd>
                    <dt>Type</dt><dd><?= h($app['mobility_type'] ?? '—') ?></dd>
                    <dt>Host institution</dt><dd><?= h($app['partner_institution'] ?? '—') ?></dd>
                    <dt>Country</dt><dd><?= h($app['country_name'] ?? '—') ?></dd>
                    <dt>Program dates</dt>
                    <dd><?php
                        $s = $fmtDate($app['start_date']); $e = $fmtDate($app['end_date']);
                        echo $s ? h($s . ($e ? ' – ' . $e : '')) : 'Dates pending';
                    ?></dd>
                    <?php if (!empty($app['semester']) || !empty($app['school_year'])): ?>
                        <dt>Term</dt><dd><?= h(trim(($app['semester'] ?? '') . ' ' . ($app['school_year'] ?? ''))) ?></dd>
                    <?php endif; ?>
                    <dt>Approved on</dt><dd><?= h($fmtDate($app['approval_date']) ?? $fmtDate($app['reviewed_at']) ?? '—') ?></dd>
                    <dt>Mobility status</dt>
                    <dd><span class="mt-badge <?= h($statusTone) ?>"><?= h($statusLabel) ?></span></dd>
                </dl>
            </div>

            <!-- Travel information -->
            <div class="mt-card" id="travel-info">
                <h3><i class="fa-solid fa-plane-departure"></i> Travel information</h3>
                <?php if ($travel): ?>
                    <dl class="mt-dl">
                        <dt>Flight number</dt><dd><?= h($travel['flight_number'] ?: '—') ?></dd>
                        <dt>Departure</dt>
                        <dd><?= h(trim(($fmtDate($travel['departure_date']) ?? '—') . ' ' . ($fmtTime($travel['departure_time']) ?? ''))) ?><br>
                            <span class="mt-muted"><?= h($travel['departure_airport'] ?: 'Airport not specified') ?></span></dd>
                        <dt>Arrival</dt>
                        <dd><?= h(trim(($fmtDate($travel['arrival_date']) ?? '—') . ' ' . ($fmtTime($travel['arrival_time']) ?? ''))) ?><br>
                            <span class="mt-muted"><?= h($travel['arrival_airport'] ?: 'Airport not specified') ?></span></dd>
                        <?php if (isset($travel['travel_status'])): ?>
                            <dt>Travel status</dt>
                            <dd><span class="mt-badge <?= $docTone((string)$travel['travel_status'] === 'rejected' ? 'rejected' : ($travel['travel_status'] === 'pending' ? 'pending' : 'approved')) ?>">
                                <?= h(ucfirst((string)$travel['travel_status'])) ?></span></dd>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <p class="mt-muted">Your flight details have not been filed yet. Staff will add them once travel is arranged — you can ask for them below.</p>
                <?php endif; ?>
            </div>

            <!-- Passport -->
            <div class="mt-card" id="passport">
                <h3><i class="fa-solid fa-passport"></i> Passport details</h3>
                <?php if ($passportNo !== '' || $passportExp): ?>
                    <dl class="mt-dl">
                        <dt>Passport no.</dt>
                        <dd>
                            <span id="mtPassNo" data-masked="<?= h($passportMasked) ?>" data-full="<?= h($passportNo) ?>"><?= h($passportMasked ?: '—') ?></span>
                            <?php if ($passportNo !== ''): ?>
                                <button type="button" class="mt-reveal" id="mtReveal" aria-controls="mtPassNo">Show</button>
                            <?php endif; ?>
                        </dd>
                        <dt>Expiry date</dt><dd><?= h($fmtDate($passportExp) ?? '—') ?></dd>
                    </dl>
                    <?php if ($expSoon): ?>
                        <div class="mt-alert warn">Your passport expires within 6 months of your program end date. Many host countries require at least 6 months of validity — consider renewing.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="mt-muted">No passport details are on file yet.</p>
                <?php endif; ?>
            </div>

            <!-- Documents -->
            <div class="mt-card" id="travel-documents">
                <h3><i class="fa-solid fa-folder-open"></i> Travel &amp; passport documents</h3>
                <?php if ($docs): ?>
                    <div class="mt-table-wrap">
                        <table class="mt-table">
                            <thead><tr><th>Document</th><th>Status</th><th>Uploaded</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($docs as $d): ?>
                                <tr>
                                    <td><?= h($d['requirement_name']) ?></td>
                                    <td><span class="mt-badge <?= $docTone((string)$d['status']) ?>"><?= h(ucfirst((string)$d['status'])) ?></span></td>
                                    <td><?= h($fmtDate($d['uploaded_at']) ?? '—') ?></td>
                                    <td><a class="mt-link" href="?page=documents&amp;download_type=user_doc&amp;id=<?= (int)$d['requirement_id'] ?>" target="_blank" rel="noopener">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="mt-muted">No travel or passport documents uploaded yet.
                        <a class="mt-link" href="?page=documents#required-documents">Go to Documents</a>.</p>
                <?php endif; ?>
            </div>

            <!-- Request -->
            <div class="mt-card" id="request-info">
                <h3><i class="fa-solid fa-paper-plane"></i> Request information</h3>
                <p class="mt-muted" style="margin-bottom:14px;">Missing or out-of-date? Send a request and the Internationalization Office will be notified.</p>
                <form class="mt-form" method="post" action="dashboard.php?page=travel_information&amp;id=<?= (int)$app['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <label for="mtType">What do you need?</label>
                    <select id="mtType" name="request_type" required>
                        <?php foreach ($requestTypes as $k => $label): ?>
                            <option value="<?= h($k) ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="mtNote">Note (optional)</label>
                    <textarea id="mtNote" name="request_note" maxlength="300" placeholder="e.g. My flight changed to Dec 2"></textarea>
                    <button type="submit" name="mt_request" value="1" class="mt-btn"><i class="fa-solid fa-paper-plane"></i> Send request</button>
                </form>
            </div>
        </div>

        <script>
        (function () {
            var b = document.getElementById('mtReveal'), s = document.getElementById('mtPassNo');
            if (!b || !s) return;
            b.addEventListener('click', function () {
                var show = b.textContent === 'Show';
                s.textContent = show ? s.dataset.full : s.dataset.masked;
                b.textContent = show ? 'Hide' : 'Show';
            });
        })();
        </script>
        <?php else: ?>
            <?php if (!$isRejected): ?>
                <div class="mt-alert info">Mobility, travel and passport details are shown here once your application is approved.</div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
