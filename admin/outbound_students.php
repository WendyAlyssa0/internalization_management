<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/role_helpers.php';

if (empty($_SESSION['user_id']) || (!canManageActivities() && !isApprover() && !canManageSystem())) {
    http_response_code(empty($_SESSION['user_id']) ? 401 : 403);
    exit('Access denied.');
}

$search = trim((string)($_GET['search'] ?? ''));
$where = "WHERE LOWER(a.mobility_type) LIKE 'outbound%'";
$params = [];
if ($search !== '') {
    $where .= " AND (CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.email LIKE ? OR p.program_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}

$stmt = db()->prepare("
    SELECT a.id, a.mobility_type, a.status, a.created_at, a.institution,
           CONCAT_WS(' ', u.first_name, u.last_name) AS applicant,
           COALESCE(NULLIF(u.account_type, ''), u.user_role) AS participant_type,
           u.program AS degree_program, c.campus_name, p.program_name
    FROM applications a
    LEFT JOIN users u ON u.id = a.applicant_id
    LEFT JOIN campuses c ON c.id = u.campus_id
    LEFT JOIN programs p ON p.id = a.program_id
    $where
    ORDER BY a.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page-header">
        <h2>Outbound Participants</h2>
        <p>Review submitted outbound mobility applications.</p>
    </div>
    <form method="get" style="display:flex;gap:8px;margin-bottom:16px;">
        <input type="hidden" name="page" value="outbound_students">
        <input name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search by name / program">
        <button type="submit">Search</button>
    </form>
    <div class="table-card" style="overflow-x:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr><th>Applicant</th><th>Type</th><th>Campus</th><th>Degree Program</th><th>Mobility Program</th><th>Status</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php foreach ($applications as $application): ?>
                <tr>
                    <td><?= htmlspecialchars($application['applicant'] ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(ucwords((string)$application['participant_type']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($application['campus_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($application['degree_program'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($application['program_name'] ?? $application['institution'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$application['status'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(date('M d, Y', strtotime((string)$application['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$applications): ?><tr><td colspan="7">No outbound applications found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
