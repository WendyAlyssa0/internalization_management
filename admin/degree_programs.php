<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (empty($_SESSION['user_id']) || !canManageSystem()) {
    http_response_code(empty($_SESSION['user_id']) ? 401 : 403);
    exit('Access denied.');
}

$pdo = db();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
        exit;
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($action === 'save') {
                $campusId = (int)($_POST['campus_id'] ?? 0);
                $programName = trim((string)($_POST['program_name'] ?? ''));
                if ($campusId <= 0 || $programName === '' || mb_strlen($programName) > 255) {
                    throw new RuntimeException('Choose a campus and enter a degree-program name up to 255 characters.');
                }
                $campusCheck = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND status = 'active'");
                $campusCheck->execute([$campusId]);
                if (!$campusCheck->fetchColumn()) {
                    throw new RuntimeException('Choose an active campus.');
                }
                if ($id > 0) {
                    $existingCheck = $pdo->prepare('SELECT id FROM degree_programs WHERE id = ?');
                    $existingCheck->execute([$id]);
                    if (!$existingCheck->fetchColumn()) {
                        throw new RuntimeException('Degree program not found.');
                    }
                    $stmt = $pdo->prepare('UPDATE degree_programs SET campus_id = ?, program_name = ? WHERE id = ?');
                    $stmt->execute([$campusId, $programName, $id]);
                    echo json_encode(['success' => true, 'message' => 'Degree program updated.']);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO degree_programs (campus_id, program_name, status) VALUES (?, ?, 'active')");
                    $stmt->execute([$campusId, $programName]);
                    echo json_encode(['success' => true, 'message' => 'Degree program added.']);
                }
                exit;
            }
            if ($action === 'toggle' && $id > 0) {
                $existingCheck = $pdo->prepare('SELECT id FROM degree_programs WHERE id = ?');
                $existingCheck->execute([$id]);
                if (!$existingCheck->fetchColumn()) {
                    throw new RuntimeException('Degree program not found.');
                }
                $stmt = $pdo->prepare("
                    UPDATE degree_programs
                    SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Degree program status updated.']);
                exit;
            }
            throw new RuntimeException('Unknown degree-program action.');
        } catch (PDOException $exception) {
            error_log('degree program save failed: ' . $exception->getMessage());
            $message = $exception->getCode() === '23000'
                ? 'That degree program is already listed for this campus.'
                : 'Unable to save the degree program. Check the database and try again.';
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        } catch (RuntimeException $exception) {
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
            exit;
        }
    }
}

$campuses = $pdo->query("SELECT id, campus_name FROM campuses WHERE status = 'active' ORDER BY campus_name")->fetchAll(PDO::FETCH_ASSOC);
$programs = $pdo->query("
    SELECT dp.id, dp.program_name, dp.status, c.campus_name, dp.campus_id
    FROM degree_programs dp
    INNER JOIN campuses c ON c.id = dp.campus_id
    ORDER BY c.campus_name, dp.program_name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page-header">
        <h2>Degree Program Lists</h2>
        <p>Maintain the campus-specific degree programs used for eligibility and applicant accounts.</p>
    </div>
    <?php if ($error !== ''): ?><p role="alert" style="padding:12px;background:#fee2e2;color:#991b1b;border-radius:8px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" data-ajax style="display:flex;flex-wrap:wrap;gap:12px;padding:18px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:18px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save">
        <label>Campus
            <select name="campus_id" required>
                <option value="">Select campus</option>
                <?php foreach ($campuses as $campus): ?><option value="<?= (int)$campus['id'] ?>"><?= htmlspecialchars($campus['campus_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Degree program
            <input type="text" name="program_name" maxlength="255" required>
        </label>
        <button type="submit">Add Degree Program</button>
    </form>
    <div class="table-card" style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr><th>Campus</th><th>Degree Program</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($programs as $program): ?>
                <tr>
                    <td><?= htmlspecialchars($program['campus_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(ucfirst($program['status']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <form method="post" data-ajax>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$program['id'] ?>">
                            <button type="submit"><?= $program['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$programs): ?><tr><td colspan="4">No degree programs configured.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
document.querySelectorAll('form[data-ajax]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"],button:not([type])');
        if (button) button.disabled = true;
        try {
            const response = await fetch(window.location.href, { method: 'POST', body: new FormData(form) });
            const result = await response.json();
            if (!response.ok || !result.success) {
                alert(result.message || 'Unable to save the degree program.');
                return;
            }
            location.reload();
        } catch (error) {
            alert('Unable to reach the server. Please try again.');
        } finally {
            if (button) button.disabled = false;
        }
    });
});
</script>
