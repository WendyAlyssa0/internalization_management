<?php
/**
 * Application handler — processes "Start Application" / "Apply" form submissions.
 * Included from home.php (or routed via ?page=apply_program).
 */

if (!isset($pdo, $userId) || !($pdo instanceof PDO)) {
    header('Location: dashboard.php?page=available_programs');
    exit;
}
require_once __DIR__ . '/../config/program_eligibility.php';

/* =========================================================
   CONFIGURE THESE IF YOUR SCHEMA DIFFERS
   ========================================================= */
$APP_USER_COL = 'applicant_id';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_ok(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

$programId = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$action    = (string)($_POST['action'] ?? $_GET['action'] ?? 'start');

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $flash = ['type' => 'error', 'msg' => 'Session expired. Please try again.'];
    } elseif ($programId <= 0) {
        $flash = ['type' => 'error', 'msg' => 'Invalid program selected.'];
    } else {
        $stmt = $pdo->prepare("
            SELECT p.id, p.program_name, p.status, p.end_date, p.mobility_direction
            FROM programs p
            INNER JOIN users applicant ON applicant.id = ?
            LEFT JOIN degree_programs degree
                ON degree.campus_id = applicant.campus_id
               AND degree.program_name = applicant.program
            WHERE p.id = ?
              AND " . programApplicationWindowSql() . "
              AND " . programEligibilitySql() . "
            LIMIT 1
        ");
        $stmt->execute([$userId, $programId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$program) {
            $flash = ['type' => 'error', 'msg' => 'Program not found.'];
        } elseif (!in_array($program['status'], ['Active', 'Upcoming'], true)) {
            $flash = ['type' => 'error', 'msg' => 'This program is not currently accepting applications.'];
        } elseif (!empty($program['end_date']) && strtotime($program['end_date']) < time()) {
            $flash = ['type' => 'error', 'msg' => 'The application deadline for this program has passed.'];
        } else {
            $stmt = $pdo->prepare("SELECT id, status FROM applications WHERE {$APP_USER_COL} = ? AND program_id = ? LIMIT 1");
            $stmt->execute([$userId, $programId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($action === 'cancel') {
                if ($existing && $existing['status'] === 'draft') {
                    $del = $pdo->prepare("DELETE FROM applications WHERE id = ? AND {$APP_USER_COL} = ? AND status = 'draft'");
                    $del->execute([$existing['id'], $userId]);
                    $_SESSION['apply_flash'] = ['type' => 'success', 'msg' => 'Draft application cancelled.'];
                } else {
                    $_SESSION['apply_flash'] = ['type' => 'error', 'msg' => 'Only draft applications can be cancelled.'];
                }
                header('Location: ?page=my_applications');
                exit;
            }

            if ($existing) {
                if ($existing['status'] === 'draft') {
                    header('Location: ?page=apply&program_id=' . $programId);
                    exit;
                }
                $_SESSION['apply_flash'] = ['type' => 'error', 'msg' => 'You have already applied to this program.'];
                header('Location: ?page=my_application_detail&id=' . (int)$existing['id']);
                exit;
            }

            try {
                $mobilityType = strtolower((string)$program['mobility_direction']) === 'inbound' ? 'Inbound' : 'Outbound';
                $ins = $pdo->prepare("INSERT INTO applications ({$APP_USER_COL}, program_id, mobility_type, status, created_at) VALUES (?, ?, ?, 'submitted', NOW())");
                $ins->execute([$userId, $programId, $mobilityType]);
                $newId = (int)$pdo->lastInsertId();
                $_SESSION['apply_flash'] = ['type' => 'success', 'msg' => 'Application submitted successfully.'];
                header('Location: ?page=my_applications&id=' . $newId);
                exit;
            } catch (PDOException $e) {
                error_log('apply_program insert: ' . $e->getMessage());
                $flash = ['type' => 'error', 'msg' => 'Could not start the application. Please try again.'];
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT p.id, p.program_name, p.description, p.start_date, p.end_date, p.status,
           country.country_name,
           EXISTS (SELECT 1 FROM applications a WHERE a.{$APP_USER_COL} = ? AND a.program_id = p.id) AS already_applied
    FROM programs p
    INNER JOIN users applicant ON applicant.id = ?
    LEFT JOIN degree_programs degree
        ON degree.campus_id = applicant.campus_id
       AND degree.program_name = applicant.program
    LEFT JOIN countries country ON country.id = p.country_id
    WHERE p.status IN ('Active', 'Upcoming')
      AND " . programApplicationWindowSql() . "
      AND " . programEligibilitySql() . "
    ORDER BY p.start_date, p.program_name
");
$stmt->execute([$userId, $userId]);
$programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($_SESSION['apply_flash'])) {
    $flash = $_SESSION['apply_flash'];
    unset($_SESSION['apply_flash']);
}
?>

<link rel="stylesheet" href="../asset/css/upload_docs.css">

<div class="content-card">
    <div class="page-header">
        <div>
            <h2>Apply to a Program</h2>
            <p>Choose a program below to start your application.</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (empty($programs)): ?>
        <p class="muted">No programs are open for application right now.</p>
    <?php else: ?>
        <div class="program-grid">
            <?php foreach ($programs as $p): ?>
            <div class="program-card">
                <div class="program-card-head">
                    <h3><?= htmlspecialchars($p['program_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <?php if (!empty($p['country_name'])): ?>
                        <span class="country-chip"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($p['country_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <p class="program-desc"><?= htmlspecialchars(mb_strimwidth((string)($p['description'] ?? ''), 0, 160, '…'), ENT_QUOTES, 'UTF-8') ?></p>
                <div class="program-meta">
                    <?php if (!empty($p['start_date']) && !empty($p['end_date'])): ?>
                        <span>
                            <i class="fa-regular fa-calendar"></i>
                            <?= htmlspecialchars(date('M d, Y', strtotime($p['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                            – <?= htmlspecialchars(date('M d, Y', strtotime($p['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="program-card-actions">
                    <?php if ($p['already_applied']): ?>
                        <span class="badge-success">Already applied</span>
                        <a class="btn-secondary" href="?page=my_applications">View status</a>
                    <?php else: ?>
                        <a class="btn-secondary" href="?page=program_detail&id=<?= (int)$p['id'] ?>">View details</a>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="program_id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="action" value="start">
                            <button type="submit" class="btn-primary">Apply now →</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>